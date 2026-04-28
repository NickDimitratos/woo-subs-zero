<?php

defined('ABSPATH') || exit;

class WSZ_Customer_Actions_Manager
{
    private WSZ_Subscription_Manager $subscription_manager;

    private WSZ_Early_Renewal_Manager $early_renewal_manager;

    private WSZ_Switching_Manager $switching_manager;

    public function __construct(
        WSZ_Subscription_Manager $subscription_manager,
        WSZ_Early_Renewal_Manager $early_renewal_manager,
        WSZ_Switching_Manager $switching_manager
    ) {
        $this->subscription_manager = $subscription_manager;
        $this->early_renewal_manager = $early_renewal_manager;
        $this->switching_manager = $switching_manager;
    }

    public function init(): void
    {
        add_filter('wcs_view_subscription_actions', array($this, 'filter_subscription_actions'), 20, 2);
        add_action('admin_post_wsz_subs_subscription_action', array($this, 'handle_subscription_action_request'));

        add_action('woocommerce_subscription_status_updated', array($this, 'handle_role_transitions'), 20, 3);
    }

    /**
     * @param array<string,mixed> $actions
     * @param mixed $subscription
     * @return array<string,mixed>
     */
    public function filter_subscription_actions(array $actions, $subscription): array
    {
        if (!($subscription instanceof WC_Order)) {
            return $actions;
        }

        $user_id = get_current_user_id();

        if ($user_id <= 0 || !$this->subscription_manager->is_customer_subscription_owner($subscription, $user_id)) {
            return $actions;
        }

        $status = $subscription->get_status();
        $subscription_id = (int) $subscription->get_id();

        if (in_array($status, array('active', 'on-hold', 'pending-cancel'), true)) {
            $actions['wsz_change_payment_method'] = array(
                'url' => $this->get_action_url($subscription_id, 'change_payment_method'),
                'name' => __('Change payment method', 'woo-subzero'),
            );
        }

        if ('active' === $status) {
            $actions['wsz_cancel'] = array(
                'url' => $this->get_action_url($subscription_id, 'cancel'),
                'name' => __('Cancel', 'woo-subzero'),
            );

            $actions['wsz_suspend'] = array(
                'url' => $this->get_action_url($subscription_id, 'suspend'),
                'name' => __('Suspend', 'woo-subzero'),
            );
        }

        if ('on-hold' === $status) {
            $actions['wsz_reactivate'] = array(
                'url' => $this->get_action_url($subscription_id, 'reactivate'),
                'name' => __('Reactivate', 'woo-subzero'),
            );
        }

        if ($this->early_renewal_manager->is_early_renewal_enabled() &&
            apply_filters('woocommerce_subscriptions_can_user_renew_early', true, $subscription, $user_id, '')
        ) {
            $actions['wsz_renew_early'] = array(
                'url' => $this->get_action_url($subscription_id, 'renew_early'),
                'name' => __('Renew early', 'woo-subzero'),
            );
        }

        $failed_order = $this->find_latest_failed_renewal_order($subscription);

        if ($failed_order instanceof WC_Order && $failed_order->needs_payment()) {
            $actions['wsz_pay_failed_renewal'] = array(
                'url' => $this->get_action_url($subscription_id, 'pay_failed'),
                'name' => __('Pay failed renewal', 'woo-subzero'),
            );
        }

        if ($this->early_renewal_manager->is_resubscribe_enabled() &&
            in_array($status, array('cancelled', 'expired'), true)
        ) {
            $actions['wsz_resubscribe'] = array(
                'url' => $this->get_action_url($subscription_id, 'resubscribe'),
                'name' => __('Resubscribe', 'woo-subzero'),
            );
        }

        if ($this->switching_manager->is_switching_enabled() &&
            in_array($status, array('active', 'on-hold'), true)
        ) {
            $actions['wsz_switch'] = array(
                'url' => add_query_arg(
                    array('wsz_switch_subscription_id' => $subscription_id),
                    wc_get_page_permalink('shop')
                ),
                'name' => __('Switch plan', 'woo-subzero'),
            );
        }

        return $actions;
    }

    public function handle_subscription_action_request(): void
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Please sign in first.', 'woo-subzero'));
        }

        $subscription_id = isset($_REQUEST['subscription_id'])
            ? absint(wp_unslash($_REQUEST['subscription_id']))
            : 0;

        $action_name = isset($_REQUEST['wsz_action'])
            ? sanitize_key((string) wp_unslash($_REQUEST['wsz_action']))
            : '';

        check_admin_referer('wsz_subs_action_' . $action_name . '_' . $subscription_id);

        $subscription = $this->subscription_manager->get_subscription($subscription_id);

        if (!($subscription instanceof WC_Order)) {
            $this->redirect_back();
        }

        $current_user_id = get_current_user_id();
        $is_owner = $this->subscription_manager->is_customer_subscription_owner($subscription, $current_user_id);
        $is_admin = current_user_can('manage_woocommerce');

        if (!$is_owner && !$is_admin) {
            wp_die(esc_html__('You do not have permission for this subscription action.', 'woo-subzero'));
        }

        switch ($action_name) {
            case 'cancel':
                $this->subscription_manager->cancel_with_prepaid_term($subscription, false);
                break;

            case 'suspend':
                $this->handle_suspend_request($subscription);
                break;

            case 'reactivate':
                $this->handle_reactivate_request($subscription);
                break;

            case 'change_payment_method':
                wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
                exit;

            case 'pay_failed':
                $this->handle_pay_failed_request($subscription);
                break;

            case 'renew_early':
                $early_order = $this->early_renewal_manager->create_early_renewal_order($subscription);
                if ($early_order instanceof WC_Order && $early_order->needs_payment()) {
                    wp_safe_redirect($early_order->get_checkout_payment_url(true));
                    exit;
                }
                break;

            case 'resubscribe':
                $resubscribe_order = $this->early_renewal_manager->create_resubscribe_order($subscription);
                if ($resubscribe_order instanceof WC_Order && $resubscribe_order->needs_payment()) {
                    wp_safe_redirect($resubscribe_order->get_checkout_payment_url(true));
                    exit;
                }
                break;

            case 'switch':
                $this->handle_switch_request($subscription);
                break;

            default:
                break;
        }

        $this->redirect_back();
    }

    /**
     * @param WC_Order $subscription
     */
    public function handle_role_transitions($subscription, string $new_status, string $old_status): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $options = $this->get_options();

        if ('yes' !== $options['enable_role_transitions']) {
            return;
        }

        $customer_id = (int) $subscription->get_customer_id();

        if ($customer_id <= 0) {
            return;
        }

        $active_statuses = array('active', 'pending-cancel');
        $is_active_state = in_array($new_status, $active_statuses, true);

        $target_role = $is_active_state
            ? sanitize_key((string) $options['active_user_role'])
            : sanitize_key((string) $options['inactive_user_role']);

        if ('' === $target_role || !$this->is_valid_role($target_role)) {
            return;
        }

        $user = get_user_by('id', $customer_id);

        if (!($user instanceof WP_User)) {
            return;
        }

        if ($user->has_cap($target_role)) {
            return;
        }

        $user->set_role($target_role);
    }

    private function handle_suspend_request(WC_Order $subscription): void
    {
        if ('active' !== $subscription->get_status()) {
            return;
        }

        $options = $this->get_options();
        $limit = max(0, (int) $options['customer_suspension_limit']);

        $suspension_count = (int) $subscription->get_meta('_wsz_customer_suspension_count', true);

        if ($limit > 0 && $suspension_count >= $limit) {
            return;
        }

        $this->subscription_manager->transition_status(
            $subscription,
            'on-hold',
            __('Suspended by customer action.', 'woo-subzero')
        );

        $subscription->update_meta_data('_wsz_customer_suspension_count', $suspension_count + 1);
        $subscription->save();
    }

    private function handle_reactivate_request(WC_Order $subscription): void
    {
        if ('on-hold' !== $subscription->get_status()) {
            return;
        }

        $this->subscription_manager->transition_status(
            $subscription,
            'active',
            __('Reactivated by customer action.', 'woo-subzero')
        );

        do_action('wsz_subs_subscription_activated', $subscription);
    }

    private function handle_pay_failed_request(WC_Order $subscription): void
    {
        $failed_order = $this->find_latest_failed_renewal_order($subscription);

        if (!($failed_order instanceof WC_Order)) {
            return;
        }

        if ($failed_order->needs_payment()) {
            wp_safe_redirect($failed_order->get_checkout_payment_url(true));
            exit;
        }
    }

    private function handle_switch_request(WC_Order $subscription): void
    {
        if (!$this->switching_manager->is_switching_enabled()) {
            return;
        }

        $product_id = isset($_REQUEST['product_id']) ? absint(wp_unslash($_REQUEST['product_id'])) : 0;
        $variation_id = isset($_REQUEST['variation_id']) ? absint(wp_unslash($_REQUEST['variation_id'])) : 0;
        $quantity = isset($_REQUEST['quantity']) ? max(1, absint(wp_unslash($_REQUEST['quantity']))) : 1;

        $target_id = $variation_id > 0 ? $variation_id : $product_id;

        if ($target_id <= 0) {
            return;
        }

        $product = wc_get_product($target_id);

        if (!($product instanceof WC_Product)) {
            return;
        }

        $switch_order = $this->switching_manager->execute_switch($subscription, $product, $quantity);

        if ($switch_order instanceof WC_Order && $switch_order->needs_payment()) {
            wp_safe_redirect($switch_order->get_checkout_payment_url(true));
            exit;
        }
    }

    private function find_latest_failed_renewal_order(WC_Order $subscription): ?WC_Order
    {
        $order_ids = $this->subscription_manager->get_related_orders($subscription, 'renewal');

        if (empty($order_ids)) {
            return null;
        }

        rsort($order_ids);

        foreach ($order_ids as $order_id) {
            $order = wc_get_order((int) $order_id);

            if (!($order instanceof WC_Order)) {
                continue;
            }

            if ($order->has_status(array('failed', 'pending', 'on-hold'))) {
                return $order;
            }
        }

        return null;
    }

    private function get_action_url(int $subscription_id, string $action_name): string
    {
        $url = add_query_arg(
            array(
                'action' => 'wsz_subs_subscription_action',
                'subscription_id' => $subscription_id,
                'wsz_action' => $action_name,
            ),
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, 'wsz_subs_action_' . $action_name . '_' . $subscription_id);
    }

    private function is_valid_role(string $role): bool
    {
        if ('' === $role) {
            return false;
        }

        if (!function_exists('wp_roles')) {
            return false;
        }

        $roles = wp_roles();

        return isset($roles->roles[$role]);
    }

    private function redirect_back(): void
    {
        wp_safe_redirect(wp_get_referer() ?: wc_get_account_endpoint_url('subscriptions'));
        exit;
    }

    private function get_options(): array
    {
        $defaults = array(
            'customer_suspension_limit' => 2,
            'enable_role_transitions' => 'no',
            'active_user_role' => 'customer',
            'inactive_user_role' => '',
        );

        return wp_parse_args((array) get_option('wsz_subs_options', array()), $defaults);
    }
}
