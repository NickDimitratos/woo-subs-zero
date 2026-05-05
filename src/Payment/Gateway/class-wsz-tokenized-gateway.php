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
            $renewal_order->update_status('failed', __('Could not resolve source subscription for renewal.', 'woo-subzero'));
            return;
        }

        $token = $this->payment_handler->get_payment_token_for_subscription($subscription);

        if (!($token instanceof WC_Payment_Token)) {
            $this->log_diagnostic(
                'error',
                __('Missing or invalid payment token for renewal.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'renewal_order_id' => $renewal_order->get_id(),
                )
            );
            $renewal_order->update_status('failed', __('Missing or invalid payment token for renewal.', 'woo-subzero'));
            return;
        }

        $recurring_id = (string) $token->get_token();
        if ('' === $recurring_id) {
            $this->log_diagnostic(
                'error',
                __('Recurring reference is empty for payment token.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'renewal_order_id' => $renewal_order->get_id(),
                    'payment_token_id' => $token->get_id(),
                )
            );
            $renewal_order->update_status('failed', __('Recurring reference is empty for payment token.', 'woo-subzero'));
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

        $renewal_order->update_status('failed', $message);
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
            $renewal_order->update_status('failed', __('Tokenized recurring payment failed.', 'woo-subzero'));
        }
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
}
