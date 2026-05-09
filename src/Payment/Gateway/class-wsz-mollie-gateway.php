<?php

defined('ABSPATH') || exit;

class WSZ_Mollie_Gateway_Integration
{
    public const GATEWAY_ID = 'mollie_wc_gateway_creditcard';

    private const CUSTOMER_PAYMENTS_ENDPOINT = 'https://api.mollie.com/v2/customers/%s/payments';

    public function init(): void
    {
        if (!$this->is_mollie_tokens_enabled()) {
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
     * @return array{paid:bool,pending?:bool,transaction_id?:string,message?:string}
     */
    public function charge_recurring_payment(
        string $recurring_reference,
        float $amount,
        string $currency,
        WC_Order $renewal_order,
        WC_Order $subscription
    ): array {
        $api_key = $this->resolve_api_key($renewal_order);
        $context = $this->resolve_recurring_context($recurring_reference, $renewal_order, $subscription);
        $currency = strtoupper(sanitize_text_field($currency ?: $this->get_woocommerce_currency()));

        if ('' === $api_key) {
            return array(
                'paid' => false,
                'message' => __('Mollie API key is not configured.', 'woo-subzero'),
            );
        }

        if ('' === $context['customer_id']) {
            return array(
                'paid' => false,
                'message' => __('Mollie customer ID is missing for this subscription renewal.', 'woo-subzero'),
            );
        }

        if ('' === $context['mandate_id'] || $amount <= 0) {
            return array(
                'paid' => false,
                'message' => __('Mollie recurring payment context is incomplete.', 'woo-subzero'),
            );
        }

        if (!function_exists('wp_remote_post')) {
            return array(
                'paid' => false,
                'message' => __('WordPress HTTP API is unavailable for Mollie recurring charge.', 'woo-subzero'),
            );
        }

        $payload = $this->build_customer_payment_payload(
            $context['mandate_id'],
            $amount,
            $currency,
            $renewal_order,
            $subscription
        );

        $payload = apply_filters(
            'wsz_subs_mollie_customer_payment_payload',
            $payload,
            $context,
            $amount,
            $currency,
            $renewal_order,
            $subscription
        );

        $endpoint = sprintf(self::CUSTOMER_PAYMENTS_ENDPOINT, rawurlencode($context['customer_id']));
        $endpoint = (string) apply_filters('wsz_subs_mollie_customer_payments_endpoint', $endpoint, $context, $renewal_order, $subscription);
        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Idempotency-Key' => $this->build_idempotency_key($renewal_order, $context['mandate_id'], $amount, $currency),
                ),
                'body' => function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload),
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

        return $this->parse_customer_payment_response($decoded, $status_code);
    }

    /**
     * @return array<string,mixed>
     */
    private function build_customer_payment_payload(
        string $mandate_id,
        float $amount,
        string $currency,
        WC_Order $renewal_order,
        WC_Order $subscription
    ): array {
        $payload = array(
            'amount' => array(
                'value' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
            ),
            'description' => sprintf('Renewal order %d', $renewal_order->get_id()),
            'sequenceType' => 'recurring',
            'mandateId' => $mandate_id,
            'metadata' => array(
                'renewal_order_id' => (int) $renewal_order->get_id(),
                'subscription_id' => (int) $subscription->get_id(),
                'source' => 'woo-subzero',
            ),
        );

        $webhook_url = $this->get_webhook_url($renewal_order, $subscription);
        if ('' !== $webhook_url) {
            $payload['webhookUrl'] = $webhook_url;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{paid:bool,pending?:bool,transaction_id?:string,message?:string}
     */
    private function parse_customer_payment_response(array $decoded, int $status_code): array
    {
        $transaction_id = isset($decoded['id']) && is_scalar($decoded['id'])
            ? sanitize_text_field((string) $decoded['id'])
            : '';
        $status = isset($decoded['status']) && is_scalar($decoded['status'])
            ? sanitize_key((string) $decoded['status'])
            : '';

        if ($status_code >= 200 && $status_code < 300 && 'paid' === $status) {
            return array(
                'paid' => true,
                'transaction_id' => $transaction_id,
            );
        }

        if ($status_code >= 200 && $status_code < 300 && in_array($status, array('open', 'pending', 'authorized'), true)) {
            return array(
                'paid' => false,
                'pending' => true,
                'transaction_id' => $transaction_id,
                'message' => __('Mollie recurring payment is pending.', 'woo-subzero'),
            );
        }

        return array(
            'paid' => false,
            'transaction_id' => $transaction_id,
            'message' => $this->resolve_error_message($decoded),
        );
    }

    /**
     * @return array<int,string>
     */
    private function get_gateway_ids(): array
    {
        $gateway_ids = apply_filters('wsz_subs_mollie_gateway_ids', array(self::GATEWAY_ID));

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

    private function is_mollie_tokens_enabled(): bool
    {
        if (!function_exists('get_option')) {
            return false;
        }

        $settings = get_option('wsz_subs_options', array());

        return is_array($settings) && 'yes' === (string) ($settings['enable_mollie_tokens'] ?? 'no');
    }

    private function resolve_api_key(WC_Order $renewal_order): string
    {
        $settings = $this->get_gateway_settings(sanitize_key((string) $renewal_order->get_payment_method()));
        $api_key = $this->first_setting($settings, array('api_key', 'live_api_key', 'test_api_key', 'mollie_api_key'));

        if ('' !== $api_key) {
            return $api_key;
        }

        foreach (array('MOLLIE_API_KEY', 'MOLLIE_TEST_API_KEY') as $constant_name) {
            if (defined($constant_name) && is_scalar(constant($constant_name)) && '' !== (string) constant($constant_name)) {
                return trim((string) constant($constant_name));
            }
        }

        foreach (array('mollie-payments-for-woocommerce_live_api_key', 'mollie-payments-for-woocommerce_test_api_key') as $option_name) {
            if (!function_exists('get_option')) {
                continue;
            }

            $value = get_option($option_name, '');
            if (is_scalar($value) && '' !== (string) $value) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @return array{customer_id:string,mandate_id:string}
     */
    private function resolve_recurring_context(string $recurring_reference, WC_Order $renewal_order, WC_Order $subscription): array
    {
        $recurring_reference = sanitize_text_field($recurring_reference);
        $parts = $this->parse_recurring_reference($recurring_reference);
        $customer_id = $parts['customer_id'];
        $mandate_id = $parts['mandate_id'];

        if ('' === $customer_id) {
            $customer_id = $this->first_order_meta($subscription, array('_mollie_customer_id', '_mollie_customer', 'mollie_customer_id'));
        }

        if ('' === $customer_id) {
            $customer_id = $this->first_order_meta($renewal_order, array('_mollie_customer_id', '_mollie_customer', 'mollie_customer_id'));
        }

        if ('' === $mandate_id) {
            $mandate_id = $this->first_order_meta($subscription, array('_mollie_mandate_id', '_mollie_mandate', 'mollie_mandate_id'));
        }

        if ('' === $mandate_id) {
            $mandate_id = $this->first_order_meta($renewal_order, array('_mollie_mandate_id', '_mollie_mandate', 'mollie_mandate_id'));
        }

        $context = array(
            'customer_id' => sanitize_text_field($customer_id),
            'mandate_id' => sanitize_text_field($mandate_id),
        );

        $filtered = apply_filters('wsz_subs_mollie_recurring_context', $context, $recurring_reference, $renewal_order, $subscription);

        return is_array($filtered)
            ? array(
                'customer_id' => sanitize_text_field((string) ($filtered['customer_id'] ?? '')),
                'mandate_id' => sanitize_text_field((string) ($filtered['mandate_id'] ?? '')),
            )
            : $context;
    }

    /**
     * @return array{customer_id:string,mandate_id:string}
     */
    private function parse_recurring_reference(string $recurring_reference): array
    {
        $context = array(
            'customer_id' => '',
            'mandate_id' => '',
        );

        foreach (preg_split('/[:|]/', $recurring_reference) ?: array() as $part) {
            $part = trim((string) $part);
            if (str_starts_with($part, 'cst_')) {
                $context['customer_id'] = $part;
                continue;
            }

            if (str_starts_with($part, 'mdt_')) {
                $context['mandate_id'] = $part;
            }
        }

        if ('' === $context['mandate_id'] && '' !== $recurring_reference && !str_starts_with($recurring_reference, 'cst_')) {
            $context['mandate_id'] = $recurring_reference;
        }

        return $context;
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

    private function get_webhook_url(WC_Order $renewal_order, WC_Order $subscription): string
    {
        $default = function_exists('home_url') ? (string) home_url('/wc-api/wsz_mollie_renewal') : '';
        $webhook_url = apply_filters('wsz_subs_mollie_webhook_url', $default, $renewal_order, $subscription);

        return is_scalar($webhook_url) ? trim((string) $webhook_url) : '';
    }

    private function build_idempotency_key(WC_Order $renewal_order, string $mandate_id, float $amount, string $currency): string
    {
        $key = sprintf(
            'wsz-renewal-%d-%s-%s-%s',
            (int) $renewal_order->get_id(),
            $mandate_id,
            str_replace('.', '-', number_format($amount, 2, '.', '')),
            strtolower($currency)
        );

        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '-', $key) ?: $key, 0, 255);
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function resolve_error_message(array $decoded): string
    {
        $message = $this->first_scalar($decoded, array('detail', 'title', 'message', 'error.message'));

        return '' !== $message ? $message : __('Mollie recurring charge was not approved.', 'woo-subzero');
    }

    private function get_woocommerce_currency(): string
    {
        if (function_exists('get_woocommerce_currency')) {
            return (string) get_woocommerce_currency();
        }

        return 'EUR';
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
