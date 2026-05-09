<?php

defined('ABSPATH') || exit;

class WSZ_Admin_Settings
{
    private const OPTION_KEY = 'wsz_subs_options';

    private const SETTINGS_PAGE = 'wsz_subs_settings';

    private const DIAGNOSTIC_LOG_OPTION = 'wsz_subs_diagnostic_logs';

    private const MAX_DIAGNOSTIC_LOGS = 200;

    private const LOG_LEVELS = array('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug');

    private const LOG_SOURCES = array(
        'woo-subzero',
        'woo-subzero-test-card',
    );

    public function init(): void
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_wsz_subs_clear_diagnostic_logs', array($this, 'handle_clear_diagnostic_logs'));

        add_filter('action_scheduler_queue_runner_batch_size', array($this, 'filter_queue_batch_size'));
        add_filter('action_scheduler_queue_runner_concurrent_batches', array($this, 'filter_queue_concurrent_batches'));
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('WSZ Subscriptions', 'woo-subzero'),
            __('WSZ Subscriptions', 'woo-subzero'),
            'manage_woocommerce',
            'wsz-subs-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings(): void
    {
        register_setting(
            self::SETTINGS_PAGE,
            self::OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => $this->default_settings(),
            )
        );

        add_settings_section(
            'wsz_subs_behavior',
            __('Subscription Behavior', 'woo-subzero'),
            array($this, 'render_behavior_section'),
            self::SETTINGS_PAGE
        );

        add_settings_section(
            'wsz_subs_features',
            __('Feature Toggles', 'woo-subzero'),
            array($this, 'render_features_section'),
            self::SETTINGS_PAGE
        );

        $feature_fields = array(
            'enable_manual_renewals' => __('Enable manual renewals', 'woo-subzero'),
            'auto_restore_automatic_renewals' => __('Auto-restore automatic renewals after successful recovery', 'woo-subzero'),
            'enable_retries' => __('Enable automatic retry rules', 'woo-subzero'),
            'enable_retry_emails_customer' => __('Enable retry emails to customers', 'woo-subzero'),
            'enable_retry_emails_admin' => __('Enable retry emails to store owner', 'woo-subzero'),
            'enable_start_date' => __('Enable delayed start date', 'woo-subzero'),
            'enable_switching' => __('Enable switching behavior', 'woo-subzero'),
            'enable_proration' => __('Enable proration', 'woo-subzero'),
            'prorate_recurring' => __('Enable recurring amount proration', 'woo-subzero'),
            'prorate_signup_fee' => __('Enable sign-up fee proration', 'woo-subzero'),
            'proration_subscription_length' => __('Enable subscription length proration', 'woo-subzero'),
            'enable_synchronization' => __('Enable synchronized renewals', 'woo-subzero'),
            'enable_early_renewal' => __('Enable early renewals', 'woo-subzero'),
            'enable_resubscribe' => __('Enable resubscribe', 'woo-subzero'),
            'allow_synced_early_renewal' => __('Enable early renewal while synchronized billing is active', 'woo-subzero'),
            'enable_sync_first_renewal_proration' => __('Enable first synchronized renewal proration', 'woo-subzero'),
            'enable_role_transitions' => __('Enable role transitions', 'woo-subzero'),
        );

        foreach ($feature_fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                array($this, 'render_checkbox_field'),
                self::SETTINGS_PAGE,
                'wsz_subs_features',
                array(
                    'key' => $key,
                    'description' => $this->get_field_description($key),
                )
            );
        }

        add_settings_field(
            'customer_suspension_limit',
            __('Customer suspension limit', 'woo-subzero'),
            array($this, 'render_number_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_behavior',
            array(
                'key' => 'customer_suspension_limit',
                'min' => 0,
                'max' => 30,
                'description' => $this->get_field_description('customer_suspension_limit'),
            )
        );

        add_settings_section(
            'wsz_subs_switching',
            __('Switching & Proration', 'woo-subzero'),
            array($this, 'render_switching_section'),
            self::SETTINGS_PAGE
        );

        add_settings_field(
            'free_switch_window_days',
            __('Free switch window (days)', 'woo-subzero'),
            array($this, 'render_number_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_switching',
            array(
                'key' => 'free_switch_window_days',
                'min' => 0,
                'max' => 60,
                'description' => $this->get_field_description('free_switch_window_days'),
            )
        );

        add_settings_section(
            'wsz_subs_early_renewal',
            __('Early Renewal & Sync', 'woo-subzero'),
            array($this, 'render_early_renewal_section'),
            self::SETTINGS_PAGE
        );

        add_settings_field(
            'early_renewal_window_days',
            __('Early renewal window (days before next payment)', 'woo-subzero'),
            array($this, 'render_number_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_early_renewal',
            array(
                'key' => 'early_renewal_window_days',
                'min' => 0,
                'max' => 365,
                'description' => $this->get_field_description('early_renewal_window_days'),
            )
        );

        add_settings_field(
            'sync_day_of_month',
            __('Synchronized renewal day of month', 'woo-subzero'),
            array($this, 'render_number_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_early_renewal',
            array(
                'key' => 'sync_day_of_month',
                'min' => 1,
                'max' => 28,
                'description' => $this->get_field_description('sync_day_of_month'),
            )
        );

        add_settings_section(
            'wsz_subs_roles',
            __('Role Transitions', 'woo-subzero'),
            array($this, 'render_roles_section'),
            self::SETTINGS_PAGE
        );

        add_settings_field(
            'active_user_role',
            __('Role when subscription is active', 'woo-subzero'),
            array($this, 'render_text_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_roles',
            array(
                'key' => 'active_user_role',
                'description' => $this->get_field_description('active_user_role'),
            )
        );

        add_settings_field(
            'inactive_user_role',
            __('Role when subscription is inactive', 'woo-subzero'),
            array($this, 'render_text_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_roles',
            array(
                'key' => 'inactive_user_role',
                'description' => $this->get_field_description('inactive_user_role'),
            )
        );

        add_settings_section(
            'wsz_subs_queue',
            __('Queue Runner Tuning', 'woo-subzero'),
            array($this, 'render_queue_section'),
            self::SETTINGS_PAGE
        );

        add_settings_section(
            'wsz_subs_testing',
            __('Testing Mode', 'woo-subzero'),
            array($this, 'render_testing_section'),
            self::SETTINGS_PAGE
        );

        add_settings_section(
            'wsz_subs_payment_gateways',
            __('Payment Gateways', 'woo-subzero'),
            array($this, 'render_payment_gateways_section'),
            self::SETTINGS_PAGE
        );

        add_settings_field(
            'enable_paynl_tokens',
            __('Enable PAY.nl tokens', 'woo-subzero'),
            array($this, 'render_checkbox_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_payment_gateways',
            array(
                'key' => 'enable_paynl_tokens',
                'description' => $this->get_field_description('enable_paynl_tokens'),
            )
        );

        add_settings_field(
            'enable_stripe_tokens',
            __('Enable Stripe tokens', 'woo-subzero'),
            array($this, 'render_checkbox_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_payment_gateways',
            array(
                'key' => 'enable_stripe_tokens',
                'description' => $this->get_field_description('enable_stripe_tokens'),
            )
        );

        add_settings_field(
            'enable_mollie_tokens',
            __('Enable Mollie tokens', 'woo-subzero'),
            array($this, 'render_checkbox_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_payment_gateways',
            array(
                'key' => 'enable_mollie_tokens',
                'description' => $this->get_field_description('enable_mollie_tokens'),
            )
        );

        $testing_fields = array(
            'enable_test_mode' => __('Enable accelerated test billing', 'woo-subzero'),
            'enable_test_deferred_start' => __('Accelerate deferred start activation', 'woo-subzero'),
            'enable_test_cycle_notifications' => __('Add test-cycle notifications', 'woo-subzero'),
        );

        foreach ($testing_fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                array($this, 'render_checkbox_field'),
                self::SETTINGS_PAGE,
                'wsz_subs_testing',
                array(
                    'key' => $key,
                    'description' => $this->get_field_description($key),
                )
            );
        }

        add_settings_field(
            'test_cycle_minutes',
            __('Minutes per billing cycle', 'woo-subzero'),
            array($this, 'render_number_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_testing',
            array(
                'key' => 'test_cycle_minutes',
                'min' => 1,
                'max' => 1440,
                'description' => $this->get_field_description('test_cycle_minutes'),
            )
        );

        add_settings_field(
            'test_deferred_start_minutes',
            __('Minutes for deferred start activation', 'woo-subzero'),
            array($this, 'render_number_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_testing',
            array(
                'key' => 'test_deferred_start_minutes',
                'min' => 1,
                'max' => 1440,
                'description' => $this->get_field_description('test_deferred_start_minutes'),
            )
        );

        add_settings_field(
            'queue_batch_size',
            __('Action Scheduler batch size', 'woo-subzero'),
            array($this, 'render_number_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_queue',
            array(
                'key' => 'queue_batch_size',
                'min' => 25,
                'max' => 1000,
                'description' => $this->get_field_description('queue_batch_size'),
            )
        );

        add_settings_field(
            'queue_concurrent_batches',
            __('Concurrent queue batches', 'woo-subzero'),
            array($this, 'render_number_field'),
            self::SETTINGS_PAGE,
            'wsz_subs_queue',
            array(
                'key' => 'queue_concurrent_batches',
                'min' => 1,
                'max' => 20,
                'description' => $this->get_field_description('queue_concurrent_batches'),
            )
        );
    }

    public function sanitize_settings(array $input): array
    {
        $defaults = $this->default_settings();
        $current = wp_parse_args((array) get_option(self::OPTION_KEY, array()), $defaults);
        $settings = wp_parse_args($input, $current);

        $output = array(
            'enable_manual_renewals' => $this->sanitize_yes_no($settings['enable_manual_renewals']),
            'auto_restore_automatic_renewals' => $this->sanitize_yes_no($settings['auto_restore_automatic_renewals']),
            'enable_retries' => $this->sanitize_yes_no($settings['enable_retries']),
            'enable_retry_emails_customer' => $this->sanitize_yes_no($settings['enable_retry_emails_customer']),
            'enable_retry_emails_admin' => $this->sanitize_yes_no($settings['enable_retry_emails_admin']),
            'enable_start_date' => $this->sanitize_yes_no($settings['enable_start_date']),
            'enable_switching' => $this->sanitize_yes_no($settings['enable_switching']),
            'enable_synchronization' => $this->sanitize_yes_no($settings['enable_synchronization']),
            'enable_proration' => $this->sanitize_yes_no($settings['enable_proration']),
            'prorate_recurring' => $this->sanitize_yes_no($settings['prorate_recurring']),
            'prorate_signup_fee' => $this->sanitize_yes_no($settings['prorate_signup_fee']),
            'proration_subscription_length' => $this->sanitize_yes_no($settings['proration_subscription_length']),
            'enable_early_renewal' => $this->sanitize_yes_no($settings['enable_early_renewal']),
            'enable_resubscribe' => $this->sanitize_yes_no($settings['enable_resubscribe']),
            'allow_synced_early_renewal' => $this->sanitize_yes_no($settings['allow_synced_early_renewal']),
            'enable_sync_first_renewal_proration' => $this->sanitize_yes_no($settings['enable_sync_first_renewal_proration']),
            'enable_role_transitions' => $this->sanitize_yes_no($settings['enable_role_transitions']),
            'enable_test_mode' => $this->sanitize_yes_no($settings['enable_test_mode']),
            'enable_test_deferred_start' => $this->sanitize_yes_no($settings['enable_test_deferred_start']),
            'enable_test_cycle_notifications' => $this->sanitize_yes_no($settings['enable_test_cycle_notifications']),
            'enable_paynl_tokens' => $this->sanitize_yes_no($settings['enable_paynl_tokens']),
            'enable_stripe_tokens' => $this->sanitize_yes_no($settings['enable_stripe_tokens']),
            'enable_mollie_tokens' => $this->sanitize_yes_no($settings['enable_mollie_tokens']),
            'customer_suspension_limit' => min(30, max(0, (int) $settings['customer_suspension_limit'])),
            'free_switch_window_days' => min(60, max(0, (int) $settings['free_switch_window_days'])),
            'early_renewal_window_days' => min(365, max(0, (int) $settings['early_renewal_window_days'])),
            'sync_day_of_month' => min(28, max(1, (int) $settings['sync_day_of_month'])),
            'test_cycle_minutes' => min(1440, max(1, (int) $settings['test_cycle_minutes'])),
            'test_deferred_start_minutes' => min(1440, max(1, (int) $settings['test_deferred_start_minutes'])),
            'active_user_role' => sanitize_key((string) $settings['active_user_role']),
            'inactive_user_role' => sanitize_key((string) $settings['inactive_user_role']),
            'queue_batch_size' => min(1000, max(25, (int) $settings['queue_batch_size'])),
            'queue_concurrent_batches' => min(20, max(1, (int) $settings['queue_concurrent_batches'])),
        );

        return $output;
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $tabs = $this->get_settings_tabs();
        $active_tab = $this->get_active_tab($tabs);
        $active_section = (string) ($tabs[$active_tab]['section'] ?? 'wsz_subs_behavior');
        $is_custom_tab = !empty($tabs[$active_tab]['custom']);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Woo Subs-Zero', 'woo-subzero') . '</h1>';
        echo '<p>' . esc_html__('Subscription controls only. Payment gateways remain managed in WooCommerce > Settings > Payments.', 'woo-subzero') . '</p>';

        $this->render_settings_tabs($tabs, $active_tab);

        if ($is_custom_tab && 'logs' === $active_tab) {
            $this->render_error_logs_tab();
        } else {
            echo '<form method="post" action="options.php">';
            settings_fields(self::SETTINGS_PAGE);
            $this->render_settings_section($active_section);
            submit_button();
            echo '</form>';
        }

        echo '</div>';
    }

    public function render_behavior_section(): void
    {
        echo '<p>' . esc_html__('Configure general subscription behavior and customer limits.', 'woo-subzero') . '</p>';
    }

    public function render_features_section(): void
    {
        echo '<p>' . esc_html__('Enable or disable each subscription feature branch from one place.', 'woo-subzero') . '</p>';
    }

    public function render_queue_section(): void
    {
        echo '<p>' . esc_html__('Tune Action Scheduler throughput for high-volume subscription stores.', 'woo-subzero') . '</p>';
    }

    public function render_testing_section(): void
    {
        echo '<p>' . esc_html__('Use accelerated billing for QA only. This simulates recurring cycles in minutes instead of real billing periods.', 'woo-subzero') . '</p>';
    }

    public function render_payment_gateways_section(): void
    {
        echo '<p>' . esc_html__('Gateway-specific subscription integrations. Gateway credentials, availability, and checkout settings stay in WooCommerce > Settings > Payments.', 'woo-subzero') . '</p>';
    }

    public function render_switching_section(): void
    {
        echo '<p>' . esc_html__('Configure plan switching and proration behavior for upgrades and downgrades.', 'woo-subzero') . '</p>';
    }

    public function render_early_renewal_section(): void
    {
        echo '<p>' . esc_html__('Control early renewals, resubscribe behavior, and synchronized renewal rules.', 'woo-subzero') . '</p>';
    }

    public function render_roles_section(): void
    {
        echo '<p>' . esc_html__('Map customer roles based on active versus inactive subscription states.', 'woo-subzero') . '</p>';
    }

    public function render_checkbox_field(array $args): void
    {
        $key = (string) ($args['key'] ?? '');
        $settings = $this->get_settings();
        $value = $settings[$key] ?? 'no';

        printf(
            '<input type="hidden" name="%1$s[%2$s]" value="no" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($key)
        );

        printf(
            '<input type="checkbox" name="%1$s[%2$s]" value="yes" %3$s />',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            checked('yes', $value, false)
        );

        $this->render_field_description((string) ($args['description'] ?? ''));
    }

    public function render_number_field(array $args): void
    {
        $key = (string) ($args['key'] ?? '');
        $settings = $this->get_settings();
        $value = (int) ($settings[$key] ?? 0);

        $min = isset($args['min']) ? (int) $args['min'] : 0;
        $max = isset($args['max']) ? (int) $args['max'] : PHP_INT_MAX;

        printf(
            '<input type="number" class="small-text" name="%1$s[%2$s]" value="%3$d" min="%4$d" max="%5$d" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            $value,
            $min,
            $max
        );

        $this->render_field_description((string) ($args['description'] ?? ''));
    }

    public function render_text_field(array $args): void
    {
        $key = (string) ($args['key'] ?? '');
        $settings = $this->get_settings();
        $value = (string) ($settings[$key] ?? '');

        printf(
            '<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            esc_attr($value)
        );

        $this->render_field_description((string) ($args['description'] ?? ''));
    }

    private function render_field_description(string $description): void
    {
        if ('' === trim($description)) {
            return;
        }

        printf('<p class="description">%s</p>', esc_html($description));
    }

    private function get_field_description(string $key): string
    {
        $descriptions = array(
            'enable_manual_renewals' => __('Allows renewal orders to remain payable manually when auto-charge is not preferred or unavailable.', 'woo-subzero'),
            'auto_restore_automatic_renewals' => __('When a customer successfully updates payment context or pays a manual renewal, switch the subscription back to automatic renewal.', 'woo-subzero'),
            'enable_retries' => __('Queues failed automatic renewal payments for retry using the configured retry policy.', 'woo-subzero'),
            'enable_retry_emails_customer' => __('Sends customers retry-related notifications after failed renewal payments.', 'woo-subzero'),
            'enable_retry_emails_admin' => __('Sends retry-related notifications to the store owner.', 'woo-subzero'),
            'enable_switching' => __('Allows customers to switch between eligible subscription plans.', 'woo-subzero'),
            'enable_start_date' => __('Lets customers choose a future subscription start date before checkout.', 'woo-subzero'),
            'enable_synchronization' => __('Aligns renewals to synchronized day-of-month billing dates.', 'woo-subzero'),
            'enable_early_renewal' => __('Lets customers renew before the next scheduled payment date.', 'woo-subzero'),
            'enable_resubscribe' => __('Lets customers start a new subscription from a cancelled or expired one.', 'woo-subzero'),
            'enable_role_transitions' => __('Automatically updates customer roles when subscription status changes.', 'woo-subzero'),
            'enable_test_mode' => __('Runs recurring schedule calculations in minute-based test cycles instead of real day/week/month/year periods.', 'woo-subzero'),
            'enable_test_deferred_start' => __('When test mode is enabled, future customer-selected start dates are accelerated to a short minute-based delay.', 'woo-subzero'),
            'enable_test_cycle_notifications' => __('Adds a subscription note and fires a hook each accelerated cycle for easier QA verification.', 'woo-subzero'),
            'enable_paynl_tokens' => __('Captures PAY.nl tokenization callbacks and uses stored PAY.nl recurring IDs for automatic subscription renewals. Leave disabled unless PAY.nl recurring card payments are configured.', 'woo-subzero'),
            'enable_stripe_tokens' => __('Uses saved Stripe payment methods and customer IDs from the Stripe gateway for automatic off-session subscription renewals. Leave disabled unless Stripe tokenized payments are configured.', 'woo-subzero'),
            'enable_mollie_tokens' => __('Uses saved Mollie customer and mandate context for automatic recurring subscription renewals. Leave disabled unless Mollie recurring payments are configured.', 'woo-subzero'),
            'customer_suspension_limit' => __('Maximum number of customer-initiated suspensions allowed per subscription.', 'woo-subzero'),
            'enable_proration' => __('Turns on proration logic for subscription plan switches.', 'woo-subzero'),
            'prorate_recurring' => __('Adjusts recurring charges based on the unused portion of the previous plan.', 'woo-subzero'),
            'prorate_signup_fee' => __('Includes sign-up fee adjustments in proration during plan switches.', 'woo-subzero'),
            'proration_subscription_length' => __('Adjusts fixed-term length effects when switching between plans.', 'woo-subzero'),
            'free_switch_window_days' => __('Number of days after start where switching can occur without proration charge.', 'woo-subzero'),
            'allow_synced_early_renewal' => __('Allows early renewal even when synchronized billing is enabled.', 'woo-subzero'),
            'enable_sync_first_renewal_proration' => __('Prorates the first synchronized renewal amount when needed.', 'woo-subzero'),
            'early_renewal_window_days' => __('How many days before next payment a customer can renew early.', 'woo-subzero'),
            'sync_day_of_month' => __('Default synchronized billing day of month (1-28).', 'woo-subzero'),
            'test_cycle_minutes' => __('Defines how many minutes one billing interval represents in test mode. Example: 1 means one payment per minute when interval is 1.', 'woo-subzero'),
            'test_deferred_start_minutes' => __('Defines the delay in minutes before activating deferred-start subscriptions while test mode is enabled.', 'woo-subzero'),
            'active_user_role' => __('Role assigned when a customer has an active subscription.', 'woo-subzero'),
            'inactive_user_role' => __('Role assigned when a customer has no active subscription. Leave empty to keep existing role.', 'woo-subzero'),
            'queue_batch_size' => __('Number of scheduled actions processed per Action Scheduler batch run.', 'woo-subzero'),
            'queue_concurrent_batches' => __('How many Action Scheduler batches can run in parallel.', 'woo-subzero'),
        );

        return (string) ($descriptions[$key] ?? '');
    }

    private function get_settings_tabs(): array
    {
        return array(
            'behavior' => array(
                'label' => __('Behavior', 'woo-subzero'),
                'section' => 'wsz_subs_behavior',
            ),
            'features' => array(
                'label' => __('Features', 'woo-subzero'),
                'section' => 'wsz_subs_features',
            ),
            'switching' => array(
                'label' => __('Switching & Proration', 'woo-subzero'),
                'section' => 'wsz_subs_switching',
            ),
            'early-renewal' => array(
                'label' => __('Early Renewal & Sync', 'woo-subzero'),
                'section' => 'wsz_subs_early_renewal',
            ),
            'roles' => array(
                'label' => __('Role Transitions', 'woo-subzero'),
                'section' => 'wsz_subs_roles',
            ),
            'testing' => array(
                'label' => __('Testing', 'woo-subzero'),
                'section' => 'wsz_subs_testing',
            ),
            'payment-gateways' => array(
                'label' => __('Payment Gateways', 'woo-subzero'),
                'section' => 'wsz_subs_payment_gateways',
            ),
            'queue' => array(
                'label' => __('Queue', 'woo-subzero'),
                'section' => 'wsz_subs_queue',
            ),
            'logs' => array(
                'label' => __('Error Logs', 'woo-subzero'),
                'section' => '',
                'custom' => true,
            ),
        );
    }

    public static function log_diagnostic(string $level, string $message, array $context = array()): void
    {
        $level = sanitize_key($level);

        if (!in_array($level, self::LOG_LEVELS, true)) {
            $level = 'error';
        }

        $entry = array(
            'timestamp' => function_exists('current_time') ? (int) current_time('timestamp', true) : time(),
            'level' => $level,
            'message' => sanitize_text_field($message),
            'source' => isset($context['source']) ? sanitize_key((string) $context['source']) : 'woo-subzero',
            'context' => self::sanitize_log_context($context),
        );

        $logs = get_option(self::DIAGNOSTIC_LOG_OPTION, array());
        $logs = is_array($logs) ? $logs : array();
        array_unshift($logs, $entry);
        $logs = array_slice($logs, 0, self::MAX_DIAGNOSTIC_LOGS);

        update_option(self::DIAGNOSTIC_LOG_OPTION, $logs, false);
    }

    public function handle_clear_diagnostic_logs(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to clear Woo Subs-Zero logs.', 'woo-subzero'));
        }

        check_admin_referer('wsz_subs_clear_diagnostic_logs');
        $this->clear_diagnostic_logs();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'wsz-subs-settings',
                    'tab' => 'logs',
                    'logs-cleared' => '1',
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function clear_diagnostic_logs(): void
    {
        delete_option(self::DIAGNOSTIC_LOG_OPTION);
    }

    private function get_active_tab(array $tabs): string
    {
        $requested_tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : '';

        if ('' !== $requested_tab && isset($tabs[$requested_tab])) {
            return $requested_tab;
        }

        $tab_keys = array_keys($tabs);

        return (string) ($tab_keys[0] ?? 'behavior');
    }

    private function render_settings_tabs(array $tabs, string $active_tab): void
    {
        echo '<h2 class="nav-tab-wrapper">';

        foreach ($tabs as $tab_key => $tab) {
            $url = add_query_arg(
                array(
                    'page' => 'wsz-subs-settings',
                    'tab' => $tab_key,
                ),
                admin_url('admin.php')
            );

            $class_name = 'nav-tab' . ($tab_key === $active_tab ? ' nav-tab-active' : '');

            printf(
                '<a href="%1$s" class="%2$s">%3$s</a>',
                esc_url($url),
                esc_attr($class_name),
                esc_html((string) ($tab['label'] ?? $tab_key))
            );
        }

        echo '</h2>';
    }

    private function render_settings_section(string $section_id): void
    {
        global $wp_settings_sections, $wp_settings_fields;

        if (!isset($wp_settings_sections[self::SETTINGS_PAGE][$section_id])) {
            return;
        }

        $section = $wp_settings_sections[self::SETTINGS_PAGE][$section_id];

        if (!empty($section['title'])) {
            printf('<h2>%s</h2>', esc_html((string) $section['title']));
        }

        if (isset($section['callback']) && is_callable($section['callback'])) {
            call_user_func($section['callback']);
        }

        if (!isset($wp_settings_fields[self::SETTINGS_PAGE][$section_id])) {
            return;
        }

        echo '<table class="form-table" role="presentation">';
        do_settings_fields(self::SETTINGS_PAGE, $section_id);
        echo '</table>';
    }

    private function render_error_logs_tab(): void
    {
        $filters = $this->get_log_filters();
        $diagnostic_logs = $this->get_diagnostic_logs($filters);
        $woocommerce_logs = $this->get_woocommerce_log_entries($filters);
        $woocommerce_log_url = admin_url('admin.php?page=wc-status&tab=logs');

        if (isset($_GET['logs-cleared'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Woo Subs-Zero diagnostic logs cleared.', 'woo-subzero') . '</p></div>';
        }

        echo '<h2>' . esc_html__('Error Logs', 'woo-subzero') . '</h2>';
        echo '<p>' . esc_html__('Review recent Woo Subs-Zero diagnostics and WooCommerce logger entries for subscription and renewal debugging.', 'woo-subzero') . '</p>';

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin: 16px 0;">';
        echo '<input type="hidden" name="page" value="wsz-subs-settings" />';
        echo '<input type="hidden" name="tab" value="logs" />';
        $this->render_log_filter_controls($filters);
        submit_button(__('Filter logs', 'woo-subzero'), 'secondary', '', false);
        echo '</form>';

        echo '<h3>' . esc_html__('Woo Subs-Zero Diagnostics', 'woo-subzero') . '</h3>';
        $this->render_log_table($diagnostic_logs);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin: 12px 0 28px;">';
        echo '<input type="hidden" name="action" value="wsz_subs_clear_diagnostic_logs" />';
        wp_nonce_field('wsz_subs_clear_diagnostic_logs');
        submit_button(__('Clear Woo Subs-Zero diagnostics', 'woo-subzero'), 'delete', '', false);
        echo '</form>';

        echo '<h3>' . esc_html__('WooCommerce Logger Entries', 'woo-subzero') . '</h3>';

        if (!empty($woocommerce_logs)) {
            $this->render_log_table($woocommerce_logs);
            return;
        }

        echo '<p>' . esc_html__('No WooCommerce database log entries were found for the selected filters. If your store logs to files, use WooCommerce > Status > Logs.', 'woo-subzero') . '</p>';
        printf(
            '<p><a class="button" href="%s">%s</a></p>',
            esc_url($woocommerce_log_url),
            esc_html__('Open WooCommerce logs', 'woo-subzero')
        );
    }

    private function render_log_filter_controls(array $filters): void
    {
        echo '<label style="margin-right: 12px;">';
        echo esc_html__('Source', 'woo-subzero') . ' ';
        echo '<select name="source">';
        echo '<option value="">' . esc_html__('All plugin sources', 'woo-subzero') . '</option>';

        foreach (self::LOG_SOURCES as $source) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($source),
                selected($source, $filters['source'], false),
                esc_html($source)
            );
        }

        echo '</select>';
        echo '</label>';

        echo '<label style="margin-right: 12px;">';
        echo esc_html__('Minimum level', 'woo-subzero') . ' ';
        echo '<select name="level">';

        foreach (self::LOG_LEVELS as $level) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($level),
                selected($level, $filters['level'], false),
                esc_html(ucfirst($level))
            );
        }

        echo '</select>';
        echo '</label>';

        printf(
            '<label style="margin-right: 12px;">%1$s <input type="number" class="small-text" name="limit" min="10" max="200" value="%2$d" /></label>',
            esc_html__('Limit', 'woo-subzero'),
            (int) $filters['limit']
        );
    }

    private function render_log_table(array $entries): void
    {
        if (empty($entries)) {
            echo '<p>' . esc_html__('No log entries found for the selected filters.', 'woo-subzero') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Time', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Level', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Source', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Message', 'woo-subzero') . '</th>';
        echo '<th>' . esc_html__('Context', 'woo-subzero') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            echo '<tr>';
            echo '<td>' . esc_html($this->format_log_timestamp((int) ($entry['timestamp'] ?? 0))) . '</td>';
            echo '<td><code>' . esc_html((string) ($entry['level'] ?? '')) . '</code></td>';
            echo '<td><code>' . esc_html((string) ($entry['source'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($entry['message'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html($this->stringify_log_context($entry['context'] ?? array())) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function get_log_filters(): array
    {
        $source = isset($_GET['source']) ? sanitize_key((string) wp_unslash($_GET['source'])) : '';
        $level = isset($_GET['level']) ? sanitize_key((string) wp_unslash($_GET['level'])) : 'warning';
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;

        if ('' !== $source && !in_array($source, self::LOG_SOURCES, true)) {
            $source = '';
        }

        if (!in_array($level, self::LOG_LEVELS, true)) {
            $level = 'warning';
        }

        return array(
            'source' => $source,
            'level' => $level,
            'limit' => min(200, max(10, $limit)),
        );
    }

    private function get_diagnostic_logs(array $filters): array
    {
        $logs = get_option(self::DIAGNOSTIC_LOG_OPTION, array());
        $logs = is_array($logs) ? $logs : array();

        return array_slice($this->filter_log_entries($logs, $filters), 0, (int) $filters['limit']);
    }

    private function get_woocommerce_log_entries(array $filters): array
    {
        if (!class_exists('WC_Log_Handler_DB') || !is_callable(array('WC_Log_Handler_DB', 'get_log_entries'))) {
            return array();
        }

        $raw_entries = WC_Log_Handler_DB::get_log_entries(
            array(
                'source' => '' !== $filters['source'] ? $filters['source'] : self::LOG_SOURCES,
                'level' => $filters['level'],
                'limit' => (int) $filters['limit'],
                'orderby' => 'timestamp',
                'order' => 'DESC',
            )
        );

        if (!is_array($raw_entries)) {
            return array();
        }

        $entries = array();

        foreach ($raw_entries as $entry) {
            $data = is_object($entry) ? get_object_vars($entry) : (array) $entry;
            $entries[] = array(
                'timestamp' => $this->normalize_log_timestamp($data['timestamp'] ?? ($data['date_created'] ?? 0)),
                'level' => sanitize_key((string) ($data['level'] ?? '')),
                'source' => sanitize_key((string) ($data['source'] ?? '')),
                'message' => (string) ($data['message'] ?? ''),
                'context' => isset($data['context']) && is_array($data['context']) ? $data['context'] : array(),
            );
        }

        return $this->filter_log_entries($entries, $filters);
    }

    private function filter_log_entries(array $entries, array $filters): array
    {
        $minimum_weight = $this->get_log_level_weight((string) $filters['level']);
        $filtered = array();

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $source = sanitize_key((string) ($entry['source'] ?? ''));
            $level = sanitize_key((string) ($entry['level'] ?? ''));

            if ('' !== $filters['source'] && $source !== $filters['source']) {
                continue;
            }

            if (!in_array($source, self::LOG_SOURCES, true)) {
                continue;
            }

            if ($this->get_log_level_weight($level) > $minimum_weight) {
                continue;
            }

            $entry['timestamp'] = $this->normalize_log_timestamp($entry['timestamp'] ?? 0);
            $entry['level'] = $level;
            $entry['source'] = $source;
            $entry['context'] = isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : array();
            $filtered[] = $entry;
        }

        usort(
            $filtered,
            static function (array $a, array $b): int {
                return (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0);
            }
        );

        return $filtered;
    }

    private function get_log_level_weight(string $level): int
    {
        $index = array_search($level, self::LOG_LEVELS, true);

        return false === $index ? count(self::LOG_LEVELS) : (int) $index;
    }

    private function normalize_log_timestamp($timestamp): int
    {
        if (is_numeric($timestamp)) {
            return max(0, (int) $timestamp);
        }

        if ($timestamp instanceof DateTimeInterface) {
            return (int) $timestamp->getTimestamp();
        }

        if (is_string($timestamp) && '' !== trim($timestamp)) {
            $parsed = strtotime($timestamp);
            return false === $parsed ? 0 : (int) $parsed;
        }

        return 0;
    }

    private function format_log_timestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s', $timestamp);
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function stringify_log_context($context): string
    {
        if (!is_array($context) || empty($context)) {
            return '';
        }

        $encoded = wp_json_encode(self::sanitize_log_context($context));

        return is_string($encoded) ? $encoded : '';
    }

    private static function sanitize_log_context(array $context): array
    {
        $sanitized = array();

        foreach ($context as $key => $value) {
            $key = sanitize_key((string) $key);

            if ('' === $key || 'source' === $key) {
                continue;
            }

            if (is_scalar($value) || null === $value) {
                $sanitized[$key] = sanitize_text_field((string) $value);
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitize_log_context($value);
            }
        }

        return $sanitized;
    }

    public function filter_queue_batch_size(int $size): int
    {
        $settings = $this->get_settings();

        return max(25, (int) ($settings['queue_batch_size'] ?? $size));
    }

    public function filter_queue_concurrent_batches(int $batches): int
    {
        $settings = $this->get_settings();

        return max(1, (int) ($settings['queue_concurrent_batches'] ?? $batches));
    }

    private function get_settings(): array
    {
        return wp_parse_args((array) get_option(self::OPTION_KEY, array()), $this->default_settings());
    }

    private function default_settings(): array
    {
        return array(
            'enable_manual_renewals' => 'no',
            'auto_restore_automatic_renewals' => 'yes',
            'enable_retries' => 'yes',
            'enable_retry_emails_customer' => 'no',
            'enable_retry_emails_admin' => 'no',
            'enable_switching' => 'no',
            'enable_start_date' => 'yes',
            'enable_synchronization' => 'no',
            'enable_proration' => 'yes',
            'prorate_recurring' => 'yes',
            'prorate_signup_fee' => 'yes',
            'proration_subscription_length' => 'yes',
            'free_switch_window_days' => 0,
            'enable_early_renewal' => 'yes',
            'enable_resubscribe' => 'yes',
            'early_renewal_window_days' => 30,
            'allow_synced_early_renewal' => 'no',
            'enable_sync_first_renewal_proration' => 'yes',
            'sync_day_of_month' => 1,
            'enable_test_mode' => 'no',
            'test_cycle_minutes' => 1,
            'enable_test_deferred_start' => 'yes',
            'test_deferred_start_minutes' => 1,
            'enable_test_cycle_notifications' => 'no',
            'enable_paynl_tokens' => 'no',
            'enable_stripe_tokens' => 'no',
            'enable_mollie_tokens' => 'no',
            'enable_role_transitions' => 'no',
            'active_user_role' => 'customer',
            'inactive_user_role' => '',
            'customer_suspension_limit' => 2,
            'queue_batch_size' => 200,
            'queue_concurrent_batches' => 1,
        );
    }

    private function sanitize_yes_no(string $value): string
    {
        return 'yes' === $value ? 'yes' : 'no';
    }
}
