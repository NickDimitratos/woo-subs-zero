<?php

defined('ABSPATH') || exit;

class WSZ_Test_Card_Gateway_Integration
{
    public const GATEWAY_ID = 'wsz_test_card';

    private const TRANSACTION_LOG_OPTION = 'wsz_subs_test_card_transactions';

    private const MAX_STORED_TRANSACTIONS = 500;

    public function init(): void
    {
        add_filter('woocommerce_payment_gateways', array($this, 'register_gateway'));
        add_action(
            'woocommerce_scheduled_subscription_payment_' . self::GATEWAY_ID,
            array($this, 'process_scheduled_payment'),
            10,
            2
        );

        add_filter(
            'wsz_subs_gateway_contract_flags_' . self::GATEWAY_ID,
            static function (array $supports): array {
                return array_values(array_unique(array_merge($supports, self::required_supports_flags())));
            }
        );
    }

    /**
     * @param array<int,string> $gateways
     * @return array<int,string>
     */
    public function register_gateway(array $gateways): array
    {
        $this->maybe_load_gateway_class();

        if (class_exists('WSZ_Test_Card_Gateway') && !in_array('WSZ_Test_Card_Gateway', $gateways, true)) {
            $gateways[] = 'WSZ_Test_Card_Gateway';
        }

        return $gateways;
    }

    /**
     * @param mixed $amount
     * @param mixed $renewal_order
     */
    public function process_scheduled_payment($amount, $renewal_order): void
    {
        if (!($renewal_order instanceof WC_Order)) {
            return;
        }

        $transaction_id = 'wsz_test_card_renewal_' . uniqid('', true);

        if (!$renewal_order->is_paid()) {
            $renewal_order->payment_complete($transaction_id);
        }

        $renewal_order->add_order_note(
            __('WSZ Test Card recurring payment approved (test gateway).', 'woo-subzero')
        );

        self::record_transaction($renewal_order, 'renewal', $transaction_id, (float) $amount);
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
        float $amount = 0.0
    ): void {
        $recorded_timestamp = function_exists('current_time')
            ? (int) current_time('timestamp', true)
            : time();

        $subscription_id = self::resolve_subscription_id_from_order($order);

        $entry = array(
            'recorded_at_local' => function_exists('wp_date')
                ? (string) wp_date('Y-m-d H:i:s', $recorded_timestamp)
                : date('Y-m-d H:i:s', $recorded_timestamp),
            'recorded_at_gmt' => gmdate('Y-m-d H:i:s', $recorded_timestamp),
            'context' => self::sanitize_text_value($context),
            'subscription_id' => $subscription_id,
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
                    'WSZ Test Card %s transaction logged: order=%d subscription=%d tx=%s amount=%s',
                    (string) $entry['context'],
                    (int) $entry['order_id'],
                    (int) $entry['subscription_id'],
                    (string) $entry['transaction_id'],
                    (string) $entry['amount']
                ),
                array('source' => 'woo-subzero-test-card')
            );
        }
    }

    public static function required_supports_flags(): array
    {
        return array(
            'products',
            'subscriptions',
            'tokenization',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        );
    }

    private function maybe_load_gateway_class(): void
    {
        if (class_exists('WSZ_Test_Card_Gateway') || !class_exists('WC_Payment_Gateway')) {
            return;
        }

        require_once __DIR__ . '/class-wsz-test-card-gateway-runtime.php';
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
}
