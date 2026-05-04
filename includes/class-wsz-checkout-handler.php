<?php

defined('ABSPATH') || exit;

class WSZ_Checkout_Handler
{
    private WSZ_Subscription_Manager $subscription_manager;

    public function __construct(WSZ_Subscription_Manager $subscription_manager)
    {
        $this->subscription_manager = $subscription_manager;
    }

    public function init(): void
    {
        add_filter('woocommerce_checkout_registration_required', array($this, 'require_registration_for_subscription_checkout'), 20);
        add_filter('woocommerce_checkout_registration_enabled', array($this, 'enable_registration_for_subscription_checkout'), 20);
        add_filter('woocommerce_create_account_default_checked', array($this, 'force_create_account_checked_for_subscription_checkout'), 20);
        add_filter('woocommerce_checkout_posted_data', array($this, 'force_create_account_in_posted_data'), 20);
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_subscription_account_checkout'), 20, 2);

        add_action('woocommerce_new_order', array($this, 'maybe_create_subscriptions_from_new_order'), 20, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'maybe_create_subscriptions_from_order'), 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'maybe_create_subscriptions_from_order'), 20, 2);

        // Safety-net hooks: if checkout processing missed subscription creation, recover on key statuses.
        add_action('woocommerce_order_status_on-hold', array($this, 'maybe_create_subscriptions_for_paid_status'), 20, 2);
        add_action('woocommerce_order_status_processing', array($this, 'maybe_create_subscriptions_for_paid_status'), 20, 2);
        add_action('woocommerce_order_status_completed', array($this, 'maybe_create_subscriptions_for_paid_status'), 20, 2);
    }

    /**
     * @param mixed $required
     */
    public function require_registration_for_subscription_checkout($required): bool
    {
        return $this->cart_contains_subscription_items() ? true : (bool) $required;
    }

    /**
     * @param mixed $enabled
     */
    public function enable_registration_for_subscription_checkout($enabled): bool
    {
        return $this->cart_contains_subscription_items() ? true : (bool) $enabled;
    }

    /**
     * @param mixed $checked
     */
    public function force_create_account_checked_for_subscription_checkout($checked): bool
    {
        return $this->cart_contains_subscription_items() ? true : (bool) $checked;
    }

    public function force_create_account_in_posted_data(array $posted_data): array
    {
        if ($this->cart_contains_subscription_items() && !$this->is_current_user_logged_in()) {
            $posted_data['createaccount'] = 1;
        }

        return $posted_data;
    }

    /**
     * @param mixed $errors
     */
    public function validate_subscription_account_checkout(array $posted_data, $errors): void
    {
        if (!$this->cart_contains_subscription_items() || $this->is_current_user_logged_in()) {
            return;
        }

        if (!empty($posted_data['createaccount'])) {
            return;
        }

        if (is_object($errors) && is_callable(array($errors, 'add'))) {
            $errors->add(
                'wsz_subs_account_required',
                __('An account is required when buying a subscription so automatic renewals can store reusable payment context.', 'woo-subzero')
            );
        }
    }

    /**
     * @param mixed $cart
     */
    public function cart_contains_subscription_items($cart = null): bool
    {
        if (null === $cart && function_exists('WC')) {
            $wc = WC();
            $cart = is_object($wc) && isset($wc->cart) ? $wc->cart : null;
        }

        if (!is_object($cart) || !is_callable(array($cart, 'get_cart'))) {
            return false;
        }

        $cart_items = $cart->get_cart();

        if (!is_array($cart_items) || empty($cart_items)) {
            return false;
        }

        foreach ($cart_items as $cart_item) {
            if (!is_array($cart_item)) {
                continue;
            }

            $product = $cart_item['data'] ?? null;

            if (!($product instanceof WC_Product) && function_exists('wc_get_product')) {
                $product_id = (int) ($cart_item['variation_id'] ?? $cart_item['product_id'] ?? 0);
                $product = $product_id > 0 ? wc_get_product($product_id) : null;
            }

            if ($this->is_subscription_product($product, null, $cart_item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $order_or_id
     * @param mixed $order
     */
    public function maybe_create_subscriptions_from_new_order($order_or_id, $order = null): void
    {
        $this->maybe_create_subscriptions_from_order($order_or_id, array(), $order);
    }

    /**
     * @param mixed $order_or_id
     * @param mixed $order
     */
    public function maybe_create_subscriptions_for_paid_status($order_or_id, $order = null): void
    {
        if ($order_or_id instanceof WC_Order) {
            $order = $order_or_id;
        }

        if (!($order instanceof WC_Order)) {
            $order = wc_get_order((int) $order_or_id);
        }

        if (!($order instanceof WC_Order)) {
            return;
        }

        if (!in_array($order->get_status(), array('on-hold', 'processing', 'completed'), true)) {
            return;
        }

        $this->maybe_create_subscriptions_from_order($order);
    }

    /**
     * @param mixed $order_or_id
     * @param mixed $posted_data
     * @param mixed $order
     */
    public function maybe_create_subscriptions_from_order($order_or_id, $posted_data = array(), $order = null): void
    {
        if ($order_or_id instanceof WC_Order) {
            $order = $order_or_id;
        }

        if (!($order instanceof WC_Order)) {
            $order = wc_get_order((int) $order_or_id);
        }

        if (!($order instanceof WC_Order)) {
            return;
        }

        if (function_exists('wc_get_order') && is_callable(array($order, 'get_id'))) {
            $fresh_order = wc_get_order((int) $order->get_id());

            if ($fresh_order instanceof WC_Order) {
                $order = $fresh_order;
            }
        }

        $order_type = is_callable(array($order, 'get_type'))
            ? sanitize_key((string) $order->get_type())
            : '';

        if ('' !== $order_type && 'shop_order' !== $order_type) {
            return;
        }

        if ($this->is_existing_subscription_related_order($order)) {
            return;
        }

        $existing_ids = $this->normalize_existing_subscription_ids($order);
        if (!empty($existing_ids)) {
            if (in_array($order->get_status(), array('processing', 'completed'), true)) {
                $this->subscription_manager->maybe_activate_subscriptions_from_parent_order(
                    (int) $order->get_id(),
                    '',
                    (string) $order->get_status(),
                    $order
                );
            }

            return;
        }

        if (!$this->contains_subscription_items($order)) {
            return;
        }

        $requested_start_date = $this->resolve_requested_start_date($order);
        $has_requested_start_date = '' !== $requested_start_date;

        $start_timestamp = $has_requested_start_date
            ? $this->date_string_to_timestamp($requested_start_date)
            : 0;

        if ($start_timestamp <= 0) {
            if ($order->get_date_created() instanceof WC_DateTime) {
                $start_timestamp = max(1, (int) $order->get_date_created()->getTimestamp());
            } else {
                $start_timestamp = current_time('timestamp', true);
            }
        }

        $billing = $this->resolve_billing_profile($order, $start_timestamp);
        $has_deferred_start = $has_requested_start_date && $start_timestamp > current_time('timestamp', true);

        $subscription = $this->subscription_manager->create_subscription_from_order(
            $order,
            array(
                'next_payment' => $billing['next_payment'],
                'billing_interval' => $billing['interval'],
                'billing_period' => $billing['period'],
                'subscription_length' => $billing['length'],
                'start_timestamp' => $start_timestamp,
            )
        );

        if (!($subscription instanceof WC_Order)) {
            return;
        }

        if (!empty($billing['sync_day'])) {
            $subscription->update_meta_data('_wsz_sync_day', (int) $billing['sync_day']);
            $subscription->update_meta_data('_wsz_synchronized', 'yes');
        }

        if ($has_requested_start_date) {
            $subscription->update_meta_data('_wsz_requested_start_date', (string) $requested_start_date);
        }

        if ($has_deferred_start) {
            $subscription->update_meta_data('_wsz_deferred_activation_at', gmdate('Y-m-d H:i:s', $start_timestamp));
        }

        $subscription->save();

        $subscription->add_order_note(
            sprintf(__('Subscription created from checkout order %d.', 'woo-subzero'), $order->get_id())
        );

        if ($has_deferred_start) {
            $activation_timestamp = $this->subscription_manager->get_deferred_activation_schedule_timestamp($start_timestamp);

            $subscription->add_order_note(
                sprintf(
                    __('Subscription deferred until %s UTC.', 'woo-subzero'),
                    gmdate('Y-m-d H:i:s', $activation_timestamp)
                )
            );

            $this->subscription_manager->schedule_deferred_activation((int) $subscription->get_id(), $start_timestamp);
        } elseif (in_array($order->get_status(), array('processing', 'completed'), true)) {
            try {
                $this->subscription_manager->activate_subscription_after_payment(
                    $subscription,
                    __('Initial payment already captured during checkout.', 'woo-subzero')
                );
            } catch (Throwable $throwable) {
                wc_get_logger()->warning(
                    $throwable->getMessage(),
                    array('source' => 'woo-subzero')
                );
            }

            // Activation hook is dispatched by the manager activation helper.
        }
    }

    /**
     * Normalize stored subscription IDs and discard stale references so recovery paths can recreate missing subscriptions.
     *
     * @return array<int,int>
     */
    private function normalize_existing_subscription_ids(WC_Order $order): array
    {
        $stored_ids = $order->get_meta('_wsz_subscription_ids', true);

        if (!is_array($stored_ids) || empty($stored_ids)) {
            return array();
        }

        $normalized_ids = array_values(array_unique(array_filter(array_map('intval', $stored_ids))));

        if (empty($normalized_ids)) {
            $this->persist_subscription_ids($order, array());
            return array();
        }

        $valid_ids = array();

        foreach ($normalized_ids as $subscription_id) {
            $subscription = $this->subscription_manager->get_subscription($subscription_id);

            if ($subscription instanceof WC_Order) {
                $valid_ids[] = $subscription_id;
            }
        }

        if ($valid_ids !== $normalized_ids) {
            $this->persist_subscription_ids($order, $valid_ids);
        }

        return $valid_ids;
    }

    /**
     * @param array<int,int> $subscription_ids
     */
    private function persist_subscription_ids(WC_Order $order, array $subscription_ids): void
    {
        if (!is_callable(array($order, 'update_meta_data')) || !is_callable(array($order, 'save'))) {
            return;
        }

        $order->update_meta_data('_wsz_subscription_ids', array_values($subscription_ids));
        $order->save();
    }

    private function contains_subscription_items(WC_Order $order): bool
    {
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();

            if ($this->is_subscription_product($product, $item)) {
                return true;
            }
        }

        return false;
    }

    private function is_existing_subscription_related_order(WC_Order $order): bool
    {
        $linked_subscription_id = (int) $order->get_meta('_wsz_subscription_id', true);

        if ($linked_subscription_id > 0) {
            $linked_subscription = $this->subscription_manager->get_subscription($linked_subscription_id);

            if ($linked_subscription instanceof WC_Order) {
                return true;
            }
        }

        if (!is_callable(array($order, 'get_parent_id'))) {
            return false;
        }

        $parent_id = (int) $order->get_parent_id();

        if ($parent_id <= 0) {
            return false;
        }

        $parent_subscription = $this->subscription_manager->get_subscription($parent_id);

        return $parent_subscription instanceof WC_Order;
    }

    /**
     * @param mixed $product
     */
    private function is_subscription_product($product, $item = null, array $cart_item = array()): bool
    {
        if ($product instanceof WC_Product) {
            foreach ($this->get_subscription_detection_products($product) as $candidate_product) {
                if (in_array($candidate_product->get_type(), array('subscription', 'variable-subscription', 'wsz_subscription', 'wsz_variable_subscription'), true)) {
                    return true;
                }

                if (function_exists('wcs_is_subscription_product') && wcs_is_subscription_product($candidate_product)) {
                    return true;
                }

                if ('yes' === $candidate_product->get_meta('_wsz_subscription_enabled', true)) {
                    return true;
                }
            }
        }

        if ('' !== $this->get_subscription_context_meta('_wsz_subscription_period', $item, $cart_item)) {
            return true;
        }

        if ((int) $this->get_subscription_context_meta('_wsz_subscription_interval', $item, $cart_item) > 0) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $item
     */
    private function get_subscription_context_meta(string $key, $item = null, array $cart_item = array()): string
    {
        if (is_object($item) && is_callable(array($item, 'get_meta'))) {
            $value = (string) $item->get_meta($key, true);

            if ('' !== $value) {
                return $value;
            }
        }

        if (array_key_exists($key, $cart_item)) {
            return (string) $cart_item[$key];
        }

        return '';
    }

    private function is_current_user_logged_in(): bool
    {
        return function_exists('is_user_logged_in') && is_user_logged_in();
    }

    private function resolve_billing_profile(WC_Order $order, int $start_timestamp = 0): array
    {
        $interval = 1;
        $period = 'month';
        $sync_day = 0;
        $length = 0;

        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();

            if (!$this->is_subscription_product($product, $item)) {
                continue;
            }

            $billing_product = $this->resolve_billing_source_product($product);

            $item_length = (int) $item->get_meta('_wsz_subscription_length', true);

            if ($item_length <= 0) {
                $item_length = (int) $item->get_meta('_subscription_length', true);
            }

            $item_interval = (int) $item->get_meta('_wsz_subscription_interval', true);
            $item_period = (string) $item->get_meta('_wsz_subscription_period', true);

            if ($billing_product instanceof WC_Product) {
                if ($item_interval <= 0) {
                    $item_interval = (int) $billing_product->get_meta('_wsz_subscription_interval', true);
                }

                if ('' === $item_period) {
                    $item_period = (string) $billing_product->get_meta('_wsz_subscription_period', true);
                }

                if ('' === $item_period && method_exists($billing_product, 'get_meta')) {
                    $period_meta = (string) $billing_product->get_meta('_subscription_period', true);
                    if ('' !== $period_meta) {
                        $item_period = $period_meta;
                    }
                }

                if ($item_interval <= 0 && method_exists($billing_product, 'get_meta')) {
                    $interval_meta = (int) $billing_product->get_meta('_subscription_period_interval', true);
                    if ($interval_meta > 0) {
                        $item_interval = $interval_meta;
                    }
                }

                if ($item_interval <= 0 && $product instanceof WC_Product && $product !== $billing_product) {
                    $interval_meta = (int) $product->get_meta('_wsz_subscription_interval', true);

                    if ($interval_meta <= 0) {
                        $interval_meta = (int) $product->get_meta('_subscription_period_interval', true);
                    }

                    if ($interval_meta > 0) {
                        $item_interval = $interval_meta;
                    }
                }

                if ('' === $item_period && $product instanceof WC_Product && $product !== $billing_product) {
                    $period_meta = (string) $product->get_meta('_wsz_subscription_period', true);

                    if ('' === $period_meta) {
                        $period_meta = (string) $product->get_meta('_subscription_period', true);
                    }

                    if ('' !== $period_meta) {
                        $item_period = $period_meta;
                    }
                }
            }

            if ($item_interval > 0) {
                $interval = $item_interval;
            }

            if ('' !== $item_period) {
                $period = sanitize_key($item_period);
            }

            if ($billing_product instanceof WC_Product) {
                $sync_meta = (int) $billing_product->get_meta('_subscription_payment_sync_date', true);
                if ($sync_meta <= 0) {
                    $sync_meta = (int) $billing_product->get_meta('_wsz_sync_day', true);
                }

                if ($sync_meta > 0) {
                    $sync_day = min(28, max(1, $sync_meta));
                }

                if ($sync_meta <= 0 && $product instanceof WC_Product && $product !== $billing_product) {
                    $sync_meta = (int) $product->get_meta('_wsz_sync_day', true);

                    if ($sync_meta <= 0) {
                        $sync_meta = (int) $product->get_meta('_subscription_payment_sync_date', true);
                    }

                    if ($sync_meta > 0) {
                        $sync_day = min(28, max(1, $sync_meta));
                    }
                }

                // Length must prioritize the concrete purchased line item/variation over parent defaults.
                $length_meta = $item_length > 0 ? $item_length : 0;

                if ($length_meta <= 0 && $product instanceof WC_Product) {
                    $length_meta = (int) $product->get_meta('_wsz_subscription_length', true);

                    if ($length_meta <= 0) {
                        $length_meta = (int) $product->get_meta('_subscription_length', true);
                    }
                }

                if ($length_meta <= 0) {
                    $length_meta = (int) $billing_product->get_meta('_wsz_subscription_length', true);
                    if ($length_meta <= 0) {
                        $length_meta = (int) $billing_product->get_meta('_subscription_length', true);
                    }
                }

                if ($length_meta > 0) {
                    $length = $length_meta;
                }
            }

            break;
        }

        if (!in_array($period, array('day', 'week', 'month', 'year'), true)) {
            $period = 'month';
        }

        $start_timestamp = $start_timestamp > 0 ? $start_timestamp : current_time('timestamp', true);

        $next_payment = $this->subscription_manager->calculate_next_payment_for_profile(
            $start_timestamp,
            max(1, $interval),
            $period
        );

        if ($next_payment > 0) {
            $next_payment = (int) apply_filters(
                'wsz_subs_next_payment_timestamp',
                $next_payment,
                null,
                array(
                    'reason' => 'checkout',
                    'period' => $period,
                    'interval' => $interval,
                    'sync_day' => $sync_day,
                )
            );
        }

        return array(
            'interval' => max(1, $interval),
            'period' => $period,
            'sync_day' => $sync_day,
            'length' => max(0, $length),
            'requested_start_date' => $start_timestamp > 0 ? gmdate('Y-m-d', $start_timestamp) : '',
            'next_payment' => $next_payment > 0
                ? $next_payment
                : $this->subscription_manager->calculate_next_payment_for_profile($start_timestamp, 1, 'month'),
        );
    }

    private function resolve_requested_start_date(WC_Order $order): string
    {
        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof WC_Order_Item_Product) || !is_callable(array($item, 'get_meta'))) {
                continue;
            }

            $requested_start_date = (string) $item->get_meta('_wsz_requested_start_date', true);

            if ('' === $requested_start_date) {
                continue;
            }

            if ($this->date_string_to_timestamp($requested_start_date) > 0) {
                return $requested_start_date;
            }
        }

        return '';
    }

    private function date_string_to_timestamp(string $date_value): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_value)) {
            return 0;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_value, $timezone);

        if (!($date instanceof DateTimeImmutable) || $date->format('Y-m-d') !== $date_value) {
            return 0;
        }

        $timestamp = $date->setTime(0, 0, 0)->getTimestamp();

        return $timestamp > 0 ? $timestamp : 0;
    }

    /**
     * @return array<int,WC_Product>
     */
    private function get_subscription_detection_products(WC_Product $product): array
    {
        $products = array($product);

        $parent_product = $this->resolve_parent_product($product);

        if ($parent_product instanceof WC_Product) {
            $products[] = $parent_product;
        }

        return $products;
    }

    /**
     * @param mixed $product
     */
    private function resolve_billing_source_product($product): ?WC_Product
    {
        if (!($product instanceof WC_Product)) {
            return null;
        }

        $parent_product = $this->resolve_parent_product($product);

        return $parent_product instanceof WC_Product ? $parent_product : $product;
    }

    private function resolve_parent_product(WC_Product $product): ?WC_Product
    {
        if (!method_exists($product, 'get_parent_id')) {
            return null;
        }

        $parent_id = (int) $product->get_parent_id();

        if ($parent_id <= 0 || !function_exists('wc_get_product')) {
            return null;
        }

        $parent_product = wc_get_product($parent_id);

        return $parent_product instanceof WC_Product ? $parent_product : null;
    }
}
