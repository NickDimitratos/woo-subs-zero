<?php

defined('ABSPATH') || exit;

if (!class_exists('WSZ_PayNL_Token_Support')) {
    require_once __DIR__ . '/class-wsz-paynl-token-support.php';
}

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
        $order = $this->resolve_order_from_payload($payload);

        if (!($order instanceof WC_Order)) {
            $this->respond_true('order_not_found');
        }

        $idempotency_key = $this->build_idempotency_key($order, $payload);

        if ($this->is_replayed_webhook($idempotency_key)) {
            $this->respond_true('duplicate');
        }

        if ($this->is_token_exchange_payload($payload)) {
            $this->mark_webhook_processed($idempotency_key);

            $token_id = $this->store_recurring_payment_token($order, $payload);
            $this->respond_true($token_id > 0 ? 'token_saved' : 'token_not_saved');
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
        return WSZ_PayNL_Token_Support::read_exchange_payload();
    }

    private function extract_order_id(array $payload): int
    {
        foreach (array('order_id', 'orderid', 'order', 'reference', 'customer_reference', 'customerreference') as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return absint($payload[$key]);
            }
        }

        return 0;
    }

    private function resolve_order_from_payload(array $payload): ?WC_Order
    {
        $filtered_order = apply_filters('wsz_subs_gateway_exchange_order', null, $payload);
        if ($filtered_order instanceof WC_Order) {
            return $filtered_order;
        }

        $order_id = $this->extract_order_id($payload);

        if ($order_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order instanceof WC_Order) {
                return $order;
            }
        }

        return $this->resolve_order_by_gateway_references($payload);
    }

    private function resolve_order_by_gateway_references(array $payload): ?WC_Order
    {
        if (!function_exists('wc_get_orders')) {
            return null;
        }

        $reference_values = array_unique(
            array_filter(
                array(
                    $this->extract_transaction_id($payload),
                    sanitize_text_field((string) ($payload['order_id'] ?? '')),
                    sanitize_text_field((string) ($payload['orderid'] ?? '')),
                    sanitize_text_field((string) ($payload['payment_session_id'] ?? '')),
                    sanitize_text_field((string) ($payload['paymentsessionid'] ?? '')),
                )
            )
        );

        $meta_keys = apply_filters(
            'wsz_subs_gateway_exchange_order_lookup_meta_keys',
            array(
                '_transaction_id',
                'transaction_id',
                '_pay_order_id',
                '_paynl_order_id',
                '_payment_session_id',
                'payment_session_id',
            ),
            $payload
        );

        if (!is_array($meta_keys)) {
            return null;
        }

        foreach ($reference_values as $reference_value) {
            foreach ($meta_keys as $meta_key) {
                $meta_key = (string) $meta_key;
                if ('' === $meta_key) {
                    continue;
                }

                $orders = wc_get_orders(
                    array(
                        'limit' => 1,
                        'type' => 'shop_order',
                        'meta_key' => $meta_key,
                        'meta_value' => $reference_value,
                        'return' => 'objects',
                    )
                );

                if (is_array($orders) && !empty($orders)) {
                    $order = reset($orders);
                    if ($order instanceof WC_Order) {
                        return $order;
                    }
                }
            }
        }

        return null;
    }

    private function extract_transaction_id(array $payload): string
    {
        return WSZ_PayNL_Token_Support::extract_transaction_id($payload);
    }

    private function is_token_exchange_payload(array $payload): bool
    {
        $action = isset($payload['action']) ? sanitize_key((string) $payload['action']) : '';

        return 'token' === $action && '' !== WSZ_PayNL_Token_Support::extract_recurring_id($payload);
    }

    private function store_recurring_payment_token(WC_Order $order, array $payload): int
    {
        if (!class_exists('WC_Payment_Token_PayNL')) {
            return 0;
        }

        $recurring_id = WSZ_PayNL_Token_Support::extract_recurring_id($payload);
        if ('' === $recurring_id) {
            return 0;
        }

        $gateway_id = sanitize_key((string) $order->get_payment_method());
        if ('' === $gateway_id) {
            $gateway_id = class_exists('WSZ_PayNL_Gateway_Integration')
                ? WSZ_PayNL_Gateway_Integration::GATEWAY_ID
                : 'pay_gateway_creditcardsgrouped';
        }

        $customer_id = (int) $order->get_customer_id();
        $token_id = $this->find_existing_recurring_token_id($customer_id, $gateway_id, $recurring_id);

        if ($token_id <= 0) {
            $token = new WC_Payment_Token_PayNL();
            $token->set_token($recurring_id);
            $token->set_gateway_id($gateway_id);
            $token->set_user_id($customer_id);
            $token->save();
            $token_id = (int) $token->get_id();
        }

        if ($token_id <= 0) {
            return 0;
        }

        $order->update_meta_data('_payment_token_id', $token_id);
        WSZ_Subscription_Manager::attach_payment_token_to_order($order, $token_id);
        $order->save();
        $this->sync_token_to_order_subscriptions($order, $gateway_id, $token_id);

        do_action('wsz_subs_paynl_token_exchange_saved', $order, $token_id, $payload);

        return $token_id;
    }

    private function find_existing_recurring_token_id(int $customer_id, string $gateway_id, string $recurring_id): int
    {
        if (
            $customer_id <= 0
            || '' === $gateway_id
            || !class_exists('WC_Payment_Tokens')
            || !is_callable(array('WC_Payment_Tokens', 'get_customer_tokens'))
        ) {
            return 0;
        }

        $tokens = WC_Payment_Tokens::get_customer_tokens($customer_id, $gateway_id);

        if (!is_array($tokens)) {
            return 0;
        }

        foreach ($tokens as $token) {
            if (!($token instanceof WC_Payment_Token) || !is_callable(array($token, 'get_token'))) {
                continue;
            }

            if (hash_equals($recurring_id, (string) $token->get_token('edit'))) {
                return (int) $token->get_id();
            }
        }

        return 0;
    }

    private function sync_token_to_order_subscriptions(WC_Order $order, string $gateway_id, int $token_id): void
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

            $this->subscription_manager->set_payment_token_id($subscription, $token_id);
            $subscription->set_payment_method($gateway_id);
            $subscription->save();
        }
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
        $action = isset($payload['action']) ? sanitize_key((string) $payload['action']) : '';
        $recurring_id = WSZ_PayNL_Token_Support::extract_recurring_id($payload);
        $recurring_fingerprint = '' !== $recurring_id
            ? hash('sha256', $recurring_id)
            : '';

        $fingerprint = $order->get_id() . '|' . $transaction_id . '|' . $state . '|' . $action . '|' . $recurring_fingerprint;

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
