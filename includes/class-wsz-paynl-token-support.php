<?php

defined('ABSPATH') || exit;

class WSZ_PayNL_Token_Support
{
    private const MAX_RAW_BODY_BYTES = 1048576;

    /**
     * @return array<string,string>
     */
    public static function read_exchange_payload(): array
    {
        $payload = self::normalize_payload($_REQUEST);
        $raw_payload = self::read_raw_body_payload();

        return array_merge($raw_payload, $payload);
    }

    /**
     * @param array<mixed,mixed> $source
     * @return array<string,string>
     */
    public static function normalize_payload(array $source): array
    {
        $payload = array();

        foreach ($source as $key => $value) {
            self::flatten_payload_value($payload, self::normalize_key((string) $key), $value);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function extract_recurring_id(array $payload): string
    {
        $key = self::extract_recurring_id_source_key($payload);

        if ('' === $key) {
            return '';
        }

        $recurring_id = sanitize_text_field((string) $payload[$key]);

        return self::is_chargeable_recurring_id($recurring_id) ? $recurring_id : '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function extract_recurring_id_source_key(array $payload): string
    {
        foreach (self::recurring_payload_keys() as $key) {
            if (!empty($payload[$key]) && is_scalar($payload[$key])) {
                return $key;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function extract_pay_order_id(array $payload): string
    {
        foreach (
            array(
                'order_id',
                'orderid',
                'id',
                'transaction_id',
                'transactionid',
                'payment_id',
                'paymentid',
                'payment_session_id',
                'paymentsessionid',
                'payment_sessionid',
                'transaction_order_id',
                'transaction_orderid',
            ) as $key
        ) {
            if (!empty($payload[$key]) && is_scalar($payload[$key])) {
                return sanitize_text_field((string) $payload[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function extract_transaction_id(array $payload): string
    {
        foreach (
            array(
                'transaction_id',
                'transactionid',
                'transaction',
                'payment_id',
                'paymentid',
                'payment_session_id',
                'paymentsessionid',
                'payment_sessionid',
            ) as $key
        ) {
            if (!empty($payload[$key]) && is_scalar($payload[$key])) {
                return sanitize_text_field((string) $payload[$key]);
            }
        }

        return '';
    }

    /**
     * @return array<int,string>
     */
    public static function payload_keys(array $payload): array
    {
        return array_values(array_unique(array_filter(array_map('strval', array_keys($payload)))));
    }

    public static function extract_recurring_id_from_order_meta(WC_Order $order): string
    {
        $meta_row = self::get_recurring_id_meta_row($order);

        if (null === $meta_row) {
            return '';
        }

        $meta_key = self::normalize_key(self::get_meta_row_key($meta_row));
        $recurring_id = self::extract_recurring_id_from_meta_value($meta_key, self::get_meta_row_value($meta_row));

        return '' !== $recurring_id ? $recurring_id : '';
    }

    public static function extract_recurring_id_meta_source_key(WC_Order $order): string
    {
        $meta_row = self::get_recurring_id_meta_row($order);

        return null === $meta_row ? '' : self::normalize_key(self::get_meta_row_key($meta_row));
    }

    public static function cache_recurring_id_on_order(WC_Order $order, string $recurring_id, string $source = ''): void
    {
        $recurring_id = sanitize_text_field($recurring_id);

        if ('' === $recurring_id || !is_callable(array($order, 'update_meta_data'))) {
            return;
        }

        $source = sanitize_key($source);

        if ('' === $source) {
            $source = 'paynl_token_exchange';
        }

        $captured_at = function_exists('current_time')
            ? (string) current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');

        $order->update_meta_data('_wsz_paynl_recurring_id', $recurring_id);
        $order->update_meta_data('_wsz_paynl_recurring_source', $source);
        $order->update_meta_data('_wsz_paynl_recurring_captured_at', $captured_at);
    }

    private static function get_recurring_id_meta_row(WC_Order $order)
    {
        if (!is_callable(array($order, 'get_meta_data'))) {
            return null;
        }

        $meta_rows = $order->get_meta_data();

        if (!is_array($meta_rows)) {
            return null;
        }

        foreach ($meta_rows as $meta_row) {
            $meta_key = self::normalize_key(self::get_meta_row_key($meta_row));

            if (!self::is_recurring_reference_meta_key($meta_key)) {
                continue;
            }

            $recurring_id = self::extract_recurring_id_from_meta_value($meta_key, self::get_meta_row_value($meta_row));

            if ('' !== $recurring_id) {
                return $meta_row;
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private static function read_raw_body_payload(): array
    {
        $raw_body = file_get_contents('php://input');

        if (!is_string($raw_body)) {
            return array();
        }

        $raw_body = trim($raw_body);

        if ('' === $raw_body || strlen($raw_body) > self::MAX_RAW_BODY_BYTES) {
            return array();
        }

        $json = json_decode($raw_body, true);
        if (is_array($json)) {
            return self::normalize_payload($json);
        }

        if (false !== strpos($raw_body, '=') && 0 !== strpos($raw_body, '<')) {
            $form_payload = array();
            parse_str($raw_body, $form_payload);
            if (!empty($form_payload)) {
                return self::normalize_payload($form_payload);
            }
        }

        if (0 !== strpos($raw_body, '<') || !function_exists('simplexml_load_string')) {
            return array();
        }

        $previous_errors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw_body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_errors);

        if (!($xml instanceof SimpleXMLElement)) {
            return array();
        }

        $xml_payload = json_decode(wp_json_encode($xml), true);

        return is_array($xml_payload) ? self::normalize_payload($xml_payload) : array();
    }

    /**
     * @param array<string,string> $payload
     * @param mixed                $value
     */
    private static function flatten_payload_value(array &$payload, string $key, $value): void
    {
        if ('' === $key) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $child_key => $child_value) {
                $child_key = self::normalize_key((string) $child_key);

                if ('' === $child_key) {
                    continue;
                }

                self::flatten_payload_value($payload, $key . '_' . $child_key, $child_value);
            }

            return;
        }

        if (!is_scalar($value)) {
            return;
        }

        $payload[$key] = self::normalize_scalar_value($value);
    }

    /**
     * @param mixed $value
     */
    private static function normalize_scalar_value($value): string
    {
        if (function_exists('wp_unslash')) {
            $value = wp_unslash($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private static function normalize_key(string $key): string
    {
        $key = strtolower(trim($key));
        $key = (string) preg_replace('/[^a-z0-9]+/', '_', $key);

        return trim($key, '_');
    }

    /**
     * @return array<int,string>
     */
    private static function recurring_payload_keys(): array
    {
        return array(
            'recurring_id',
            'recurringid',
            'recurring_reference',
            'recurringreference',
            'recurring_reference_id',
            'recurringreferenceid',
            'payment_token_recurring_id',
            'payment_token_recurringid',
            'payment_recurring_id',
            'payment_recurringid',
        );
    }

    private static function is_recurring_reference_meta_key(string $key): bool
    {
        if ('' === $key || in_array($key, array('payment_token_id', '_payment_token_id'), true)) {
            return false;
        }

        if (0 === strpos($key, 'wsz_paynl_recurring_') && !in_array($key, array('wsz_paynl_recurring_id'), true)) {
            return false;
        }

        $exact_keys = array(
            'wsz_paynl_recurring_id',
            'paynl_recurring_id',
            'paynl_recurringid',
            'paynl_recurring_reference',
            'paynl_recurringreference',
            'paynl_recurring_token',
            'paynl_recurringtoken',
            'pay_recurring_id',
            'pay_recurringid',
            'recurring_id',
            'recurringid',
            'recurring_reference',
            'recurringreference',
            'recurring_token',
            'recurringtoken',
        );

        if (in_array($key, $exact_keys, true)) {
            return true;
        }

        if (false !== strpos($key, 'paynl') && false !== strpos($key, 'recurring')) {
            return true;
        }

        return false !== strpos($key, 'recurring')
            && (
                false !== strpos($key, 'id')
                || false !== strpos($key, 'reference')
            );
    }

    /**
     * @param mixed $value
     */
    private static function extract_recurring_id_from_meta_value(string $meta_key, $value): string
    {
        if (is_array($value)) {
            $recurring_id = self::extract_recurring_id(self::normalize_payload($value));

            return '' !== $recurring_id ? $recurring_id : '';
        }

        if (!is_scalar($value)) {
            return '';
        }

        $value = trim(self::normalize_scalar_value($value));

        if ('' === $value) {
            return '';
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $recurring_id = self::extract_recurring_id(self::normalize_payload($decoded));

            if ('' !== $recurring_id) {
                return $recurring_id;
            }
        }

        if (self::is_non_chargeable_recurring_token_key($meta_key)) {
            return '';
        }

        $recurring_id = sanitize_text_field($value);

        return self::is_chargeable_recurring_id($recurring_id) ? $recurring_id : '';
    }

    private static function is_chargeable_recurring_id(string $recurring_id): bool
    {
        return 1 === preg_match('/^VY-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $recurring_id);
    }

    private static function is_non_chargeable_recurring_token_key(string $key): bool
    {
        return in_array(
            $key,
            array(
                'paynl_recurring_token',
                'paynl_recurringtoken',
                'pay_recurring_token',
                'pay_recurringtoken',
                'recurring_token',
                'recurringtoken',
                'payment_recurring_token',
                'payment_recurringtoken',
            ),
            true
        );
    }

    private static function get_meta_row_key($meta_row): string
    {
        if (is_object($meta_row) && is_callable(array($meta_row, 'get_key'))) {
            return (string) $meta_row->get_key();
        }

        if (is_object($meta_row) && is_callable(array($meta_row, 'get_data'))) {
            $data = $meta_row->get_data();

            return is_array($data) ? (string) ($data['key'] ?? '') : '';
        }

        if (is_array($meta_row)) {
            return (string) ($meta_row['key'] ?? $meta_row['meta_key'] ?? '');
        }

        return '';
    }

    /**
     * @return mixed
     */
    private static function get_meta_row_value($meta_row)
    {
        if (is_object($meta_row) && is_callable(array($meta_row, 'get_value'))) {
            return $meta_row->get_value();
        }

        if (is_object($meta_row) && is_callable(array($meta_row, 'get_data'))) {
            $data = $meta_row->get_data();

            return is_array($data) ? ($data['value'] ?? '') : '';
        }

        if (is_array($meta_row)) {
            return $meta_row['value'] ?? $meta_row['meta_value'] ?? '';
        }

        return '';
    }
}
