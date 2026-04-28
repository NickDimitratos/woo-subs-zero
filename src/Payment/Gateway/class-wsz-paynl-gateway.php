<?php

defined('ABSPATH') || exit;

class WSZ_Paynl_Gateway
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
        add_action('woocommerce_scheduled_subscription_payment_paynl', array($this, 'process_scheduled_payment'), 10, 2);

        add_filter(
            'wsz_subs_gateway_contract_flags_paynl',
            static function (array $supports): array {
                return array_values(array_unique(array_merge($supports, self::required_supports_flags())));
            }
        );
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

    /**
     * @param mixed $amount
     * @param mixed $renewal_order
     */
    public function process_scheduled_payment($amount, $renewal_order): void
    {
        if (!($renewal_order instanceof WC_Order)) {
            return;
        }

        $subscription = $this->resolve_subscription_from_renewal_order($renewal_order);

        if (!($subscription instanceof WC_Order)) {
            $renewal_order->update_status('failed', __('Could not resolve source subscription for Pay.nl renewal.', 'woo-subzero'));
            return;
        }

        $token = $this->payment_handler->get_payment_token_for_subscription($subscription);

        if (!($token instanceof WC_Payment_Token)) {
            $renewal_order->update_status('failed', __('Missing or invalid payment token for Pay.nl renewal.', 'woo-subzero'));
            return;
        }

        $recurring_id = (string) $token->get_token();
        if ('' === $recurring_id) {
            $renewal_order->update_status('failed', __('Recurring reference is empty for Pay.nl token.', 'woo-subzero'));
            return;
        }

        $currency = $renewal_order->get_currency() ?: get_woocommerce_currency();
        $charge_result = $this->charge_recurring_reference($recurring_id, (float) $amount, $currency, $renewal_order, $subscription);

        if (!empty($charge_result['paid'])) {
            $transaction_id = isset($charge_result['transaction_id']) ? (string) $charge_result['transaction_id'] : '';

            if (!$renewal_order->is_paid()) {
                $renewal_order->payment_complete($transaction_id);
            }

            return;
        }

        $message = !empty($charge_result['message'])
            ? sanitize_text_field((string) $charge_result['message'])
            : __('Pay.nl recurring charge failed.', 'woo-subzero');

        $renewal_order->update_status('failed', $message);
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
            'wsz_subs_paynl_charge_result',
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

        if (!class_exists('Paynl\\Transaction')) {
            return array(
                'paid' => false,
                'message' => __('Pay.nl SDK not installed.', 'woo-subzero'),
            );
        }

        try {
            $response = Paynl\Transaction::byRecurringId(
                array(
                    'recurringId' => $recurring_id,
                    'amount' => (int) round($amount * 100),
                    'currency' => strtoupper($currency),
                )
            );

            $is_paid = is_object($response) && method_exists($response, 'isPaid')
                ? (bool) $response->isPaid()
                : false;

            $transaction_id = is_object($response) && method_exists($response, 'getTransactionId')
                ? (string) $response->getTransactionId()
                : '';

            return array(
                'paid' => $is_paid,
                'transaction_id' => $transaction_id,
                'message' => $is_paid
                    ? __('Pay.nl recurring charge completed.', 'woo-subzero')
                    : __('Pay.nl recurring charge not paid.', 'woo-subzero'),
            );
        } catch (Throwable $throwable) {
            wc_get_logger()->error(
                sprintf('Pay.nl renewal error: %s', $throwable->getMessage()),
                array('source' => 'woo-subzero')
            );

            return array(
                'paid' => false,
                'message' => $throwable->getMessage(),
            );
        }
    }
}
