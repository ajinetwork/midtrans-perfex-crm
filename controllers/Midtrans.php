<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Midtrans extends App_Controller
{
    /**
     * Halaman pembayaran: buat transaksi Snap lalu tampilkan popup Snap.
     */
    public function make_payment()
    {
        $invoiceid = $this->input->get('invoiceid');
        $hash      = $this->input->get('hash');

        check_invoice_restrictions($invoiceid, $hash);

        $this->load->model('invoices_model');
        $invoice = $this->invoices_model->get($invoiceid);

        load_client_language($invoice->clientid);

        // Midtrans hanya mendukung IDR
        if (strtoupper($invoice->currency_name) !== 'IDR') {
            set_alert('danger', _l('midtrans_only_idr'));
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }

        // Nominal: dari session (bisa pembayaran parsial), fallback ke sisa tagihan
        $amount = $this->session->userdata('midtrans_total');
        if (! $amount) {
            $amount = get_invoice_total_left_to_pay($invoice->id, $invoice->total);
        }
        $amount = (int) round((float) $amount);

        if ($amount < 1) {
            set_alert('warning', _l('invalid_transaction'));
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }

        // Data customer
        $this->load->model('clients_model');
        $contact = null;
        if (is_client_logged_in()) {
            $contact = $this->clients_model->get_contact(get_contact_user_id());
        } elseif (total_rows(db_prefix() . 'contacts', ['userid' => $invoice->clientid]) == 1) {
            $contact = $this->clients_model->get_contact(get_primary_contact_user_id($invoice->clientid));
        }

        $invoice_number = format_invoice_number($invoice->id);
        $description    = str_replace(
            '{invoice_number}',
            $invoice_number,
            $this->midtrans_gateway->getSetting('description_dashboard')
        );

        $customer = [];
        if ($contact) {
            $customer = [
                'first_name' => $contact->firstname,
                'last_name'  => $contact->lastname,
                'email'      => $contact->email,
                'phone'      => $contact->phonenumber,
            ];
        }

        $order_id   = $this->midtrans_gateway->gen_order_id($invoice->id);
        $finish_url = site_url('midtrans/finish?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash);

        $payload = [
            'transaction_details' => [
                'order_id'     => $order_id,
                'gross_amount' => $amount,
            ],
            'item_details' => [[
                'id'       => 'INV-' . $invoice->id,
                'price'    => $amount,
                'quantity' => 1,
                'name'     => mb_substr($description, 0, 50),
            ]],
            'customer_details' => $customer,
            'expiry'           => [
                'unit'     => 'minutes',
                'duration' => $this->midtrans_gateway->expiry_minutes(),
            ],
            'callbacks' => [
                'finish' => $finish_url,
            ],
        ];

        $res = $this->midtrans_gateway->create_snap_transaction($payload);

        if (! $res['ok']) {
            log_activity('Midtrans: gagal membuat transaksi invoice ' . $invoice_number . ' - ' . $res['error']);
            set_alert('danger', _l('midtrans_create_failed'));
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }

        $data = [
            'invoice'    => $invoice,
            'amount'     => $amount,
            'token'      => $res['token'],
            'client_key' => $this->midtrans_gateway->client_key(),
            'snap_js'    => $this->midtrans_gateway->snap_js_url(),
            'finish_url' => $finish_url,
            'fail_url'   => site_url('midtrans/failure?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash),
        ];

        echo $this->get_html($data);
    }

    protected function get_html($data)
    {
        ob_start(); ?>
        <?php echo payment_gateway_head(_l('payment_for_invoice') . ' ' . format_invoice_number($data['invoice']->id)); ?>
        <body class="gateway-midtrans">
          <div class="container">
            <div class="col-md-8 col-md-offset-2 mtop30">
              <div class="mbot30 text-center">
                <?php echo payment_gateway_logo(); ?>
              </div>
              <div class="row">
                <div class="panel_s">
                  <div class="panel-body">
                    <h3 class="no-margin">
                      <b><?php echo _l('payment_for_invoice'); ?></b>
                      <a href="<?php echo site_url('invoice/' . $data['invoice']->id . '/' . $data['invoice']->hash); ?>">
                        <b><?php echo format_invoice_number($data['invoice']->id); ?></b>
                      </a>
                    </h3>
                    <h4><?php echo _l('payment_total', app_format_money($data['amount'], $data['invoice']->currency_name)); ?></h4>
                    <hr />
                    <button type="button" id="midtrans-pay-button" class="btn btn-info">
                      <?php echo _l('submit_payment'); ?>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <?php echo payment_gateway_scripts(); ?>
          <script src="<?php echo html_escape($data['snap_js']); ?>"
                  data-client-key="<?php echo html_escape($data['client_key']); ?>"></script>
          <script>
            (function () {
              var token   = <?php echo json_encode($data['token']); ?>;
              var finish  = <?php echo json_encode($data['finish_url']); ?>;
              var failure = <?php echo json_encode($data['fail_url']); ?>;

              function goFinish(result) {
                var q = result && result.order_id ? '&order_id=' + encodeURIComponent(result.order_id) : '';
                window.location.replace(finish + q);
              }

              function pay() {
                window.snap.pay(token, {
                  onSuccess: goFinish,
                  onPending: goFinish,
                  onError:   function () { window.location.replace(failure); },
                  onClose:   function () { /* user bisa menekan tombol lagi */ }
                });
              }

              document.getElementById('midtrans-pay-button').addEventListener('click', pay);
              window.addEventListener('load', pay);
            })();
          </script>
          <?php echo payment_gateway_footer(); ?>
        <?php
        $contents = ob_get_contents();
        ob_end_clean();

        return $contents;
    }

    /**
     * Halaman kembali dari Snap / redirect e-wallet.
     * Status SELALU diverifikasi ke API Midtrans, bukan dari parameter URL.
     */
    public function finish()
    {
        $invoiceid = $this->input->get('invoiceid');
        $hash      = $this->input->get('hash');
        $order_id  = $this->input->get('order_id');

        check_invoice_restrictions($invoiceid, $hash);

        if ($order_id && $this->midtrans_gateway->invoice_id_from_order($order_id) == (int) $invoiceid) {
            $status = $this->midtrans_gateway->get_status($order_id);

            if ($status) {
                if ($this->midtrans_gateway->is_paid($status)) {
                    $this->record_payment($status);
                    set_alert('success', _l('online_payment_recorded_success'));
                } elseif ($status['transaction_status'] === 'pending') {
                    set_alert('info', _l('midtrans_payment_pending'));
                } else {
                    set_alert('warning', _l('midtrans_payment_failed') . ' (' . $status['transaction_status'] . ')');
                }
            } else {
                set_alert('warning', _l('midtrans_status_unknown'));
            }
        } else {
            set_alert('warning', _l('midtrans_payment_pending'));
        }

        $this->session->unset_userdata('midtrans_total');
        redirect(site_url('invoice/' . $invoiceid . '/' . $hash));
    }

    public function failure()
    {
        $invoiceid = $this->input->get('invoiceid');
        $hash      = $this->input->get('hash');

        check_invoice_restrictions($invoiceid, $hash);

        set_alert('warning', _l('invalid_transaction'));
        $this->session->unset_userdata('midtrans_total');
        redirect(site_url('invoice/' . $invoiceid . '/' . $hash));
    }

    /**
     * HTTP Notification (Payment Notification URL) dari Midtrans.
     */
    public function webhook()
    {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (! is_array($body) || ! $this->midtrans_gateway->valid_signature($body)) {
            log_activity('Midtrans webhook: signature tidak valid');
            $this->output->set_status_header(403)->set_output('invalid signature');

            return;
        }

        // Konfirmasi ulang ke API Midtrans
        $status = $this->midtrans_gateway->get_status($body['order_id']);
        if (! $status) {
            $this->output->set_status_header(500)->set_output('status check failed');

            return;
        }

        if ($this->midtrans_gateway->is_paid($status)) {
            $this->record_payment($status);
        } elseif (in_array($status['transaction_status'], ['deny', 'cancel', 'expire', 'failure'], true)) {
            log_activity('Midtrans: order ' . $status['order_id'] . ' berstatus ' . $status['transaction_status']);
        }

        $this->output->set_status_header(200)->set_output('OK');
    }

    /**
     * Catat pembayaran ke invoice, idempotent (aman dipanggil berkali-kali
     * oleh finish + webhook).
     */
    protected function record_payment(array $status)
    {
        $invoice_id = $this->midtrans_gateway->invoice_id_from_order($status['order_id']);
        if (! $invoice_id) {
            return false;
        }

        if (isset($status['currency']) && strtoupper($status['currency']) !== 'IDR') {
            return false;
        }

        // transaction_id Midtrans sebagai penanda unik
        $txid = isset($status['transaction_id']) ? $status['transaction_id'] : $status['order_id'];

        $exists = total_rows(db_prefix() . 'invoicepaymentrecords', [
            'invoiceid'     => $invoice_id,
            'transactionid' => $txid,
        ]);
        if ($exists > 0) {
            return true;
        }

        $success = $this->midtrans_gateway->addPayment([
            'amount'        => (float) $status['gross_amount'],
            'invoiceid'     => $invoice_id,
            'transactionid' => $txid,
            'paymentmethod' => isset($status['payment_type']) ? $status['payment_type'] : '',
        ]);

        if (! $success) {
            log_activity('Midtrans: gagal mencatat pembayaran order ' . $status['order_id']);
        }

        return $success;
    }
}
