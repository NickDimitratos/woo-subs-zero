<?php

defined('ABSPATH') || exit;

if (!class_exists('WSZ_PayNL_Token_Support')) {
    require_once dirname(__DIR__, 3) . '/includes/class-wsz-paynl-token-support.php';
}

class WSZ_PayNL_Gateway_Integration
{
    public const GATEWAY_ID = 'pay_gateway_creditcardsgrouped';

    public const VISA_MASTERCARD_GATEWAY_ID = 'pay_gateway_visamastercard';

    public const VISA_GATEWAY_ID = 'pay_gateway_visa';

    public const MASTERCARD_GATEWAY_ID = 'pay_gateway_mastercard';

    private const GATEWAY_ID_PREFIX = 'pay_gateway_';

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
        $raw = self::raw_token_exchange_body();
        $body_payload = self::token_payload_from_body($raw);
        $payload = $this->is_token_exchange_payload($body_payload) ? $body_payload : $this->read_exchange_payload();
        if (!$this->is_token_exchange_payload($payload)) {
            if ('token' === sanitize_key((string) ($payload['action'] ?? ''))) {
                $this->log_diagnostic('warning', __('PAY.nl token exchange received without a recurring token reference.', 'woo-subzero'), $this->sanitize_token_exchange_log_context($payload));
            }
            return;
        }

        // Order lookup is only a hint until the signature and order association are verified.
        $order = $this->resolve_order_from_token_exchange($payload);
        $verified = $order instanceof WC_Order ? $this->verified_token_exchange_payload($order, $raw, self::token_exchange_headers()) : null;
        if (!is_array($verified)) {
            self::respond_token_exchange(false, 'token_not_verified');
        }
        $token_id = $this->store_recurring_payment_token($order, $verified);
        self::respond_token_exchange($token_id > 0, $token_id > 0 ? 'token_saved' : 'token_not_saved', $token_id > 0 ? 200 : 503);
    }

    /** Read unmodified request bytes: PAY.nl signs the original body, not decoded JSON. */
    public static function raw_token_exchange_body(): string
    {
        $raw = file_get_contents('php://input', false, null, 0, 1048577);
        return is_string($raw) && strlen($raw) <= 1048576 ? $raw : '';
    }

    public static function token_payload_from_body(string $raw): array
    {
        if ('' === $raw || strlen($raw) > 1048576) {
            return array();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        $object = isset($decoded['object']) && is_array($decoded['object']) ? $decoded['object'] : $decoded;
        return WSZ_PayNL_Token_Support::normalize_payload($object);
    }

    public static function token_exchange_headers(): array
    {
        $all = function_exists('getallheaders') ? getallheaders() : array();
        $all = is_array($all) ? array_change_key_case($all, CASE_LOWER) : array();
        $headers = array();
        foreach (array('signature-method', 'signature-algorithm', 'signature-keyid', 'signature') as $key) {
            $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $value = $all[$key] ?? $_SERVER[$server_key] ?? '';
            $headers[$key] = is_scalar($value) ? trim((string) $value) : '';
        }
        return $headers;
    }

    /** Return only signed body data, never merged query parameters. */
    public function verified_token_exchange_payload(WC_Order $order, string $raw, array $headers): ?array
    {
        if ('' === $raw || strlen($raw) > 1048576 || (int) $order->get_customer_id() <= 0) {
            return null;
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        foreach (array('signature-method', 'signature-algorithm', 'signature-keyid', 'signature') as $header) {
            if (isset($headers[$header]) && !is_scalar($headers[$header])) {
                return null;
            }
        }
        $verified = $this->verify_exchange_hmac($order, $raw, $headers);
        // Extensions may perform their own authoritative verification of the entire token exchange.
        $verified = apply_filters('wsz_subs_verify_paynl_token_exchange', $verified, $order, $raw, $headers);
        if (true !== $verified) {
            return null;
        }
        $payload = self::token_payload_from_body($raw);
        $credentials = $this->get_recurring_credentials($order, $order);
        $service_id = (string) ($credentials['service_id'] ?? '');
        $key_id = (string) ($headers['signature-keyid'] ?? '');
        $body_service = $payload['serviceid'] ?? $payload['service_id'] ?? '';
        if (!is_scalar($body_service) || ('' !== (string) $body_service && !hash_equals($service_id, (string) $body_service))
            || (0 === strpos(strtoupper($key_id), 'SL-') && !hash_equals($service_id, $key_id))) {
            return null;
        }
        if (!$this->is_token_exchange_payload($payload) || !$this->token_exchange_matches_order($order, $payload)) {
            return null;
        }
        $resolved = $this->resolve_order_from_token_exchange($payload);
        return $resolved instanceof WC_Order && $resolved->get_id() === $order->get_id() ? $payload : null;
    }

    /** Verify exactly the bytes received, using the key identified by PAY.nl. */
    private function verify_exchange_hmac(WC_Order $order, string $raw, array $headers): bool
    {
        if ('' === $raw || strlen($raw) > 1048576) {
            return false;
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        foreach (array('signature-method', 'signature-algorithm', 'signature-keyid', 'signature') as $header) {
            if (isset($headers[$header]) && !is_scalar($headers[$header])) {
                return false;
            }
        }
        $method = strtolower((string) ($headers['signature-method'] ?? ''));
        $algorithm = strtolower((string) ($headers['signature-algorithm'] ?? ''));
        $key_id = (string) ($headers['signature-keyid'] ?? '');
        $signature = strtolower((string) ($headers['signature'] ?? ''));
        $credentials = $this->get_recurring_credentials($order, $order);
        $keys = array();
        if (!empty($credentials['username']) && !empty($credentials['password'])) {
            $keys[(string) $credentials['username']] = (string) $credentials['password'];
        }
        $keys = apply_filters('wsz_subs_paynl_token_signing_keys', $keys, $order);
        $secret = is_array($keys) && isset($keys[$key_id]) && is_string($keys[$key_id]) ? $keys[$key_id] : '';
        $service_id = (string) ($credentials['service_id'] ?? '');
        return 'hmac' === $method && in_array($algorithm, array('sha256', 'sha512'), true)
            && '' !== $key_id && '' !== $secret && '' !== $signature
            && (0 !== strpos(strtoupper($key_id), 'SL-') || ('' !== $service_id && hash_equals($service_id, $key_id)))
            && hash_equals(hash_hmac($algorithm, $raw, $secret), $signature);
    }

    /** A customer-visible order key is not proof that the payment provider was paid. */
    public function verified_order_exchange_status(WC_Order $order, string $raw, array $headers): ?string
    {
        if (!$this->verify_exchange_hmac($order, $raw, $headers)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || 'order' !== ($decoded['type'] ?? null) || !isset($decoded['object']) || !is_array($decoded['object'])) {
            return null;
        }
        $object = $decoded['object'];
        $credentials = $this->get_recurring_credentials($order, $order);
        $service_id = (string) ($credentials['service_id'] ?? '');
        $body_service = $object['serviceId'] ?? null;
        $reference = $object['reference'] ?? null;
        $amount = $object['amount']['value'] ?? null;
        $currency = $object['amount']['currency'] ?? null;
        $status = $object['status']['code'] ?? null;
        if ('' === $service_id || !is_string($body_service) || !hash_equals($service_id, $body_service)
            || !is_string($reference) || !is_scalar($amount) || !preg_match('/^[0-9]+$/D', (string) $amount)
            || (int) $amount !== $this->amount_to_cents((float) $order->get_total())
            || !is_string($currency) || strtoupper($currency) !== strtoupper((string) $order->get_currency())
            || !is_scalar($status) || !preg_match('/^-?[0-9]+$/D', (string) $status)) {
            return null;
        }
        $payload = WSZ_PayNL_Token_Support::normalize_payload($object);
        if (!hash_equals('WSZ-R' . $order->get_id(), $reference) && !$this->token_exchange_matches_order($order, $payload)) {
            return null;
        }
        if (100 === (int) $status) {
            return 'paid';
        }
        if (in_array((int) $status, array(-60, -61, -63, -64, -80, -90), true)) {
            return 'failed';
        }
        return in_array((int) $status, array(20, 50, 80, 85, 90, 95, 97, 98), true) ? 'pending' : null;
    }

    private function token_exchange_matches_order(WC_Order $order, array $payload): bool
    {
        $provider_ids = $this->paynl_exchange_identifiers($payload);
        if (empty($provider_ids)) {
            return false;
        }
        $keys = apply_filters('wsz_subs_paynl_token_exchange_order_lookup_meta_keys',
            array('transactionId', '_transaction_id', 'transaction_id', '_pay_order_id', '_paynl_order_id', '_payment_session_id', 'payment_session_id'), $payload);
        foreach (is_array($keys) ? $keys : array() as $key) {
            $value = $order->get_meta((string) $key, true);
            if (is_scalar($value) && '' !== (string) $value && in_array((string) $value, $provider_ids, true)) {
                return true;
            }
        }
        if (is_callable(array($order, 'get_transaction_id'))) {
            $transaction_id = (string) $order->get_transaction_id();
            if ('' !== $transaction_id && in_array($transaction_id, $provider_ids, true)) {
                return true;
            }
        }
        if (class_exists('PPMFWC_Helper_Transaction') && is_callable(array('PPMFWC_Helper_Transaction', 'getTransaction'))) {
            foreach ($provider_ids as $provider_id) {
                $transaction = PPMFWC_Helper_Transaction::getTransaction($provider_id);
                if (is_array($transaction) && (int) ($transaction['order_id'] ?? 0) === (int) $order->get_id()) {
                    return true;
                }
            }
        }
        return false;
    }

    /** PAY.nl token exchanges can contain distinct order, transaction and session IDs. */
    private function paynl_exchange_identifiers(array $payload): array
    {
        $ids = array();
        foreach (array('order_id', 'orderid', 'id', 'transaction_id', 'transactionid', 'payment_id', 'paymentid',
            'payment_session_id', 'paymentsessionid', 'payment_sessionid', 'transaction_order_id', 'transaction_orderid') as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && '' !== (string) $payload[$key]) {
                $ids[] = sanitize_text_field((string) $payload[$key]);
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public static function respond_token_exchange(bool $accepted, string $message, int $status = 0): void
    {
        if (!headers_sent()) {
            status_header($status > 0 ? $status : ($accepted ? 200 : 403));
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode(array('result' => $accepted, 'description' => sanitize_text_field($message)));
        exit;
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

        if (!self::is_supported_gateway_id($gateway_id)) {
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
        if (!$this->is_valid_recurring_id($recurring_id)) {
            return array(
                'paid' => false,
                'message' => __('PAY.nl recurring_id must use the documented VY-XXXX-XXXX-XXXX token format.', 'woo-subzero'),
            );
        }

        if ($this->amount_to_cents($amount) < 1) {
            return array(
                'paid' => false,
                'message' => __('PAY.nl recurring amount must be a positive integer in cents.', 'woo-subzero'),
            );
        }

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
                        'error_message' => __('PAY.nl connection failed.', 'woo-subzero'),
                    ),
                    $this->authorize_request_diagnostic_context($endpoint, $payload)
                )
            );

            return array(
                'paid' => false,
                'message' => __('PAY.nl connection failed. Review the gateway connection and retry policy.', 'woo-subzero'),
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
                        'response_keys' => array_values(array_intersect(array_keys($decoded), array('request', 'payment', 'transaction'))),
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
                        'response_keys' => array_values(array_intersect(array_keys($decoded), array('request', 'payment', 'transaction'))),
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
            'transaction_id' => self::safe_transaction_log_id($transaction_id),
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
        $transaction_id = self::safe_transaction_log_id($transaction_id);

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

        foreach ($this->paynl_exchange_identifiers($payload) as $pay_order_id) {
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

        if (!self::is_supported_gateway_id($gateway_id)) {
            return 0;
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
        if ('' !== $gateway_id && !self::is_supported_gateway_id($gateway_id)) {
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
                'amount' => $this->amount_to_cents($amount),
                'currency' => strtoupper($currency ?: get_woocommerce_currency()),
                'ipAddress' => $this->resolve_authorize_ip_address($renewal_order, $subscription),
                'language' => 'EN',
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
                'extra3' => $reference,
                'object' => 'Woo Subs-Zero ' . (defined('WSZ_WOO_SUBZERO_VERSION') ? WSZ_WOO_SUBZERO_VERSION : ''),
            ),
        );
    }

    private function resolve_authorize_ip_address(WC_Order $renewal_order, WC_Order $subscription): string
    {
        $ip_address = '';

        foreach (array($renewal_order, $subscription) as $order) {
            if (is_callable(array($order, 'get_customer_ip_address'))) {
                $ip_address = (string) $order->get_customer_ip_address();

                if ('' !== $ip_address) {
                    break;
                }
            }
        }

        if ('' === $ip_address) {
            foreach (array('REMOTE_ADDR', 'SERVER_ADDR') as $server_key) {
                if (!empty($_SERVER[$server_key]) && is_scalar($_SERVER[$server_key])) {
                    $ip_address = (string) $_SERVER[$server_key];
                    break;
                }
            }
        }

        $ip_address = (string) apply_filters(
            'wsz_subs_paynl_recurring_ip_address',
            $ip_address,
            $renewal_order,
            $subscription
        );

        $ip_address = trim($ip_address);

        if (false !== filter_var($ip_address, FILTER_VALIDATE_IP)) {
            return $ip_address;
        }

        return '127.0.0.1';
    }

    private function amount_to_cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function is_valid_recurring_id(string $recurring_id): bool
    {
        return 1 === preg_match('/^VY-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', trim($recurring_id));
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
                    array_merge($this->paynl_exchange_identifiers($payload), array(
                        isset($payload['reference']) && is_scalar($payload['reference']) ? sanitize_text_field((string) $payload['reference']) : '',
                    ))
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

    /** Never copy arbitrary provider text, tokens, IBANs or API secrets into transaction logs. */
    private static function safe_transaction_log_id(string $value): string
    {
        $value = self::sanitize_text_value($value);
        if (preg_match('/^(?:VY|AT|SL)-/i', $value)) {
            return '';
        }
        $pattern = '/^(?:EX-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}|[0-9]{8,20}X[A-Z0-9]{3,16}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|(?:[A-Z]{2,16}-){1,3}[0-9]{1,16})$/iD';
        return 1 === preg_match($pattern, $value) ? $value : '';
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

    public static function is_supported_gateway_id(string $gateway_id): bool
    {
        $gateway_id = function_exists('sanitize_key')
            ? sanitize_key($gateway_id)
            : strtolower(preg_replace('/[^a-z0-9_\-]/', '', $gateway_id));

        if ('' === $gateway_id) {
            return false;
        }

        $gateway_ids = function_exists('apply_filters')
            ? apply_filters('wsz_subs_paynl_gateway_ids', self::default_gateway_ids())
            : self::default_gateway_ids();

        if (!is_array($gateway_ids)) {
            $gateway_ids = self::default_gateway_ids();
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

    public static function is_paynl_gateway_id(string $gateway_id): bool
    {
        $gateway_id = sanitize_key($gateway_id);

        return 0 === strpos($gateway_id, self::GATEWAY_ID_PREFIX) || self::is_supported_gateway_id($gateway_id);
    }

    public static function is_manual_only_gateway_id(string $gateway_id): bool
    {
        if (!self::is_paynl_gateway_id($gateway_id)
            || (self::is_supported_gateway_id($gateway_id) && self::are_card_tokens_enabled())) {
            return false;
        }

        // Custom adapters must explicitly opt in and supply their own recurring-charge implementation.
        $custom_ids = apply_filters('wsz_subs_tokenized_gateway_ids', array());

        return !is_array($custom_ids) || !in_array(sanitize_key($gateway_id), array_map('sanitize_key', $custom_ids), true);
    }

    public static function has_valid_recurring_payment_token(WC_Order $subscription, int $token_id): bool
    {
        $gateway_id = sanitize_key((string) $subscription->get_payment_method());
        if (!self::is_supported_gateway_id($gateway_id) || $token_id <= 0 || !class_exists('WC_Payment_Tokens')) {
            return false;
        }

        $token = WC_Payment_Tokens::get($token_id);
        $customer_id = (int) $subscription->get_customer_id();

        return $token instanceof WC_Payment_Token
            && $customer_id > 0
            && (int) $token->get_user_id() === $customer_id
            && sanitize_key((string) $token->get_gateway_id()) === $gateway_id
            && '' !== WSZ_PayNL_Token_Support::extract_recurring_id(array('recurring_id' => (string) $token->get_token()));
    }

    public static function can_sync_parent_payment_context(WC_Order $order, WC_Order $subscription): bool
    {
        $gateway_id = sanitize_key((string) $subscription->get_payment_method());

        $current_token_id = (int) $subscription->get_meta('_payment_token_id', true);
        if ($current_token_id > 0 && self::has_valid_recurring_payment_token($subscription, $current_token_id)
            && $current_token_id !== (int) $order->get_meta('_payment_token_id', true)) {
            return false;
        }

        return ('' === $gateway_id || $gateway_id === sanitize_key((string) $order->get_payment_method()))
            && (int) $subscription->get_customer_id() === (int) $order->get_customer_id();
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

            if (!self::can_sync_parent_payment_context($order, $subscription)) {
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
                'transaction.transactionId',
            )
        );
        $request_result = $this->first_scalar($decoded, array('request.result'));
        $status = strtolower(
            $this->first_scalar(
                $decoded,
                array(
                    'transaction.state',
                    'transaction.stateName',
                )
            )
        );

        if ('0' === $request_result) {
            return array(
                'paid' => false,
                'message' => $this->resolve_authorize_error_message($decoded),
            );
        }

        if ('1' !== $request_result) {
            return array(
                'paid' => false,
                'message' => $this->resolve_authorize_error_message($decoded),
            );
        }

        if ($status_code >= 200 && $status_code < 300 && $this->is_paid_authorize_status($status)) {
            return array(
                'paid' => true,
                'transaction_id' => $transaction_id,
            );
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

        return 'paid' === $status;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function resolve_authorize_error_message(array $decoded): string
    {
        $request_result = $this->first_scalar($decoded, array('request.result'));

        if ('' === $request_result) {
            return __('PAY.nl recurring charge response did not include the documented request result.', 'woo-subzero');
        }

        if ('1' === $request_result) {
            return __('PAY.nl recurring charge response did not include an approved transaction status.', 'woo-subzero');
        }

        // Provider error text is untrusted and may echo credentials or payment details.
        return __('PAY.nl recurring charge was not approved.', 'woo-subzero');
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,string>
     */
    private function authorize_response_diagnostic_context(array $decoded): array
    {
        $context = array();
        // Only documented, bounded codes are logged; provider prose can echo sensitive data.
        $fields = array(
            'request_result' => array('request.result', '/^[01]$/D'),
            'request_error_id' => array('request.errorId', '/^PAY-[0-9]{1,10}$/iD'),
            'transaction_state' => array('transaction.state', '/^-?[0-9]{1,6}$/D'),
            'transaction_state_name' => array('transaction.stateName', '/^(paid|pending|declined|denied|cancelled|failed|error|authorised|authorized)$/iD'),
            'payment_bank_code' => array('payment.bankCode', '/^[0-9]{1,8}$/D'),
        );
        foreach ($fields as $context_key => $field) {
            $value = $this->first_scalar($decoded, array($field[0]));
            if ('' !== $value && 1 === preg_match($field[1], $value)) {
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
        $type = $this->first_scalar($payload, array('transaction.type'));
        $method = $this->first_scalar($payload, array('payment.method'));
        $amount = $this->first_scalar($payload, array('transaction.amount'));
        $currency = $this->first_scalar($payload, array('transaction.currency'));
        $reference = $this->first_scalar($payload, array('transaction.reference'));

        return array(
            'paynl_api' => 'Payment/authorize',
            'paynl_endpoint' => self::AUTHORIZE_ENDPOINT === $endpoint ? self::AUTHORIZE_ENDPOINT : 'custom',
            'content_type' => 'application/json',
            'transaction_type' => 'MIT' === $type ? 'MIT' : 'custom',
            'payment_method' => 'token' === $method ? 'token' : 'custom',
            'has_token_id' => '' !== $token_id ? 'yes' : 'no',
            'request_amount' => preg_match('/^[0-9]{1,12}$/D', $amount) ? $amount : 'invalid',
            'request_currency' => preg_match('/^[A-Z]{3}$/D', $currency) ? $currency : 'invalid',
            'request_reference' => preg_match('/^WSZ-R[0-9]+$/D', $reference) ? $reference : 'custom',
        );
    }

    /**
     * @return array{service_id?:string,username?:string,password?:string}
     */
    public function get_recurring_credentials(WC_Order $renewal_order, WC_Order $subscription): array
    {
        return $this->resolve_credentials($renewal_order, $subscription);
    }

    private function resolve_credentials(WC_Order $renewal_order, WC_Order $subscription): array
    {
        $gateway_id = sanitize_key((string) $renewal_order->get_payment_method());
        $settings = $this->get_gateway_settings($gateway_id);
        $paynl_settings = $this->get_paynl_plugin_credentials();

        $service_id = $this->first_setting($settings, array('service_id', 'serviceId', 'service', 'service_location_id', 'sales_location_id'));

        if ('' === $service_id) {
            $service_id = $paynl_settings['service_id'] ?? '';
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
        foreach (array('order_id', 'orderid', 'extra1', 'extra3') as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && 1 === preg_match('/^[0-9]{1,20}$/D', (string) $payload[$key])) {
                $context[$key] = (string) $payload[$key];
            }
        }
        if ('token' === sanitize_key((string) ($payload['action'] ?? ''))) {
            $context['action'] = 'token';
        }
        $source_key = WSZ_PayNL_Token_Support::extract_recurring_id_source_key($payload);
        $context['has_recurring_id'] = '' !== $source_key ? 'yes' : 'no';
        if ('' !== $source_key) {
            $context['recurring_id_source_key'] = $source_key;
        }
        $known_keys = array('action', 'source', 'order_id', 'orderid', 'id', 'transaction_id', 'transactionid', 'payment_id', 'paymentid', 'payment_session_id', 'paymentsessionid', 'reference', 'extra1', 'extra3', 'recurring_id', 'recurringId', 'recurringid');
        $context['payload_keys'] = array_values(array_intersect(array_keys($payload), $known_keys));
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
            self::default_gateway_ids()
        );

        if (!is_array($gateway_ids)) {
            return self::default_gateway_ids();
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

    /**
     * @return array<int,string>
     */
    private static function default_gateway_ids(): array
    {
        return array(
            self::GATEWAY_ID,
            self::VISA_MASTERCARD_GATEWAY_ID,
            self::VISA_GATEWAY_ID,
            self::MASTERCARD_GATEWAY_ID,
        );
    }

    public static function are_card_tokens_enabled(): bool
    {
        if (!function_exists('get_option')) {
            return false;
        }
        $settings = get_option('wsz_subs_options', array());
        return is_array($settings) && 'yes' === sanitize_key((string) ($settings['enable_paynl_tokens'] ?? 'no'));
    }

    private function is_paynl_tokens_enabled(): bool
    {
        return self::are_card_tokens_enabled();
    }
}
