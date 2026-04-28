<?php

defined('ABSPATH') || exit;

class WSZ_Early_Renewal_Manager
{
    private WSZ_Subscription_Manager $subscription_manager;

    public function __construct(WSZ_Subscription_Manager $subscription_manager)
    {
        $this->subscription_manager = $subscription_manager;
    }

    public function init(): void
    {
        add_filter('wcs_is_early_renewal_enabled', array($this, 'filter_early_renewal_enabled'), 10, 1);
        add_filter('woocommerce_subscriptions_can_user_renew_early', array($this, 'filter_can_user_renew_early'), 10, 4);
        add_filter('woocommerce_subscriptions_get_early_renewal_url', array($this, 'filter_early_renewal_url'), 10, 2);
        add_filter('woocommerce_subscriptions_is_early_renewal_order', array($this, 'filter_is_early_renewal_order'), 10, 2);

        add_action('admin_post_wsz_subs_early_renewal', array($this, 'handle_early_renewal_request'));
        add_action('admin_post_wsz_subs_resubscribe', array($this, 'handle_resubscribe_request'));

        add_action('wcs_resubscribe_order_created', array($this, 'handle_resubscribe_order_created'), 10, 2);
        add_action('woocommerce_order_status_changed', array($this, 'maybe_process_paid_early_renewal'), 20, 4);
    }

    public function is_early_renewal_enabled(): bool
    {
        $options = $this->get_options();

        return 'yes' === $options['enable_early_renewal'];
    }

    public function is_resubscribe_enabled(): bool
    {
        $options = $this->get_options();

        return 'yes' === $options['enable_resubscribe'];
    }

    public function get_early_renewal_url(int $subscription_id): string
    {
        $url = add_query_arg(
            array(
                'action' => 'wsz_subs_early_renewal',
                'subscription_id' => $subscription_id,
            ),
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, 'wsz_subs_early_renewal_' . $subscription_id);
    }

    public function get_resubscribe_url(int $subscription_id): string
    {
        $url = add_query_arg(
            array(
                'action' => 'wsz_subs_resubscribe',
                'subscription_id' => $subscription_id,
            ),
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, 'wsz_subs_resubscribe_' . $subscription_id);
    }

    public function filter_early_renewal_enabled(bool $enabled): bool
    {
        return $this->is_early_renewal_enabled();
    }

    /**
     * @param mixed $subscription
     * @param mixed $user_id
     * @param mixed $reason
     */
    public function filter_can_user_renew_early(bool $can_renew_early, $subscription, $user_id, $reason): bool
    {
        if (!$this->is_early_renewal_enabled()) {
            return false;
        }

        if (!($subscription instanceof WC_Order)) {
            return false;
        }

        $user_id = (int) $user_id;

        if ($user_id > 0 && !$this->subscription_manager->is_customer_subscription_owner($subscription, $user_id)) {
            return false;
        }

        if (!in_array($subscription->get_status(), array('active', 'pending-cancel'), true)) {
            return false;
        }

        $next_payment = $this->subscription_manager->get_next_payment_timestamp($subscription);

        if ($next_payment <= 0) {
            return false;
        }

        $options = $this->get_options();
        $window_days = max(0, (int) $options['early_renewal_window_days']);

        if ($window_days <= 0) {
            return true;
        }

        $window_seconds = $window_days * DAY_IN_SECONDS;

        return ($next_payment - current_time('timestamp', true)) <= $window_seconds;
    }

    public function filter_early_renewal_url(string $url, int $subscription_id): string
    {
        return $this->get_early_renewal_url($subscription_id);
    }

    /**
     * @param mixed $order
     */
    public function filter_is_early_renewal_order(bool $is_early_renewal, $order): bool
    {
        if (!($order instanceof WC_Order)) {
            return $is_early_renewal;
        }

        if ('yes' === $order->get_meta('_wsz_is_early_renewal_order', true)) {
            return true;
        }

        return $is_early_renewal;
    }

    public function handle_early_renewal_request(): void
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Please sign in first.', 'woo-subzero'));
        }

        $subscription_id = isset($_REQUEST['subscription_id']) ? absint(wp_unslash($_REQUEST['subscription_id'])) : 0;

        check_admin_referer('wsz_subs_early_renewal_' . $subscription_id);

        $subscription = $this->subscription_manager->get_subscription($subscription_id);

        if (!($subscription instanceof WC_Order)) {
            wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
            exit;
        }

        if (!$this->subscription_manager->is_customer_subscription_owner($subscription, get_current_user_id())) {
            wp_die(esc_html__('You do not own this subscription.', 'woo-subzero'));
        }

        if (!$this->filter_can_user_renew_early(true, $subscription, get_current_user_id(), 'manual_request')) {
            wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
            exit;
        }

        $renewal_order = $this->create_early_renewal_order($subscription);

        if ($renewal_order instanceof WC_Order && $renewal_order->needs_payment()) {
            wp_safe_redirect($renewal_order->get_checkout_payment_url(true));
            exit;
        }

        wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
        exit;
    }

    public function handle_resubscribe_request(): void
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Please sign in first.', 'woo-subzero'));
        }

        $subscription_id = isset($_REQUEST['subscription_id']) ? absint(wp_unslash($_REQUEST['subscription_id'])) : 0;

        check_admin_referer('wsz_subs_resubscribe_' . $subscription_id);

        $subscription = $this->subscription_manager->get_subscription($subscription_id);

        if (!($subscription instanceof WC_Order)) {
            wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
            exit;
        }

        if (!$this->subscription_manager->is_customer_subscription_owner($subscription, get_current_user_id())) {
            wp_die(esc_html__('You do not own this subscription.', 'woo-subzero'));
        }

        $resubscribe_order = $this->create_resubscribe_order($subscription);

        if ($resubscribe_order instanceof WC_Order && $resubscribe_order->needs_payment()) {
            wp_safe_redirect($resubscribe_order->get_checkout_payment_url(true));
            exit;
        }

        wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
        exit;
    }

    /**
     * @param WC_Order $subscription
     */
    public function create_early_renewal_order($subscription)
    {
        if (!($subscription instanceof WC_Order)) {
            return null;
        }

        if (!$this->is_early_renewal_enabled()) {
            return null;
        }

        $renewal_order = $this->create_order_from_subscription(
            $subscription,
            'woo-subzero-early-renewal',
            array(
                '_wsz_subscription_id' => $subscription->get_id(),
                '_wsz_is_early_renewal_order' => 'yes',
                '_wsz_early_renewal_processed' => 'no',
            )
        );

        if (!($renewal_order instanceof WC_Order)) {
            return null;
        }

        $this->subscription_manager->add_related_order($subscription, $renewal_order->get_id(), 'renewal');

        if (!$renewal_order->needs_payment() && !$renewal_order->is_paid()) {
            $renewal_order->payment_complete();
        }

        return $renewal_order;
    }

    /**
     * @param WC_Order $subscription
     */
    public function create_resubscribe_order($subscription)
    {
        if (!($subscription instanceof WC_Order)) {
            return null;
        }

        if (!$this->is_resubscribe_enabled()) {
            return null;
        }

        if (!in_array($subscription->get_status(), array('cancelled', 'expired'), true)) {
            return null;
        }

        $resubscribe_order = $this->create_order_from_subscription(
            $subscription,
            'woo-subzero-resubscribe',
            array(
                '_wsz_is_resubscribe_order' => 'yes',
                '_wsz_resubscribe_source_subscription_id' => $subscription->get_id(),
            )
        );

        if (!($resubscribe_order instanceof WC_Order)) {
            return null;
        }

        $next_payment = $this->subscription_manager->calculate_next_payment_from_timestamp(
            $subscription,
            current_time('timestamp', true)
        );

        $new_subscription = $this->subscription_manager->create_subscription_from_order(
            $resubscribe_order,
            array(
                'next_payment' => $next_payment,
                'billing_interval' => $this->subscription_manager->get_billing_interval($subscription),
                'billing_period' => $this->subscription_manager->get_billing_period($subscription),
                'subscription_length' => $this->subscription_manager->get_subscription_length($subscription),
                'start_timestamp' => current_time('timestamp', true),
            )
        );

        if ($new_subscription instanceof WC_Order) {
            $new_subscription->update_meta_data('_wsz_resubscribe_from_subscription_id', $subscription->get_id());
            $new_subscription->save();

            $this->subscription_manager->add_related_order($new_subscription, $resubscribe_order->get_id(), 'resubscribe');
            $this->subscription_manager->add_related_order($subscription, $resubscribe_order->get_id(), 'resubscribe');

            do_action('wcs_resubscribe_order_created', $resubscribe_order, $subscription);
        }

        if (!$resubscribe_order->needs_payment() && !$resubscribe_order->is_paid()) {
            $resubscribe_order->payment_complete();
        }

        return $resubscribe_order;
    }

    /**
     * @param WC_Order $resubscribe_order
     * @param WC_Order $subscription
     */
    public function handle_resubscribe_order_created($resubscribe_order, $subscription): void
    {
        if (!($resubscribe_order instanceof WC_Order) || !($subscription instanceof WC_Order)) {
            return;
        }

        $resubscribe_order->update_meta_data('_wsz_resubscribe_logged_at', current_time('mysql', true));
        $resubscribe_order->save();
    }

    /**
     * @param mixed $order
     */
    public function maybe_process_paid_early_renewal(int $order_id, string $old_status, string $new_status, $order): void
    {
        if (!in_array($new_status, array('processing', 'completed'), true)) {
            return;
        }

        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);
        }

        if (!($order instanceof WC_Order)) {
            return;
        }

        if ('yes' !== $order->get_meta('_wsz_is_early_renewal_order', true)) {
            return;
        }

        if ('yes' === $order->get_meta('_wsz_early_renewal_processed', true)) {
            return;
        }

        $subscription = $this->subscription_manager->get_subscription(
            (int) $order->get_meta('_wsz_subscription_id', true)
        );

        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $current_next = $this->subscription_manager->get_next_payment_timestamp($subscription);
        $base_timestamp = max(current_time('timestamp', true), $current_next);
        $new_next = $this->subscription_manager->calculate_next_payment_from_timestamp($subscription, $base_timestamp);

        if ($new_next > 0) {
            $this->subscription_manager->update_next_payment_timestamp($subscription, $new_next);
        }

        if (!in_array($subscription->get_status(), array('active', 'pending-cancel'), true)) {
            try {
                $this->subscription_manager->transition_status(
                    $subscription,
                    'active',
                    __('Subscription reactivated by early renewal payment.', 'woo-subzero')
                );
            } catch (Throwable $throwable) {
                wc_get_logger()->warning(
                    $throwable->getMessage(),
                    array('source' => 'woo-subzero')
                );
            }
        }

        $order->update_meta_data('_wsz_early_renewal_processed', 'yes');
        $order->save();

        do_action('woocommerce_subscription_renewal_payment_complete', $subscription, $order);
        do_action('wsz_subs_subscription_activated', $subscription);
    }

    private function create_order_from_subscription(WC_Order $subscription, string $created_via, array $meta): ?WC_Order
    {
        if (!function_exists('wc_create_order')) {
            return null;
        }

        $order = wc_create_order(
            array(
                'customer_id' => $subscription->get_customer_id(),
                'parent' => $subscription->get_id(),
                'created_via' => $created_via,
            )
        );

        if (!($order instanceof WC_Order)) {
            return null;
        }

        foreach ($subscription->get_items(array('line_item', 'fee', 'shipping', 'tax', 'coupon')) as $item) {
            $clone = clone $item;
            $clone->set_id(0);
            $order->add_item($clone);
        }

        $order->set_currency($subscription->get_currency());
        $order->set_payment_method($subscription->get_payment_method());
        $order->set_payment_method_title($subscription->get_payment_method_title());
        $order->set_total($subscription->get_total());
        $order->calculate_totals(false);

        foreach ($meta as $key => $value) {
            $order->update_meta_data($key, $value);
        }

        $order->save();

        return $order;
    }

    private function get_options(): array
    {
        $defaults = array(
            'enable_early_renewal' => 'yes',
            'enable_resubscribe' => 'yes',
            'early_renewal_window_days' => 30,
        );

        return wp_parse_args((array) get_option('wsz_subs_options', array()), $defaults);
    }
}
