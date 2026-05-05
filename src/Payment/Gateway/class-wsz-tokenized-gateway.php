<?php

defined('ABSPATH') || exit;

class WSZ_Tokenized_Gateway
{
    private WSZ_Subscription_Manager $subscription_manager;

    private WSZ_Payment_Handler $payment_handler;

    public function __construct(WSZ_Subscription_Manager $subscription_manager, WSZ_Payment_Handler $payment_handler)
    {
        $this->subscription_manager = $subscription_manager;
        $this->payment_handler = $payment_handler;
    }

    public function init(): void
    {
        foreach ($this->get_gateway_ids() as $gateway_id) {
            add_action("woocommerce_scheduled_subscription_payment_{$gateway_id}", array($this, 'process_scheduled_payment'), 10, 2);

            add_filter(
                "wsz_subs_gateway_contract_flags_{$gateway_id}",
                static function (array $supports): array {
                    return array_values(array_unique(array_merge($supports, self::required_supports_flags())));
                }
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

    private function get_gateway_ids(): array
    {
        $gateway_ids = apply_filters('wsz_subs_tokenized_gateway_ids', array());

        if (!is_array($gateway_ids)) {
            return array();
        }

        $normalized = array();

        foreach ($gateway_ids as $gateway_id) {
            $normalized_id = sanitize_key((string) $gateway_id);

            if ('' === $normalized_id) {
                continue;
            }

            $normalized[] = $normalized_id;
        }

        return array_values(array_unique($normalized));
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

        try {
            $this->process_scheduled_payment_for_order($amount, $renewal_order);
        } catch (Throwable $throwable) {
            $this->handle_processing_throwable($renewal_order, $throwable);
        }
    }

    private function process_scheduled_payment_for_order($amount, WC_Order $renewal_order): void
    {
        $subscription = $this->resolve_subscription_from_renewal_order($renewal_order);

        if (!($subscription instanceof WC_Order)) {
            $this->log_diagnostic(
                'error',
                __('Could not resolve source subscription for renewal.', 'woo-subzero'),
                array('renewal_order_id' => $renewal_order->get_id())
            );
            $this->mark_renewal_failed(
                $renewal_order,
                __('Could not resolve source subscription for renewal.', 'woo-subzero'),
                array('renewal_order_id' => $renewal_order->get_id())
            );
            return;
        }

        $token = $this->payment_handler->get_payment_token_for_subscription($subscription);

        if (!($token instanceof WC_Payment_Token)) {
            $context = $this->build_missing_token_context($subscription, $renewal_order);

            $this->log_diagnostic(
                'error',
                __('Missing or invalid payment token for renewal.', 'woo-subzero'),
                $context
            );
            $this->mark_renewal_failed(
                $renewal_order,
                __('Missing or invalid payment token for renewal.', 'woo-subzero'),
                $context
            );
            return;
        }

        $recurring_id = (string) $token->get_token();
        if ('' === $recurring_id) {
            $context = $this->build_missing_token_context($subscription, $renewal_order) + array(
                'payment_token_id' => $token->get_id(),
            );

            $this->log_diagnostic(
                'error',
                __('Recurring reference is empty for payment token.', 'woo-subzero'),
                $context
            );
            $this->mark_renewal_failed(
                $renewal_order,
                __('Recurring reference is empty for payment token.', 'woo-subzero'),
                $context
            );
            return;
        }

        $currency = $renewal_order->get_currency() ?: get_woocommerce_currency();
        $charge_result = $this->charge_recurring_reference($recurring_id, (float) $amount, $currency, $renewal_order, $subscription);

        if (!empty($charge_result['paid'])) {
            $transaction_id = isset($charge_result['transaction_id']) ? (string) $charge_result['transaction_id'] : '';

            if (!$renewal_order->is_paid()) {
                $this->complete_paid_renewal_order($renewal_order, $transaction_id);
            }

            return;
        }

        $message = !empty($charge_result['message'])
            ? sanitize_text_field((string) $charge_result['message'])
            : __('Recurring charge failed.', 'woo-subzero');

        $this->log_diagnostic(
            'error',
            $message,
            array(
                'subscription_id' => $subscription->get_id(),
                'renewal_order_id' => $renewal_order->get_id(),
                'payment_token_id' => $token->get_id(),
            )
        );

        $this->mark_renewal_failed(
            $renewal_order,
            $message,
            array(
                'subscription_id' => $subscription->get_id(),
                'renewal_order_id' => $renewal_order->get_id(),
                'payment_token_id' => $token->get_id(),
            )
        );
    }

    private function complete_paid_renewal_order(WC_Order $renewal_order, string $transaction_id): void
    {
        try {
            $renewal_order->payment_complete($transaction_id);
        } catch (Throwable $throwable) {
            if (!$renewal_order->is_paid()) {
                if ('' !== $transaction_id && is_callable(array($renewal_order, 'update_meta_data'))) {
                    $renewal_order->update_meta_data('_transaction_id', $transaction_id);
                }

                $renewal_order->update_status(
                    'processing',
                    __('Recurring charge approved, but WooCommerce payment completion hooks failed.', 'woo-subzero')
                );
            }

            throw $throwable;
        }
    }

    private function handle_processing_throwable(WC_Order $renewal_order, Throwable $throwable): void
    {
        $context = array(
            'renewal_order_id' => $renewal_order->get_id(),
            'exception_class' => get_class($throwable),
            'reason' => $throwable->getMessage(),
        );

        $subscription = $this->resolve_subscription_from_renewal_order($renewal_order);
        if ($subscription instanceof WC_Order) {
            $context['subscription_id'] = $subscription->get_id();
        }

        $this->log_diagnostic(
            'error',
            __('Tokenized recurring payment processing failed.', 'woo-subzero'),
            $context
        );

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();

            if (is_object($logger) && is_callable(array($logger, 'error'))) {
                $logger->error(
                    sprintf('Tokenized recurring payment failed for renewal order %d: %s', $renewal_order->get_id(), $throwable->getMessage()),
                    array('source' => 'woo-subzero')
                );
            }
        }

        if (!$renewal_order->is_paid() && !$renewal_order->has_status(array('failed'))) {
            $this->mark_renewal_failed(
                $renewal_order,
                __('Tokenized recurring payment failed.', 'woo-subzero'),
                $context
            );
        }
    }

    private function mark_renewal_failed(WC_Order $renewal_order, string $message, array $context = array()): void
    {
        try {
            if (!$renewal_order->has_status(array('failed'))) {
                $renewal_order->update_status('failed', $message);
            }
        } catch (Throwable $throwable) {
            $this->safe_log_diagnostic(
                'warning',
                __('Renewal status update failed after tokenized payment failure.', 'woo-subzero'),
                array(
                    'renewal_order_id' => $renewal_order->get_id(),
                    'status_update_target' => 'failed',
                    'status_update_reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                ) + $context
            );

            $this->safe_log_to_woocommerce(
                'warning',
                sprintf('Failed to mark renewal order %d as failed: %s', $renewal_order->get_id(), $throwable->getMessage())
            );
        }
    }

    private function build_missing_token_context(WC_Order $subscription, WC_Order $renewal_order): array
    {
        $gateway_id = sanitize_key((string) $subscription->get_payment_method());
        $customer_id = (int) $subscription->get_customer_id();

        return array(
            'subscription_id' => $subscription->get_id(),
            'renewal_order_id' => $renewal_order->get_id(),
            'gateway_id' => $gateway_id,
            'renewal_payment_method' => sanitize_key((string) $renewal_order->get_payment_method()),
            'subscription_customer_id' => $customer_id,
            'stored_payment_token_id' => (int) $subscription->get_meta('_payment_token_id', true),
            'renewal_order_token_meta' => $renewal_order->get_meta('_payment_token_id', true),
            'subscription_parent_order_id' => $this->get_order_parent_id($subscription),
            'subscription_parent_order_meta_id' => (int) $subscription->get_meta('_wsz_parent_order_id', true),
            'renewal_order_parent_id' => $this->get_order_parent_id($renewal_order),
        ) + $this->get_parent_order_payment_context($subscription) + $this->get_customer_token_context($customer_id, $gateway_id);
    }

    private function get_parent_order_payment_context(WC_Order $subscription): array
    {
        $parent_order = $this->resolve_parent_order_for_context($subscription);

        if (!($parent_order instanceof WC_Order)) {
            return array('parent_order_resolved' => 'no');
        }

        $context = array(
            'parent_order_resolved' => 'yes',
            'parent_order_id' => $parent_order->get_id(),
            'parent_order_payment_method' => sanitize_key((string) $parent_order->get_payment_method()),
            'parent_order_token_meta' => $parent_order->get_meta('_payment_token_id', true),
        );

        if (is_callable(array($parent_order, 'get_payment_tokens'))) {
            $tokens = $parent_order->get_payment_tokens();
            $context += $this->summarize_order_tokens(is_array($tokens) ? $tokens : array(), 'parent_order');
        }

        if (
            class_exists('WSZ_PayNL_Token_Support')
            && is_callable(array('WSZ_PayNL_Token_Support', 'extract_recurring_id_meta_source_key'))
        ) {
            $recurring_source_key = WSZ_PayNL_Token_Support::extract_recurring_id_meta_source_key($parent_order);
            $context['parent_order_has_paynl_recurring_meta'] = '' !== $recurring_source_key ? 'yes' : 'no';

            if ('' !== $recurring_source_key) {
                $context['parent_order_paynl_recurring_meta_source_key'] = $recurring_source_key;
            }
        }

        return $context;
    }

    private function resolve_parent_order_for_context(WC_Order $subscription): ?WC_Order
    {
        $parent_order_id = (int) $subscription->get_meta('_wsz_parent_order_id', true);

        if ($parent_order_id <= 0 && is_callable(array($subscription, 'get_parent_id'))) {
            $parent_order_id = (int) $subscription->get_parent_id();
        }

        if ($parent_order_id <= 0 || !function_exists('wc_get_order')) {
            return null;
        }

        $parent_order = wc_get_order($parent_order_id);

        return $parent_order instanceof WC_Order ? $parent_order : null;
    }

    private function summarize_order_tokens(array $tokens, string $prefix): array
    {
        $token_ids = array();
        $token_classes = array();

        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $token_ids[] = max(0, (int) $token);
                continue;
            }

            if (!($token instanceof WC_Payment_Token)) {
                continue;
            }

            $token_ids[] = max(0, (int) $token->get_id());
            $token_classes[] = get_class($token);
        }

        return array(
            $prefix . '_payment_token_count' => count($tokens),
            $prefix . '_payment_token_ids' => array_values(array_filter(array_unique($token_ids))),
            $prefix . '_payment_token_classes' => array_values(array_unique($token_classes)),
        );
    }

    private function get_customer_token_context(int $customer_id, string $gateway_id): array
    {
        if (
            $customer_id <= 0
            || '' === $gateway_id
            || !class_exists('WC_Payment_Tokens')
            || !is_callable(array('WC_Payment_Tokens', 'get_customer_tokens'))
        ) {
            return array();
        }

        $tokens = WC_Payment_Tokens::get_customer_tokens($customer_id, $gateway_id);

        if (!is_array($tokens)) {
            return array('customer_gateway_tokens_result' => gettype($tokens));
        }

        $token_ids = array();
        $token_classes = array();

        foreach ($tokens as $token) {
            if (!($token instanceof WC_Payment_Token)) {
                continue;
            }

            $token_id = (int) $token->get_id();
            if ($token_id > 0) {
                $token_ids[] = $token_id;
            }

            $token_classes[] = get_class($token);
        }

        return array(
            'customer_gateway_token_count' => count($tokens),
            'customer_gateway_token_ids' => $token_ids,
            'customer_gateway_token_classes' => array_values(array_unique($token_classes)),
        );
    }

    private function get_order_parent_id(WC_Order $order): int
    {
        if (!is_callable(array($order, 'get_parent_id'))) {
            return 0;
        }

        return (int) $order->get_parent_id();
    }

    private function resolve_subscription_from_renewal_order(WC_Order $renewal_order)
    {
        $subscription_id = (int) $renewal_order->get_meta('_wsz_subscription_id', true);

        if ($subscription_id > 0) {
            $subscription = $this->subscription_manager->get_subscription($subscription_id);
            if ($subscription) {
                return $subscription;
            }
        }

        if (function_exists('wcs_get_subscriptions_for_renewal_order')) {
            $subscriptions = wcs_get_subscriptions_for_renewal_order($renewal_order);
            if (is_array($subscriptions) && !empty($subscriptions)) {
                $first = reset($subscriptions);
                if ($first instanceof WC_Order) {
                    return $first;
                }
            }
        }

        $parent_id = $renewal_order->get_parent_id();
        if ($parent_id > 0) {
            $parent = $this->subscription_manager->get_subscription($parent_id);
            if ($parent) {
                return $parent;
            }
        }

        return null;
    }

    private function charge_recurring_reference(
        string $recurring_id,
        float $amount,
        string $currency,
        WC_Order $renewal_order,
        WC_Order $subscription
    ): array {
        $preflight = apply_filters(
            'wsz_subs_recurring_charge_result',
            null,
            $recurring_id,
            $amount,
            $currency,
            $renewal_order,
            $subscription
        );

        if (is_array($preflight) && array_key_exists('paid', $preflight)) {
            return $preflight;
        }

        $charge_callback = apply_filters(
            'wsz_subs_recurring_charge_callback',
            null,
            $recurring_id,
            $amount,
            $currency,
            $renewal_order,
            $subscription
        );

        if (!is_callable($charge_callback)) {
            $this->log_diagnostic(
                'error',
                __('Recurring charge handler not configured.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'renewal_order_id' => $renewal_order->get_id(),
                )
            );

            return array(
                'paid' => false,
                'message' => __('Recurring charge handler not configured.', 'woo-subzero'),
            );
        }

        try {
            $result = call_user_func(
                $charge_callback,
                $recurring_id,
                $amount,
                $currency,
                $renewal_order,
                $subscription
            );

            if (is_array($result) && array_key_exists('paid', $result)) {
                return $result;
            }

            return array(
                'paid' => false,
                'message' => __('Recurring charge handler returned an invalid response.', 'woo-subzero'),
            );
        } catch (Throwable $throwable) {
            wc_get_logger()->error(
                sprintf('Recurring renewal error: %s', $throwable->getMessage()),
                array('source' => 'woo-subzero')
            );

            return array(
                'paid' => false,
                'message' => $throwable->getMessage(),
            );
        }
    }

    private function log_diagnostic(string $level, string $message, array $context = array()): void
    {
        if (!class_exists('WSZ_Admin_Settings')) {
            return;
        }

        $context['source'] = 'woo-subzero';
        WSZ_Admin_Settings::log_diagnostic($level, $message, $context);
    }

    private function safe_log_diagnostic(string $level, string $message, array $context = array()): void
    {
        try {
            $this->log_diagnostic($level, $message, $context);
        } catch (Throwable $throwable) {
            $this->safe_log_to_woocommerce(
                'warning',
                sprintf('Woo Subs-Zero diagnostic logging failed: %s', $throwable->getMessage())
            );
        }
    }

    private function safe_log_to_woocommerce(string $level, string $message): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        try {
            $logger = wc_get_logger();
            $level = sanitize_key($level);

            if (is_object($logger) && is_callable(array($logger, $level))) {
                $logger->{$level}($message, array('source' => 'woo-subzero'));
            }
        } catch (Throwable $throwable) {
            return;
        }
    }
}
