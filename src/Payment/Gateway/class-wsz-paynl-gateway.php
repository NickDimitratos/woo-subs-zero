<?php

defined('ABSPATH') || exit;

if (!class_exists('WSZ_PayNL_Token_Support')) {
    require_once dirname(__DIR__, 3) . '/includes/class-wsz-paynl-token-support.php';
}

class WSZ_PayNL_Gateway_Integration
{
    public const GATEWAY_ID = 'pay_gateway_creditcardsgrouped';

    private const AUTHORIZE_ENDPOINT = 'https://payment.pay.nl/v1/Payment/authorize/json';

    private const TRANSACTION_LOG_OPTION = 'wsz_subs_paynl_card_transactions';

    private const MAX_STORED_TRANSACTIONS = 500;

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

        $request_body = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);

        if (!is_string($request_body) || '' === $request_body) {
            $this->log_diagnostic(
                'error',
                __('PAY.nl recurring authorize payload could not be encoded.', 'woo-subzero'),
                array_merge(
                    array(
                        'renewal_order_id' => $renewal_order->get_id(),
                        'subscription_id' => $subscription->get_id(),
                    ),
                    $this->authorize_request_diagnostic_context(self::AUTHORIZE_ENDPOINT, $payload)
                )
            );

            return array(
                'paid' => false,
                'message' => __('PAY.nl recurring payload could not be encoded.', 'woo-subzero'),
            );
        }

        $endpoint = (string) apply_filters('wsz_subs_paynl_authorize_endpoint', self::AUTHORIZE_ENDPOINT);
        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode((string) $credentials['username'] . ':' . (string) $credentials['password']),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ),
                'body' => $request_body,
            )
        );

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            $this->log_diagnostic(
                'error',
                __('PAY.nl recurring authorize request failed.', 'woo-subzero'),
                array_merge(
                    array(
                        'renewal_order_id' => $renewal_order->get_id(),
                        'subscription_id' => $subscription->get_id(),
                        'error_message' => $response->get_error_message(),
                    ),
                    $this->authorize_request_diagnostic_context($endpoint, $payload)
                )
            );

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

        $result = apply_filters(
            'wsz_subs_paynl_recurring_charge_result',
            $result,
            $decoded,
            $status_code,
            $renewal_order,
            $subscription
        );

        if (empty($result['paid'])) {
            $this->log_diagnostic(
                'warning',
                __('PAY.nl recurring charge was not approved.', 'woo-subzero'),
                array_merge(
                    array(
                        'renewal_order_id' => $renewal_order->get_id(),
                        'subscription_id' => $subscription->get_id(),
                        'status_code' => $status_code,
                        'response_keys' => WSZ_PayNL_Token_Support::payload_keys($decoded),
                        'body_empty' => '' === trim($body) ? 'yes' : 'no',
                    ),
                    $this->authorize_request_diagnostic_context($endpoint, $payload),
                    $this->authorize_response_diagnostic_context($decoded)
                )
            );
        }

        if (!empty($result['paid']) && '' === (string) ($result['transaction_id'] ?? '')) {
            $this->log_diagnostic(
                'warning',
                __('PAY.nl recurring charge approved without a transaction identifier.', 'woo-subzero'),
                array_merge(
                    array(
                        'renewal_order_id' => $renewal_order->get_id(),
                        'subscription_id' => $subscription->get_id(),
                        'status_code' => $status_code,
                        'response_keys' => WSZ_PayNL_Token_Support::payload_keys($decoded),
                        'body_empty' => '' === trim($body) ? 'yes' : 'no',
                    ),
                    $this->authorize_request_diagnostic_context($endpoint, $payload),
                    $this->authorize_response_diagnostic_context($decoded)
                )
            );
        }

        return $result;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_transactions(int $subscription_id = 0, int $limit = 50): array
    {
        $transactions = self::read_transaction_logs();

        if ($subscription_id > 0) {
            $transactions = array_values(
                array_filter(
                    $transactions,
                    static function (array $entry) use ($subscription_id): bool {
                        return (int) ($entry['subscription_id'] ?? 0) === $subscription_id;
                    }
                )
            );
        }

        if ($limit > 0) {
            $transactions = array_slice($transactions, 0, $limit);
        }

        return $transactions;
    }

    public static function record_transaction(
        WC_Order $order,
        string $context,
        string $transaction_id,
        float $amount = 0.0,
        int $subscription_id = 0
    ): void {
        $recorded_timestamp = function_exists('current_time')
            ? (int) current_time('timestamp', true)
            : time();

        $entry = array(
            'recorded_at_local' => function_exists('wp_date')
                ? (string) wp_date('Y-m-d H:i:s', $recorded_timestamp)
                : date('Y-m-d H:i:s', $recorded_timestamp),
            'recorded_at_gmt' => gmdate('Y-m-d H:i:s', $recorded_timestamp),
            'gateway' => 'PAY.nl',
            'context' => self::sanitize_text_value($context),
            'subscription_id' => $subscription_id > 0 ? $subscription_id : self::resolve_subscription_id_from_order($order),
            'order_id' => (int) $order->get_id(),
            'status' => function_exists('sanitize_key')
                ? sanitize_key((string) $order->get_status())
                : preg_replace('/[^a-z0-9_\-]/i', '', (string) $order->get_status()),
            'amount' => $amount > 0 ? $amount : (float) $order->get_total(),
            'currency' => is_callable(array($order, 'get_currency'))
                ? self::sanitize_text_value((string) $order->get_currency())
                : '',
            'transaction_id' => self::sanitize_text_value($transaction_id),
        );

        self::append_transaction_log($entry);

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info(
                sprintf(
                    'PAY.nl %s card transaction logged: order=%d subscription=%d tx=%s amount=%s',
                    (string) $entry['context'],
                    (int) $entry['order_id'],
                    (int) $entry['subscription_id'],
                    (string) $entry['transaction_id'],
                    (string) $entry['amount']
                ),
                array('source' => 'woo-subzero-paynl')
            );
        }
    }

    public static function record_renewal_transaction(
        WC_Order $renewal_order,
        WC_Order $subscription,
        float $amount,
        string $transaction_id
    ): void {
        if (!self::is_supported_gateway_id((string) $renewal_order->get_payment_method())) {
            return;
        }

        self::record_transaction(
            $renewal_order,
            'renewal',
            $transaction_id,
            $amount,
            (int) $subscription->get_id()
        );
    }

    public static function record_renewal_exchange_transaction(WC_Order $renewal_order, string $transaction_id): void
    {
        $transaction_id = self::sanitize_text_value($transaction_id);

        if ('' === $transaction_id || !self::is_supported_gateway_id((string) $renewal_order->get_payment_method())) {
            return;
        }

        $subscription_id = self::resolve_renewal_subscription_id_from_order($renewal_order);

        if ($subscription_id <= 0) {
            return;
        }

        $logs = self::read_transaction_logs();
        $order_id = (int) $renewal_order->get_id();

        foreach ($logs as $index => $entry) {
            if (
                'renewal' !== (string) ($entry['context'] ?? '')
                || $order_id !== (int) ($entry['order_id'] ?? 0)
                || $subscription_id !== (int) ($entry['subscription_id'] ?? 0)
            ) {
                continue;
            }

            $logs[$index]['transaction_id'] = $transaction_id;
            $logs[$index]['status'] = function_exists('sanitize_key')
                ? sanitize_key((string) $renewal_order->get_status())
                : preg_replace('/[^a-z0-9_\-]/i', '', (string) $renewal_order->get_status());
            $logs[$index]['amount'] = (float) $renewal_order->get_total();
            $logs[$index]['currency'] = is_callable(array($renewal_order, 'get_currency'))
                ? self::sanitize_text_value((string) $renewal_order->get_currency())
                : '';

            self::write_transaction_logs($logs);
            self::log_renewal_exchange_transaction_updated($renewal_order, $subscription_id, $transaction_id);
            return;
        }

        self::record_transaction($renewal_order, 'renewal', $transaction_id, 0.0, $subscription_id);
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
        self::record_transaction(
            $order,
            'initial',
            $this->resolve_initial_transaction_id($order, $payload),
            0.0
        );

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
                'type' => 'MIT',
                'serviceId' => $service_id,
                'description' => sprintf('Renewal order %d', $renewal_order->get_id()),
                'reference' => $reference,
                'amount' => max(0, (int) round($amount * 100)),
                'currency' => strtoupper($currency ?: get_woocommerce_currency()),
                'exchangeUrl' => $this->get_exchange_url(),
            ),
            'options' => array(
                'tokenization' => 1,
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
                'extra3' => $reference,
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

    /**
     * @param array<string,mixed> $payload
     */
    private function resolve_initial_transaction_id(WC_Order $order, array $payload): string
    {
        $payload_transaction_id = $this->first_scalar(
            $payload,
            array(
                'transactionid',
                'transactionId',
                'transaction_id',
                'transaction.id',
                'payment.id',
                'paymentId',
                'payment_id',
                'paymentSessionId',
                'payment_session_id',
                'id',
            )
        );

        if ('' !== $payload_transaction_id) {
            return $payload_transaction_id;
        }

        if (is_callable(array($order, 'get_transaction_id'))) {
            $order_transaction_id = (string) $order->get_transaction_id();

            if ('' !== $order_transaction_id) {
                return sanitize_text_field($order_transaction_id);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function append_transaction_log(array $entry): void
    {
        $existing = self::read_transaction_logs();
        array_unshift($existing, $entry);

        if (count($existing) > self::MAX_STORED_TRANSACTIONS) {
            $existing = array_slice($existing, 0, self::MAX_STORED_TRANSACTIONS);
        }

        self::write_transaction_logs($existing);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function read_transaction_logs(): array
    {
        if (function_exists('get_option') && function_exists('update_option')) {
            $stored = get_option(self::TRANSACTION_LOG_OPTION, array());

            return is_array($stored) ? $stored : array();
        }

        $stored = $GLOBALS[self::TRANSACTION_LOG_OPTION] ?? array();

        return is_array($stored) ? $stored : array();
    }

    /**
     * @param array<int,array<string,mixed>> $logs
     */
    private static function write_transaction_logs(array $logs): void
    {
        if (function_exists('update_option') && function_exists('get_option')) {
            update_option(self::TRANSACTION_LOG_OPTION, $logs, false);
            return;
        }

        $GLOBALS[self::TRANSACTION_LOG_OPTION] = $logs;
    }

    private static function sanitize_text_value(string $value): string
    {
        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim($value);
    }

    private static function resolve_subscription_id_from_order(WC_Order $order): int
    {
        $subscription_id = (int) $order->get_meta('_wsz_subscription_id', true);

        if ($subscription_id <= 0 && is_callable(array($order, 'get_type'))) {
            $type = function_exists('sanitize_key')
                ? sanitize_key((string) $order->get_type())
                : preg_replace('/[^a-z0-9_\-]/i', '', (string) $order->get_type());

            if ('shop_subscription' === $type) {
                $subscription_id = (int) $order->get_id();
            }
        }

        if ($subscription_id <= 0 && is_callable(array($order, 'get_parent_id'))) {
            $subscription_id = (int) $order->get_parent_id();
        }

        if ($subscription_id <= 0) {
            $subscription_ids = $order->get_meta('_wsz_subscription_ids', true);

            if (is_array($subscription_ids) && !empty($subscription_ids)) {
                $subscription_id = (int) reset($subscription_ids);
            }
        }

        return max(0, $subscription_id);
    }

    private static function resolve_renewal_subscription_id_from_order(WC_Order $order): int
    {
        $subscription_id = (int) $order->get_meta('_wsz_subscription_id', true);

        if ($subscription_id <= 0 && is_callable(array($order, 'get_parent_id'))) {
            $subscription_id = (int) $order->get_parent_id();
        }

        return max(0, $subscription_id);
    }

    private static function log_renewal_exchange_transaction_updated(
        WC_Order $renewal_order,
        int $subscription_id,
        string $transaction_id
    ): void {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->info(
            sprintf(
                'PAY.nl renewal card transaction updated from exchange: order=%d subscription=%d tx=%s amount=%s',
                (int) $renewal_order->get_id(),
                $subscription_id,
                $transaction_id,
                (string) $renewal_order->get_total()
            ),
            array('source' => 'woo-subzero-paynl')
        );
    }

    private static function is_supported_gateway_id(string $gateway_id): bool
    {
        $gateway_id = function_exists('sanitize_key')
            ? sanitize_key($gateway_id)
            : strtolower(preg_replace('/[^a-z0-9_\-]/', '', $gateway_id));

        if ('' === $gateway_id) {
            return false;
        }

        $gateway_ids = function_exists('apply_filters')
            ? apply_filters('wsz_subs_paynl_gateway_ids', array(self::GATEWAY_ID))
            : array(self::GATEWAY_ID);

        if (!is_array($gateway_ids)) {
            $gateway_ids = array(self::GATEWAY_ID);
        }

        $normalized = array();

        foreach ($gateway_ids as $supported_gateway_id) {
            $supported_gateway_id = function_exists('sanitize_key')
                ? sanitize_key((string) $supported_gateway_id)
                : strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $supported_gateway_id));

            if ('' !== $supported_gateway_id) {
                $normalized[] = $supported_gateway_id;
            }
        }

        return in_array($gateway_id, array_values(array_unique($normalized)), true);
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
                'transaction.transactionId',
                'transaction.transaction_id',
                'transaction.id',
                'paymentSessionId',
                'payment_session_id',
                'payment.id',
                'paymentId',
                'payment_id',
                'paymentSession.id',
                'payment_session.id',
                'transaction.paymentSessionId',
                'transaction.payment_session_id',
                'transaction.orderId',
                'transaction.order_id',
                'orderId',
                'order_id',
                'id',
            )
        );
        $request_result = $this->first_scalar($decoded, array('request.result', 'request_result'));
        $status = strtolower(
            $this->first_scalar(
                $decoded,
                array(
                    'state',
                    'status',
                    'transaction.stateName',
                    'transaction.state_name',
                    'transaction.status',
                    'transaction.state',
                    'status.action',
                    'status.code',
                    'payment.status',
                    'payment.bankMessage',
                    'result',
                )
            )
        );

        if ('0' === $request_result) {
            return array(
                'paid' => false,
                'message' => $this->resolve_authorize_error_message($decoded),
            );
        }

        if ($status_code >= 200 && $status_code < 300) {
            if ('' === $status || $this->is_paid_authorize_status($status)) {
                return array(
                    'paid' => true,
                    'transaction_id' => $transaction_id,
                );
            }
        }

        return array(
            'paid' => false,
            'message' => $this->resolve_authorize_error_message($decoded),
        );
    }

    private function is_paid_authorize_status(string $status): bool
    {
        $status = strtolower(trim($status));

        if ('100' === $status) {
            return true;
        }

        return in_array($status, array('paid', 'success', 'approved', 'authorized', 'authorised', 'captured'), true);
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function resolve_authorize_error_message(array $decoded): string
    {
        $message = $this->first_scalar(
            $decoded,
            array(
                'request.errorMessage',
                'request.error_message',
                'request.errorTag',
                'request.error_tag',
                'message',
                'error.message',
                'error_description',
                'description',
            )
        );

        return '' !== $message ? $message : __('PAY.nl recurring charge was not approved.', 'woo-subzero');
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,string>
     */
    private function authorize_response_diagnostic_context(array $decoded): array
    {
        $context = array();
        $fields = array(
            'request_result' => array('request.result', 'request_result'),
            'request_error_id' => array('request.errorId', 'request.error_id'),
            'request_error_tag' => array('request.errorTag', 'request.error_tag'),
            'request_error_message' => array('request.errorMessage', 'request.error_message'),
            'transaction_state' => array('transaction.state'),
            'transaction_state_name' => array('transaction.stateName', 'transaction.state_name'),
            'payment_bank_code' => array('payment.bankCode', 'payment.bank_code'),
            'payment_bank_message' => array('payment.bankMessage', 'payment.bank_message'),
        );

        foreach ($fields as $context_key => $paths) {
            $value = $this->first_scalar($decoded, $paths);

            if ('' !== $value) {
                $context[$context_key] = $value;
            }
        }

        return $context;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    private function authorize_request_diagnostic_context(string $endpoint, array $payload): array
    {
        $token_id = $this->first_scalar($payload, array('payment.token.id'));

        return array(
            'paynl_api' => 'Payment/authorize',
            'paynl_endpoint' => sanitize_text_field($endpoint),
            'content_type' => 'application/json',
            'transaction_type' => $this->first_scalar($payload, array('transaction.type')),
            'payment_method' => $this->first_scalar($payload, array('payment.method')),
            'has_token_id' => '' !== $token_id ? 'yes' : 'no',
            'request_amount' => $this->first_scalar($payload, array('transaction.amount')),
            'request_currency' => $this->first_scalar($payload, array('transaction.currency')),
            'request_reference' => $this->first_scalar($payload, array('transaction.reference')),
            'request_tokenization' => $this->first_scalar($payload, array('options.tokenization')),
        );
    }

    /**
     * @return array{service_id?:string,username?:string,password?:string}
     */
    private function resolve_credentials(WC_Order $renewal_order, WC_Order $subscription): array
    {
        $gateway_id = sanitize_key((string) $renewal_order->get_payment_method());
        $settings = $this->get_gateway_settings($gateway_id);
        $paynl_settings = $this->get_paynl_plugin_credentials();

        $service_id = $this->first_setting($settings, array('service_id', 'serviceId', 'service', 'service_location_id', 'sales_location_id'));
        $service_secret = $this->first_setting($settings, array('service_secret', 'serviceSecret', 'service_location_secret', 'sales_location_secret', 'saleslocation_secret'));

        if ('' === $service_id) {
            $service_id = $paynl_settings['service_id'] ?? '';
        }

        if ('' === $service_secret) {
            $service_secret = $paynl_settings['service_secret'] ?? '';
        }

        $credentials = array(
            'service_id' => $service_id,
            'username' => $this->first_setting($settings, array('api_username', 'username', 'at_code', 'atcode', 'token_code', 'api_token_id')),
            'password' => $this->first_setting($settings, array('api_password', 'password', 'token', 'api_token', 'apitoken', 'secret', 'api_secret')),
        );

        foreach (array('service_id', 'username', 'password') as $key) {
            if ('' === $credentials[$key] && '' !== ($paynl_settings[$key] ?? '')) {
                $credentials[$key] = $paynl_settings[$key];
            }
        }

        if ('' === $credentials['username'] && '' !== $service_id && '' !== $service_secret) {
            $credentials['username'] = $service_id;
            $credentials['password'] = $service_secret;
        }

        return apply_filters('wsz_subs_paynl_recurring_credentials', $credentials, $renewal_order, $subscription, $settings);
    }

    /**
     * @return array{service_id:string,username:string,password:string}
     */
    private function get_paynl_plugin_credentials(): array
    {
        $credentials = array(
            'service_id' => '',
            'username' => '',
            'password' => '',
            'service_secret' => '',
        );

        if (class_exists('PPMFWC_Helper_Config')) {
            if (is_callable(array('PPMFWC_Helper_Config', 'getServiceId'))) {
                $credentials['service_id'] = trim((string) PPMFWC_Helper_Config::getServiceId());
            }

            if (is_callable(array('PPMFWC_Helper_Config', 'getTokenCode'))) {
                $credentials['username'] = trim((string) PPMFWC_Helper_Config::getTokenCode());
            }

            if (is_callable(array('PPMFWC_Helper_Config', 'getApiToken'))) {
                $credentials['password'] = trim((string) PPMFWC_Helper_Config::getApiToken());
            }

            if (is_callable(array('PPMFWC_Helper_Config', 'getServiceSecret'))) {
                $credentials['service_secret'] = trim((string) PPMFWC_Helper_Config::getServiceSecret());
            }
        }

        if ('' === $credentials['service_id']) {
            $credentials['service_id'] = $this->get_paynl_constant_or_option('PAYNL_SERVICE_ID', 'paynl_serviceid');
        }

        if ('' === $credentials['username']) {
            $credentials['username'] = $this->get_paynl_constant_or_option('PAYNL_TOKEN_CODE', 'paynl_tokencode');
        }

        if ('' === $credentials['password']) {
            $credentials['password'] = $this->get_paynl_constant_or_option('PAYNL_API_TOKEN', 'paynl_apitoken');
        }

        if ('' === $credentials['service_secret']) {
            $credentials['service_secret'] = $this->get_paynl_constant_or_option('PAYNL_SERVICE_SECRET', 'paynl_service_secret');
        }

        if ('' === $credentials['service_secret']) {
            $credentials['service_secret'] = $this->get_paynl_constant_or_option('PAYNL_SECRET', 'paynl_secret');
        }

        return $credentials;
    }

    private function get_paynl_constant_or_option(string $constant_name, string $option_name): string
    {
        if (defined($constant_name) && is_scalar(constant($constant_name)) && '' !== (string) constant($constant_name)) {
            return trim((string) constant($constant_name));
        }

        if (!function_exists('get_option')) {
            return '';
        }

        $value = get_option($option_name, '');

        return is_scalar($value) ? trim((string) $value) : '';
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
