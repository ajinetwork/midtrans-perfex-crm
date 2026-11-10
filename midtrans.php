<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Midtrans Payment Gateway
Description: Terima pembayaran invoice Perfex CRM melalui Midtrans Snap (VA, e-wallet, QRIS, kartu kredit, convenience store, dll).
Version: 1.0.0
Requires at least: 2.3.*
*/

define('MIDTRANS_MODULE_NAME', 'midtrans');

/**
 * Daftarkan gateway: library "Midtrans_gateway" di modules/midtrans/libraries
 */
register_payment_gateway('midtrans_gateway', MIDTRANS_MODULE_NAME);

/**
 * Bahasa
 */
register_language_files(MIDTRANS_MODULE_NAME, [MIDTRANS_MODULE_NAME]);

/**
 * Webhook Midtrans adalah POST JSON tanpa token CSRF.
 * Jika versi Perfex Anda tidak membaca filter ini, tambahkan 'midtrans/webhook'
 * ke $config['csrf_exclude_uris'] di application/config/config.php (lihat README).
 */
hooks()->add_filter('csrf_exclude_uris', 'midtrans_csrf_exclude');
function midtrans_csrf_exclude($uris)
{
    $uris[] = 'midtrans/webhook';

    return $uris;
}

/**
 * Info URL notifikasi di halaman Setup > Settings > Payment Gateways
 */
hooks()->add_action('before_render_payment_gateway_settings', 'midtrans_settings_notice');
function midtrans_settings_notice($gateway)
{
    if (isset($gateway['id']) && $gateway['id'] == 'midtrans') {
        echo '<div class="alert alert-info">';
        echo '<p class="bold">' . _l('midtrans_notice_title') . '</p>';
        echo '<p>' . _l('midtrans_notice_webhook') . '</p>';
        echo '<p><code>' . site_url('midtrans/webhook') . '</code></p>';
        echo '<p>' . _l('midtrans_notice_currency') . '</p>';
        echo '</div>';
    }
}
