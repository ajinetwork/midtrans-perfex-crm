<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Midtrans_gateway extends App_gateway
{
    protected $snap_url_sandbox    = 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    protected $snap_url_production = 'https://app.midtrans.com/snap/v1/transactions';

    protected $api_url_sandbox    = 'https://api.sandbox.midtrans.com/v2';
    protected $api_url_production = 'https://api.midtrans.com/v2';

    protected $snap_js_sandbox    = 'https://app.sandbox.midtrans.com/snap/snap.js';
    protected $snap_js_production = 'https://app.midtrans.com/snap/snap.js';

    public function __construct()
    {
        parent::__construct();

        $this->setId('midtrans');
        $this->setName('Midtrans');

        $this->setSettings([
            [
                'name'      => 'midtrans_server_key',
                'encrypted' => true,
                'label'     => 'Server Key (Production)',
            ],
            [
                'name'      => 'midtrans_client_key',
                'encrypted' => true,
                'label'     => 'Client Key (Production)',
            ],
            [
                'name'      => 'midtrans_server_key_sandbox',
                'encrypted' => true,
                'label'     => 'Server Key (Sandbox)',
            ],
            [
                'name'      => 'midtrans_client_key_sandbox',
                'encrypted' => true,
                'label'     => 'Client Key (Sandbox)',
            ],
            [
                'name'          => 'midtrans_expiry_minutes',
                'label'         => 'Masa berlaku pembayaran (menit)',
                'default_value' => '1440',
            ],
            [
                'name'          => 'currencies',
                'label'         => 'settings_paymentmethod_currencies',
                'default_value' => 'IDR',
            ],
            [
                'name'          => 'description_dashboard',
                'label'         => 'settings_paymentmethod_description',
                'type'          => 'textarea',
                'default_value' => 'Pembayaran Invoice {invoice_number}',
            ],
            [
                'name'          => 'test_mode_enabled',
                'type'          => 'yes_no',
                'default_value' => 1,
                'label'         => 'settings_paymentmethod_testing_mode',
            ],
        ]);
    }

    /**
     * REQUIRED - dipanggil Perfex saat klien menekan "Pay Now".
     */
    public function process_payment($data)
    {
        $this->ci->session->set_userdata([
            'midtrans_total' => number_format($data['amount'], 2, '.', ''),
        ]);

        redirect(site_url('midtrans/make_payment?invoiceid=' . $data['invoiceid'] . '&hash=' . $data['invoice']->hash));
    }

    public function is_sandbox()
    {
        return $this->getSetting('test_mode_enabled') == '1';
    }

    public function server_key()
    {
        return trim((string) ($this->is_sandbox()
            ? $this->decryptSetting('midtrans_server_key_sandbox')
            : $this->decryptSetting('midtrans_server_key')));
    }

    public function client_key()
    {
        return trim((string) ($this->is_sandbox()
            ? $this->decryptSetting('midtrans_client_key_sandbox')
            : $this->decryptSetting('midtrans_client_key')));
    }

    public function snap_js_url()
    {
        return $this->is_sandbox() ? $this->snap_js_sandbox : $this->snap_js_production;
    }

    public function expiry_minutes()
    {
        $m = (int) $this->getSetting('midtrans_expiry_minutes');

        return $m > 0 ? $m : 1440;
    }

    /**
     * Order ID unik per percobaan bayar (maks 50 karakter di Midtrans).
     * Format: PCRM-{invoiceId}-{timestamp}
     */
    public function gen_order_id($invoice_id)
    {
        return 'PCRM-' . (int) $invoice_id . '-' . time();
    }

    /**
     * Ambil invoice id dari order id. Return null jika format tidak cocok.
     */
    public function invoice_id_from_order($order_id)
    {
        if (preg_match('/^PCRM-(\d+)-\d+$/', (string) $order_id, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Buat transaksi Snap.
     * @return array ['ok' => bool, 'token' => string, 'redirect_url' => string, 'error' => string]
     */
    public function create_snap_transaction(array $payload)
    {
        $url = $this->is_sandbox() ? $this->snap_url_sandbox : $this->snap_url_production;
        $res = $this->request('POST', $url, $payload);

        if (! $res['ok'] || empty($res['body']['token'])) {
            $msg = $res['error'];
            if (isset($res['body']['error_messages'])) {
                $msg = implode('; ', (array) $res['body']['error_messages']);
            }

            return ['ok' => false, 'error' => $msg ?: 'Gagal membuat transaksi Midtrans'];
        }

        return [
            'ok'           => true,
            'token'        => $res['body']['token'],
            'redirect_url' => isset($res['body']['redirect_url']) ? $res['body']['redirect_url'] : '',
        ];
    }

    /**
     * Cek status transaksi langsung ke Midtrans (sumber kebenaran).
     * @return array|null
     */
    public function get_status($order_id)
    {
        $base = $this->is_sandbox() ? $this->api_url_sandbox : $this->api_url_production;
        $res  = $this->request('GET', $base . '/' . rawurlencode($order_id) . '/status');

        if (! $res['ok'] || ! is_array($res['body']) || empty($res['body']['order_id'])) {
            return null;
        }

        return $res['body'];
    }

    /**
     * Validasi signature_key pada notifikasi:
     * SHA512(order_id + status_code + gross_amount + ServerKey)
     */
    public function valid_signature(array $n)
    {
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $k) {
            if (! isset($n[$k])) {
                return false;
            }
        }
        $expected = hash('sha512', $n['order_id'] . $n['status_code'] . $n['gross_amount'] . $this->server_key());

        return hash_equals($expected, (string) $n['signature_key']);
    }

    /**
     * Apakah status sudah berarti uang diterima?
     */
    public function is_paid(array $s)
    {
        $ts = isset($s['transaction_status']) ? $s['transaction_status'] : '';
        $fs = isset($s['fraud_status']) ? $s['fraud_status'] : 'accept';

        if ($ts === 'settlement') {
            return true;
        }

        return $ts === 'capture' && $fs === 'accept';
    }

    /**
     * HTTP client sederhana (Basic Auth: ServerKey sebagai username, password kosong).
     */
    protected function request($method, $url, $body = null)
    {
        $ch      = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->server_key() . ':'),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            log_activity('Midtrans cURL error: ' . $err);

            return ['ok' => false, 'error' => $err, 'body' => null, 'http' => 0];
        }

        $json = json_decode($raw, true);

        if ($code === 401) {
            $key = $this->server_key();
            log_activity('Midtrans 401 Unauthorized | mode: ' . ($this->is_sandbox() ? 'SANDBOX' : 'PRODUCTION')
                . ' | server key: ' . ($key === '' ? '(KOSONG)' : substr($key, 0, 14) . '... (' . strlen($key) . ' karakter)')
                . ' | harapan awalan: ' . ($this->is_sandbox() ? 'SB-Mid-server-' : 'Mid-server-'));
        }

        return [
            'ok'    => $code >= 200 && $code < 300,
            'error' => $code >= 200 && $code < 300 ? '' : 'HTTP ' . $code,
            'body'  => $json,
            'http'  => $code,
        ];
    }
}
