<?php

defined('ABSPATH') || exit;

if (class_exists('WSZ_Woo_Subzero', false)) {
    return;
}

final class WSZ_Woo_Subzero
{
    private static ?WSZ_Woo_Subzero $instance = null;

    private bool $bootstrapped = false;

    private ?WSZ_Subscription_Manager $subscription_manager = null;

    private ?WSZ_Payment_Handler $payment_handler = null;

    private ?WSZ_Retry_Manager $retry_manager = null;

    private ?WSZ_Renewal_Engine $renewal_engine = null;

    private ?WSZ_Checkout_Handler $checkout_handler = null;

    private ?WSZ_Webhook_Handler $webhook_handler = null;

    private ?WSZ_Admin_Settings $admin_settings = null;

    private ?WSZ_Admin_Subscriptions $admin_subscriptions = null;

    private ?WSZ_Switching_Manager $switching_manager = null;

    private ?WSZ_Synchronization_Manager $synchronization_manager = null;

    private ?WSZ_Early_Renewal_Manager $early_renewal_manager = null;

    private ?WSZ_Customer_Actions_Manager $customer_actions_manager = null;

    private ?WSZ_Product_Type_Manager $product_type_manager = null;

    public static function instance(): WSZ_Woo_Subzero
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        if (!get_option('wsz_subs_options')) {
            add_option(
                'wsz_subs_options',
                array(
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
                )
            );
        }
    }

    public static function deactivate(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wsz_subs_process_renewal', array(), 'wsz-subscriptions');
            as_unschedule_all_actions('wsz_subs_process_retry', array(), 'wsz-subscriptions');
            as_unschedule_all_actions('wsz_subs_finalize_pending_cancel', array(), 'wsz-subscriptions');
            as_unschedule_all_actions('wsz_subs_expire_subscription', array(), 'wsz-subscriptions');
        }
    }

    private function __construct()
    {
        add_action('plugins_loaded', array($this, 'bootstrap'), 20);
    }

    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'render_missing_woocommerce_notice'));
            return;
        }

        $this->load_files();

        $this->subscription_manager = new WSZ_Subscription_Manager();
        $this->product_type_manager = new WSZ_Product_Type_Manager();
        $this->payment_handler = new WSZ_Payment_Handler($this->subscription_manager);
        $this->retry_manager = new WSZ_Retry_Manager($this->subscription_manager, $this->payment_handler);
        $this->switching_manager = new WSZ_Switching_Manager($this->subscription_manager);
        $this->synchronization_manager = new WSZ_Synchronization_Manager($this->subscription_manager);
        $this->early_renewal_manager = new WSZ_Early_Renewal_Manager($this->subscription_manager);
        $this->renewal_engine = new WSZ_Renewal_Engine(
            $this->subscription_manager,
            $this->payment_handler,
            $this->retry_manager
        );
        $this->checkout_handler = new WSZ_Checkout_Handler($this->subscription_manager);
        $this->webhook_handler = new WSZ_Webhook_Handler($this->subscription_manager);
        $this->customer_actions_manager = new WSZ_Customer_Actions_Manager(
            $this->subscription_manager,
            $this->early_renewal_manager,
            $this->switching_manager
        );
        $this->admin_settings = new WSZ_Admin_Settings();
        $this->admin_subscriptions = new WSZ_Admin_Subscriptions($this->subscription_manager);

        $this->subscription_manager->init();
        $this->product_type_manager->init();
        $this->payment_handler->init();
        $this->retry_manager->init();
        $this->switching_manager->init();
        $this->synchronization_manager->init();
        $this->early_renewal_manager->init();
        $this->renewal_engine->init();
        $this->checkout_handler->init();
        $this->webhook_handler->init();
        $this->customer_actions_manager->init();

        if (is_admin()) {
            $this->admin_settings->init();
            $this->admin_subscriptions->init();
        }

        do_action('wsz_subs_bootstrapped', $this);

        $this->bootstrapped = true;
    }

    public function render_missing_woocommerce_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Woo Subs-Zero requires WooCommerce to be active.', 'woo-subzero');
        echo '</p></div>';
    }

    private function load_files(): void
    {
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-subscription-manager.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-product-type-manager.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-renewal-engine.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-retry-manager.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-switching-manager.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-synchronization-manager.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-early-renewal-manager.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-customer-actions-manager.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-checkout-handler.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-wsz-webhook-handler.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/admin/class-wsz-admin-settings.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'includes/admin/class-wsz-admin-subscriptions.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'src/Payment/class-wsz-payment-handler.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'src/Payment/Gateway/class-wsz-test-card-gateway.php';
        require_once WSZ_WOO_SUBZERO_PATH . 'src/Payment/Gateway/class-wsz-tokenized-gateway.php';
    }
}
