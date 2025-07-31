<?php

use MPHB\Payments\Gateways\Gateway;

class Xendit_Gateway extends Gateway
{
    const GATEWAY_ID = 'xendit';

    private $xendit_secret_key = 'xnd_development_bHZUxpXKLdiXWK71YD4n278htXzCwQPndlEQ4dSfvDEKTOVpI4yOsBvMoQArsdyo';

    public function __construct()
    {
        parent::__construct();
    }

    public function getId()
    {
        return self::GATEWAY_ID;
    }

    public function getTitle()
    {
        return 'Bayar via Xendit (VA/QRIS)';
    }

    public function getAdminTitle()
    {
        return 'Xendit Payment';
    }

    public function getSettingsFields()
    {
        return [];
    }

    public function processPayment(\MPHB\Entities\Booking $booking, \MPHB\Entities\Payment $payment)
    {
        $xendit_api = new Xendit_API($this->xendit_secret_key);

        $response = $xendit_api->create_invoice(
            $booking->getId(),
            $booking->getTotalPrice(),
            $booking->getCustomer()->getEmail(),
            $payment
        );

        if ($response && isset($response['invoice_url'])) {
            wp_redirect($response['invoice_url']);
            exit;
        } else {
            wp_die('Pembayaran Xendit gagal: ' . ($response['message'] ?? 'Unknown error'));
        }
    }
}
