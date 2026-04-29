<?php

defined('ABSPATH') || exit;

class WSZ_Admin_Subscriptions
{
    private const FINITE_TERM_CLEANUP_OPTION_KEY = 'wsz_subs_finite_term_cleanup_completed_at';

    private WSZ_Subscription_Manager $subscription_manager;

    private int $finite_cleanup_completed_at_runtime = 0;

    public function __construct(WSZ_Subscription_Manager $subscription_manager)
    {
        $this->subscription_manager = $subscription_manager;
    }

    public function init(): void
    {
        add_filter('manage_edit-shop_subscription_columns', array($this, 'filter_subscription_list_columns'));
        add_action('manage_shop_subscription_posts_custom_column', array($this, 'render_subscription_list_custom_column'), 10, 2);

        add_filter('woocommerce_' . WSZ_Subscription_Manager::ORDER_TYPE . '_list_table_columns', array($this, 'filter_subscription_list_columns'));
        add_action('woocommerce_' . WSZ_Subscription_Manager::ORDER_TYPE . '_list_table_custom_column', array($this, 'render_subscription_hpos_custom_column'), 10, 2);

        add_action('add_meta_boxes', array($this, 'register_renewals_meta_box'));
        add_action('admin_notices', array($this, 'render_finite_term_cleanup_notice'));

        add_filter('post_row_actions', array($this, 'add_toggle_manual_renewal_action'), 10, 2);
        add_action('admin_post_wsz_subs_toggle_manual_renewal', array($this, 'handle_toggle_manual_renewal'));
        add_action('admin_post_wsz_subs_cleanup_finite_term_renewals', array($this, 'handle_finite_term_cleanup'));
    }

    public function render_finite_term_cleanup_notice(): void
    {
        if (!current_user_can('manage_woocommerce') || !$this->is_subscription_admin_screen()) {
            return;
        }

        $status = isset($_GET['wsz_subs_cleanup_status'])
            ? sanitize_key((string) wp_unslash($_GET['wsz_subs_cleanup_status']))
            : '';

        if ('completed' === $status) {
            $unscheduled = isset($_GET['wsz_subs_cleanup_unscheduled']) ? absint($_GET['wsz_subs_cleanup_unscheduled']) : 0;
            $expired = isset($_GET['wsz_subs_cleanup_expired']) ? absint($_GET['wsz_subs_cleanup_expired']) : 0;

            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(
                sprintf(
                    __('One-time finite-term cleanup completed. Removed %1$d pending renewal action(s) and finalized %2$d subscription(s).', 'woo-subzero'),
                    $unscheduled,
                    $expired
                )
            );
            echo '</p></div>';
            return;
        }

        if ('already-completed' === $status) {
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo esc_html__('One-time finite-term cleanup was already completed previously.', 'woo-subzero');
            echo '</p></div>';
            return;
        }

        if ($this->get_finite_cleanup_completed_timestamp() > 0) {
            return;
        }

        $run_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'wsz_subs_cleanup_finite_term_renewals',
                ),
                admin_url('admin-post.php')
            ),
            'wsz_subs_cleanup_finite_term_renewals'
        );

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('One-time safety cleanup is available to remove any stale pending renewal jobs that could create extra payments after finite plans end.', 'woo-subzero');
        echo ' <a class="button button-secondary" href="' . esc_url($run_url) . '">';
        echo esc_html__('Run One-Time Cleanup', 'woo-subzero');
        echo '</a>';
        echo '</p></div>';
    }

    public function handle_finite_term_cleanup(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized.', 'woo-subzero'));
        }

        check_admin_referer('wsz_subs_cleanup_finite_term_renewals');

        $report = $this->run_finite_term_cleanup();

        $redirect_url = wp_get_referer();

        if (!is_string($redirect_url) || '' === trim($redirect_url)) {
            $redirect_url = admin_url('edit.php?post_type=shop_subscription');
        }

        $redirect_url = add_query_arg(
            array(
                'wsz_subs_cleanup_status' => $report['already_completed'] ? 'already-completed' : 'completed',
                'wsz_subs_cleanup_unscheduled' => (int) ($report['unscheduled_actions'] ?? 0),
                'wsz_subs_cleanup_expired' => (int) ($report['expired_subscriptions'] ?? 0),
            ),
            $redirect_url
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * @return array<string,int|bool>
     */
    public function run_finite_term_cleanup(bool $force = false): array
    {
        $report = array(
            'already_completed' => false,
            'scanned_actions' => 0,
            'eligible_actions' => 0,
            'unscheduled_actions' => 0,
            'expired_subscriptions' => 0,
            'completed_at' => 0,
        );

        $completed_at = $this->get_finite_cleanup_completed_timestamp();

        if (!$force && $completed_at > 0) {
            $report['already_completed'] = true;
            $report['completed_at'] = $completed_at;

            return $report;
        }

        if (!function_exists('as_get_scheduled_actions')) {
            $report['completed_at'] = $this->mark_finite_cleanup_completed();
            return $report;
        }

        $actions = $this->get_pending_renewal_actions_for_cleanup();

        $report['scanned_actions'] = count($actions);

        if (empty($actions)) {
            $report['completed_at'] = $this->mark_finite_cleanup_completed();
            return $report;
        }

        $now_timestamp = current_time('timestamp', true);
        $expired_subscription_ids = array();

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $args = $action['args'] ?? array();

            if (is_string($args)) {
                $decoded = json_decode($args, true);
                $args = is_array($decoded) ? $decoded : array();
            }

            if (!is_array($args)) {
                $args = array();
            }

            $subscription_id = (int) ($args['subscription_id'] ?? 0);

            if ($subscription_id <= 0) {
                if ($this->unschedule_renewal_action($args)) {
                    $report['unscheduled_actions']++;
                }

                continue;
            }

            $subscription = $this->subscription_manager->get_subscription($subscription_id);

            if (!($subscription instanceof WC_Order)) {
                if ($this->unschedule_renewal_action($args)) {
                    $report['unscheduled_actions']++;
                }

                continue;
            }

            $end_timestamp = $this->subscription_manager->get_end_timestamp($subscription);

            if ($end_timestamp <= 0 || $now_timestamp < $end_timestamp) {
                continue;
            }

            $report['eligible_actions']++;

            if ($this->unschedule_renewal_action($args)) {
                $report['unscheduled_actions']++;
            }

            if (isset($expired_subscription_ids[$subscription_id])) {
                continue;
            }

            $this->subscription_manager->update_next_payment_timestamp($subscription, $end_timestamp);
            $this->subscription_manager->process_expiration($subscription_id);

            if (is_callable(array($subscription, 'update_meta_data')) && is_callable(array($subscription, 'save'))) {
                $subscription->update_meta_data('_wsz_next_schedule_key', '');
                $subscription->save();
            }

            $expired_subscription_ids[$subscription_id] = true;
            $report['expired_subscriptions']++;
        }

        $report['completed_at'] = $this->mark_finite_cleanup_completed();

        return $report;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function get_pending_renewal_actions_for_cleanup(): array
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return array();
        }

        $actions = array();
        $offset = 0;
        $per_page = 100;

        for ($page = 0; $page < 50; $page++) {
            $batch = as_get_scheduled_actions(
                array(
                    'hook' => 'wsz_subs_process_renewal',
                    'group' => WSZ_Subscription_Manager::ACTION_GROUP,
                    'status' => 'pending',
                    'per_page' => $per_page,
                    'offset' => $offset,
                ),
                'ARRAY_A'
            );

            if (!is_array($batch) || empty($batch)) {
                break;
            }

            foreach ($batch as $action) {
                if (is_array($action)) {
                    $actions[] = $action;
                }
            }

            $batch_count = count($batch);

            if ($batch_count < $per_page) {
                break;
            }

            $offset += $batch_count;
        }

        return $actions;
    }

    private function unschedule_renewal_action(array $args): bool
    {
        if (function_exists('as_unschedule_action')) {
            $result = as_unschedule_action(
                'wsz_subs_process_renewal',
                $args,
                WSZ_Subscription_Manager::ACTION_GROUP
            );

            return is_numeric($result) ? ((int) $result > 0) : (true === $result);
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(
                'wsz_subs_process_renewal',
                $args,
                WSZ_Subscription_Manager::ACTION_GROUP
            );

            return true;
        }

        return false;
    }

    private function get_finite_cleanup_completed_timestamp(): int
    {
        $stored = 0;

        if (function_exists('get_option')) {
            $stored = (int) get_option(self::FINITE_TERM_CLEANUP_OPTION_KEY, 0);
        }

        if ($stored > 0) {
            return $stored;
        }

        return $this->finite_cleanup_completed_at_runtime;
    }

    private function mark_finite_cleanup_completed(): int
    {
        $completed_at = current_time('timestamp', true);

        $this->finite_cleanup_completed_at_runtime = $completed_at;

        if (function_exists('update_option')) {
            update_option(self::FINITE_TERM_CLEANUP_OPTION_KEY, $completed_at, false);
        }

        return $completed_at;
    }

    private function is_subscription_admin_screen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        if (!is_object($screen) || !isset($screen->id)) {
            return false;
        }

        $screen_id = (string) $screen->id;
        $supported_screens = array('edit-shop_subscription', 'shop_subscription');

        if (function_exists('wc_get_page_screen_id')) {
            $hpos_screen_id = wc_get_page_screen_id(WSZ_Subscription_Manager::ORDER_TYPE);

            if (is_string($hpos_screen_id) && '' !== $hpos_screen_id) {
                $supported_screens[] = $hpos_screen_id;
            }
        }

        return in_array($screen_id, array_unique($supported_screens), true);
    }

    public function register_renewals_meta_box(): void
    {
        $screens = array('shop_subscription');

        if (function_exists('wc_get_page_screen_id')) {
            $hpos_screen = wc_get_page_screen_id(WSZ_Subscription_Manager::ORDER_TYPE);

            if (is_string($hpos_screen) && '' !== $hpos_screen) {
                $screens[] = $hpos_screen;
            }
        }

        foreach (array_unique($screens) as $screen_id) {
            $this->remove_order_style_meta_boxes((string) $screen_id);

            add_meta_box(
                'wsz_subs_upcoming_renewals',
                __('Upcoming Renewals', 'woo-subzero'),
                array($this, 'render_upcoming_renewals_meta_box'),
                $screen_id,
                'normal',
                'default'
            );

            add_meta_box(
                'wsz_subs_test_card_transactions',
                __('WSZ Test Card Transactions', 'woo-subzero'),
                array($this, 'render_test_card_transactions_meta_box'),
                $screen_id,
                'normal',
                'default'
            );

            add_meta_box(
                'wsz_subs_meta_keys',
                __('Subscription Meta Keys', 'woo-subzero'),
                array($this, 'render_subscription_meta_keys_meta_box'),
                $screen_id,
                'side',
                'high'
            );
        }
    }

    private function remove_order_style_meta_boxes(string $screen_id): void
    {
        if ('' === $screen_id || !function_exists('remove_meta_box')) {
            return;
        }

        $meta_boxes_to_remove = array(
            array('id' => 'woocommerce-order-data', 'context' => 'normal'),
            array('id' => 'woocommerce-order-items', 'context' => 'normal'),
            array('id' => 'woocommerce-order-downloads', 'context' => 'normal'),
            array('id' => 'woocommerce-order-actions', 'context' => 'side'),
            array('id' => 'woocommerce-order-notes', 'context' => 'side'),
        );

        foreach ($meta_boxes_to_remove as $meta_box) {
            remove_meta_box(
                (string) ($meta_box['id'] ?? ''),
                $screen_id,
                (string) ($meta_box['context'] ?? 'normal')
            );
        }
    }

    /**
     * @param mixed $post_or_order
     */
    public function render_upcoming_renewals_meta_box($post_or_order): void
    {
        $subscription = $this->resolve_subscription_from_meta_box_subject($post_or_order);

        if (!($subscription instanceof WC_Order)) {
            echo '<p>' . esc_html__('Subscription context not available.', 'woo-subzero') . '</p>';
            return;
        }

        $subscription_id = (int) $subscription->get_id();
        $subscription_status = sanitize_key((string) $subscription->get_status());
        $now_timestamp = current_time('timestamp', true);
        $next_payment_timestamp = $this->subscription_manager->get_next_payment_timestamp($subscription);
        $renewal_order_ids = $this->subscription_manager->get_related_orders($subscription, 'renewal');
        $scheduled = $this->get_upcoming_renewals($subscription_id);
        $recovery_scheduled = false;

        if (empty($scheduled)) {
            $recovery_scheduled = $this->maybe_schedule_missing_due_renewal(
                $subscription,
                $subscription_status,
                $next_payment_timestamp,
                $now_timestamp
            );

            if ($recovery_scheduled) {
                $scheduled = $this->get_upcoming_renewals($subscription_id);
            }
        }

        echo '<p><strong>' . esc_html__('Current Time:', 'woo-subzero') . '</strong> ';
        echo esc_html($this->format_local_timestamp($now_timestamp) . ' (' . $this->get_timezone_label() . ')');
        echo ' / ' . esc_html(gmdate('Y-m-d H:i:s', $now_timestamp) . ' UTC');
        echo '</p>';

        echo '<p><strong>' . esc_html__('Subscription Status:', 'woo-subzero') . '</strong> ';
        echo esc_html('' !== $subscription_status ? $subscription_status : 'unknown');
        echo '</p>';

        echo '<p><strong>' . esc_html__('Next payment (profile):', 'woo-subzero') . '</strong> ';
        echo $next_payment_timestamp > 0
            ? esc_html($this->format_local_timestamp($next_payment_timestamp) . ' (' . $this->get_timezone_label() . ')')
                . ' / '
                . esc_html(gmdate('Y-m-d H:i:s', $next_payment_timestamp) . ' UTC')
            : esc_html__('Not set', 'woo-subzero');
        echo '</p>';

        echo '<p><strong>' . esc_html__('Renewal orders created:', 'woo-subzero') . '</strong> ';
        echo esc_html((string) count($renewal_order_ids));
        echo '</p>';

        if ($recovery_scheduled) {
            echo '<p class="description" style="color:#0a7f42;">' . esc_html__('Recovery: queued an immediate renewal action because next payment was due/overdue with no pending schedule.', 'woo-subzero') . '</p>';
        }

        if (empty($scheduled)) {
            echo '<p>' . esc_html__('No upcoming renewal actions are currently scheduled.', 'woo-subzero') . '</p>';

            if (!function_exists('as_get_scheduled_actions')) {
                echo '<p class="description">' . esc_html__('Action Scheduler is unavailable. Renewals cannot be queued.', 'woo-subzero') . '</p>';
                return;
            }

            if ($next_payment_timestamp > 0 && $next_payment_timestamp <= $now_timestamp) {
                $minutes_overdue = (int) max(1, floor(($now_timestamp - $next_payment_timestamp) / 60));
                echo '<p class="description">' . esc_html(
                    sprintf(
                        __('Renewal is due/overdue by %d minute(s), but no pending action exists. Check Action Scheduler and ensure runner execution.', 'woo-subzero'),
                        $minutes_overdue
                    )
                ) . '</p>';
                return;
            }

            if ('active' === $subscription_status && $next_payment_timestamp > $now_timestamp) {
                echo '<p class="description">' . esc_html__('Subscription is active and next payment is in the future, but no pending action is queued yet. Verify checkout activation hooks and Action Scheduler writes.', 'woo-subzero') . '</p>';
                return;
            }

            if ('active' !== $subscription_status) {
                echo '<p class="description">' . esc_html__('No action is expected until the subscription is active.', 'woo-subzero') . '</p>';
                return;
            }

            echo '<p class="description">' . esc_html__('If next payment is in the future, verify Action Scheduler runner/WP-Cron execution.', 'woo-subzero') . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="margin-top:8px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Local Time', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('UTC Time', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Status', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Schedule Key', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Action ID', 'woo-subzero') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($scheduled as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['scheduled_date_local'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['scheduled_date_gmt'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['status'] ?? 'pending')) . '</td>';
            echo '<td><code>' . esc_html((string) ($row['schedule_key'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['action_id'] ?? 0)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param mixed $post_or_order
     */
    public function render_test_card_transactions_meta_box($post_or_order): void
    {
        $subscription = $this->resolve_subscription_from_meta_box_subject($post_or_order);

        if (!($subscription instanceof WC_Order)) {
            echo '<p>' . esc_html__('Subscription context not available.', 'woo-subzero') . '</p>';
            return;
        }

        $subscription_id = (int) $subscription->get_id();
        $transactions = $this->get_test_card_transactions($subscription_id, 30);

        if (empty($transactions)) {
            echo '<p>' . esc_html__('No WSZ Test Card transactions logged yet for this subscription.', 'woo-subzero') . '</p>';
            echo '<p class="description">' . esc_html__('In test mode with 1-minute cycles, a new renewal transaction should appear roughly every minute.', 'woo-subzero') . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="margin-top:8px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Local Time', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('UTC Time', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Context', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Order ID', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Amount', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Status', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Transaction ID', 'woo-subzero') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($transactions as $row) {
            $amount = isset($row['amount']) ? (float) $row['amount'] : 0.0;
            $currency = (string) ($row['currency'] ?? '');

            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['recorded_at_local'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['recorded_at_gmt'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['context'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($row['order_id'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) $amount . ($currency !== '' ? ' ' . $currency : '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string) ($row['transaction_id'] ?? '')) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param mixed $post_or_order
     */
    public function render_subscription_meta_keys_meta_box($post_or_order): void
    {
        $subscription = $this->resolve_subscription_from_meta_box_subject($post_or_order);

        if (!($subscription instanceof WC_Order)) {
            echo '<p>' . esc_html__('Subscription context not available.', 'woo-subzero') . '</p>';
            return;
        }

        $subscription_status = sanitize_key((string) $subscription->get_status());

        echo '<p><strong>' . esc_html__('Subscription Status:', 'woo-subzero') . '</strong> ';
        echo esc_html('' !== $subscription_status ? $subscription_status : __('unknown', 'woo-subzero'));
        echo '</p>';

        $meta_rows = $this->get_subscription_meta_key_rows($subscription);

        if (empty($meta_rows)) {
            echo '<p>' . esc_html__('No subscription meta keys found.', 'woo-subzero') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Meta Key', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Value', 'woo-subzero') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($meta_rows as $key => $value) {
            echo '<tr>';
            echo '<td><code>' . esc_html((string) $key) . '</code></td>';
            echo '<td>' . esc_html((string) $value) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Subscription profile values only (meta keys), without order-style billing layout.', 'woo-subzero') . '</p>';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_upcoming_renewals(int $subscription_id, int $limit = 20): array
    {
        if ($subscription_id <= 0 || !function_exists('as_get_scheduled_actions')) {
            return array();
        }

        $actions = as_get_scheduled_actions(
            array(
                'hook' => 'wsz_subs_process_renewal',
                'group' => WSZ_Subscription_Manager::ACTION_GROUP,
                'status' => 'pending',
                'per_page' => max(1, $limit * 5),
            ),
            'ARRAY_A'
        );

        if (!is_array($actions) || empty($actions)) {
            return array();
        }

        $rows = array();

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $args = $action['args'] ?? array();

            if (is_string($args)) {
                $decoded_args = json_decode($args, true);
                $args = is_array($decoded_args) ? $decoded_args : array();
            }

            if (!is_array($args) || (int) ($args['subscription_id'] ?? 0) !== $subscription_id) {
                continue;
            }

            $rows[] = array(
                'action_id' => (int) ($action['action_id'] ?? 0),
                'scheduled_date_local' => sanitize_text_field((string) ($action['scheduled_date_local'] ?? '')),
                'scheduled_date_gmt' => sanitize_text_field((string) ($action['scheduled_date_gmt'] ?? '')),
                'status' => sanitize_key((string) ($action['status'] ?? 'pending')),
                'schedule_key' => sanitize_text_field((string) ($args['schedule_key'] ?? '')),
            );
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                return strcmp((string) ($left['scheduled_date_gmt'] ?? ''), (string) ($right['scheduled_date_gmt'] ?? ''));
            }
        );

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_test_card_transactions(int $subscription_id, int $limit = 30): array
    {
        if ($subscription_id <= 0 || !class_exists('WSZ_Test_Card_Gateway_Integration')) {
            return array();
        }

        return WSZ_Test_Card_Gateway_Integration::get_transactions($subscription_id, $limit);
    }

    /**
     * @return array<string,string>
     */
    private function get_subscription_meta_key_rows(WC_Order $subscription): array
    {
        $rows = array();

        $preferred_keys = array(
            '_wsz_parent_order_id',
            '_wsz_start_date',
            '_wsz_requested_start_date',
            '_wsz_deferred_activation_at',
            '_wsz_next_payment',
            '_wsz_end_date',
            '_wsz_billing_interval',
            '_wsz_billing_period',
            '_wsz_subscription_length',
            '_requires_manual_renewal',
            '_payment_token_id',
            '_wsz_next_schedule_key',
            '_wsz_last_processed_schedule_key',
            '_wsz_related_order_ids',
        );

        foreach ($preferred_keys as $meta_key) {
            $value = $subscription->get_meta($meta_key, true);

            if ($this->is_empty_meta_value($value)) {
                continue;
            }

            $rows[$meta_key] = $this->stringify_meta_value($value);
        }

        if (is_callable(array($subscription, 'get_meta_data'))) {
            $meta_data = $subscription->get_meta_data();

            if (is_array($meta_data)) {
                foreach ($meta_data as $meta_entry) {
                    if (!is_object($meta_entry) || !is_callable(array($meta_entry, 'get_data'))) {
                        continue;
                    }

                    $data = $meta_entry->get_data();

                    if (!is_array($data)) {
                        continue;
                    }

                    $meta_key = isset($data['key']) ? (string) $data['key'] : '';

                    if ('' === $meta_key || isset($rows[$meta_key])) {
                        continue;
                    }

                    if (0 !== strpos($meta_key, '_wsz_') && !in_array($meta_key, array('_requires_manual_renewal', '_payment_token_id'), true)) {
                        continue;
                    }

                    $value = $data['value'] ?? '';

                    if ($this->is_empty_meta_value($value)) {
                        continue;
                    }

                    $rows[$meta_key] = $this->stringify_meta_value($value);
                }
            }
        }

        ksort($rows);

        return $rows;
    }

    /**
     * @param mixed $value
     */
    private function is_empty_meta_value($value): bool
    {
        if (null === $value) {
            return true;
        }

        if (is_string($value)) {
            return '' === trim($value);
        }

        if (is_array($value)) {
            return empty($value);
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function stringify_meta_value($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($value);

            if (is_string($encoded) && '' !== $encoded) {
                return $encoded;
            }
        }

        $encoded = json_encode($value);

        return is_string($encoded) ? $encoded : '';
    }

    private function format_local_timestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        if (function_exists('wp_date')) {
            return (string) wp_date('Y-m-d H:i:s', $timestamp);
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function get_timezone_label(): string
    {
        if (function_exists('wp_timezone_string')) {
            $timezone = wp_timezone_string();

            if (is_string($timezone) && '' !== trim($timezone)) {
                return $timezone;
            }
        }

        return __('site timezone', 'woo-subzero');
    }

    /**
     * @param mixed $post_or_order
     */
    private function resolve_subscription_from_meta_box_subject($post_or_order): ?WC_Order
    {
        if ($post_or_order instanceof WC_Order) {
            return $this->subscription_manager->get_subscription((int) $post_or_order->get_id());
        }

        if ($post_or_order instanceof WP_Post) {
            return $this->subscription_manager->get_subscription((int) $post_or_order->ID);
        }

        return null;
    }

    private function maybe_schedule_missing_due_renewal(
        WC_Order $subscription,
        string $subscription_status,
        int $next_payment_timestamp,
        int $now_timestamp
    ): bool {
        if ('active' !== $subscription_status || $next_payment_timestamp <= 0) {
            return false;
        }

        $end_timestamp = $this->subscription_manager->get_end_timestamp($subscription);

        if ($end_timestamp > 0 && $now_timestamp >= $end_timestamp) {
            $subscription_id = (int) $subscription->get_id();

            if ($subscription_id > 0) {
                $this->subscription_manager->process_expiration($subscription_id);
            }

            return false;
        }

        if ($end_timestamp > 0 && $next_payment_timestamp >= $end_timestamp) {
            $subscription_id = (int) $subscription->get_id();

            if ($subscription_id > 0) {
                $this->subscription_manager->schedule_expiration($subscription_id, $end_timestamp);
            }

            return false;
        }

        // Only auto-recover when renewal is already due (or very close).
        if ($next_payment_timestamp > ($now_timestamp + 60)) {
            return false;
        }

        if (!function_exists('as_schedule_single_action')) {
            return false;
        }

        $subscription_id = (int) $subscription->get_id();

        if ($subscription_id <= 0) {
            return false;
        }

        $scheduled_timestamp = max($now_timestamp + 1, $next_payment_timestamp);
        $schedule_key = hash('sha256', $subscription_id . '|' . $scheduled_timestamp);

        $result = as_schedule_single_action(
            $scheduled_timestamp,
            'wsz_subs_process_renewal',
            array(
                'subscription_id' => $subscription_id,
                'schedule_key' => $schedule_key,
            ),
            WSZ_Subscription_Manager::ACTION_GROUP,
            true
        );

        $action_id = is_numeric($result) ? (int) $result : (true === $result ? 1 : 0);

        if ($action_id <= 0) {
            return false;
        }

        $subscription->update_meta_data('_wsz_next_schedule_key', $schedule_key);
        $subscription->save();

        return true;
    }

    public function filter_subscription_list_columns(array $columns): array
    {
        $filtered = array();

        if (isset($columns['cb'])) {
            $filtered['cb'] = $columns['cb'];
        }

        if (isset($columns['order_number'])) {
            $filtered['order_number'] = __('Subscription', 'woo-subzero');
        } elseif (isset($columns['title'])) {
            $filtered['title'] = __('Subscription', 'woo-subzero');
        }

        if (isset($columns['order_status']) || isset($columns['post_status'])) {
            $filtered['order_status'] = __('Status', 'woo-subzero');
        }

        $filtered['wsz_next_renewal'] = __('Next Renewal', 'woo-subzero');
        $filtered['wsz_upcoming_renewals'] = __('Queued Renewals', 'woo-subzero');
        $filtered['wsz_renewal_orders'] = __('Renewal Orders', 'woo-subzero');
        $filtered['wsz_last_test_card_tx'] = __('Last Test Card Tx', 'woo-subzero');
        $filtered['wsz_manual_renewal'] = __('Renewal Mode', 'woo-subzero');

        if (isset($columns['order_date'])) {
            $filtered['order_date'] = __('Started', 'woo-subzero');
        } elseif (isset($columns['date'])) {
            $filtered['date'] = __('Started', 'woo-subzero');
        }

        if (isset($columns['wc_actions'])) {
            $filtered['wc_actions'] = $columns['wc_actions'];
        }

        return $filtered;
    }

    public function render_subscription_hpos_custom_column(string $column, WC_Order $order): void
    {
        $subscription = $this->subscription_manager->get_subscription((int) $order->get_id());

        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $this->render_subscription_informational_column($column, $subscription);
    }

    public function render_subscription_list_custom_column(string $column, int $post_id): void
    {
        $subscription = $this->subscription_manager->get_subscription($post_id);

        if (!($subscription instanceof WC_Order)) {
            if (in_array($column, array('wsz_next_renewal', 'wsz_upcoming_renewals', 'wsz_renewal_orders', 'wsz_last_test_card_tx', 'wsz_manual_renewal'), true)) {
                echo esc_html__('n/a', 'woo-subzero');
            }

            return;
        }

        $this->render_subscription_informational_column($column, $subscription);
    }

    private function render_subscription_informational_column(string $column, WC_Order $subscription): void
    {
        switch ($column) {
            case 'wsz_next_renewal':
                $next_payment_timestamp = $this->subscription_manager->get_next_payment_timestamp($subscription);

                if ($next_payment_timestamp <= 0) {
                    echo esc_html__('Not set', 'woo-subzero');
                    return;
                }

                echo esc_html($this->format_local_timestamp($next_payment_timestamp));
                return;

            case 'wsz_upcoming_renewals':
                echo esc_html((string) count($this->get_upcoming_renewals((int) $subscription->get_id(), 20)));
                return;

            case 'wsz_renewal_orders':
                echo esc_html((string) count($this->subscription_manager->get_related_orders($subscription, 'renewal')));
                return;

            case 'wsz_last_test_card_tx':
                $transactions = $this->get_test_card_transactions((int) $subscription->get_id(), 1);

                if (empty($transactions)) {
                    echo esc_html__('None', 'woo-subzero');
                    return;
                }

                $last = $transactions[0];
                echo esc_html((string) ($last['recorded_at_local'] ?? ''));
                return;

            case 'wsz_manual_renewal':
                $manual = $this->subscription_manager->is_manual_renewal($subscription);

                echo $manual
                    ? esc_html__('Manual', 'woo-subzero')
                    : esc_html__('Automatic', 'woo-subzero');
                return;
        }
    }

    public function add_manual_renewal_column(array $columns): array
    {
        return $this->filter_subscription_list_columns($columns);
    }

    public function render_manual_renewal_column(string $column, int $post_id): void
    {
        $this->render_subscription_list_custom_column($column, $post_id);
    }

    /**
     * @param array<string,string> $actions
     * @param WP_Post $post
     */
    public function add_toggle_manual_renewal_action(array $actions, WP_Post $post): array
    {
        if ('shop_subscription' !== $post->post_type) {
            return $actions;
        }

        if (!current_user_can('manage_woocommerce')) {
            return $actions;
        }

        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'wsz_subs_toggle_manual_renewal',
                    'subscription_id' => $post->ID,
                ),
                admin_url('admin-post.php')
            ),
            'wsz_subs_toggle_manual_renewal_' . $post->ID
        );

        $subscription = $this->subscription_manager->get_subscription((int) $post->ID);
        $is_manual = $subscription instanceof WC_Order
            ? $this->subscription_manager->is_manual_renewal($subscription)
            : false;

        $actions['wsz_subs_toggle_manual_renewal'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url($url),
            $is_manual
                ? esc_html__('Switch to automatic renewal', 'woo-subzero')
                : esc_html__('Switch to manual renewal', 'woo-subzero')
        );

        return $actions;
    }

    public function handle_toggle_manual_renewal(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized.', 'woo-subzero'));
        }

        $subscription_id = isset($_GET['subscription_id']) ? absint($_GET['subscription_id']) : 0;

        check_admin_referer('wsz_subs_toggle_manual_renewal_' . $subscription_id);

        $subscription = $this->subscription_manager->get_subscription($subscription_id);

        if ($subscription instanceof WC_Order) {
            $current = $this->subscription_manager->is_manual_renewal($subscription);
            $this->subscription_manager->set_manual_renewal($subscription, !$current);

            $subscription->add_order_note(
                $current
                    ? __('Renewal mode changed to automatic by admin.', 'woo-subzero')
                    : __('Renewal mode changed to manual by admin.', 'woo-subzero')
            );
        }

        wp_safe_redirect(
            wp_get_referer() ?: admin_url('edit.php?post_type=shop_subscription')
        );
        exit;
    }
}
