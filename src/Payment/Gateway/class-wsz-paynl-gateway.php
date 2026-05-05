<?php

defined('ABSPATH') || exit;

if (!class_exists('WSZ_PayNL_Token_Support')) {
    require_once dirname(__DIR__, 3) . '/includes/class-wsz-paynl-token-support.php';
}

class WSZ_PayNL_Gateway_Integration
{
    public const GATEWAY_ID = 'pay_gateway_creditcardsgrouped';

    private const AUTHORIZE_ENDPOINT = 'https://payment.pay.nl/v1/Payment/authorize/json';

    private ?WSZ_Subscription_Manager $subscription_manager;

    public function __construct(?WSZ_Subscription_Manager $subscription_manager = null)
    {
        $this->subscription_manager = $subscription_manager;
    }

    public function init(): void
    {
        if (!$this->is_paynl_tokens_enabled()) {
            return;
        }

        add_action('woocommerce_api_wc_pay_gateway_exchange', array($this, 'intercept_paynl_token_exchange'), 1);
        add_filter('wsz_subs_tokenized_gateway_ids', array($this, 'register_gateway_ids'));
        add_filter('wsz_subs_recurring_charge_callback', array($this, 'provide_recurring_charge_callback'), 10, 6);
    }

    public function intercept_paynl_token_exchange(): void
    {
        $payload = $this->read_exchange_payload();

        if (!$this->is_token_exchange_payload($payload)) {
            if ('token' === sanitize_key((string) ($payload['action'] ?? ''))) {
                $this->log_diagnostic(
                    'warning',
                    __('PAY.nl token exchange received without a recurring token reference.', 'woo-subzero'),
                    $this->sanitize_token_exchange_log_context($payload)
                );
            }

            return;
        }

        $order = $this->resolve_order_from_token_exchange($payload);
        if (!($order instanceof WC_Order)) {
            $this->log_diagnostic(
                'warning',
                __('PAY.nl token exchange received, but the WooCommerce order could not be resolved.', 'woo-subzero'),
                $this->sanitize_token_exchange_log_context($payload)
            );
            $this->respond_true('token_order_not_found');
        }

        $token_id = $this->store_recurring_payment_token($order, $payload);
        $this->respond_true($token_id > 0 ? 'token_saved' : 'token_not_saved');
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
     * @param array<string,mixed> $payload
     */
    public function is_token_exchange_payload(array $payload): bool
    {
        $action = isset($payload['action']) ? sanitize_key((string) $payload['action']) : '';

        return 'token' === $action && '' !== $this->extract_recurring_id($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function resolve_order_from_token_exchange(array $payload): ?WC_Order
    {
        $filtered_order = apply_filters('wsz_subs_paynl_token_exchange_order', null, $payload);
        if ($filtered_order instanceof WC_Order) {
            return $filtered_order;
        }

        $pay_order_id = $this->extract_pay_order_id($payload);
        if ('' !== $pay_order_id) {
            $order = $this->resolve_order_from_paynl_transaction_table($pay_order_id);
            if ($order instanceof WC_Order) {
                return $order;
            }
        }

        foreach ($this->get_possible_woo_order_ids($payload) as $order_id) {
            if ($order_id <= 0 || !function_exists('wc_get_order')) {
                continue;
            }

            $order = wc_get_order($order_id);
            if ($order instanceof WC_Order) {
                return $order;
            }
        }

        return $this->resolve_order_by_gateway_meta($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function store_recurring_payment_token(WC_Order $order, array $payload): int
    {
        if (!class_exists('WC_Payment_Token_PayNL')) {
            return 0;
        }

        $recurring_id = $this->extract_recurring_id($payload);
        if ('' === $recurring_id) {
            return 0;
        }

        $gateway_id = sanitize_key((string) $order->get_payment_method());
        if ('' === $gateway_id) {
            $gateway_id = self::GATEWAY_ID;
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
            $this->log_diagnostic(
                'warning',
                __('PAY.nl recurring token could not be saved.', 'woo-subzero'),
                array(
                    'order_id' => $order->get_id(),
                    'gateway_id' => $gateway_id,
                    'customer_id' => $customer_id,
                ) + $this->sanitize_token_exchange_log_context($payload)
            );

            return 0;
        }

        $order->update_meta_data('_payment_token_id', $token_id);
        $order->update_meta_data('_wsz_paynl_recurring_missing_logged', 'no');
        WSZ_PayNL_Token_Support::cache_recurring_id_on_order(
            $order,
            $recurring_id,
            $this->resolve_recurring_cache_source($payload)
        );
        WSZ_Subscription_Manager::attach_payment_token_to_order($order, $token_id);
        $order->save();
        $this->sync_token_to_order_subscriptions($order, $gateway_id, $token_id);

        $this->log_diagnostic(
            'info',
            __('PAY.nl recurring token saved for subscription renewals.', 'woo-subzero'),
            array(
                'order_id' => $order->get_id(),
                'gateway_id' => $gateway_id,
                'customer_id' => $customer_id,
                'payment_token_id' => $token_id,
            ) + $this->sanitize_token_exchange_log_context($payload)
        );

        do_action('wsz_subs_paynl_token_exchange_saved', $order, $token_id, $payload);

        return $token_id;
    }

    public function store_recurring_payment_token_from_order_meta(WC_Order $order): int
    {
        if (!$this->is_paynl_tokens_enabled()) {
            return 0;
        }

        $gateway_id = sanitize_key((string) $order->get_payment_method());
        if ('' !== $gateway_id && !in_array($gateway_id, $this->get_gateway_ids(), true)) {
            return 0;
        }

        $recurring_id = WSZ_PayNL_Token_Support::extract_recurring_id_from_order_meta($order);

        if ('' === $recurring_id) {
            return 0;
        }

        return $this->store_recurring_payment_token(
            $order,
            array(
                'action' => 'token',
                'recurring_id' => $recurring_id,
                'source' => 'order_meta',
            )
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
            'options' => array(
                'tokenization' => 1,
            ),
            'stats' => array(
                'extra1' => 'subscription_' . $subscription->get_id(),
                'extra2' => 'renewal_' . $renewal_order->get_id(),
                'object' => 'Woo Subs-Zero ' . (defined('WSZ_WOO_SUBZERO_VERSION') ? WSZ_WOO_SUBZERO_VERSION : ''),
            ),
        );
    }

    /**
     * @return array<string,string>
     */
    private function read_exchange_payload(): array
    {
        return WSZ_PayNL_Token_Support::read_exchange_payload();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extract_recurring_id(array $payload): string
    {
        return WSZ_PayNL_Token_Support::extract_recurring_id($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolve_recurring_cache_source(array $payload): string
    {
        $source = isset($payload['source']) && is_scalar($payload['source'])
            ? sanitize_key((string) $payload['source'])
            : '';

        if ('' !== $source) {
            return $source;
        }

        $source = WSZ_PayNL_Token_Support::extract_recurring_id_source_key($payload);

        return '' !== $source ? $source : 'paynl_token_exchange';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extract_pay_order_id(array $payload): string
    {
        return WSZ_PayNL_Token_Support::extract_pay_order_id($payload);
    }

    private function resolve_order_from_paynl_transaction_table(string $pay_order_id): ?WC_Order
    {
        if ('' === $pay_order_id || !class_exists('PPMFWC_Helper_Transaction') || !function_exists('wc_get_order')) {
            return null;
        }

        $transaction = PPMFWC_Helper_Transaction::getTransaction($pay_order_id);

        if (!is_array($transaction) || empty($transaction['order_id'])) {
            return null;
        }

        $order = wc_get_order((int) $transaction['order_id']);

        return $order instanceof WC_Order ? $order : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,int>
     */
    private function get_possible_woo_order_ids(array $payload): array
    {
        $order_ids = array();

        foreach (array('reference', 'customer_reference', 'customerreference', 'extra1', 'extra3', 'order') as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $order_ids[] = absint($payload[$key]);
            }
        }

        return array_values(array_unique(array_filter($order_ids)));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolve_order_by_gateway_meta(array $payload): ?WC_Order
    {
        if (!function_exists('wc_get_orders')) {
            return null;
        }

        $reference_values = array_values(
            array_unique(
                array_filter(
                    array(
                        $this->extract_pay_order_id($payload),
                        sanitize_text_field((string) ($payload['payment_session_id'] ?? '')),
                        sanitize_text_field((string) ($payload['paymentsessionid'] ?? '')),
                        sanitize_text_field((string) ($payload['reference'] ?? '')),
                    )
                )
            )
        );

        $meta_keys = apply_filters(
            'wsz_subs_paynl_token_exchange_order_lookup_meta_keys',
            array(
                'transactionId',
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
        if (!($this->subscription_manager instanceof WSZ_Subscription_Manager)) {
            return;
        }

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
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    private function sanitize_token_exchange_log_context(array $payload): array
    {
        $context = array();

        foreach (array('action', 'source', 'order_id', 'orderid', 'id', 'transaction_id', 'transactionid', 'payment_id', 'paymentid', 'payment_session_id', 'paymentsessionid', 'reference', 'extra1', 'extra3') as $key) {
            if (!empty($payload[$key]) && is_scalar($payload[$key])) {
                $context[$key] = sanitize_text_field((string) $payload[$key]);
            }
        }

        $recurring_id_source_key = WSZ_PayNL_Token_Support::extract_recurring_id_source_key($payload);

        if ('' !== $recurring_id_source_key) {
            $context['has_recurring_id'] = 'yes';
            $context['recurring_id_source_key'] = $recurring_id_source_key;
        } else {
            $context['has_recurring_id'] = 'no';
        }

        $context['payload_keys'] = WSZ_PayNL_Token_Support::payload_keys($payload);

        return $context;
    }

    private function log_diagnostic(string $level, string $message, array $context = array()): void
    {
        if (!class_exists('WSZ_Admin_Settings')) {
            return;
        }

        $context['source'] = 'woo-subzero';
        WSZ_Admin_Settings::log_diagnostic($level, $message, $context);
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

    private function is_paynl_tokens_enabled(): bool
    {
        if (!function_exists('get_option')) {
            return false;
        }

        $settings = get_option('wsz_subs_options', array());

        if (!is_array($settings)) {
            return false;
        }

        return 'yes' === sanitize_key((string) ($settings['enable_paynl_tokens'] ?? 'no'));
    }
}
