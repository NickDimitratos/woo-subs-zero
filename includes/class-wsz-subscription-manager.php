<?php

defined('ABSPATH') || exit;

class WSZ_Subscription_Manager
{
    public const ORDER_TYPE = 'shop_subscription';

    public const ACTION_GROUP = 'wsz-subscriptions';

    private const OPTION_LOCK_PREFIX = 'wsz_subs_lock_';

    private const VALID_TRANSITIONS = array(
        'pending' => array('active', 'cancelled'),
        'active' => array('on-hold', 'pending-cancel', 'expired', 'cancelled'),
        'on-hold' => array('active', 'cancelled'),
        'pending-cancel' => array('cancelled'),
        'cancelled' => array(),
        'expired' => array(),
    );

    public function init(): void
    {
        add_action('init', array($this, 'register_subscription_order_type'), 20);
        add_action('init', array($this, 'register_subscription_statuses'), 20);
        add_filter('wc_order_statuses', array($this, 'inject_custom_statuses'));

        add_action('woocommerce_order_status_changed', array($this, 'maybe_activate_subscriptions_from_parent_order'), 20, 4);
        add_action('woocommerce_order_status_cancelled', array($this, 'maybe_cancel_subscription_from_renewal_order'), 20, 2);
        add_action('woocommerce_order_status_refunded', array($this, 'maybe_cancel_subscription_from_renewal_order'), 20, 2);
        add_action('woocommerce_subscription_status_updated', array($this, 'sync_parent_order_status_context'), 20, 3);
        add_action('wsz_subs_activate_deferred_subscription', array($this, 'activate_deferred_subscription'), 10, 1);
        add_action('wsz_subs_finalize_pending_cancel', array($this, 'finalize_pending_cancel'), 10, 1);
        add_action('wsz_subs_expire_subscription', array($this, 'process_expiration'), 10, 1);
    }

    public static function get_valid_transitions(): array
    {
        return self::VALID_TRANSITIONS;
    }

    public static function is_valid_transition(string $from_status, string $to_status): bool
    {
        $from = self::normalize_status($from_status);
        $to = self::normalize_status($to_status);

        if ($from === $to) {
            return true;
        }

        return in_array($to, self::VALID_TRANSITIONS[$from] ?? array(), true);
    }

    public function register_subscription_order_type(): void
    {
        if (!function_exists('wc_register_order_type')) {
            return;
        }

        global $wc_order_types;

        if (isset($wc_order_types[self::ORDER_TYPE])) {
            return;
        }

        wc_register_order_type(
            self::ORDER_TYPE,
            array(
                'label' => _x('Subscriptions', 'custom order type setting', 'woo-subzero'),
                'public' => false,
                'exclude_from_orders_screen' => false,
                'add_order_meta_boxes' => true,
                'exclude_from_order_count' => false,
                'exclude_from_order_views' => false,
                'exclude_from_order_reports' => false,
                'exclude_from_order_sales_reports' => false,
                'class_name' => 'WSZ_Order_Subscription',
                'show_in_menu' => current_user_can('manage_woocommerce') ? 'woocommerce' : true,
                'supports' => array('custom-fields'),
                'capability_type' => 'shop_order',
                'map_meta_cap' => true,
                'publicly_queryable' => false,
                'show_ui' => true,
            )
        );
    }

    public function register_subscription_statuses(): void
    {
        register_post_status(
            'wc-active',
            array(
                'label' => _x('Active', 'Order status', 'woo-subzero'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop('Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'woo-subzero'),
            )
        );

        register_post_status(
            'wc-pending-cancel',
            array(
                'label' => _x('Pending Cancellation', 'Order status', 'woo-subzero'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop('Pending cancellation <span class="count">(%s)</span>', 'Pending cancellation <span class="count">(%s)</span>', 'woo-subzero'),
            )
        );

        register_post_status(
            'wc-expired',
            array(
                'label' => _x('Expired', 'Order status', 'woo-subzero'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop('Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'woo-subzero'),
            )
        );
    }

    public function inject_custom_statuses(array $statuses): array
    {
        if (!isset($statuses['wc-active'])) {
            $statuses['wc-active'] = _x('Active', 'Order status', 'woo-subzero');
        }

        if (!isset($statuses['wc-pending-cancel'])) {
            $statuses['wc-pending-cancel'] = _x('Pending cancellation', 'Order status', 'woo-subzero');
        }

        if (!isset($statuses['wc-expired'])) {
            $statuses['wc-expired'] = _x('Expired', 'Order status', 'woo-subzero');
        }

        return $statuses;
    }

    /**
     * @param WC_Order $order Parent order.
     */
    public function create_subscription_from_order($order, array $args = array())
    {
        if (!($order instanceof WC_Order)) {
            return null;
        }

        $stored_existing_ids = $order->get_meta('_wsz_subscription_ids', true);
        $existing_ids = array();

        if (is_array($stored_existing_ids) && !empty($stored_existing_ids)) {
            $existing_ids = array_values(array_unique(array_filter(array_map('intval', $stored_existing_ids))));

            $valid_existing_ids = array();

            foreach ($existing_ids as $existing_id) {
                $existing_subscription = $this->get_subscription($existing_id);

                if ($existing_subscription instanceof WC_Order) {
                    $valid_existing_ids[] = (int) $existing_subscription->get_id();
                }
            }

            $valid_existing_ids = array_values(array_unique(array_map('intval', $valid_existing_ids)));

            if ($valid_existing_ids !== $existing_ids) {
                $order->update_meta_data('_wsz_subscription_ids', $valid_existing_ids);
                $order->save();
            }

            if (!empty($valid_existing_ids)) {
                return $this->get_subscription((int) reset($valid_existing_ids));
            }

            $existing_ids = array();
        }

        $subscription = $this->create_subscription_order($order);

        if (!($subscription instanceof WC_Order)) {
            return null;
        }

        $this->copy_order_context($order, $subscription);

        $start_timestamp = isset($args['start_timestamp'])
            ? max(1, (int) $args['start_timestamp'])
            : current_time('timestamp', true);

        $billing_period = isset($args['billing_period'])
            ? sanitize_key((string) $args['billing_period'])
            : 'month';

        if (!in_array($billing_period, array('day', 'week', 'month', 'year'), true)) {
            $billing_period = 'month';
        }

        $billing_interval = isset($args['billing_interval'])
            ? max(1, (int) $args['billing_interval'])
            : 1;

        $next_payment_timestamp = isset($args['next_payment'])
            ? (int) $args['next_payment']
            : $this->calculate_next_payment_for_profile($start_timestamp, $billing_interval, $billing_period);

        if ($next_payment_timestamp <= 0) {
            $next_payment_timestamp = max(1, $start_timestamp + DAY_IN_SECONDS * 30);
        }

        $subscription_length = isset($args['subscription_length'])
            ? max(0, (int) $args['subscription_length'])
            : 0;

        $subscription->update_meta_data('_wsz_parent_order_id', $order->get_id());
        $subscription->update_meta_data('_wsz_related_order_ids', wp_json_encode(array()));
        $subscription->update_meta_data('_requires_manual_renewal', 'no');
        $subscription->update_meta_data('_wsz_start_date', gmdate('Y-m-d H:i:s', $start_timestamp));
        $subscription->update_meta_data('_wsz_next_payment', gmdate('Y-m-d H:i:s', $next_payment_timestamp));
        $subscription->update_meta_data('_wsz_billing_period', $billing_period);
        $subscription->update_meta_data('_wsz_billing_interval', $billing_interval);

        if ($subscription_length > 0) {
            $subscription->update_meta_data('_wsz_subscription_length', $subscription_length);
            $subscription->update_meta_data('_wsz_total_payments', $subscription_length);

            $initial_payment_completed = false;

            if (is_callable(array($order, 'is_paid'))) {
                $initial_payment_completed = (bool) $order->is_paid();
            } else {
                $initial_payment_completed = in_array($order->get_status(), array('processing', 'completed'), true);
            }

            $subscription->update_meta_data('_wsz_completed_payments', $initial_payment_completed ? 1 : 0);
            $subscription->update_meta_data('_wsz_initial_payment_counted', $initial_payment_completed ? 'yes' : 'no');
        }

        $subscription->set_status('pending');
        $subscription->save();

        $this->update_next_payment_timestamp($subscription, $next_payment_timestamp);

        if ($subscription_length > 0) {
            $end_timestamp = $this->calculate_end_timestamp_for_profile(
                $start_timestamp,
                $billing_interval,
                $billing_period,
                $subscription_length
            );

            if ($end_timestamp > 0) {
                $this->update_end_timestamp($subscription, $end_timestamp);
                $this->schedule_expiration($subscription->get_id(), $end_timestamp);
            }
        }

        $subscription_ids = $existing_ids;
        $subscription_ids[] = $subscription->get_id();

        $order->update_meta_data('_wsz_subscription_ids', array_values(array_unique(array_map('intval', $subscription_ids))));
        $order->save();

        return $subscription;
    }

    private function create_subscription_order(WC_Order $order): ?WC_Order
    {
        if (!class_exists('WSZ_Order_Subscription')) {
            return null;
        }

        $subscription = new WSZ_Order_Subscription();

        if (!($subscription instanceof WC_Order)) {
            return null;
        }

        if (is_callable(array($subscription, 'set_parent_id'))) {
            $subscription->set_parent_id($order->get_id());
        }

        if (is_callable(array($subscription, 'set_status'))) {
            $subscription->set_status('pending');
        }

        if (is_callable(array($subscription, 'set_customer_id'))) {
            $subscription->set_customer_id($order->get_customer_id());
        }

        if (is_callable(array($subscription, 'set_created_via'))) {
            $subscription->set_created_via('woo-subzero');
        }

        if (function_exists('get_woocommerce_currency') && is_callable(array($subscription, 'set_currency'))) {
            $subscription->set_currency(get_woocommerce_currency());
        }

        if (function_exists('get_option') && is_callable(array($subscription, 'set_prices_include_tax'))) {
            $subscription->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
        }

        if (class_exists('WC_Geolocation') && is_callable(array('WC_Geolocation', 'get_ip_address')) && is_callable(array($subscription, 'set_customer_ip_address'))) {
            $subscription->set_customer_ip_address(WC_Geolocation::get_ip_address());
        }

        if (function_exists('wc_get_user_agent') && is_callable(array($subscription, 'set_customer_user_agent'))) {
            $subscription->set_customer_user_agent(wc_get_user_agent());
        }

        $subscription->save();

        if ((int) $subscription->get_id() <= 0) {
            return null;
        }

        return $subscription;
    }

    public function maybe_activate_subscriptions_from_parent_order(int $order_id, string $from_status, string $to_status, $order): void
    {
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);
        }

        if (function_exists('wc_get_order')) {
            $fresh_order = wc_get_order($order_id);

            if ($fresh_order instanceof WC_Order) {
                $order = $fresh_order;
            }
        }

        if (!($order instanceof WC_Order)) {
            return;
        }

        $to_status = sanitize_key((string) $to_status);

        if (!in_array($to_status, array('processing', 'completed', 'cancelled', 'refunded'), true)) {
            return;
        }

        $subscription_ids = $order->get_meta('_wsz_subscription_ids', true);

        if (!is_array($subscription_ids) || empty($subscription_ids)) {
            return;
        }

        foreach ($subscription_ids as $subscription_id) {
            $subscription = $this->get_subscription((int) $subscription_id);
            if (!$subscription) {
                continue;
            }

            if (in_array($to_status, array('cancelled', 'refunded'), true)) {
                $current_status = self::normalize_status((string) $subscription->get_status());

                if (!in_array($current_status, array('cancelled', 'expired'), true)) {
                    $this->transition_status(
                        $subscription,
                        'cancelled',
                        __('Parent order was cancelled or refunded.', 'woo-subzero')
                    );
                }

                continue;
            }

            if ('pending' !== self::normalize_status($subscription->get_status())) {
                continue;
            }

            $deferred_start_timestamp = $this->get_deferred_start_timestamp($subscription);

            if ($deferred_start_timestamp > current_time('timestamp', true)) {
                continue;
            }

            $this->activate_subscription_after_payment(
                $subscription,
                __('Initial payment completed.', 'woo-subzero')
            );
        }
    }

    /**
     * Activate a pending subscription after confirmed payment across all entry points.
     *
     * @param mixed $subscription
     */
    public function activate_subscription_after_payment($subscription, string $note = ''): bool
    {
        if (!($subscription instanceof WC_Order)) {
            return false;
        }

        if ('pending' !== self::normalize_status((string) $subscription->get_status())) {
            return false;
        }

        $this->mark_initial_payment_completed($subscription);
        $this->maybe_rebase_lifecycle_from_activation($subscription, current_time('timestamp', true));

        $this->transition_status(
            $subscription,
            'active',
            '' !== $note ? $note : __('Initial payment completed.', 'woo-subzero')
        );

        do_action('wsz_subs_subscription_activated', $subscription);

        return true;
    }

    /**
     * Keep renewal-order cancellation in sync with linked subscriptions.
     *
     * @param WC_Order|int $order_or_id
     * @param mixed        $order
     */
    public function maybe_cancel_subscription_from_renewal_order($order_or_id, $order = null): void
    {
        if ($order instanceof WC_Order) {
            $renewal_order = $order;
        } elseif ($order_or_id instanceof WC_Order) {
            $renewal_order = $order_or_id;
        } elseif (function_exists('wc_get_order')) {
            $renewal_order = wc_get_order((int) $order_or_id);
        } else {
            $renewal_order = null;
        }

        if (!($renewal_order instanceof WC_Order)) {
            return;
        }

        $subscription_id = (int) $renewal_order->get_meta('_wsz_subscription_id', true);

        if ($subscription_id <= 0 && is_callable(array($renewal_order, 'get_parent_id'))) {
            $parent_id = (int) $renewal_order->get_parent_id();

            if ($parent_id > 0 && $this->get_subscription($parent_id) instanceof WC_Order) {
                $subscription_id = $parent_id;
            }
        }

        if ($subscription_id <= 0) {
            return;
        }

        $subscription = $this->get_subscription($subscription_id);

        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $status = self::normalize_status((string) $subscription->get_status());

        if (in_array($status, array('cancelled', 'expired'), true)) {
            return;
        }

        try {
            $this->transition_status(
                $subscription,
                'cancelled',
                __('Renewal order was cancelled or refunded.', 'woo-subzero')
            );
        } catch (Throwable $throwable) {
            wc_get_logger()->warning(
                $throwable->getMessage(),
                array('source' => 'woo-subzero')
            );
        }
    }

    public function get_subscription(int $subscription_id)
    {
        if ($subscription_id <= 0) {
            return null;
        }

        $order = wc_get_order($subscription_id);

        if (!($order instanceof WC_Order)) {
            return null;
        }

        if (self::ORDER_TYPE !== $order->get_type()) {
            return null;
        }

        return $order;
    }

    public function transition_status($subscription, string $new_status, string $note = ''): bool
    {
        if (!($subscription instanceof WC_Order)) {
            return false;
        }

        $old_status = self::normalize_status($subscription->get_status());
        $target_status = self::normalize_status($new_status);

        if ($old_status === $target_status) {
            return true;
        }

        if (!self::is_valid_transition($old_status, $target_status)) {
            $message = sprintf(
                'Invalid subscription transition attempted: %s -> %s (subscription %d)',
                $old_status,
                $target_status,
                $subscription->get_id()
            );

            wc_get_logger()->error($message, array('source' => 'woo-subzero'));

            throw new InvalidArgumentException($message);
        }

        do_action('woocommerce_subscription_pre_update_status', $subscription, $target_status, $old_status);

        $subscription->update_status($target_status, $note, true);

        do_action('woocommerce_subscription_status_updated', $subscription, $target_status, $old_status);
        do_action("woocommerce_subscription_status_{$target_status}", $subscription);
        do_action("woocommerce_subscription_status_{$old_status}_to_{$target_status}", $subscription);

        if ('active' === $target_status) {
            $subscription->update_meta_data('_wsz_deferred_activation_at', '');
            $subscription->save();

            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(
                    'wsz_subs_activate_deferred_subscription',
                    array('subscription_id' => $subscription->get_id()),
                    self::ACTION_GROUP
                );
            }
        }

        if (in_array($target_status, array('cancelled', 'expired'), true) && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(
                'wsz_subs_activate_deferred_subscription',
                array('subscription_id' => $subscription->get_id()),
                self::ACTION_GROUP
            );

            as_unschedule_all_actions(
                'wsz_subs_expire_subscription',
                array('subscription_id' => $subscription->get_id()),
                self::ACTION_GROUP
            );
        }

        return true;
    }

    public function schedule_deferred_activation(int $subscription_id, int $start_timestamp): void
    {
        if ($subscription_id <= 0 || $start_timestamp <= 0) {
            return;
        }

        $activation_timestamp = $this->get_deferred_activation_schedule_timestamp($start_timestamp);

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(
                'wsz_subs_activate_deferred_subscription',
                array('subscription_id' => $subscription_id),
                self::ACTION_GROUP
            );
        }

        $subscription = $this->get_subscription($subscription_id);

        if ($subscription instanceof WC_Order) {
            $subscription->update_meta_data('_wsz_deferred_activation_at', gmdate('Y-m-d H:i:s', $activation_timestamp));
            $subscription->save();
        }

        if ($activation_timestamp <= current_time('timestamp', true)) {
            $this->activate_deferred_subscription($subscription_id);
            return;
        }

        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        as_schedule_single_action(
            $activation_timestamp,
            'wsz_subs_activate_deferred_subscription',
            array('subscription_id' => $subscription_id),
            self::ACTION_GROUP,
            false
        );
    }

    public function activate_deferred_subscription(int $subscription_id): void
    {
        $subscription = $this->get_subscription($subscription_id);

        if (!($subscription instanceof WC_Order)) {
            return;
        }

        if ('pending' !== self::normalize_status((string) $subscription->get_status())) {
            return;
        }

        $deferred_start_timestamp = $this->get_deferred_start_timestamp($subscription);

        if ($deferred_start_timestamp > current_time('timestamp', true)) {
            $this->schedule_deferred_activation($subscription_id, $deferred_start_timestamp);
            return;
        }

        $parent_order = $this->resolve_parent_order_for_subscription($subscription);

        if ($parent_order instanceof WC_Order && !$this->is_parent_order_paid_for_subscription_activation($parent_order)) {
            $retry_after_seconds = $this->get_test_cycle_minutes() > 0
                ? $this->get_test_cycle_minutes() * 60
                : HOUR_IN_SECONDS;

            $this->schedule_deferred_activation($subscription_id, current_time('timestamp', true) + max(60, $retry_after_seconds));
            return;
        }

        $activated_at_timestamp = current_time('timestamp', true);

        // In accelerated QA mode, align lifecycle dates to actual activation time so renewals begin immediately.
        if ($this->get_test_deferred_start_minutes() > 0) {
            $billing_interval = $this->get_billing_interval($subscription);
            $billing_period = $this->get_billing_period($subscription);
            $next_payment_timestamp = $this->calculate_next_payment_for_profile(
                $activated_at_timestamp,
                $billing_interval,
                $billing_period
            );

            $subscription->update_meta_data('_wsz_start_date', gmdate('Y-m-d H:i:s', $activated_at_timestamp));
            $subscription->save();

            if ($next_payment_timestamp > 0) {
                $this->update_next_payment_timestamp($subscription, $next_payment_timestamp);
            }
        }

        $this->activate_subscription_after_payment(
            $subscription,
            __('Subscription activated on scheduled start date.', 'woo-subzero')
        );

        if ($this->get_test_cycle_minutes() > 0) {
            $next_payment_timestamp = $this->get_next_payment_timestamp($subscription);

            if ($next_payment_timestamp > 0) {
                $this->ensure_test_mode_first_renewal_is_queued($subscription, $next_payment_timestamp);
            }
        }
    }

    public function cancel_with_prepaid_term($subscription, bool $immediate = false): bool
    {
        if (!($subscription instanceof WC_Order)) {
            return false;
        }

        if ($immediate) {
            return $this->transition_status($subscription, 'cancelled', __('Cancelled immediately.', 'woo-subzero'));
        }

        $end_timestamp = $this->get_end_timestamp($subscription);

        if ($end_timestamp > current_time('timestamp', true)) {
            $this->transition_status($subscription, 'pending-cancel', __('Cancellation queued for prepaid term end.', 'woo-subzero'));

            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(
                    $end_timestamp,
                    'wsz_subs_finalize_pending_cancel',
                    array('subscription_id' => $subscription->get_id()),
                    self::ACTION_GROUP,
                    true
                );
            }

            return true;
        }

        return $this->transition_status($subscription, 'cancelled', __('Cancelled at end of term.', 'woo-subzero'));
    }

    public function finalize_pending_cancel(int $subscription_id): void
    {
        $subscription = $this->get_subscription($subscription_id);

        if (!$subscription) {
            return;
        }

        if ('pending-cancel' !== self::normalize_status($subscription->get_status())) {
            return;
        }

        if ($this->get_end_timestamp($subscription) > current_time('timestamp', true)) {
            return;
        }

        $this->transition_status($subscription, 'cancelled', __('Prepaid term ended.', 'woo-subzero'));
    }

    public function get_next_payment_timestamp($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        $stored_next_payment = (string) $subscription->get_meta('_wsz_next_payment', true);
        $stored_next_payment_timestamp = 0;

        if ('' !== $stored_next_payment) {
            $stored_next_payment_timestamp = (int) strtotime($stored_next_payment . ' UTC');
            $stored_next_payment_timestamp = $stored_next_payment_timestamp > 0 ? $stored_next_payment_timestamp : 0;
        }

        // In accelerated deferred-start QA mode, trust WSZ-managed schedule source first.
        if ($this->get_test_deferred_start_minutes() > 0 && $stored_next_payment_timestamp > 0) {
            return $stored_next_payment_timestamp;
        }

        if (is_callable(array($subscription, 'get_time'))) {
            $timestamp = (int) $subscription->get_time('next_payment');
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        if (is_callable(array($subscription, 'get_date'))) {
            $next_date = $subscription->get_date('next_payment');
            if ($next_date instanceof WC_DateTime) {
                return (int) $next_date->getTimestamp();
            }
        }

        if ('' === $stored_next_payment) {
            return 0;
        }

        return $stored_next_payment_timestamp;
    }

    public function update_next_payment_timestamp($subscription, int $next_payment_timestamp): void
    {
        if (!($subscription instanceof WC_Order) || $next_payment_timestamp <= 0) {
            return;
        }

        $gm_date = gmdate('Y-m-d H:i:s', $next_payment_timestamp);

        if (is_callable(array($subscription, 'update_dates'))) {
            try {
                $subscription->update_dates(array('next_payment' => $gm_date), 'gmt');
            } catch (Throwable $throwable) {
                wc_get_logger()->warning(
                    $throwable->getMessage(),
                    array('source' => 'woo-subzero')
                );
            }
        }

        $subscription->update_meta_data('_wsz_next_payment', $gm_date);
        $subscription->update_meta_data('_schedule_next_payment', $gm_date);
        $subscription->save();
    }

    public function set_manual_renewal($subscription, bool $enabled): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $subscription->update_meta_data('_requires_manual_renewal', $enabled ? 'yes' : 'no');

        if (!$enabled) {
            $subscription->update_meta_data('_wsz_manual_renewal_fallback_reason', '');
        }

        $subscription->save();
    }

    public function is_manual_renewal($subscription): bool
    {
        if (!($subscription instanceof WC_Order)) {
            return true;
        }

        return 'yes' === $subscription->get_meta('_requires_manual_renewal', true);
    }

    public function set_payment_token_id($subscription, int $token_id): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $subscription->update_meta_data('_payment_token_id', max(0, $token_id));
        $subscription->save();
    }

    public function get_payment_token_id($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        return (int) $subscription->get_meta('_payment_token_id', true);
    }

    public function add_related_order($subscription, int $order_id, string $relationship): void
    {
        if (!($subscription instanceof WC_Order) || $order_id <= 0) {
            return;
        }

        $stored = $subscription->get_meta('_wsz_related_order_ids', true);
        $decoded = is_string($stored) ? json_decode($stored, true) : array();

        if (!is_array($decoded)) {
            $decoded = array();
        }

        if (!isset($decoded[$relationship]) || !is_array($decoded[$relationship])) {
            $decoded[$relationship] = array();
        }

        $decoded[$relationship][] = $order_id;
        $decoded[$relationship] = array_values(array_unique(array_map('intval', $decoded[$relationship])));

        $subscription->update_meta_data('_wsz_related_order_ids', wp_json_encode($decoded));
        $subscription->save();
    }

    public function get_related_orders($subscription, string $relationship): array
    {
        if (!($subscription instanceof WC_Order)) {
            return array();
        }

        $stored = $subscription->get_meta('_wsz_related_order_ids', true);
        $decoded = is_string($stored) ? json_decode($stored, true) : array();

        if (!is_array($decoded) || !isset($decoded[$relationship]) || !is_array($decoded[$relationship])) {
            return array();
        }

        return array_values(array_unique(array_map('intval', $decoded[$relationship])));
    }

    public function get_subscription_length($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        $length = (int) $subscription->get_meta('_wsz_subscription_length', true);

        if ($length <= 0) {
            $length = (int) $subscription->get_meta('_subscription_length', true);
        }

        return max(0, $length);
    }

    public function get_total_payments($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        $total = (int) $subscription->get_meta('_wsz_total_payments', true);

        if ($total <= 0) {
            $total = $this->get_subscription_length($subscription);
        }

        return max(0, $total);
    }

    public function get_completed_payments($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        $completed = max(0, (int) $subscription->get_meta('_wsz_completed_payments', true));

        if ($completed > 0) {
            return $completed;
        }

        if ($this->get_total_payments($subscription) <= 0) {
            return 0;
        }

        $initial_counted = (string) $subscription->get_meta('_wsz_initial_payment_counted', true);

        if ('yes' === $initial_counted) {
            return 1;
        }

        $status = self::normalize_status((string) $subscription->get_status());

        if (in_array($status, array('active', 'on-hold', 'pending-cancel', 'expired', 'cancelled'), true)) {
            return 1;
        }

        return 0;
    }

    public function mark_initial_payment_completed($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        $total = $this->get_total_payments($subscription);

        if ($total <= 0) {
            return 0;
        }

        $completed = $this->get_completed_payments($subscription);

        if ($completed >= 1) {
            return $completed;
        }

        $subscription->update_meta_data('_wsz_completed_payments', 1);
        $subscription->update_meta_data('_wsz_initial_payment_counted', 'yes');
        $subscription->save();

        return 1;
    }

    public function increment_completed_payments($subscription, int $step = 1): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        $total = $this->get_total_payments($subscription);

        if ($total <= 0) {
            return $this->get_completed_payments($subscription);
        }

        $completed = $this->get_completed_payments($subscription);
        $completed += max(1, $step);
        $completed = min($total, $completed);

        $subscription->update_meta_data('_wsz_completed_payments', $completed);

        if ($completed >= 1) {
            $subscription->update_meta_data('_wsz_initial_payment_counted', 'yes');
        }

        $subscription->save();

        return $completed;
    }

    public function has_completed_all_payments($subscription): bool
    {
        if (!($subscription instanceof WC_Order)) {
            return false;
        }

        $total = $this->get_total_payments($subscription);

        if ($total <= 0) {
            return false;
        }

        return $this->get_completed_payments($subscription) >= $total;
    }

    public static function calculate_end_timestamp(
        int $start_timestamp,
        int $billing_interval,
        string $billing_period,
        int $subscription_length
    ): int {
        if ($subscription_length <= 0 || $start_timestamp <= 0) {
            return 0;
        }

        $billing_interval = max(1, $billing_interval);
        $billing_period = sanitize_key($billing_period);

        if (!in_array($billing_period, array('day', 'week', 'month', 'year'), true)) {
            $billing_period = 'month';
        }

        $total_units = $billing_interval * $subscription_length;
        $end_timestamp = strtotime(sprintf('+%d %s', $total_units, $billing_period), $start_timestamp);

        return $end_timestamp > 0 ? $end_timestamp : 0;
    }

    public function calculate_end_timestamp_for_profile(
        int $start_timestamp,
        int $billing_interval,
        string $billing_period,
        int $subscription_length
    ): int {
        if ($subscription_length <= 0 || $start_timestamp <= 0) {
            return 0;
        }

        $billing_interval = max(1, $billing_interval);
        $test_cycle_minutes = $this->get_test_cycle_minutes();

        if ($test_cycle_minutes > 0) {
            $minutes_per_cycle = max(1, $test_cycle_minutes);
            $total_seconds = $billing_interval * $subscription_length * $minutes_per_cycle * 60;

            return max(1, $start_timestamp + $total_seconds);
        }

        return self::calculate_end_timestamp($start_timestamp, $billing_interval, $billing_period, $subscription_length);
    }

    public function calculate_next_payment_for_profile(int $from_timestamp, int $interval, string $period): int
    {
        $from_timestamp = max(1, $from_timestamp);
        $interval = max(1, $interval);

        $period = sanitize_key($period);

        if (!in_array($period, array('day', 'week', 'month', 'year'), true)) {
            $period = 'month';
        }

        $test_cycle_minutes = $this->get_test_cycle_minutes();

        if ($test_cycle_minutes > 0) {
            $seconds_per_cycle = max(1, $test_cycle_minutes) * 60;

            return $from_timestamp + ($interval * $seconds_per_cycle);
        }

        $next = strtotime(sprintf('+%d %s', $interval, $period), $from_timestamp);

        return $next > 0 ? $next : 0;
    }

    public function get_test_cycle_minutes(): int
    {
        $options = $this->get_options();

        if ('yes' !== $options['enable_test_mode']) {
            return 0;
        }

        return max(1, (int) $options['test_cycle_minutes']);
    }

    public function should_send_test_cycle_notifications(): bool
    {
        $options = $this->get_options();

        return $this->get_test_cycle_minutes() > 0
            && 'yes' === $options['enable_test_cycle_notifications'];
    }

    public function update_end_timestamp($subscription, int $end_timestamp): void
    {
        if (!($subscription instanceof WC_Order) || $end_timestamp <= 0) {
            return;
        }

        $gm_date = gmdate('Y-m-d H:i:s', $end_timestamp);

        if (is_callable(array($subscription, 'update_dates'))) {
            try {
                $subscription->update_dates(array('end' => $gm_date), 'gmt');
            } catch (Throwable $throwable) {
                wc_get_logger()->warning(
                    $throwable->getMessage(),
                    array('source' => 'woo-subzero')
                );
            }
        }

        $subscription->update_meta_data('_wsz_end_date', $gm_date);
        $subscription->save();
    }

    public function schedule_expiration(int $subscription_id, int $end_timestamp): void
    {
        if ($subscription_id <= 0 || $end_timestamp <= 0 || !function_exists('as_schedule_single_action')) {
            return;
        }

        if ($end_timestamp <= current_time('timestamp', true)) {
            $this->process_expiration($subscription_id);
            return;
        }

        as_schedule_single_action(
            $end_timestamp,
            'wsz_subs_expire_subscription',
            array('subscription_id' => $subscription_id),
            self::ACTION_GROUP,
            false
        );
    }

    public function process_expiration(int $subscription_id): void
    {
        $subscription = $this->get_subscription($subscription_id);

        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $status = self::normalize_status($subscription->get_status());

        if (in_array($status, array('cancelled', 'expired'), true)) {
            return;
        }

        $end_timestamp = $this->get_end_timestamp($subscription);

        if ($end_timestamp <= 0 || $end_timestamp > current_time('timestamp', true)) {
            return;
        }

        if ($this->get_total_payments($subscription) > 0 && !$this->has_completed_all_payments($subscription)) {
            // Finite plans should only expire when all required payments are completed.
            return;
        }

        try {
            if ('active' === $status) {
                $this->transition_status($subscription, 'expired', __('Subscription term completed.', 'woo-subzero'));
                return;
            }

            if ('pending-cancel' === $status) {
                $this->transition_status($subscription, 'cancelled', __('Subscription ended at term boundary.', 'woo-subzero'));
                return;
            }

            if (in_array($status, array('pending', 'on-hold'), true)) {
                // Keep non-activated subscriptions pending/on-hold. They will be rebased at activation.
                return;
            }
        } catch (Throwable $throwable) {
            wc_get_logger()->warning(
                $throwable->getMessage(),
                array('source' => 'woo-subzero')
            );
        }
    }

    /**
     * Keep parent order and subscription status context synchronized for observability/debugging.
     *
     * @param mixed $subscription
     */
    public function sync_parent_order_status_context($subscription, string $new_status, string $old_status): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $parent_order_id = (int) $subscription->get_meta('_wsz_parent_order_id', true);

        if ($parent_order_id <= 0 && is_callable(array($subscription, 'get_parent_id'))) {
            $parent_order_id = (int) $subscription->get_parent_id();
        }

        if ($parent_order_id <= 0) {
            return;
        }

        $parent_order = wc_get_order($parent_order_id);

        if (!($parent_order instanceof WC_Order)) {
            return;
        }

        $normalized_new = self::normalize_status($new_status);
        $normalized_old = self::normalize_status($old_status);
        $changed_at = gmdate('Y-m-d H:i:s', current_time('timestamp', true));

        $parent_order->update_meta_data('_wsz_subscription_status', $normalized_new);
        $parent_order->update_meta_data('_wsz_subscription_status_changed_at', $changed_at);
        $parent_order->save();

        $parent_order->add_order_note(
            sprintf(
                __('Subscription #%1$d status changed: %2$s -> %3$s.', 'woo-subzero'),
                (int) $subscription->get_id(),
                $normalized_old,
                $normalized_new
            )
        );
    }

    public function get_billing_interval($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 1;
        }

        $interval = (int) $subscription->get_meta('_wsz_billing_interval', true);

        if ($interval <= 0 && is_callable(array($subscription, 'get_billing_interval'))) {
            $interval = (int) $subscription->get_billing_interval();
        }

        return max(1, $interval);
    }

    public function get_billing_period($subscription): string
    {
        if (!($subscription instanceof WC_Order)) {
            return 'month';
        }

        $period = (string) $subscription->get_meta('_wsz_billing_period', true);

        if ('' === $period && is_callable(array($subscription, 'get_billing_period'))) {
            $period = (string) $subscription->get_billing_period();
        }

        $period = sanitize_key($period);

        if (!in_array($period, array('day', 'week', 'month', 'year'), true)) {
            $period = 'month';
        }

        return $period;
    }

    public function update_billing_profile($subscription, int $interval, string $period): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $interval = max(1, $interval);
        $period = sanitize_key($period);

        if (!in_array($period, array('day', 'week', 'month', 'year'), true)) {
            $period = 'month';
        }

        $subscription->update_meta_data('_wsz_billing_interval', $interval);
        $subscription->update_meta_data('_wsz_billing_period', $period);

        if (is_callable(array($subscription, 'set_billing_interval'))) {
            $subscription->set_billing_interval($interval);
        }

        if (is_callable(array($subscription, 'set_billing_period'))) {
            $subscription->set_billing_period($period);
        }

        $subscription->save();
    }

    public function get_cycle_length_in_seconds($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return DAY_IN_SECONDS * 30;
        }

        $interval = $this->get_billing_interval($subscription);
        $period = $this->get_billing_period($subscription);

        $test_cycle_minutes = $this->get_test_cycle_minutes();

        if ($test_cycle_minutes > 0) {
            return max(1, $interval * $test_cycle_minutes * 60);
        }

        $reference = current_time('timestamp', true);
        $next = strtotime(sprintf('+%d %s', $interval, $period), $reference);

        if ($next <= $reference) {
            return DAY_IN_SECONDS * 30;
        }

        return max(1, $next - $reference);
    }

    public function calculate_next_payment_from_timestamp($subscription, int $from_timestamp): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        $from_timestamp = max(1, $from_timestamp);

        $interval = $this->get_billing_interval($subscription);
        $period = $this->get_billing_period($subscription);

        return $this->calculate_next_payment_for_profile($from_timestamp, $interval, $period);
    }

    public function is_customer_subscription_owner($subscription, int $user_id): bool
    {
        if (!($subscription instanceof WC_Order) || $user_id <= 0) {
            return false;
        }

        return (int) $subscription->get_customer_id() === $user_id;
    }

    public function acquire_lock(string $name, int $id, int $ttl = 300): bool
    {
        $lock_key = self::OPTION_LOCK_PREFIX . sanitize_key($name) . '_' . $id;

        $lock = array(
            'acquired_at' => time(),
            'expires_at' => time() + max(30, $ttl),
        );

        return add_option($lock_key, $lock, '', 'no');
    }

    public function release_lock(string $name, int $id): void
    {
        $lock_key = self::OPTION_LOCK_PREFIX . sanitize_key($name) . '_' . $id;
        delete_option($lock_key);
    }

    public function get_end_timestamp($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        if (is_callable(array($subscription, 'get_time'))) {
            $timestamp = (int) $subscription->get_time('end');
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        if (is_callable(array($subscription, 'get_date'))) {
            $date = $subscription->get_date('end');
            if ($date instanceof WC_DateTime) {
                return (int) $date->getTimestamp();
            }
        }

        $end_date = (string) $subscription->get_meta('_wsz_end_date', true);

        if ('' === $end_date) {
            return 0;
        }

        $timestamp = strtotime($end_date . ' UTC');

        return $timestamp > 0 ? $timestamp : 0;
    }

    public function get_deferred_start_timestamp($subscription): int
    {
        if (!($subscription instanceof WC_Order)) {
            return 0;
        }

        $deferred_start = (string) $subscription->get_meta('_wsz_deferred_activation_at', true);

        if ('' === $deferred_start) {
            return 0;
        }

        $timestamp = strtotime($deferred_start . ' UTC');

        return $timestamp > 0 ? $timestamp : 0;
    }

    public function get_test_deferred_start_minutes(): int
    {
        $options = $this->get_options();

        if ('yes' !== $options['enable_test_mode']) {
            return 0;
        }

        if ('yes' !== $options['enable_test_deferred_start']) {
            return 0;
        }

        return max(1, (int) $options['test_deferred_start_minutes']);
    }

    public function get_deferred_activation_schedule_timestamp(int $requested_start_timestamp, int $reference_timestamp = 0): int
    {
        $requested_start_timestamp = max(1, $requested_start_timestamp);
        $reference_timestamp = $reference_timestamp > 0 ? $reference_timestamp : current_time('timestamp', true);

        if ($requested_start_timestamp <= $reference_timestamp) {
            return $requested_start_timestamp;
        }

        $test_deferred_start_minutes = $this->get_test_deferred_start_minutes();

        if ($test_deferred_start_minutes > 0) {
            return max(1, $reference_timestamp + ($test_deferred_start_minutes * 60));
        }

        return $requested_start_timestamp;
    }

    private function resolve_parent_order_for_subscription(WC_Order $subscription): ?WC_Order
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

    private function is_parent_order_paid_for_subscription_activation(WC_Order $order): bool
    {
        $status = sanitize_key((string) $order->get_status());

        if (in_array($status, array('processing', 'completed'), true)) {
            return true;
        }

        if (is_callable(array($order, 'is_paid'))) {
            return (bool) $order->is_paid();
        }

        return false;
    }

    private function copy_order_context(WC_Order $order, WC_Order $subscription): void
    {
        foreach ($order->get_items(array('line_item', 'fee', 'shipping', 'tax', 'coupon')) as $item) {
            $clone = clone $item;
            $clone->set_id(0);
            $subscription->add_item($clone);
        }

        $subscription->set_currency($order->get_currency());
        $subscription->set_prices_include_tax($order->get_prices_include_tax());
        $subscription->set_payment_method($order->get_payment_method());
        $subscription->set_payment_method_title($order->get_payment_method_title());
        $this->copy_payment_context_meta($order, $subscription);
        $subscription->set_billing_first_name($order->get_billing_first_name());
        $subscription->set_billing_last_name($order->get_billing_last_name());
        $subscription->set_billing_company($order->get_billing_company());
        $subscription->set_billing_address_1($order->get_billing_address_1());
        $subscription->set_billing_address_2($order->get_billing_address_2());
        $subscription->set_billing_city($order->get_billing_city());
        $subscription->set_billing_postcode($order->get_billing_postcode());
        $subscription->set_billing_country($order->get_billing_country());
        $subscription->set_billing_state($order->get_billing_state());
        $subscription->set_billing_email($order->get_billing_email());
        $subscription->set_billing_phone($order->get_billing_phone());

        $subscription->set_shipping_first_name($order->get_shipping_first_name());
        $subscription->set_shipping_last_name($order->get_shipping_last_name());
        $subscription->set_shipping_company($order->get_shipping_company());
        $subscription->set_shipping_address_1($order->get_shipping_address_1());
        $subscription->set_shipping_address_2($order->get_shipping_address_2());
        $subscription->set_shipping_city($order->get_shipping_city());
        $subscription->set_shipping_postcode($order->get_shipping_postcode());
        $subscription->set_shipping_country($order->get_shipping_country());
        $subscription->set_shipping_state($order->get_shipping_state());

        $subscription->set_total($order->get_total());
        $subscription->calculate_totals(false);
    }

    public function copy_payment_context_meta(WC_Order $source, WC_Order $target): bool
    {
        if (!is_callable(array($source, 'get_meta_data')) || !is_callable(array($target, 'update_meta_data'))) {
            return false;
        }

        $copied = false;
        $meta_rows = $source->get_meta_data();

        if (!is_array($meta_rows)) {
            return false;
        }

        foreach ($meta_rows as $meta_row) {
            $key = $this->get_meta_row_key($meta_row);

            if (!$this->is_payment_context_meta_key($key, $source, $target)) {
                continue;
            }

            $target->update_meta_data($key, $this->get_meta_row_value($meta_row));
            $copied = true;
        }

        return $copied;
    }

    private function get_meta_row_key($meta_row): string
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

    private function get_meta_row_value($meta_row)
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

    private function is_payment_context_meta_key(string $key, WC_Order $source, WC_Order $target): bool
    {
        $key = trim($key);

        if ('' === $key) {
            return false;
        }

        $copy_keys = apply_filters(
            'wsz_subs_payment_context_meta_keys',
            array(
                '_payment_token_id',
                '_payment_method',
                '_payment_method_title',
                '_transaction_id',
            ),
            $source,
            $target
        );

        if (is_array($copy_keys) && in_array($key, array_map('strval', $copy_keys), true)) {
            return true;
        }

        if (0 === strpos($key, '_wsz_')) {
            return false;
        }

        $normalized_key = strtolower($key);
        $patterns = apply_filters(
            'wsz_subs_payment_context_meta_key_patterns',
            array(
                'payment',
                'transaction',
                'token',
                'customerkey',
                'customer_key',
                'recurring',
                'mandate',
                'alias',
                'card',
            ),
            $source,
            $target
        );

        if (!is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $pattern = strtolower((string) $pattern);

            if ('' !== $pattern && false !== strpos($normalized_key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function get_options(): array
    {
        $defaults = array(
            'enable_test_mode' => 'no',
            'test_cycle_minutes' => 1,
            'enable_test_deferred_start' => 'yes',
            'test_deferred_start_minutes' => 1,
            'enable_test_cycle_notifications' => 'no',
        );

        return wp_parse_args((array) get_option('wsz_subs_options', array()), $defaults);
    }

    /**
     * Rebase start/next/end lifecycle markers at first activation when no explicit start date was selected.
     */
    private function maybe_rebase_lifecycle_from_activation(WC_Order $subscription, int $activated_at_timestamp): void
    {
        $requested_start_date = trim((string) $subscription->get_meta('_wsz_requested_start_date', true));

        if ('' !== $requested_start_date) {
            return;
        }

        $activated_at_timestamp = max(1, $activated_at_timestamp);
        $subscription->update_meta_data('_wsz_start_date', gmdate('Y-m-d H:i:s', $activated_at_timestamp));
        $subscription->save();

        $next_payment_timestamp = $this->calculate_next_payment_from_timestamp($subscription, $activated_at_timestamp);

        if ($next_payment_timestamp > 0) {
            $this->update_next_payment_timestamp($subscription, $next_payment_timestamp);
        }

        $subscription_length = $this->get_subscription_length($subscription);

        if ($subscription_length <= 0) {
            return;
        }

        $end_timestamp = $this->calculate_end_timestamp_for_profile(
            $activated_at_timestamp,
            $this->get_billing_interval($subscription),
            $this->get_billing_period($subscription),
            $subscription_length
        );

        if ($end_timestamp <= 0) {
            return;
        }

        $this->update_end_timestamp($subscription, $end_timestamp);
        $this->schedule_expiration((int) $subscription->get_id(), $end_timestamp);
    }

    private function ensure_test_mode_first_renewal_is_queued(WC_Order $subscription, int $next_payment_timestamp): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        $subscription_id = (int) $subscription->get_id();

        if ($subscription_id <= 0) {
            return;
        }

        $now = current_time('timestamp', true);

        if ($next_payment_timestamp <= $now) {
            $next_payment_timestamp = $now + 1;
        }

        $schedule_key = hash('sha256', $subscription_id . '|' . $next_payment_timestamp);
        $args = array(
            'subscription_id' => $subscription_id,
            'schedule_key' => $schedule_key,
        );

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('wsz_subs_process_renewal', $args, self::ACTION_GROUP)) {
            return;
        }

        $result = as_schedule_single_action(
            $next_payment_timestamp,
            'wsz_subs_process_renewal',
            $args,
            self::ACTION_GROUP,
            false
        );

        $action_id = is_numeric($result) ? (int) $result : (true === $result ? 1 : 0);

        if ($action_id <= 0) {
            return;
        }

        $subscription->update_meta_data('_wsz_next_schedule_key', $schedule_key);
        $subscription->save();
    }

    private static function normalize_status(string $status): string
    {
        return preg_replace('/^wc-/', '', sanitize_key($status));
    }
}

if (class_exists('WC_Order') && !class_exists('WSZ_Order_Subscription')) {
    class WSZ_Order_Subscription extends WC_Order
    {
        public function get_type(): string
        {
            return WSZ_Subscription_Manager::ORDER_TYPE;
        }
    }
}
