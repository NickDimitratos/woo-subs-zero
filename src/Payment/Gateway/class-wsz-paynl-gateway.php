<?php

defined('ABSPATH') || exit;

class WSZ_PayNL_Gateway_Integration
{
    public const GATEWAY_ID = 'pay_gateway_creditcardsgrouped';

    private const AUTHORIZE_ENDPOINT = 'https://payment.pay.nl/v1/Payment/authorize/json';

    public function init(): void
    {
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
        string $recurring_id,
        float $amount,
        string $currency,
        WC_Order $renewal_order,
        WC_Order $subscription
    ): array {
        $credentials = $this->resolve_credentials($renewal_order, $subscription);

        if (empty($credentials['service_id']) || empty($credentials['username']) || empty($credentials['password'])) {
            return array(
                'paid' => false,
                'message' => __('PAY.nl recurring credentials are not configured.', 'woo-subzero'),
            );
        }

        if (!function_exists('wp_remote_post')) {
            return array(
                'paid' => false,
                'message' => __('WordPress HTTP API is unavailable for PAY.nl recurring charge.', 'woo-subzero'),
            );
        }

        $payload = $this->build_authorize_payload(
            $recurring_id,
            $amount,
            $currency,
            $renewal_order,
            $subscription,
            (string) $credentials['service_id']
        );

        $payload = apply_filters(
            'wsz_subs_paynl_recurring_payload',
            $payload,
            $recurring_id,
            $amount,
            $currency,
            $renewal_order,
            $subscription
        );

        $endpoint = (string) apply_filters('wsz_subs_paynl_authorize_endpoint', self::AUTHORIZE_ENDPOINT);
        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode((string) $credentials['username'] . ':' . (string) $credentials['password']),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
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

        $result = $this->parse_authorize_response($decoded, $status_code);

        return apply_filters(
            'wsz_subs_paynl_recurring_charge_result',
            $result,
            $decoded,
            $status_code,
            $renewal_order,
            $subscription
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function build_authorize_payload(
        string $recurring_id,
        float $amount,
        string $currency,
        WC_Order $renewal_order,
        WC_Order $subscription,
        string $service_id
    ): array {
        $reference = 'WSZ-R' . $renewal_order->get_id();

        return array(
            'transaction' => array(
                'type' => 'mit',
                'serviceId' => $service_id,
                'description' => sprintf('Renewal order %d', $renewal_order->get_id()),
                'reference' => $reference,
                'amount' => max(0, (int) round($amount * 100)),
                'currency' => strtoupper($currency ?: get_woocommerce_currency()),
                'exchangeUrl' => $this->get_exchange_url(),
            ),
            'payment' => array(
                'method' => 'token',
                'token' => array(
                    'id' => $recurring_id,
                ),
            ),
            'stats' => array(
                'extra1' => 'subscription_' . $subscription->get_id(),
                'extra2' => 'renewal_' . $renewal_order->get_id(),
                'object' => 'Woo Subs-Zero ' . (defined('WSZ_WOO_SUBZERO_VERSION') ? WSZ_WOO_SUBZERO_VERSION : ''),
            ),
        );
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{paid:bool,transaction_id?:string,message?:string}
     */
    private function parse_authorize_response(array $decoded, int $status_code): array
    {
        $transaction_id = $this->first_scalar(
            $decoded,
            array(
                'transactionId',
                'transaction_id',
                'transaction.id',
                'paymentSessionId',
                'payment_session_id',
                'orderId',
                'order_id',
            )
        );
        $status = strtolower(
            $this->first_scalar(
                $decoded,
                array(
                    'state',
                    'status',
                    'transaction.status',
                    'payment.status',
                    'result',
                )
            )
        );

        if ($status_code >= 200 && $status_code < 300) {
            if ('' === $status || in_array($status, array('paid', 'success', 'approved', 'authorized', 'authorised', 'captured'), true)) {
                return array(
                    'paid' => true,
                    'transaction_id' => $transaction_id,
                );
            }
        }

        $message = $this->first_scalar($decoded, array('message', 'error.message', 'error_description', 'description'));

        return array(
            'paid' => false,
            'message' => '' !== $message ? $message : __('PAY.nl recurring charge was not approved.', 'woo-subzero'),
        );
    }

    /**
     * @return array{service_id?:string,username?:string,password?:string}
     */
    private function resolve_credentials(WC_Order $renewal_order, WC_Order $subscription): array
    {
        $gateway_id = sanitize_key((string) $renewal_order->get_payment_method());
        $settings = $this->get_gateway_settings($gateway_id);

        $credentials = array(
            'service_id' => $this->first_setting($settings, array('service_id', 'serviceId', 'service', 'service_location_id', 'sales_location_id')),
            'username' => $this->first_setting($settings, array('api_username', 'username', 'at_code', 'atcode', 'token_code', 'api_token_id')),
            'password' => $this->first_setting($settings, array('api_password', 'password', 'token', 'api_token', 'apitoken', 'secret', 'api_secret')),
        );

        return apply_filters('wsz_subs_paynl_recurring_credentials', $credentials, $renewal_order, $subscription, $settings);
    }

    /**
     * @return array<string,mixed>
     */
    private function get_gateway_settings(string $gateway_id): array
    {
        $gateway = $this->get_gateway($gateway_id);

        if (is_object($gateway) && property_exists($gateway, 'settings') && is_array($gateway->settings)) {
            return $gateway->settings;
        }

        if (function_exists('get_option')) {
            $settings = get_option('woocommerce_' . $gateway_id . '_settings', array());
            if (is_array($settings)) {
                return $settings;
            }
        }

        return array();
    }

    private function get_gateway(string $gateway_id)
    {
        if ('' === $gateway_id || !function_exists('WC')) {
            return null;
        }

        $wc = WC();
        if (!$wc || !isset($wc->payment_gateways)) {
            return null;
        }

        $gateways = $wc->payment_gateways();
        if (!is_object($gateways) || !is_callable(array($gateways, 'payment_gateways'))) {
            return null;
        }

        $registered = $gateways->payment_gateways();

        return is_array($registered) ? ($registered[$gateway_id] ?? null) : null;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<int,string>  $keys
     */
    private function first_setting(array $settings, array $keys): string
    {
        foreach ($keys as $key) {
            if (!empty($settings[$key]) && is_scalar($settings[$key])) {
                return (string) $settings[$key];
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

    private function get_exchange_url(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }

        return home_url('/wc-api/wsz_gateway_webhook');
    }

    /**
     * @return array<int,string>
     */
    private function get_gateway_ids(): array
    {
        $gateway_ids = apply_filters(
            'wsz_subs_paynl_gateway_ids',
            array(self::GATEWAY_ID)
        );

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
}
