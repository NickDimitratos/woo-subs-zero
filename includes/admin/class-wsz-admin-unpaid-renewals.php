<?php

defined('ABSPATH') || exit;

/** Administrator review and individual payment email for existing renewal orders. */
final class WSZ_Admin_Unpaid_Renewals
{
    private const PAGE = 'wsz-unpaid-renewals';
    private WSZ_Subscription_Manager $manager;
    private WSZ_Customer_Subscriptions $customer;

    public function __construct(WSZ_Subscription_Manager $manager, WSZ_Customer_Subscriptions $customer)
    {
        $this->manager = $manager;
        $this->customer = $customer;
    }

    public function init(): void
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_wsz_send_unpaid_payment_email', array($this, 'handle_send'));
        add_filter('woocommerce_order_query_args', array($this, 'hpos_query'));
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', array($this, 'legacy_query'), 10, 2);
    }

    public function register_menu(): void
    {
        add_submenu_page('woocommerce', __('Unpaid Renewals', 'woo-subzero'), __('Unpaid Renewals', 'woo-subzero'), 'manage_woocommerce', self::PAGE, array($this, 'render'));
    }

    public function hpos_query(array $args): array
    {
        if (!empty($args['wsz_unpaid_renewals'])) {
            $args['meta_query'][] = array('key' => '_wsz_subscription_id', 'compare' => 'EXISTS');
            unset($args['wsz_unpaid_renewals']);
        }
        return $args;
    }

    public function legacy_query(array $query, array $vars): array
    {
        if (!empty($vars['wsz_unpaid_renewals'])) {
            $query['meta_query'][] = array('key' => '_wsz_subscription_id', 'compare' => 'EXISTS');
        }
        return $query;
    }

    private function message(string $state): string
    {
        $messages = array(
            'eligible' => __('Ready to send', 'woo-subzero'),
            'not_payable' => __('Not currently payable', 'woo-subzero'),
            'collection_pending' => __('Bank payment in progress — review before requesting payment', 'woo-subzero'),
            'subscription_review' => __('Subscription or customer link needs review', 'woo-subzero'),
            'automatic_renewal' => __('Automatic renewal — review payment retries', 'woo-subzero'),
            'invalid_email' => __('Customer email needs review', 'woo-subzero'),
            'email_disabled' => __('The renewal payment email is disabled in WooCommerce settings.', 'woo-subzero'),
            'busy' => __('Another notification is being processed. Try again shortly.', 'woo-subzero'),
            'send_failed' => __('The email could not be sent. Check the renewal order notes and mail configuration.', 'woo-subzero'),
            'sent' => __('Payment email sent to the customer.', 'woo-subzero'),
        );
        return $messages[$state] ?? __('This order needs review before an email can be sent.', 'woo-subzero');
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized.', 'woo-subzero'));
        }
        $page = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $notice = isset($_GET['wsz_result']) ? sanitize_key(wp_unslash($_GET['wsz_result'])) : '';
        $result = wc_get_orders(array(
            'type' => 'shop_order', 'status' => array('pending', 'failed', 'on-hold'),
            'limit' => 20, 'paged' => $page, 'paginate' => true,
            'orderby' => 'date', 'order' => 'DESC', 'wsz_unpaid_renewals' => true,
        ));
        echo '<div class="wrap"><h1>' . esc_html__('Unpaid Renewals', 'woo-subzero') . '</h1>';
        echo '<p>' . esc_html__('Review existing renewal orders and send a payment email individually. Email history may be incomplete for older orders; this action does not schedule a backdated reminder.', 'woo-subzero') . '</p>';
        if ('' !== $notice) {
            echo '<div class="notice ' . ('sent' === $notice ? 'notice-success' : 'notice-warning') . '"><p>' . esc_html($this->message($notice)) . '</p></div>';
        }
        echo '<div role="region" tabindex="0" aria-label="' . esc_attr__('Unpaid renewal orders', 'woo-subzero') . '" style="overflow-x:auto;"><table class="widefat striped" style="min-width:1000px;"><thead><tr>';
        foreach (array(__('Renewal order', 'woo-subzero'), __('Subscription', 'woo-subzero'), __('Customer', 'woo-subzero'), __('Order date', 'woo-subzero'), __('Amount', 'woo-subzero'), __('Order status', 'woo-subzero'), __('Email history', 'woo-subzero'), __('Action', 'woo-subzero')) as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $orders = is_object($result) && isset($result->orders) && is_array($result->orders) ? $result->orders : array();
        if (!$orders) {
            echo '<tr><td colspan="8">' . esc_html__('No renewal orders found on this page.', 'woo-subzero') . '</td></tr>';
        }
        foreach ($orders as $order) {
            if (!($order instanceof WC_Order)) continue;
            $subscription_id = (int) $order->get_meta('_wsz_subscription_id', true);
            $subscription = $this->manager->get_subscription($subscription_id);
            $state = $this->customer->admin_payment_eligibility($order);
            $date = $order->get_date_created();
            $history = 'yes' === $order->get_meta('_wsz_manual_renewal_notice_sent', true) ? __('Email recorded', 'woo-subzero') : __('No email recorded', 'woo-subzero');
            $sent_at = (int) $order->get_meta('_wsz_manual_renewal_admin_sent_at', true);
            if ($sent_at > 0) $history .= ' · ' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), $sent_at);
            echo '<tr><td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html((string) $order->get_order_number()) . '</a></td>';
            echo '<td>' . ($subscription instanceof WC_Order ? '<a href="' . esc_url($subscription->get_edit_order_url()) . '">#' . esc_html((string) $subscription->get_order_number()) . '</a>' : esc_html('#' . $subscription_id)) . '</td>';
            echo '<td>' . esc_html(trim($order->get_formatted_billing_full_name()) ?: __('Unknown', 'woo-subzero')) . '<br>' . esc_html((string) $order->get_billing_email()) . '</td>';
            echo '<td>' . esc_html($date ? $date->date_i18n(get_option('date_format')) : '—') . '</td><td>' . wp_kses_post($order->get_formatted_order_total()) . '</td>';
            echo '<td>' . esc_html(wc_get_order_status_name($order->get_status())) . '</td><td>' . esc_html($history) . '</td><td>';
            if ('eligible' === $state) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="wsz_send_unpaid_payment_email"><input type="hidden" name="order_id" value="' . esc_attr((string) $order->get_id()) . '"><input type="hidden" name="paged" value="' . esc_attr((string) $page) . '">';
                wp_nonce_field('wsz_send_unpaid_payment_email_' . $order->get_id());
                echo '<button type="submit" class="button" aria-label="' . esc_attr(sprintf(__('Send payment email for order %s', 'woo-subzero'), $order->get_order_number())) . '">' . esc_html__('Send payment email', 'woo-subzero') . '</button></form>';
            } else {
                echo esc_html($this->message($state));
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        $max = is_object($result) && isset($result->max_num_pages) ? (int) $result->max_num_pages : 1;
        $base = admin_url('admin.php?page=' . self::PAGE);
        echo '<p>' . esc_html(sprintf(__('Page %1$d of %2$d', 'woo-subzero'), $page, max(1, $max))) . ' ';
        if ($page > 1) echo '<a class="button" href="' . esc_url(add_query_arg('paged', $page - 1, $base)) . '">' . esc_html__('Previous', 'woo-subzero') . '</a> ';
        if ($page < $max) echo '<a class="button" href="' . esc_url(add_query_arg('paged', $page + 1, $base)) . '">' . esc_html__('Next', 'woo-subzero') . '</a>';
        echo '</p></div>';
    }

    public function handle_send(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Unauthorized.', 'woo-subzero'));
        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        check_admin_referer('wsz_send_unpaid_payment_email_' . $order_id);
        $page = isset($_POST['paged']) ? max(1, absint(wp_unslash($_POST['paged']))) : 1;
        $result = $this->customer->send_admin_payment_email($order_id);
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE, 'paged' => $page, 'wsz_result' => $result), admin_url('admin.php')));
        exit;
    }
}
