<?php

/**
 * Plugin Name: Xendit Payment for MotoPress
 * Plugin URI: https://github.com/codeirawan/xendit-motopress-integration
 * Description: Integrasi pembayaran Xendit untuk MotoPress Hotel Booking. Mendukung Bank Transfer, E-Wallet, dan QRIS. Otomatis konfirmasi booking setelah pembayaran.
 * Version: 1.0.0
 * Author: codeirawan
 * Author URI: https://github.com/codeirawan
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * Text Domain: xendit-motopress-integration
 */


defined('ABSPATH') || exit;

add_filter('plugin_row_meta', function($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $links[] = '<a href="https://github.com/codeirawan/xendit-motopress-integration" target="_blank">Documentation</a>';
        $links[] = '<a href="mailto:codeirawan@gmail.com">Support</a>';
    }
    return $links;
}, 10, 2);


// 1. DEFINE CONSTANTS
define('XENDIT_MP_PATH', plugin_dir_path(__FILE__));
define('XENDIT_MP_URL', plugin_dir_url(__FILE__));

// 2. INIT PLUGIN
add_action('plugins_loaded', 'init_xendit_mp_gateway', 15);

function init_xendit_mp_gateway()
{
    error_log('[Xendit] init_xendit_mp_gateway triggered');

    // Pastikan MPHB aktif
    if (!function_exists('MPHB')) {
        error_log('[Xendit] MPHB not found, showing admin notice');
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error">';
            echo '<p><strong>Xendit:</strong> Plugin MotoPress Hotel Booking harus diaktifkan terlebih dahulu.</p>';
            echo '</div>';
        });
        return;
    }

    error_log('[Xendit] MPHB found, loading core files');

    // 3. LOAD CORE FILES
    require_once XENDIT_MP_PATH . 'includes/class-xendit-api.php';
    require_once XENDIT_MP_PATH . 'includes/class-mphb-integration.php';
    require_once XENDIT_MP_PATH . 'includes/class-xendit-gateway.php';

    if (class_exists('Xendit_Gateway')) {
        error_log('[Xendit] Xendit_Gateway class loaded OK');
        new Xendit_Gateway();
    } else {
        error_log('[Xendit] ERROR: Xendit_Gateway class NOT found');
    }

    // Register gateway ke MotoPress
    add_filter('mphb_payment_gateways', function ($gateways) {
        $gateways['xendit'] = array(
            'admin_label' => 'Xendit Payment',
            'checkout_label' => 'Bayar via Xendit (VA/QRIS)',
            'gateway_class' => 'Xendit_Gateway'
        );
        error_log('[Xendit] Gateway registered');
        return $gateways;
    });
}

// 4. Tambahkan fields setting API Key Xendit
add_filter('mphb_payment_gateway_settings_fields', function ($allFields, $gatewayId) {
    if ($gatewayId === 'xendit') {
        $allFields['xendit_api_key'] = [
            'title' => __('Xendit API Key', 'mphb'),
            'type' => 'text',
            'default' => get_option('xendit_api_key', ''),
        ];

        $allFields['xendit_secret_key'] = [
            'title' => __('Xendit Secret Key', 'mphb'),
            'type' => 'password',
            'default' => get_option('xendit_secret_key', ''),
        ];

        $allFields['xendit_callback_url'] = [
            'title' => __('Callback URL', 'mphb'),
            'type' => 'text',
            'default' => home_url('/wp-json/xendit/v1/webhook'),
            'custom_attributes' => ['readonly' => 'readonly'],
        ];
    }
    return $allFields;
}, 10, 2);

// 5. Simpan API Key
add_action('update_option_mphb_payment_gateways', function ($value) {
    if (!empty($value['xendit']['xendit_api_key'])) {
        update_option('xendit_api_key', sanitize_text_field($value['xendit']['xendit_api_key']));
    }
    if (!empty($value['xendit']['xendit_secret_key'])) {
        update_option('xendit_secret_key', sanitize_text_field($value['xendit']['xendit_secret_key']));
    }
}, 10, 1);

// 6. Webhook handler
add_action('rest_api_init', function () {
    register_rest_route('xendit/v1', '/webhook', [
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            global $wpdb;
            $posts_table = $wpdb->prefix . 'posts';
            error_log("[Xendit Debug] Using posts table: {$posts_table}");

            $body = $request->get_json_params();
            error_log('[Xendit Webhook] Payload: ' . print_r($body, true));

            $external_id = $body['external_id'] ?? '';
            $status = $body['status'] ?? '';
            $xendit_txid = $body['payment_id'] ?? '';
            $success_redirect_url = $body['success_redirect_url'] ?? '';

            if ($status !== 'PAID' || empty($external_id)) {
                error_log("[Xendit Webhook] Status bukan PAID atau external_id kosong → STOP");
                return new WP_REST_Response(['skipped' => true], 200);
            }

            if (!preg_match('/booking_(\d+)/', $external_id, $matches)) {
                error_log("[Xendit Webhook] Tidak bisa extract booking_id dari external_id");
                return new WP_REST_Response(['skipped' => true], 200);
            }

            $booking_id = intval($matches[1]);
            error_log("[Xendit Webhook] Booking ID ditemukan: {$booking_id}");

            // Ambil payment_id dari URL
            $payment_id = 0;
            if (!empty($success_redirect_url)) {
                $parts = parse_url($success_redirect_url);
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $query);
                    $payment_id = intval($query['payment_id'] ?? 0);
                }
            }
            error_log("[Xendit Webhook] Payment ID dari URL: {$payment_id}");

            if (!$payment_id) {
                error_log("[Xendit Webhook] ERROR: payment_id dari URL tidak ditemukan");
                return new WP_REST_Response(['error' => 'no_payment_id'], 200);
            }

            // Cek apakah Payment ada di DB
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$posts_table} WHERE ID = %d", $payment_id),
                ARRAY_A
            );

            if (!$row) {
                error_log("[Xendit Webhook] Payment ID {$payment_id} BELUM ADA → buat baru");

                $new_payment_id = wp_insert_post([
                    'ID' => $payment_id,
                    'post_type' => 'mphb_payment',
                    'post_status' => 'mphb-p-completed', // fallback agar muncul di admin
                    'post_title' => 'Xendit Payment for Booking #' . $booking_id,
                    'post_parent' => $booking_id,
                ], true);

                if (is_wp_error($new_payment_id)) {
                    error_log("[Xendit Webhook] GAGAL buat Payment baru: " . $new_payment_id->get_error_message());
                } else {
                    $payment_id = $new_payment_id;
                    error_log("[Xendit Webhook] Payment baru dibuat dengan ID {$payment_id}");
                }
            } else {
                error_log("[Xendit Webhook] Payment ditemukan di DB: " . print_r($row, true));
            }

            // Update Payment status
            $result = wp_update_post([
                'ID' => $payment_id,
                'post_status' => 'mphb-p-completed', // publish biar tampil di admin list
                'post_parent' => $booking_id,
            ], true);

            if (is_wp_error($result)) {
                error_log("[Xendit Webhook] GAGAL update Payment: " . $result->get_error_message());
            } else {
                error_log("[Xendit Webhook] Payment #{$payment_id} diupdate jadi mphb-completed");

                // Tambahkan log riwayat
                $logs = get_post_meta($payment_id, '_mphb_logs', true);
                if (!is_array($logs))
                    $logs = [];

                if (!empty($logs)) {
                    $last = end($logs);
                    if (isset($last['message']) && preg_match('/to\s+\.$/', $last['message'])) {
                        array_pop($logs);
                        error_log("[Xendit Webhook] Log kosong dihapus");
                    }
                }

                $logs[] = [
                    'date' => current_time('mysql'),
                    'message' => 'Status changed from Pending to Completed.',
                ];
                update_post_meta($payment_id, '_mphb_logs', $logs);
            }

            // Update Meta MotoPress
            update_post_meta($payment_id, '_mphb_status', 'completed');
            update_post_meta($payment_id, '_mphb_gateway_id', 'xendit');
            update_post_meta($payment_id, '_mphb_gateway', 'xendit');
            update_post_meta($payment_id, '_mphb_gateway_mode', 'sandbox');
            update_post_meta($payment_id, '_mphb_amount', $body['paid_amount'] ?? $body['amount']);
            update_post_meta($payment_id, '_mphb_currency', $body['currency'] ?? 'IDR');
            update_post_meta($payment_id, '_mphb_booking_id', $booking_id);

            if (!empty($external_id)) {
                update_post_meta($payment_id, '_mphb_transaction_id', sanitize_text_field($external_id));
            }

            // Bersihkan cache agar Payment muncul di admin
            delete_transient('mphb_payments_list');

            $meta = get_post_meta($payment_id);
            error_log("[Xendit Debug] Final Meta Payment #{$payment_id}: " . print_r($meta, true));

            // Update Booking
            wp_update_post([
                'ID' => $booking_id,
                'post_status' => 'confirmed',
            ]);
            error_log("[Xendit Webhook] Booking #{$booking_id} CONFIRMED");

            return new WP_REST_Response(['success' => true, 'payment_id' => $payment_id], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});

// Override tampilan status Payment di admin list MotoPress
add_filter('display_post_states', function ($post_states, $post) {
    if ($post->post_type === 'mphb_payment') {
        $mphb_status = get_post_meta($post->ID, '_mphb_status', true);
        if ($mphb_status === 'mphb-p-completed') {
            $post_states = ['Completed'];
        } elseif ($mphb_status === 'pending') {
            $post_states = ['Pending'];
        }
    }
    return $post_states;
}, 10, 2);
