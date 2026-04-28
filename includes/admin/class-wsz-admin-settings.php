<?php

defined('ABSPATH') || exit;

class WSZ_Admin_Settings
{
    private const OPTION_KEY = 'wsz_subs_options';

    private const SETTINGS_PAGE = 'wsz_subs_settings';

    public function init(): void
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));

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

        $fields = array(
            'enable_manual_renewals' => __('Allow manual renewals', 'woo-subzero'),
            'enable_retries' => __('Enable automatic retry rules', 'woo-subzero'),
            'enable_retry_emails_customer' => __('Send retry emails to customers', 'woo-subzero'),
            'enable_retry_emails_admin' => __('Send retry emails to store owner', 'woo-subzero'),
            'enable_switching' => __('Enable switching behavior', 'woo-subzero'),
            'enable_synchronization' => __('Enable synchronized renewals', 'woo-subzero'),
            'enable_early_renewal' => __('Enable early renewals', 'woo-subzero'),
            'enable_resubscribe' => __('Enable resubscribe', 'woo-subzero'),
            'enable_role_transitions' => __('Enable role transitions', 'woo-subzero'),
        );

        foreach ($fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                array($this, 'render_checkbox_field'),
                    self::SETTINGS_PAGE,
                'wsz_subs_behavior',
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

        $switching_fields = array(
            'enable_proration' => __('Enable proration', 'woo-subzero'),
            'prorate_recurring' => __('Prorate recurring amount', 'woo-subzero'),
            'prorate_signup_fee' => __('Prorate sign-up fee', 'woo-subzero'),
            'proration_subscription_length' => __('Prorate subscription length effects', 'woo-subzero'),
        );

        foreach ($switching_fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                array($this, 'render_checkbox_field'),
                    self::SETTINGS_PAGE,
                'wsz_subs_switching',
                    array(
                        'key' => $key,
                        'description' => $this->get_field_description($key),
                    )
            );
        }

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

        $early_fields = array(
            'allow_synced_early_renewal' => __('Allow early renewal for synchronized subscriptions', 'woo-subzero'),
            'enable_sync_first_renewal_proration' => __('Prorate first synchronized renewal', 'woo-subzero'),
        );

        foreach ($early_fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                array($this, 'render_checkbox_field'),
                    self::SETTINGS_PAGE,
                'wsz_subs_early_renewal',
                    array(
                        'key' => $key,
                        'description' => $this->get_field_description($key),
                    )
            );
        }

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

        $testing_fields = array(
            'enable_test_mode' => __('Enable accelerated test billing', 'woo-subzero'),
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

        $output = array(
            'enable_manual_renewals' => $this->sanitize_yes_no($input['enable_manual_renewals'] ?? $defaults['enable_manual_renewals']),
            'enable_retries' => $this->sanitize_yes_no($input['enable_retries'] ?? $defaults['enable_retries']),
            'enable_retry_emails_customer' => $this->sanitize_yes_no($input['enable_retry_emails_customer'] ?? $defaults['enable_retry_emails_customer']),
            'enable_retry_emails_admin' => $this->sanitize_yes_no($input['enable_retry_emails_admin'] ?? $defaults['enable_retry_emails_admin']),
            'enable_switching' => $this->sanitize_yes_no($input['enable_switching'] ?? $defaults['enable_switching']),
            'enable_synchronization' => $this->sanitize_yes_no($input['enable_synchronization'] ?? $defaults['enable_synchronization']),
            'enable_proration' => $this->sanitize_yes_no($input['enable_proration'] ?? $defaults['enable_proration']),
            'prorate_recurring' => $this->sanitize_yes_no($input['prorate_recurring'] ?? $defaults['prorate_recurring']),
            'prorate_signup_fee' => $this->sanitize_yes_no($input['prorate_signup_fee'] ?? $defaults['prorate_signup_fee']),
            'proration_subscription_length' => $this->sanitize_yes_no($input['proration_subscription_length'] ?? $defaults['proration_subscription_length']),
            'enable_early_renewal' => $this->sanitize_yes_no($input['enable_early_renewal'] ?? $defaults['enable_early_renewal']),
            'enable_resubscribe' => $this->sanitize_yes_no($input['enable_resubscribe'] ?? $defaults['enable_resubscribe']),
            'allow_synced_early_renewal' => $this->sanitize_yes_no($input['allow_synced_early_renewal'] ?? $defaults['allow_synced_early_renewal']),
            'enable_sync_first_renewal_proration' => $this->sanitize_yes_no($input['enable_sync_first_renewal_proration'] ?? $defaults['enable_sync_first_renewal_proration']),
            'enable_role_transitions' => $this->sanitize_yes_no($input['enable_role_transitions'] ?? $defaults['enable_role_transitions']),
            'enable_test_mode' => $this->sanitize_yes_no($input['enable_test_mode'] ?? $defaults['enable_test_mode']),
            'enable_test_cycle_notifications' => $this->sanitize_yes_no($input['enable_test_cycle_notifications'] ?? $defaults['enable_test_cycle_notifications']),
            'customer_suspension_limit' => min(30, max(0, (int) ($input['customer_suspension_limit'] ?? $defaults['customer_suspension_limit']))),
            'free_switch_window_days' => min(60, max(0, (int) ($input['free_switch_window_days'] ?? $defaults['free_switch_window_days']))),
            'early_renewal_window_days' => min(365, max(0, (int) ($input['early_renewal_window_days'] ?? $defaults['early_renewal_window_days']))),
            'sync_day_of_month' => min(28, max(1, (int) ($input['sync_day_of_month'] ?? $defaults['sync_day_of_month']))),
            'test_cycle_minutes' => min(1440, max(1, (int) ($input['test_cycle_minutes'] ?? $defaults['test_cycle_minutes']))),
            'active_user_role' => sanitize_key((string) ($input['active_user_role'] ?? $defaults['active_user_role'])),
            'inactive_user_role' => sanitize_key((string) ($input['inactive_user_role'] ?? $defaults['inactive_user_role'])),
            'queue_batch_size' => min(1000, max(25, (int) ($input['queue_batch_size'] ?? $defaults['queue_batch_size']))),
            'queue_concurrent_batches' => min(20, max(1, (int) ($input['queue_concurrent_batches'] ?? $defaults['queue_concurrent_batches']))),
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

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Woo Subs-Zero', 'woo-subzero') . '</h1>';
        echo '<p>' . esc_html__('Subscription controls only. Payment gateways remain managed in WooCommerce > Settings > Payments.', 'woo-subzero') . '</p>';

        $this->render_settings_tabs($tabs, $active_tab);

        echo '<form method="post" action="options.php">';
        settings_fields(self::SETTINGS_PAGE);
        $this->render_settings_section($active_section);
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function render_behavior_section(): void
    {
        echo '<p>' . esc_html__('Configure retry behavior, manual renewals, and parity features without duplicating gateway credentials.', 'woo-subzero') . '</p>';
    }

    public function render_queue_section(): void
    {
        echo '<p>' . esc_html__('Tune Action Scheduler throughput for high-volume subscription stores.', 'woo-subzero') . '</p>';
    }

    public function render_testing_section(): void
    {
        echo '<p>' . esc_html__('Use accelerated billing for QA only. This simulates recurring cycles in minutes instead of real billing periods.', 'woo-subzero') . '</p>';
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
            'enable_retries' => __('Queues failed automatic renewal payments for retry using the configured retry policy.', 'woo-subzero'),
            'enable_retry_emails_customer' => __('Sends customers retry-related notifications after failed renewal payments.', 'woo-subzero'),
            'enable_retry_emails_admin' => __('Sends retry-related notifications to the store owner.', 'woo-subzero'),
            'enable_switching' => __('Allows customers to switch between eligible subscription plans.', 'woo-subzero'),
            'enable_synchronization' => __('Aligns renewals to synchronized day-of-month billing dates.', 'woo-subzero'),
            'enable_early_renewal' => __('Lets customers renew before the next scheduled payment date.', 'woo-subzero'),
            'enable_resubscribe' => __('Lets customers start a new subscription from a cancelled or expired one.', 'woo-subzero'),
            'enable_role_transitions' => __('Automatically updates customer roles when subscription status changes.', 'woo-subzero'),
            'enable_test_mode' => __('Runs recurring schedule calculations in minute-based test cycles instead of real day/week/month/year periods.', 'woo-subzero'),
            'enable_test_cycle_notifications' => __('Adds a subscription note and fires a hook each accelerated cycle for easier QA verification.', 'woo-subzero'),
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
            'queue' => array(
                'label' => __('Queue', 'woo-subzero'),
                'section' => 'wsz_subs_queue',
            ),
        );
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
            'enable_manual_renewals' => 'yes',
            'enable_retries' => 'yes',
            'enable_retry_emails_customer' => 'no',
            'enable_retry_emails_admin' => 'no',
            'enable_switching' => 'no',
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
            'enable_test_cycle_notifications' => 'no',
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
