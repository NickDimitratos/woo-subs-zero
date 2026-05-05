<?php

defined('ABSPATH') || exit;

class WSZ_Payment_Handler
{
    private WSZ_Subscription_Manager $subscription_manager;

    private ?WSZ_Test_Card_Gateway_Integration $test_card_gateway = null;

    private ?WSZ_PayNL_Gateway_Integration $paynl_gateway = null;

    private ?WSZ_Tokenized_Gateway $tokenized_gateway = null;

    public function __construct(WSZ_Subscription_Manager $subscription_manager)
    {
        $this->subscription_manager = $subscription_manager;
    }

    public function init(): void
    {
        $this->test_card_gateway = new WSZ_Test_Card_Gateway_Integration();
        $this->test_card_gateway->init();

        $this->paynl_gateway = new WSZ_PayNL_Gateway_Integration($this->subscription_manager);
        $this->paynl_gateway->init();

        $this->tokenized_gateway = new WSZ_Tokenized_Gateway($this->subscription_manager, $this);
        $this->tokenized_gateway->init();

        add_action('woocommerce_subscription_failing_payment_method_updated', array($this, 'handle_failing_payment_method_update'), 10, 3);
        add_action('woocommerce_subscriptions_changed_failing_payment_method', array($this, 'handle_failing_payment_method_update'), 10, 3);
        add_action('woocommerce_subscription_payment_method_updated', array($this, 'handle_subscription_payment_method_updated'), 10, 3);
        add_action('woocommerce_subscription_renewal_payment_complete', array($this, 'handle_manual_renewal_payment_complete'), 10, 2);

        // Capture token/gateway metadata after checkout payment settles so renewals can charge automatically.
        add_action('woocommerce_payment_complete', array($this, 'sync_subscriptions_from_paid_parent_order'), 20, 1);
        add_action('woocommerce_order_status_processing', array($this, 'sync_subscriptions_from_paid_parent_order'), 20, 2);
        add_action('woocommerce_order_status_completed', array($this, 'sync_subscriptions_from_paid_parent_order'), 20, 2);
        add_action('wsz_subs_subscription_activated', array($this, 'sync_subscription_from_parent_order'), 20, 1);
        add_filter('woocommerce_subscription_payment_meta', array($this, 'register_subscription_payment_meta'), 10, 2);
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

        $gateway_registered = $this->is_gateway_registered($gateway_id);
        $has_reusable_payment_context = false;
        $payment_context_diagnostics = array();

        if (!$gateway_registered && '' !== $gateway_id) {
            $payment_context_diagnostics = $this->inspect_reusable_payment_context($subscription, $gateway_id);
            $has_reusable_payment_context = !empty($payment_context_diagnostics['has_reusable_payment_context']);
        }

        if ('' === $gateway_id || (!$gateway_registered && !$has_reusable_payment_context)) {
            $subscription->update_meta_data('_wsz_manual_renewal_fallback_reason', 'gateway_unavailable');
            $subscription->save();
            $this->subscription_manager->set_manual_renewal($subscription, true);
            $this->log_diagnostic(
                'warning',
                __('Payment gateway unavailable. Subscription switched to manual renewal.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'renewal_order_id' => $renewal_order->get_id(),
                    'gateway_id' => $gateway_id,
                ) + $this->build_gateway_unavailable_diagnostics(
                    $subscription,
                    $renewal_order,
                    $gateway_id,
                    $gateway_registered,
                    $payment_context_diagnostics
                )
            );
            $renewal_order->update_status(
                'pending',
                __('Payment gateway unavailable. Subscription switched to manual renewal.', 'woo-subzero')
            );

            do_action('wsz_subs_gateway_unavailable_manual_fallback', $subscription, $renewal_order, $gateway_id);
            return;
        }

        if ($this->process_wsz_tokenized_gateway_payment($gateway_id, $amount, $renewal_order)) {
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
            $token_id = $this->resolve_fallback_token_id($subscription);

            if ($token_id > 0) {
                $this->subscription_manager->set_payment_token_id($subscription, $token_id);
            }
        }

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
            $this->log_diagnostic(
                'warning',
                __('Payment token ownership mismatch for subscription.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'payment_token_id' => $token->get_id(),
                    'customer_id' => $customer_id,
                )
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
        $gateway_updated = false;

        if ('' !== $gateway_id && ($this->is_gateway_registered($gateway_id) || $token_id > 0)) {
            $subscription->set_payment_method($gateway_id);
            $subscription->save();
            $gateway_updated = true;
        } elseif ('' !== $gateway_id && function_exists('wc_get_logger')) {
            wc_get_logger()->warning(
                sprintf('Ignoring unknown WooCommerce gateway id "%s" for subscription %d.', $gateway_id, $subscription->get_id()),
                array('source' => 'woo-subzero')
            );
            $this->log_diagnostic(
                'warning',
                __('Ignoring unknown WooCommerce gateway id for subscription.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'gateway_id' => $gateway_id,
                )
            );
        }

        if ($this->should_auto_restore_automatic_renewals() && ($token_id > 0 || $gateway_updated)) {
            $this->subscription_manager->set_manual_renewal($subscription, false);
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
        $gateway_id = '';

        if (is_string($new_payment_method)) {
            $gateway_id = sanitize_key($new_payment_method);
        } elseif ($new_payment_method instanceof WC_Order) {
            $gateway_id = sanitize_key((string) $subscription->get_payment_method());
            if ($token_id <= 0) {
                $order_token = $new_payment_method->get_meta('_payment_token_id', true);
                $token_id = is_numeric($order_token) ? (int) $order_token : 0;
            }
        }

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
     * @param mixed $subscription_or_id
     * @param mixed $new_payment_method
     */
    public function handle_subscription_payment_method_updated($subscription_or_id, $new_payment_method = '', $old_payment_method = ''): void
    {
        $subscription = $subscription_or_id instanceof WC_Order
            ? $subscription_or_id
            : $this->subscription_manager->get_subscription((int) $subscription_or_id);

        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $gateway_id = is_string($new_payment_method) ? sanitize_key($new_payment_method) : '';

        if ('' === $gateway_id) {
            $gateway_id = sanitize_key((string) $subscription->get_payment_method());
        }

        $this->update_subscription_payment_context($subscription, 0, $gateway_id);
    }

    /**
     * @param mixed $subscription
     * @param mixed $renewal_order
     */
    public function handle_manual_renewal_payment_complete($subscription, $renewal_order): void
    {
        if (!($subscription instanceof WC_Order) || !($renewal_order instanceof WC_Order)) {
            return;
        }

        if (!$this->should_auto_restore_automatic_renewals()) {
            return;
        }

        if (!$this->subscription_manager->is_manual_renewal($subscription)) {
            return;
        }

        $gateway_id = sanitize_key((string) $subscription->get_payment_method());

        if ('' === $gateway_id) {
            $gateway_id = sanitize_key((string) $renewal_order->get_payment_method());
        }

        if ('' === $gateway_id || !$this->is_gateway_registered($gateway_id)) {
            return;
        }

        $token_id = 0;
        $renewal_order_token = $renewal_order->get_meta('_payment_token_id', true);
        if (is_numeric($renewal_order_token)) {
            $token_id = (int) $renewal_order_token;
        }

        $this->update_subscription_payment_context($subscription, $token_id, $gateway_id);
    }

    /**
     * @param WC_Order|int $order_or_id
     * @param mixed        $order
     */
    public function sync_subscriptions_from_paid_parent_order($order_or_id, $order = null): void
    {
        if ($order_or_id instanceof WC_Order) {
            $order = $order_or_id;
        }

        if (!($order instanceof WC_Order) && function_exists('wc_get_order')) {
            $order = wc_get_order((int) $order_or_id);
        }

        if (!($order instanceof WC_Order)) {
            return;
        }

        $order_type = is_callable(array($order, 'get_type'))
            ? sanitize_key((string) $order->get_type())
            : '';

        if ('' !== $order_type && 'shop_order' !== $order_type) {
            return;
        }

        $subscription_ids = $order->get_meta('_wsz_subscription_ids', true);

        if (!is_array($subscription_ids) || empty($subscription_ids)) {
            return;
        }

        $gateway_id = sanitize_key((string) $order->get_payment_method());
        $token_id = $this->resolve_token_id_from_order($order);

        foreach ($subscription_ids as $subscription_id) {
            $subscription = $this->subscription_manager->get_subscription((int) $subscription_id);

            if (!($subscription instanceof WC_Order)) {
                continue;
            }

            $copied = $this->subscription_manager->copy_payment_context_meta($order, $subscription);

            if ($token_id > 0 || ('' !== $gateway_id && $this->is_gateway_registered($gateway_id))) {
                $this->update_subscription_payment_context($subscription, $token_id, $gateway_id);
                continue;
            }

            if ($copied) {
                $subscription->save();
            }
        }
    }

    /**
     * @param mixed $subscription
     */
    public function sync_subscription_from_parent_order($subscription): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $parent_order = $this->resolve_parent_order($subscription);

        if (!($parent_order instanceof WC_Order)) {
            return;
        }

        $this->subscription_manager->copy_payment_context_meta($parent_order, $subscription);

        $gateway_id = sanitize_key((string) $subscription->get_payment_method());
        $parent_gateway_id = sanitize_key((string) $parent_order->get_payment_method());

        if ('' === $gateway_id) {
            $gateway_id = $parent_gateway_id;
        }

        $token_id = $this->subscription_manager->get_payment_token_id($subscription);

        if ($token_id <= 0) {
            $token_id = $this->resolve_token_id_from_order($parent_order);
        }

        if ($token_id <= 0 && '' !== $gateway_id) {
            $token_id = $this->resolve_fallback_token_id_for_gateway($subscription, $gateway_id);
        }

        if ($token_id > 0 || ('' !== $gateway_id && $this->is_gateway_registered($gateway_id))) {
            $this->update_subscription_payment_context($subscription, $token_id, $gateway_id);
            return;
        }

        $subscription->save();
    }

    /**
     * @param mixed $payment_meta
     * @param mixed $subscription
     */
    public function register_subscription_payment_meta($payment_meta, $subscription): array
    {
        if (!is_array($payment_meta) || !($subscription instanceof WC_Order)) {
            return is_array($payment_meta) ? $payment_meta : array();
        }

        $gateway_id = sanitize_key((string) $subscription->get_payment_method());

        if ('' === $gateway_id) {
            return $payment_meta;
        }

        if (!isset($payment_meta[$gateway_id]) || !is_array($payment_meta[$gateway_id])) {
            $payment_meta[$gateway_id] = array();
        }

        if (!isset($payment_meta[$gateway_id]['post_meta']) || !is_array($payment_meta[$gateway_id]['post_meta'])) {
            $payment_meta[$gateway_id]['post_meta'] = array();
        }

        $payment_meta[$gateway_id]['post_meta']['_payment_token_id'] = array(
            'value' => $this->subscription_manager->get_payment_token_id($subscription),
            'label' => __('WooCommerce payment token ID', 'woo-subzero'),
        );

        return $payment_meta;
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

    private function inspect_reusable_payment_context(WC_Order $subscription, string $gateway_id): array
    {
        $customer_id = (int) $subscription->get_customer_id();
        $stored_token_id_before = $this->subscription_manager->get_payment_token_id($subscription);
        $token = $this->get_payment_token_for_subscription($subscription);
        $stored_token_id_after = $this->subscription_manager->get_payment_token_id($subscription);

        $diagnostics = array(
            'has_reusable_payment_context' => $token instanceof WC_Payment_Token,
            'subscription_customer_id' => $customer_id,
            'stored_payment_token_id_before_resolution' => $stored_token_id_before,
            'stored_payment_token_id_after_resolution' => $stored_token_id_after,
        );

        if ($token instanceof WC_Payment_Token) {
            $token_user_id = (int) $token->get_user_id();

            $diagnostics['resolved_payment_token_id'] = (int) $token->get_id();
            $diagnostics['payment_token_class'] = get_class($token);
            $diagnostics['payment_token_user_id'] = $token_user_id;
            $diagnostics['payment_token_owner_matches_customer'] = $customer_id <= 0 || $token_user_id === $customer_id;

            return $diagnostics;
        }

        if (class_exists('WC_Payment_Tokens') && $stored_token_id_after > 0 && is_callable(array('WC_Payment_Tokens', 'get'))) {
            $raw_token = WC_Payment_Tokens::get($stored_token_id_after);
            $diagnostics['payment_token_lookup_result'] = is_object($raw_token) ? get_class($raw_token) : gettype($raw_token);

            if ($raw_token instanceof WC_Payment_Token) {
                $diagnostics['payment_token_user_id'] = (int) $raw_token->get_user_id();
                $diagnostics['payment_token_owner_matches_customer'] = $customer_id <= 0 || (int) $raw_token->get_user_id() === $customer_id;
            }
        }

        $customer_tokens = $this->get_customer_token_diagnostics($customer_id, $gateway_id);
        if (!empty($customer_tokens)) {
            $diagnostics += $customer_tokens;
        }

        return $diagnostics;
    }

    private function get_customer_token_diagnostics(int $customer_id, string $gateway_id): array
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
        $default_token_id = 0;

        foreach ($tokens as $token) {
            if (!($token instanceof WC_Payment_Token)) {
                continue;
            }

            $token_id = (int) $token->get_id();
            if ($token_id > 0) {
                $token_ids[] = $token_id;
            }

            $token_classes[] = get_class($token);

            if (0 === $default_token_id && is_callable(array($token, 'is_default')) && $token->is_default()) {
                $default_token_id = $token_id;
            }
        }

        return array(
            'customer_gateway_token_count' => count($tokens),
            'customer_gateway_token_ids' => $token_ids,
            'customer_gateway_token_classes' => array_values(array_unique($token_classes)),
            'customer_gateway_default_token_id' => $default_token_id,
        );
    }

    private function build_gateway_unavailable_diagnostics(
        WC_Order $subscription,
        WC_Order $renewal_order,
        string $gateway_id,
        bool $gateway_registered,
        array $payment_context_diagnostics
    ): array {
        return array(
            'gateway_registered' => $gateway_registered,
            'scheduled_payment_hook_has_listeners' => $this->has_gateway_scheduled_payment_listeners($gateway_id),
            'subscription_payment_method' => sanitize_key((string) $subscription->get_payment_method()),
            'subscription_payment_method_title' => (string) $subscription->get_payment_method_title(),
            'renewal_payment_method' => sanitize_key((string) $renewal_order->get_payment_method()),
            'renewal_payment_method_title' => (string) $renewal_order->get_payment_method_title(),
            'subscription_status' => (string) $subscription->get_status(),
            'renewal_order_status' => (string) $renewal_order->get_status(),
            'subscription_parent_order_id' => $this->get_order_parent_id($subscription),
            'subscription_parent_order_meta_id' => (int) $subscription->get_meta('_wsz_parent_order_id', true),
            'renewal_order_parent_id' => $this->get_order_parent_id($renewal_order),
            'renewal_order_token_meta' => $renewal_order->get_meta('_payment_token_id', true),
        ) + $payment_context_diagnostics + $this->get_gateway_runtime_diagnostics($gateway_id);
    }

    private function get_gateway_runtime_diagnostics(string $gateway_id): array
    {
        $registered_gateways = $this->get_registered_gateway_map();
        $available_gateways = $this->get_available_gateway_map();
        $target_gateway = $registered_gateways[$gateway_id] ?? $available_gateways[$gateway_id] ?? null;

        $diagnostics = array(
            'registered_gateway_ids' => array_values(array_map('strval', array_keys($registered_gateways))),
            'available_gateway_ids' => array_values(array_map('strval', array_keys($available_gateways))),
        );

        if (is_object($target_gateway)) {
            $diagnostics['target_gateway_class'] = get_class($target_gateway);

            if (property_exists($target_gateway, 'enabled')) {
                $diagnostics['target_gateway_enabled'] = (string) $target_gateway->enabled;
            }

            foreach (array('subscriptions', 'tokenization', 'subscription_payment_method_change') as $support) {
                if (is_callable(array($target_gateway, 'supports'))) {
                    $diagnostics['target_gateway_supports_' . $support] = (bool) $target_gateway->supports($support);
                }
            }
        }

        return $diagnostics;
    }

    private function get_available_gateway_map(): array
    {
        if (!function_exists('WC')) {
            return array();
        }

        $wc = WC();

        if (!$wc || !isset($wc->payment_gateways)) {
            return array();
        }

        $gateways = $wc->payment_gateways();

        if (!is_object($gateways) || !is_callable(array($gateways, 'get_available_payment_gateways'))) {
            return array();
        }

        $available = $gateways->get_available_payment_gateways();

        return is_array($available) ? $available : array();
    }

    private function has_gateway_scheduled_payment_listeners(string $gateway_id): bool
    {
        if ('' === $gateway_id || !function_exists('has_action')) {
            return false;
        }

        return false !== has_action("woocommerce_scheduled_subscription_payment_{$gateway_id}");
    }

    private function process_wsz_tokenized_gateway_payment(string $gateway_id, float $amount, WC_Order $renewal_order): bool
    {
        $gateway_id = sanitize_key($gateway_id);

        if ('' === $gateway_id || !$this->is_wsz_tokenized_gateway_id($gateway_id)) {
            return false;
        }

        if (!($this->tokenized_gateway instanceof WSZ_Tokenized_Gateway)) {
            $this->tokenized_gateway = new WSZ_Tokenized_Gateway($this->subscription_manager, $this);
        }

        $this->tokenized_gateway->process_scheduled_payment($amount, $renewal_order);

        return true;
    }

    private function is_wsz_tokenized_gateway_id(string $gateway_id): bool
    {
        if (!function_exists('apply_filters')) {
            return false;
        }

        $gateway_ids = apply_filters('wsz_subs_tokenized_gateway_ids', array());

        if (!is_array($gateway_ids)) {
            return false;
        }

        foreach ($gateway_ids as $registered_gateway_id) {
            if ($gateway_id === sanitize_key((string) $registered_gateway_id)) {
                return true;
            }
        }

        return false;
    }

    private function get_order_parent_id(WC_Order $order): int
    {
        if (!is_callable(array($order, 'get_parent_id'))) {
            return 0;
        }

        return (int) $order->get_parent_id();
    }

    private function resolve_fallback_token_id(WC_Order $subscription): int
    {
        $gateway_id = sanitize_key((string) $subscription->get_payment_method());

        return $this->resolve_fallback_token_id_for_gateway($subscription, $gateway_id);
    }

    private function resolve_fallback_token_id_for_gateway(WC_Order $subscription, string $gateway_id): int
    {
        if (!class_exists('WC_Payment_Tokens')) {
            return 0;
        }

        $customer_id = (int) $subscription->get_customer_id();
        $gateway_id = sanitize_key($gateway_id);

        if ($customer_id <= 0 || '' === $gateway_id || !is_callable(array('WC_Payment_Tokens', 'get_customer_tokens'))) {
            return 0;
        }

        $tokens = WC_Payment_Tokens::get_customer_tokens($customer_id, $gateway_id);

        if (!is_array($tokens) || empty($tokens)) {
            return 0;
        }

        $fallback_token_id = 0;

        foreach ($tokens as $token) {
            if (!($token instanceof WC_Payment_Token)) {
                continue;
            }

            $token_id = (int) $token->get_id();

            if ($token_id <= 0) {
                continue;
            }

            if (0 === $fallback_token_id) {
                $fallback_token_id = $token_id;
            }

            if (is_callable(array($token, 'is_default')) && $token->is_default()) {
                return $token_id;
            }
        }

        return $fallback_token_id;
    }

    private function resolve_parent_order(WC_Order $subscription): ?WC_Order
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

    private function resolve_token_id_from_order(WC_Order $order): int
    {
        $raw_token = $order->get_meta('_payment_token_id', true);

        if (is_numeric($raw_token)) {
            return max(0, (int) $raw_token);
        }

        if (is_callable(array($order, 'get_payment_tokens'))) {
            $tokens = $order->get_payment_tokens();

            if (is_array($tokens) && !empty($tokens)) {
                $first = reset($tokens);

                if (is_numeric($first)) {
                    return max(0, (int) $first);
                }

                if ($first instanceof WC_Payment_Token) {
                    return max(0, (int) $first->get_id());
                }
            }
        }

        return 0;
    }

    private function should_auto_restore_automatic_renewals(): bool
    {
        if (!function_exists('get_option')) {
            return true;
        }

        $options = get_option('wsz_subs_options', array());
        if (!is_array($options)) {
            return true;
        }

        $value = isset($options['auto_restore_automatic_renewals'])
            ? sanitize_key((string) $options['auto_restore_automatic_renewals'])
            : 'yes';

        return 'yes' === $value;
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
