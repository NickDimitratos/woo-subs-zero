<?php

defined('ABSPATH') || exit;

class WSZ_Switching_Manager
{
    private WSZ_Subscription_Manager $subscription_manager;

    public function __construct(WSZ_Subscription_Manager $subscription_manager)
    {
        $this->subscription_manager = $subscription_manager;
    }

    public function init(): void
    {
        add_filter('wcs_switch_should_prorate_recurring_price', array($this, 'filter_prorate_recurring_price'), 10, 2);
        add_filter('wcs_switch_should_prorate_sign_up_fee', array($this, 'filter_prorate_sign_up_fee'), 10, 2);
        add_filter('wcs_switch_proration_extra_to_pay', array($this, 'filter_proration_extra_to_pay'), 10, 4);
        add_filter('wcs_switch_proration_days_in_old_cycle', array($this, 'filter_days_in_old_cycle'), 10, 3);
        add_filter('wcs_switch_proration_days_in_new_cycle', array($this, 'filter_days_in_new_cycle'), 10, 4);

        add_action('woocommerce_subscriptions_switch_completed', array($this, 'handle_switch_completed'), 10, 1);
        add_action('admin_post_wsz_subs_request_switch', array($this, 'handle_switch_request'));
    }

    public function is_switching_enabled(): bool
    {
        $options = $this->get_options();

        return 'yes' === $options['enable_switching'];
    }

    public function get_switch_url(int $subscription_id, int $product_id, int $quantity = 1, int $variation_id = 0): string
    {
        $url = add_query_arg(
            array(
                'action' => 'wsz_subs_request_switch',
                'subscription_id' => $subscription_id,
                'product_id' => $product_id,
                'variation_id' => max(0, $variation_id),
                'quantity' => max(1, $quantity),
            ),
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, 'wsz_subs_switch_' . $subscription_id);
    }

    public function calculate_proration_breakdown(
        float $old_recurring_total,
        float $new_recurring_total,
        int $seconds_remaining,
        int $cycle_seconds,
        bool $prorate_recurring,
        bool $prorate_signup_fee,
        float $new_signup_fee = 0.0
    ): array {
        $cycle_seconds = max(1, $cycle_seconds);
        $seconds_remaining = max(0, min($seconds_remaining, $cycle_seconds));

        $old_cents = (int) round($old_recurring_total * 100);
        $new_cents = (int) round($new_recurring_total * 100);

        $old_credit_cents = 0;
        $new_charge_cents = 0;

        if ($prorate_recurring) {
            $old_credit_cents = (int) round(($old_cents / $cycle_seconds) * $seconds_remaining);
            $new_charge_cents = (int) round(($new_cents / $cycle_seconds) * $seconds_remaining);
        }

        $signup_fee_cents = (int) round(max(0, $new_signup_fee) * 100);

        if ($signup_fee_cents > 0 && $prorate_signup_fee) {
            $signup_fee_cents = (int) round(($signup_fee_cents / $cycle_seconds) * $seconds_remaining);
        }

        $net_cents = $new_charge_cents - $old_credit_cents + $signup_fee_cents;

        return array(
            'old_credit_cents' => max(0, $old_credit_cents),
            'new_charge_cents' => max(0, $new_charge_cents),
            'signup_fee_cents' => max(0, $signup_fee_cents),
            'extra_to_pay_cents' => max(0, $net_cents),
            'credit_cents' => max(0, -$net_cents),
        );
    }

    /**
     * @param WC_Order $subscription
     * @param WC_Product $new_product
     */
    public function execute_switch($subscription, $new_product, int $quantity = 1)
    {
        if (!($subscription instanceof WC_Order) || !($new_product instanceof WC_Product)) {
            return null;
        }

        if (!$this->is_switching_enabled()) {
            return null;
        }

        $quantity = max(1, $quantity);
        $next_payment = $this->subscription_manager->get_next_payment_timestamp($subscription);

        $seconds_remaining = max(0, $next_payment - current_time('timestamp', true));
        $cycle_seconds = max(1, $this->subscription_manager->get_cycle_length_in_seconds($subscription));

        $new_recurring_total = (float) $new_product->get_price() * $quantity;
        $new_signup_fee = $this->get_signup_fee($new_product);

        $options = $this->get_options();

        $breakdown = $this->calculate_proration_breakdown(
            (float) $subscription->get_total(),
            $new_recurring_total,
            $seconds_remaining,
            $cycle_seconds,
            'yes' === $options['prorate_recurring'],
            'yes' === $options['prorate_signup_fee'],
            $new_signup_fee
        );

        $switch_order = $this->create_switch_order($subscription, $breakdown);

        if (!($switch_order instanceof WC_Order)) {
            return null;
        }

        $this->apply_subscription_product_switch($subscription, $new_product, $quantity, $options);

        $subscription->add_order_note(
            sprintf(
                __('Subscription switched to product %1$d. Proration charge: %2$s', 'woo-subzero'),
                $new_product->get_id(),
                wc_price($breakdown['extra_to_pay_cents'] / 100)
            )
        );

        $switch_order->add_order_note(
            sprintf(
                __('Switch created from subscription %d.', 'woo-subzero'),
                $subscription->get_id()
            )
        );

        $this->subscription_manager->add_related_order($subscription, $switch_order->get_id(), 'switch');

        do_action('woocommerce_subscriptions_switch_completed', $switch_order);
        do_action('wsz_subs_switch_completed', $subscription, $switch_order, $breakdown);

        return $switch_order;
    }

    /**
     * @param WC_Order $switch_order
     */
    public function handle_switch_completed($switch_order): void
    {
        if (!($switch_order instanceof WC_Order)) {
            return;
        }

        $switch_order->update_meta_data('_wsz_switch_completed_at', current_time('mysql', true));
        $switch_order->save();
    }

    public function handle_switch_request(): void
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Please sign in first.', 'woo-subzero'));
        }

        $subscription_id = isset($_REQUEST['subscription_id']) ? absint(wp_unslash($_REQUEST['subscription_id'])) : 0;
        $product_id = isset($_REQUEST['product_id']) ? absint(wp_unslash($_REQUEST['product_id'])) : 0;
        $variation_id = isset($_REQUEST['variation_id']) ? absint(wp_unslash($_REQUEST['variation_id'])) : 0;
        $quantity = isset($_REQUEST['quantity']) ? max(1, absint(wp_unslash($_REQUEST['quantity']))) : 1;

        check_admin_referer('wsz_subs_switch_' . $subscription_id);

        $subscription = $this->subscription_manager->get_subscription($subscription_id);

        if (!($subscription instanceof WC_Order)) {
            wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
            exit;
        }

        if ((int) $subscription->get_customer_id() !== get_current_user_id()) {
            wp_die(esc_html__('You do not own this subscription.', 'woo-subzero'));
        }

        $target_product_id = $variation_id > 0 ? $variation_id : $product_id;
        $target_product = wc_get_product($target_product_id);

        if (!($target_product instanceof WC_Product)) {
            wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
            exit;
        }

        $switch_order = $this->execute_switch($subscription, $target_product, $quantity);

        if ($switch_order instanceof WC_Order && $switch_order->needs_payment()) {
            wp_safe_redirect($switch_order->get_checkout_payment_url(true));
            exit;
        }

        wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
        exit;
    }

    /**
     * @param mixed $switch_item
     */
    public function filter_prorate_recurring_price(bool $should_prorate, $switch_item): bool
    {
        $options = $this->get_options();

        if ('yes' !== $options['enable_proration']) {
            return false;
        }

        return 'yes' === $options['prorate_recurring'] ? true : $should_prorate;
    }

    /**
     * @param mixed $switch_item
     */
    public function filter_prorate_sign_up_fee(bool $should_prorate, $switch_item): bool
    {
        $options = $this->get_options();

        if ('yes' !== $options['enable_proration']) {
            return false;
        }

        return 'yes' === $options['prorate_signup_fee'] ? true : $should_prorate;
    }

    /**
     * @param mixed $cart_item
     * @param mixed $days_in_old_cycle
     */
    public function filter_proration_extra_to_pay($extra_to_pay, $subscription, $cart_item, $days_in_old_cycle): float
    {
        $options = $this->get_options();

        if ('yes' !== $options['enable_proration']) {
            return (float) $extra_to_pay;
        }

        $free_window_days = max(0, (int) $options['free_switch_window_days']);

        if ($free_window_days > 0 && $subscription instanceof WC_Order) {
            $created_timestamp = $subscription->get_date_created()
                ? $subscription->get_date_created()->getTimestamp()
                : 0;

            if ($created_timestamp > 0) {
                $age_days = floor((current_time('timestamp', true) - $created_timestamp) / DAY_IN_SECONDS);

                if ($age_days <= $free_window_days) {
                    return 0.0;
                }
            }
        }

        return max(0, (float) $extra_to_pay);
    }

    /**
     * @param WC_Order $subscription
     * @param mixed $cart_item
     */
    public function filter_days_in_old_cycle($days, $subscription, $cart_item): int
    {
        $options = $this->get_options();

        if ('yes' !== $options['proration_subscription_length']) {
            return (int) $days;
        }

        if (!($subscription instanceof WC_Order)) {
            return (int) $days;
        }

        return (int) floor($this->subscription_manager->get_cycle_length_in_seconds($subscription) / DAY_IN_SECONDS);
    }

    /**
     * @param WC_Order $subscription
     * @param mixed $cart_item
     * @param mixed $days_in_old_cycle
     */
    public function filter_days_in_new_cycle($days, $subscription, $cart_item, $days_in_old_cycle): int
    {
        $options = $this->get_options();

        if ('yes' !== $options['proration_subscription_length']) {
            return (int) $days;
        }

        if (!($subscription instanceof WC_Order)) {
            return (int) $days;
        }

        $period = $this->subscription_manager->get_billing_period($subscription);
        $interval = $this->subscription_manager->get_billing_interval($subscription);

        if ('month' === $period) {
            return 30 * $interval;
        }

        if ('year' === $period) {
            return 365 * $interval;
        }

        return (int) $days;
    }

    private function create_switch_order(WC_Order $subscription, array $breakdown): ?WC_Order
    {
        if (!function_exists('wc_create_order')) {
            return null;
        }

        $switch_order = wc_create_order(
            array(
                'customer_id' => $subscription->get_customer_id(),
                'parent' => $subscription->get_id(),
                'created_via' => 'woo-subzero-switch',
            )
        );

        if (!($switch_order instanceof WC_Order)) {
            return null;
        }

        $switch_order->set_payment_method($subscription->get_payment_method());
        $switch_order->set_payment_method_title($subscription->get_payment_method_title());
        $switch_order->set_currency($subscription->get_currency());

        $amount = $breakdown['extra_to_pay_cents'] / 100;

        if ($amount > 0) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name(__('Switch proration charge', 'woo-subzero'));
            $fee->set_total($amount);
            $switch_order->add_item($fee);
        }

        $switch_order->calculate_totals(false);
        $switch_order->update_meta_data('_wsz_switch_breakdown', wp_json_encode($breakdown));

        if ($amount > 0) {
            $switch_order->set_status('pending');
        } else {
            $switch_order->set_status('completed');
        }

        $switch_order->save();

        return $switch_order;
    }

    private function apply_subscription_product_switch(
        WC_Order $subscription,
        WC_Product $new_product,
        int $quantity,
        array $options
    ): void {
        foreach ($subscription->get_items('line_item') as $item_id => $item) {
            $subscription->remove_item($item_id);
        }

        $item = new WC_Order_Item_Product();
        $item->set_product($new_product);
        $item->set_quantity($quantity);

        $line_total = (float) $new_product->get_price() * $quantity;

        $item->set_subtotal($line_total);
        $item->set_total($line_total);

        $subscription->add_item($item);
        $subscription->set_total($line_total);

        $this->sync_billing_profile_from_product($subscription, $new_product, $options);

        $subscription->calculate_totals(false);
        $subscription->save();
    }

    private function sync_billing_profile_from_product(WC_Order $subscription, WC_Product $new_product, array $options): void
    {
        $interval = max(
            1,
            (int) $new_product->get_meta('_subscription_period_interval', true),
            (int) $new_product->get_meta('_wsz_subscription_interval', true)
        );

        $period = (string) $new_product->get_meta('_subscription_period', true);

        if ('' === $period) {
            $period = (string) $new_product->get_meta('_wsz_subscription_period', true);
        }

        if ('' !== $period) {
            $this->subscription_manager->update_billing_profile($subscription, $interval, $period);
        }

        $sync_day = (int) $new_product->get_meta('_subscription_payment_sync_date', true);

        if ($sync_day > 0) {
            $subscription->update_meta_data('_wsz_sync_day', min(31, max(1, $sync_day)));
        }

        if ('yes' !== $options['proration_subscription_length']) {
            return;
        }

        $length = (int) $new_product->get_meta('_subscription_length', true);

        if ($length <= 0) {
            return;
        }

        $base_period = $period ?: $this->subscription_manager->get_billing_period($subscription);
        $end_timestamp = $this->subscription_manager->calculate_end_timestamp_for_profile(
            current_time('timestamp', true),
            $interval,
            sanitize_key($base_period),
            $length
        );

        if ($end_timestamp <= 0) {
            return;
        }

        $end_date = gmdate('Y-m-d H:i:s', $end_timestamp);

        if (is_callable(array($subscription, 'update_dates'))) {
            try {
                $subscription->update_dates(array('end' => $end_date), 'gmt');
            } catch (Throwable $throwable) {
                wc_get_logger()->warning(
                    $throwable->getMessage(),
                    array('source' => 'woo-subzero')
                );
            }
        }

        $subscription->update_meta_data('_wsz_end_date', $end_date);
    }

    private function get_signup_fee(WC_Product $product): float
    {
        $fee = (float) $product->get_meta('_subscription_sign_up_fee', true);

        if ($fee <= 0) {
            $fee = (float) $product->get_meta('_wsz_signup_fee', true);
        }

        return max(0, $fee);
    }

    private function get_options(): array
    {
        $defaults = array(
            'enable_switching' => 'no',
            'enable_proration' => 'yes',
            'prorate_recurring' => 'yes',
            'prorate_signup_fee' => 'yes',
            'proration_subscription_length' => 'yes',
            'free_switch_window_days' => 0,
        );

        return wp_parse_args((array) get_option('wsz_subs_options', array()), $defaults);
    }
}
