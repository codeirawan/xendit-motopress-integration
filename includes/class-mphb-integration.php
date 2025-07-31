<?php

class Xendit_MP_Integration
{
    public function __construct()
    {
        add_action('plugins_loaded', array($this, 'init_gateway'), 20);
    }

    public function init_gateway()
    {
        if (class_exists('\MPHB\Payments\Gateways\Gateway')) {
            require_once XENDIT_MP_PATH . 'includes/class-xendit-gateway.php';
            add_filter('mphb_payment_gateways', function ($gateways) {
                $gateways['xendit'] = 'Xendit_Gateway';
                return $gateways;
            });
        } else {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>Xendit:</strong> MotoPress Hotel Booking harus aktif.</p></div>';
            });

        }

    }

}

new Xendit_MP_Integration();
