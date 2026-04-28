<?php

defined('ABSPATH') || exit;

class WSZ_Payment_Handler
{
    private WSZ_Subscription_Manager $subscription_manager;

    private ?WSZ_Test_Card_Gateway_Integration $test_card_gateway = null;

    private ?WSZ_Paynl_Gateway $paynl_gateway = null;

    public function __construct(WSZ_Subscription_Manager $subscription_manager)
    {
        $this->subscription_manager = $subscription_manager;
    }

    public function init(): void
    {
        $this->test_card_gateway = new WSZ_Test_Card_Gateway_Integration();
        $this->test_card_gateway->init();

        $this->paynl_gateway = new WSZ_Paynl_Gateway($this->subscription_manager, $this);
        $this->paynl_gateway->init();

        add_action('woocommerce_subscription_failing_payment_method_updated', array($this, 'handle_failing_payment_method_update'), 10, 3);
        add_action('woocommerce_subscriptions_changed_failing_payment_method', array($this, 'handle_failing_payment_method_update'), 10, 3);
    }

    /**
     * @param WC_Order $subscription
     * @param WC_Order $renewal_order
     */
    public function dispatch_scheduled_payment($subscription, $renewal_order, float $amount): void
    {
        if (!($subscription instanceof WC_Order) || !($renewal_order instanceof WC_Order)) {
            return;
        }

        $gateway_id = sanitize_key((string) $subscription->get_payment_method());

        if ($this->subscription_manager->is_manual_renewal($subscription)) {
            $renewal_order->update_status(
                'pending',
                __('Manual renewal required for this subscription.', 'woo-subzero')
            );

            do_action('wsz_subs_manual_renewal_required', $subscription, $renewal_order);
            return;
        }

        // Always allow WSZ test card renewals to run in QA mode, even if gateway availability checks are stale.
        if (
            class_exists('WSZ_Test_Card_Gateway_Integration')
            && WSZ_Test_Card_Gateway_Integration::GATEWAY_ID === $gateway_id
        ) {
            do_action('woocommerce_scheduled_subscription_payment', $amount, $renewal_order);
            do_action('woocommerce_scheduled_subscription_payment_' . WSZ_Test_Card_Gateway_Integration::GATEWAY_ID, $amount, $renewal_order);
            return;
        }

        if ('' === $gateway_id || !$this->is_gateway_available($gateway_id)) {
            $this->subscription_manager->set_manual_renewal($subscription, true);
            $renewal_order->update_status(
                'pending',
                __('Payment gateway unavailable. Subscription switched to manual renewal.', 'woo-subzero')
            );

            do_action('wsz_subs_gateway_unavailable_manual_fallback', $subscription, $renewal_order, $gateway_id);
            return;
        }

        do_action('woocommerce_scheduled_subscription_payment', $amount, $renewal_order);
        do_action("woocommerce_scheduled_subscription_payment_{$gateway_id}", $amount, $renewal_order);
    }

    public function is_gateway_available(string $gateway_id): bool
    {
        $gateway_id = sanitize_key($gateway_id);

        if ('' === $gateway_id || !function_exists('WC')) {
            return false;
        }

        $registered_gateways = $this->get_registered_gateway_map();

        if (isset($registered_gateways[$gateway_id]) && is_object($registered_gateways[$gateway_id])) {
            $gateway = $registered_gateways[$gateway_id];

            if (property_exists($gateway, 'enabled') && 'yes' !== (string) $gateway->enabled) {
                return false;
            }

            return true;
        }

        $wc = WC();
        if (!$wc || !isset($wc->payment_gateways)) {
            return false;
        }

        $gateways = $wc->payment_gateways();
        if (!is_object($gateways)) {
            return false;
        }

        $available_gateways = $gateways->get_available_payment_gateways();

        return isset($available_gateways[$gateway_id]);
    }

    public function is_gateway_registered(string $gateway_id): bool
    {
        $gateway_id = sanitize_key($gateway_id);

        if ('' === $gateway_id) {
            return false;
        }

        $registered_gateways = $this->get_registered_gateway_map();

        if (isset($registered_gateways[$gateway_id])) {
            return true;
        }

        return $this->is_gateway_available($gateway_id);
    }

    /**
     * @param WC_Order $subscription
     */
    public function get_payment_token_for_subscription($subscription): ?WC_Payment_Token
    {
        if (!($subscription instanceof WC_Order) || !class_exists('WC_Payment_Tokens')) {
            return null;
        }

        $token_id = $this->subscription_manager->get_payment_token_id($subscription);

        if ($token_id <= 0) {
            return null;
        }

        $token = WC_Payment_Tokens::get($token_id);
        if (!($token instanceof WC_Payment_Token)) {
            return null;
        }

        $customer_id = (int) $subscription->get_customer_id();

        if ($customer_id > 0 && (int) $token->get_user_id() !== $customer_id) {
            wc_get_logger()->warning(
                sprintf('Payment token ownership mismatch for subscription %d.', $subscription->get_id()),
                array('source' => 'woo-subzero')
            );

            return null;
        }

        return $token;
    }

    /**
     * @param WC_Order $subscription
     */
    public function update_subscription_payment_context($subscription, int $token_id, string $gateway_id = ''): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        if ($token_id > 0) {
            $this->subscription_manager->set_payment_token_id($subscription, $token_id);
        }

        $gateway_id = sanitize_key($gateway_id);

        if ('' !== $gateway_id && $this->is_gateway_registered($gateway_id)) {
            $subscription->set_payment_method($gateway_id);
            $subscription->save();
        } elseif ('' !== $gateway_id && function_exists('wc_get_logger')) {
            wc_get_logger()->warning(
                sprintf('Ignoring unknown WooCommerce gateway id "%s" for subscription %d.', $gateway_id, $subscription->get_id()),
                array('source' => 'woo-subzero')
            );
        }

        do_action('wsz_subs_payment_context_updated', $subscription, $token_id, $gateway_id);
    }

    /**
     * Hook signatures can vary across gateways/subscription layers, so this parser is intentionally defensive.
     *
     * @param mixed $subscription_or_id
     * @param mixed $new_payment_method
     * @param mixed $payment_meta
     */
    public function handle_failing_payment_method_update($subscription_or_id, $new_payment_method = '', $payment_meta = array()): void
    {
        $subscription = $subscription_or_id instanceof WC_Order
            ? $subscription_or_id
            : $this->subscription_manager->get_subscription((int) $subscription_or_id);

        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $token_id = $this->extract_token_id($payment_meta);
        $gateway_id = is_string($new_payment_method) ? sanitize_key($new_payment_method) : '';

        $this->update_subscription_payment_context($subscription, $token_id, $gateway_id);

        do_action(
            'wsz_subs_failing_payment_method_updated',
            $subscription,
            $gateway_id,
            $token_id,
            $payment_meta
        );
    }

    /**
     * @param mixed $payment_meta
     */
    private function extract_token_id($payment_meta): int
    {
        if (is_numeric($payment_meta)) {
            return (int) $payment_meta;
        }

        if (!is_array($payment_meta)) {
            return 0;
        }

        foreach (array('token_id', 'payment_token_id', 'wc_token_id') as $key) {
            if (isset($payment_meta[$key]) && is_numeric($payment_meta[$key])) {
                return (int) $payment_meta[$key];
            }
        }

        if (isset($payment_meta['post_meta']) && is_array($payment_meta['post_meta'])) {
            foreach ($payment_meta['post_meta'] as $meta_row) {
                if (!is_array($meta_row) || !isset($meta_row['meta_key'], $meta_row['meta_value'])) {
                    continue;
                }

                if ('_payment_token_id' === $meta_row['meta_key'] && is_numeric($meta_row['meta_value'])) {
                    return (int) $meta_row['meta_value'];
                }
            }
        }

        return 0;
    }

    private function get_registered_gateway_map(): array
    {
        if (!function_exists('WC')) {
            return array();
        }

        $wc = WC();

        if (!$wc || !isset($wc->payment_gateways)) {
            return array();
        }

        $gateways = $wc->payment_gateways();

        if (!is_object($gateways) || !is_callable(array($gateways, 'payment_gateways'))) {
            return array();
        }

        $registered = $gateways->payment_gateways();

        return is_array($registered) ? $registered : array();
    }
}
