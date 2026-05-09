<?php

defined('ABSPATH') || exit;

class WSZ_Stripe_Gateway_Integration
{
    public const GATEWAY_ID = 'stripe';

    private const PAYMENT_INTENTS_ENDPOINT = 'https://api.stripe.com/v1/payment_intents';

    public function init(): void
    {
        if (!$this->is_stripe_tokens_enabled()) {
            return;
        }

        add_filter('wsz_subs_tokenized_gateway_ids', array($this, 'register_gateway_ids'));
        add_filter('wsz_subs_recurring_charge_callback', array($this, 'provide_recurring_charge_callback'), 10, 6);
    }

    /**
     * @param array<int,string> $gateway_ids
     * @return array<int,string>
     */
    public function register_gateway_ids(array $gateway_ids): array
    {
        foreach ($this->get_gateway_ids() as $gateway_id) {
            $gateway_ids[] = $gateway_id;
        }

        return array_values(array_unique(array_filter(array_map('sanitize_key', $gateway_ids))));
    }

    /**
     * @param mixed $callback
     * @param mixed $recurring_id
     * @param mixed $amount
     * @param mixed $currency
     * @param mixed $renewal_order
     * @param mixed $subscription
     */
    public function provide_recurring_charge_callback($callback, $recurring_id, $amount, $currency, $renewal_order, $subscription)
    {
        if (is_callable($callback) || !($renewal_order instanceof WC_Order)) {
            return $callback;
        }

        $gateway_id = sanitize_key((string) $renewal_order->get_payment_method());

        if (!in_array($gateway_id, $this->get_gateway_ids(), true)) {
            return $callback;
        }

        return function (
            string $resolved_recurring_id,
            float $resolved_amount,
            string $resolved_currency,
            WC_Order $resolved_renewal_order,
            WC_Order $resolved_subscription
        ): array {
            return $this->charge_recurring_payment(
                $resolved_recurring_id,
                $resolved_amount,
                $resolved_currency,
                $resolved_renewal_order,
                $resolved_subscription
            );
        };
    }

    /**
     * @return array{paid:bool,transaction_id?:string,message?:string}
     */
    public function charge_recurring_payment(
        string $payment_method_id,
        float $amount,
        string $currency,
        WC_Order $renewal_order,
        WC_Order $subscription
    ): array {
        $payment_method_id = sanitize_text_field($payment_method_id);
        $currency = strtolower(sanitize_text_field($currency ?: $this->get_woocommerce_currency()));
        $amount_minor = $this->amount_to_minor_units($amount, $currency);
        $secret_key = $this->resolve_secret_key($renewal_order);
        $customer_id = $this->resolve_customer_id($renewal_order, $subscription);

        if ('' === $secret_key) {
            return array(
                'paid' => false,
                'message' => __('Stripe secret key is not configured.', 'woo-subzero'),
            );
        }

        if ('' === $customer_id) {
            return array(
                'paid' => false,
                'message' => __('Stripe customer ID is missing for this subscription renewal.', 'woo-subzero'),
            );
        }

        if ('' === $payment_method_id || $amount_minor <= 0) {
            return array(
                'paid' => false,
                'message' => __('Stripe recurring payment context is incomplete.', 'woo-subzero'),
            );
        }

        if (!function_exists('wp_remote_post')) {
            return array(
                'paid' => false,
                'message' => __('WordPress HTTP API is unavailable for Stripe recurring charge.', 'woo-subzero'),
            );
        }

        $payload = $this->build_payment_intent_payload(
            $payment_method_id,
            $amount_minor,
            $currency,
            $customer_id,
            $renewal_order,
            $subscription
        );

        $payload = apply_filters(
            'wsz_subs_stripe_payment_intent_payload',
            $payload,
            $payment_method_id,
            $amount,
            $currency,
            $renewal_order,
            $subscription
        );

        $endpoint = (string) apply_filters('wsz_subs_stripe_payment_intents_endpoint', self::PAYMENT_INTENTS_ENDPOINT);
        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                    'Idempotency-Key' => $this->build_idempotency_key($renewal_order, $payment_method_id, $amount_minor, $currency),
                ),
                'body' => http_build_query($payload, '', '&'),
            )
        );

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return array(
                'paid' => false,
                'message' => $response->get_error_message(),
            );
        }

        $status_code = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : 0;
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            $decoded = array();
        }

        return $this->parse_payment_intent_response($decoded, $status_code);
    }

    /**
     * @return array<string,mixed>
     */
    private function build_payment_intent_payload(
        string $payment_method_id,
        int $amount_minor,
        string $currency,
        string $customer_id,
        WC_Order $renewal_order,
        WC_Order $subscription
    ): array {
        return array(
            'amount' => $amount_minor,
            'currency' => $currency,
            'customer' => $customer_id,
            'payment_method' => $payment_method_id,
            'off_session' => 'true',
            'confirm' => 'true',
            'description' => sprintf('Renewal order %d', $renewal_order->get_id()),
            'metadata' => array(
                'renewal_order_id' => (string) $renewal_order->get_id(),
                'subscription_id' => (string) $subscription->get_id(),
                'source' => 'woo-subzero',
            ),
        );
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{paid:bool,transaction_id?:string,message?:string}
     */
    private function parse_payment_intent_response(array $decoded, int $status_code): array
    {
        $payment_intent = isset($decoded['error']['payment_intent']) && is_array($decoded['error']['payment_intent'])
            ? $decoded['error']['payment_intent']
            : $decoded;

        $payment_intent_id = isset($payment_intent['id']) && is_scalar($payment_intent['id'])
            ? sanitize_text_field((string) $payment_intent['id'])
            : '';
        $status = isset($payment_intent['status']) && is_scalar($payment_intent['status'])
            ? sanitize_key((string) $payment_intent['status'])
            : '';

        if ($status_code >= 200 && $status_code < 300 && 'succeeded' === $status) {
            return array(
                'paid' => true,
                'transaction_id' => $payment_intent_id,
            );
        }

        if (in_array($status, array('requires_action', 'requires_source_action', 'requires_payment_method'), true)) {
            return array(
                'paid' => false,
                'transaction_id' => $payment_intent_id,
                'message' => __('Stripe renewal requires customer authentication or a new payment method.', 'woo-subzero'),
            );
        }

        return array(
            'paid' => false,
            'transaction_id' => $payment_intent_id,
            'message' => $this->resolve_error_message($decoded),
        );
    }

    /**
     * @return array<int,string>
     */
    private function get_gateway_ids(): array
    {
        $gateway_ids = apply_filters('wsz_subs_stripe_gateway_ids', array(self::GATEWAY_ID));

        if (!is_array($gateway_ids)) {
            return array(self::GATEWAY_ID);
        }

        $normalized = array();

        foreach ($gateway_ids as $gateway_id) {
            $gateway_id = sanitize_key((string) $gateway_id);
            if ('' !== $gateway_id) {
                $normalized[] = $gateway_id;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function is_stripe_tokens_enabled(): bool
    {
        if (!function_exists('get_option')) {
            return false;
        }

        $settings = get_option('wsz_subs_options', array());

        return is_array($settings) && 'yes' === (string) ($settings['enable_stripe_tokens'] ?? 'no');
    }

    private function resolve_secret_key(WC_Order $renewal_order): string
    {
        $settings = $this->get_gateway_settings(sanitize_key((string) $renewal_order->get_payment_method()));
        $testmode = 'yes' === (string) ($settings['testmode'] ?? 'no');
        $keys = $testmode
            ? array('test_secret_key', 'secret_key', 'api_secret', 'secret')
            : array('secret_key', 'live_secret_key', 'api_secret', 'secret');

        $secret_key = $this->first_setting($settings, $keys);

        if ('' !== $secret_key) {
            return $secret_key;
        }

        foreach (array('STRIPE_SECRET_KEY', 'STRIPE_TEST_SECRET_KEY') as $constant_name) {
            if (defined($constant_name) && is_scalar(constant($constant_name)) && '' !== (string) constant($constant_name)) {
                return trim((string) constant($constant_name));
            }
        }

        return '';
    }

    private function resolve_customer_id(WC_Order $renewal_order, WC_Order $subscription): string
    {
        $customer_id = $this->first_order_meta($subscription, array('_stripe_customer_id', '_stripe_customer', 'stripe_customer_id'));

        if ('' === $customer_id) {
            $customer_id = $this->first_order_meta($renewal_order, array('_stripe_customer_id', '_stripe_customer', 'stripe_customer_id'));
        }

        $customer_id = (string) apply_filters('wsz_subs_stripe_customer_id', $customer_id, $renewal_order, $subscription);

        return sanitize_text_field($customer_id);
    }

    /**
     * @param array<int,string> $keys
     */
    private function first_order_meta(WC_Order $order, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $order->get_meta($key, true);
            if (is_scalar($value) && '' !== (string) $value) {
                return (string) $value;
            }
        }

        return '';
    }

    private function amount_to_minor_units(float $amount, string $currency): int
    {
        $currency = strtolower($currency);
        $zero_decimal_currencies = array(
            'bif',
            'clp',
            'djf',
            'gnf',
            'jpy',
            'kmf',
            'krw',
            'mga',
            'pyg',
            'rwf',
            'ugx',
            'vnd',
            'vuv',
            'xaf',
            'xof',
            'xpf',
        );

        if (in_array($currency, $zero_decimal_currencies, true)) {
            return max(0, (int) round($amount));
        }

        return max(0, (int) round($amount * 100));
    }

    private function build_idempotency_key(WC_Order $renewal_order, string $payment_method_id, int $amount_minor, string $currency): string
    {
        $key = sprintf(
            'wsz-renewal-%d-%s-%d-%s',
            (int) $renewal_order->get_id(),
            $payment_method_id,
            $amount_minor,
            strtolower($currency)
        );

        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '-', $key) ?: $key, 0, 255);
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function resolve_error_message(array $decoded): string
    {
        $message = $this->first_scalar($decoded, array('error.message', 'last_payment_error.message', 'message'));

        return '' !== $message ? $message : __('Stripe recurring charge was not approved.', 'woo-subzero');
    }

    private function get_woocommerce_currency(): string
    {
        if (function_exists('get_woocommerce_currency')) {
            return (string) get_woocommerce_currency();
        }

        return 'USD';
    }

    /**
     * @return array<string,mixed>
     */
    private function get_gateway_settings(string $gateway_id): array
    {
        if (function_exists('get_option')) {
            $settings = get_option('woocommerce_' . $gateway_id . '_settings', array());
            if (is_array($settings)) {
                return $settings;
            }
        }

        return array();
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<int,string>  $keys
     */
    private function first_setting(array $settings, array $keys): string
    {
        foreach ($keys as $key) {
            if (!empty($settings[$key]) && is_scalar($settings[$key])) {
                return trim((string) $settings[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>  $paths
     */
    private function first_scalar(array $data, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $this->get_path($data, $path);
            if (is_scalar($value) && '' !== (string) $value) {
                return sanitize_text_field((string) $value);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $data
     * @return mixed
     */
    private function get_path(array $data, string $path)
    {
        $current = $data;

        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }
}
