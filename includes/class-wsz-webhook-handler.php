<?php

defined('ABSPATH') || exit;

class WSZ_Webhook_Handler
{
    private WSZ_Subscription_Manager $subscription_manager;

    public function __construct(WSZ_Subscription_Manager $subscription_manager)
    {
        $this->subscription_manager = $subscription_manager;
    }

    public function init(): void
    {
        add_action('woocommerce_api_wsz_gateway_webhook', array($this, 'handle_gateway_exchange'));
    }

    public function handle_gateway_exchange(): void
    {
        $payload = $this->read_exchange_payload();
        $order_id = $this->extract_order_id($payload);

        if ($order_id <= 0) {
            $this->respond_true('missing_order_id');
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            $this->respond_true('order_not_found');
        }

        $idempotency_key = $this->build_idempotency_key($order, $payload);

        if ($this->is_replayed_webhook($idempotency_key)) {
            $this->respond_true('duplicate');
        }

        $verified_paid = apply_filters('wsz_subs_verify_gateway_exchange', null, $payload, $order);

        if (!is_bool($verified_paid)) {
            if (!$this->is_order_key_valid($order, $payload)) {
                $this->respond_true('invalid_order_key');
            }

            $verified_paid = $this->fallback_paid_check($payload);
        }

        $this->mark_webhook_processed($idempotency_key);

        if ($verified_paid) {
            $transaction_id = $this->extract_transaction_id($payload);

            if (!$order->is_paid()) {
                $order->payment_complete($transaction_id);
            }

            $this->activate_order_subscriptions($order);
            $this->respond_true('paid');
        }

        $order->update_status('failed', __('Gateway exchange verification indicated unpaid state.', 'woo-subzero'));
        $this->hold_order_subscriptions($order);
        $this->respond_true('not_paid');
    }

    private function activate_order_subscriptions(WC_Order $order): void
    {
        $subscription_ids = $order->get_meta('_wsz_subscription_ids', true);

        if (!is_array($subscription_ids)) {
            return;
        }

        foreach ($subscription_ids as $subscription_id) {
            $subscription = $this->subscription_manager->get_subscription((int) $subscription_id);
            if (!($subscription instanceof WC_Order)) {
                continue;
            }

            if ('pending' !== $subscription->get_status()) {
                continue;
            }

            try {
                $this->subscription_manager->activate_subscription_after_payment(
                    $subscription,
                    __('Activated by verified exchange webhook.', 'woo-subzero')
                );
            } catch (Throwable $throwable) {
                wc_get_logger()->warning(
                    $throwable->getMessage(),
                    array('source' => 'woo-subzero')
                );
                continue;
            }

            // Activation hook is dispatched by the manager activation helper.
        }
    }

    private function hold_order_subscriptions(WC_Order $order): void
    {
        $subscription_ids = $order->get_meta('_wsz_subscription_ids', true);

        if (!is_array($subscription_ids)) {
            return;
        }

        foreach ($subscription_ids as $subscription_id) {
            $subscription = $this->subscription_manager->get_subscription((int) $subscription_id);
            if (!($subscription instanceof WC_Order)) {
                continue;
            }

            if ('active' !== $subscription->get_status()) {
                continue;
            }

            try {
                $this->subscription_manager->transition_status(
                    $subscription,
                    'on-hold',
                    __('Moved to on-hold by failed exchange webhook verification.', 'woo-subzero')
                );
            } catch (Throwable $throwable) {
                wc_get_logger()->warning(
                    $throwable->getMessage(),
                    array('source' => 'woo-subzero')
                );
            }
        }
    }

    private function read_exchange_payload(): array
    {
        $payload = array();

        foreach ($_REQUEST as $key => $value) {
            $payload[sanitize_key((string) $key)] = is_scalar($value) ? wp_unslash((string) $value) : '';
        }

        $raw_body = file_get_contents('php://input');

        if (is_string($raw_body) && '' !== trim($raw_body)) {
            if (strlen($raw_body) > 1024 * 1024) {
                return $payload;
            }

            $json = json_decode($raw_body, true);
            if (is_array($json)) {
                foreach ($json as $key => $value) {
                    $payload[sanitize_key((string) $key)] = is_scalar($value) ? (string) $value : '';
                }
            }

            if (empty($json) && str_starts_with(trim($raw_body), '<')) {
                $previous_errors = libxml_use_internal_errors(true);
                $xml = simplexml_load_string($raw_body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
                libxml_clear_errors();
                libxml_use_internal_errors($previous_errors);

                if ($xml instanceof SimpleXMLElement) {
                    $xml_payload = json_decode(wp_json_encode($xml), true);
                    if (is_array($xml_payload)) {
                        foreach ($xml_payload as $key => $value) {
                            $payload[sanitize_key((string) $key)] = is_scalar($value) ? (string) $value : '';
                        }
                    }
                }
            }
        }

        return $payload;
    }

    private function extract_order_id(array $payload): int
    {
        foreach (array('order_id', 'orderid', 'order') as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return absint($payload[$key]);
            }
        }

        return 0;
    }

    private function extract_transaction_id(array $payload): string
    {
        foreach (array('transaction_id', 'transactionid', 'transaction') as $key) {
            if (!empty($payload[$key])) {
                return sanitize_text_field((string) $payload[$key]);
            }
        }

        return '';
    }

    private function is_order_key_valid(WC_Order $order, array $payload): bool
    {
        if (empty($payload['order_key'])) {
            return false;
        }

        return hash_equals($order->get_order_key(), sanitize_text_field((string) $payload['order_key']));
    }

    private function build_idempotency_key(WC_Order $order, array $payload): string
    {
        $transaction_id = $this->extract_transaction_id($payload);
        $state = isset($payload['state']) ? sanitize_key((string) $payload['state']) : '';

        $fingerprint = $order->get_id() . '|' . $transaction_id . '|' . $state;

        return 'wsz_subs_webhook_' . hash('sha256', $fingerprint);
    }

    private function is_replayed_webhook(string $idempotency_key): bool
    {
        return (bool) get_transient($idempotency_key);
    }

    private function mark_webhook_processed(string $idempotency_key): void
    {
        set_transient($idempotency_key, '1', 7 * DAY_IN_SECONDS);
    }

    private function fallback_paid_check(array $payload): bool
    {
        foreach (array('paid', 'is_paid') as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $value = strtolower((string) $payload[$key]);
            if (in_array($value, array('1', 'true', 'yes', 'paid'), true)) {
                return true;
            }
        }

        if (isset($payload['state'])) {
            $state = strtolower((string) $payload['state']);
            return in_array($state, array('paid', 'success'), true);
        }

        return false;
    }

    private function respond_true(string $message): void
    {
        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo 'TRUE|' . sanitize_text_field($message);
        exit;
    }
}
