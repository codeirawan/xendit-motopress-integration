<?php

if (!class_exists('Xendit_API')) {

    class Xendit_API
    {
        private $api_key;

        public function __construct($api_key)
        {
            $this->api_key = $api_key;
        }

        public function create_invoice($order_id, $amount, $customer_email, $payment)
        {
            $endpoint = 'https://api.xendit.co/v2/invoices';

            $success_url = MPHB()->settings()->pages()->getReservationReceivedPageUrl($payment);
            $failure_url = MPHB()->settings()->pages()->getPaymentFailedPageUrl($payment);
            $success_url = add_query_arg('mphb_payment_status', 'mphb-p-completed', $success_url);

            $args = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($this->api_key . ':'),
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array(
                    'external_id' => 'booking_' . $order_id . '_' . time(),
                    'amount' => (int) $amount,
                    'payer_email' => $customer_email,
                    'description' => 'Pembayaran Booking Hotel #' . $order_id,
                    'success_redirect_url' => $success_url,
                    'failure_redirect_url' => $failure_url
                )),
                'timeout' => 120
            );

            $response = wp_remote_post($endpoint, $args);

            if (is_wp_error($response)) {
                return ['message' => $response->get_error_message()];
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code >= 200 && $status_code < 300 && isset($body['invoice_url'])) {
                return $body;
            }

            return ['message' => $body['message'] ?? 'Unknown error'];
        }
    }
}
