<?php

defined('ABSPATH') || exit;

/** Customer account and notification flow for WSZ-owned subscriptions. */
final class WSZ_Customer_Subscriptions
{
    private const ENDPOINT = 'wsz-subscriptions';
    private const REMINDER_HOOK = 'wsz_subs_manual_renewal_reminder';
    private const REMINDER_RETRY_HOOK = 'wsz_subs_manual_renewal_reminder_retry';
    private const REMINDER_MAX_RETRIES = 3;
    private WSZ_Subscription_Manager $manager;
    private WSZ_Renewal_Engine $engine;

    public function __construct(WSZ_Subscription_Manager $manager, WSZ_Renewal_Engine $engine)
    {
        $this->manager = $manager;
        $this->engine = $engine;
    }

    public function init(): void
    {
        add_filter('woocommerce_email_classes', array($this, 'register_email'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_email_preview'));
        add_filter('woocommerce_get_query_vars', array($this, 'register_query_var'));
        add_filter('woocommerce_account_menu_items', array($this, 'account_menu'));
        add_filter('woocommerce_get_endpoint_url', array($this, 'endpoint_url'), 10, 4);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array($this, 'render_account'));
        add_action('wsz_subs_manual_renewal_pending_payment', array($this, 'notify_manual_renewal'), 10, 2);
        add_action('woocommerce_order_status_changed', array($this, 'complete_paid_manual_renewal'), 30, 4);
        add_action('woocommerce_order_status_changed', array($this, 'cancel_finished_reminder'), 40, 4);
        add_action(self::REMINDER_HOOK, array($this, 'send_manual_reminder'), 10, 1);
        add_action(self::REMINDER_RETRY_HOOK, array($this, 'send_manual_reminder'), 10, 2);
    }

    public function enqueue_email_preview(): void
    {
        if (!current_user_can('manage_woocommerce')
            || !isset($_GET['page'], $_GET['tab'])
            || 'wc-settings' !== sanitize_key(wp_unslash($_GET['page']))
            || 'email' !== sanitize_key(wp_unslash($_GET['tab']))) {
            return;
        }
        wp_enqueue_script('wsz-email-preview', WSZ_WOO_SUBZERO_URL . 'assets/js/wsz-email-preview.js', array(), WSZ_WOO_SUBZERO_VERSION, true);
    }

    public function register_email(array $emails): array
    {
        if (class_exists('WC_Email')) {
            require_once WSZ_WOO_SUBZERO_PATH . 'src/Email/class-wsz-manual-renewal-email.php';
            $emails['WSZ_Manual_Renewal_Email'] = new WSZ_Manual_Renewal_Email();
            $emails['WSZ_Manual_Renewal_Reminder_Email'] = new WSZ_Manual_Renewal_Reminder_Email();
        }
        return $emails;
    }

    public function register_query_var(array $vars): array
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;
        return $vars;
    }

    public function account_menu(array $items): array
    {
        $result = array();
        foreach ($items as $key => $label) {
            if ('customer-logout' === $key) {
                $result[self::ENDPOINT] = __('Subscriptions', 'woo-subzero');
            }
            $result[$key] = $label;
        }
        if (!isset($result[self::ENDPOINT])) {
            $result[self::ENDPOINT] = __('Subscriptions', 'woo-subzero');
        }
        return $result;
    }

    public function endpoint_url(string $url, string $endpoint, string $value, string $permalink): string
    {
        if (self::ENDPOINT !== $endpoint) {
            return $url;
        }
        // Query URLs work immediately on activation and on sites with stale rewrite rules.
        return add_query_arg(self::ENDPOINT, $value ?: '1', $permalink);
    }

    public function render_account(): void
    {
        $customer_id = get_current_user_id();
        if ($customer_id <= 0) {
            return;
        }
        $subscriptions = wc_get_orders(array(
            'type' => WSZ_Subscription_Manager::ORDER_TYPE,
            'customer_id' => $customer_id,
            'status' => array('wc-pending', 'wc-active', 'wc-on-hold', 'wc-pending-cancel', 'wc-cancelled', 'wc-expired'),
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        echo '<h2>' . esc_html__('Subscriptions', 'woo-subzero') . '</h2>';
        if (empty($subscriptions)) {
            echo '<p>' . esc_html__('You have no subscriptions yet.', 'woo-subzero') . '</p>';
            return;
        }
        echo '<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table"><thead><tr>';
        foreach (array(__('Subscription', 'woo-subzero'), __('Status', 'woo-subzero'), __('Next payment', 'woo-subzero'), __('Payment', 'woo-subzero')) as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($subscriptions as $subscription) {
            if (!($subscription instanceof WC_Order) || (int) $subscription->get_customer_id() !== $customer_id) {
                continue;
            }
            $names = array();
            foreach ($subscription->get_items() as $item) {
                $names[] = $item->get_name();
            }
            $ended = in_array($subscription->get_status(), array('cancelled', 'expired', 'pending-cancel'), true);
            $timestamp = $ended ? 0 : $this->manager->get_next_payment_timestamp($subscription);
            echo '<tr><td data-title="' . esc_attr__('Subscription', 'woo-subzero') . '">' . esc_html(implode(', ', $names) ?: sprintf(__('Subscription #%d', 'woo-subzero'), $subscription->get_id())) . '</td>';
            echo '<td data-title="' . esc_attr__('Status', 'woo-subzero') . '">' . esc_html(wc_get_order_status_name($subscription->get_status())) . '</td>';
            echo '<td data-title="' . esc_attr__('Next payment', 'woo-subzero') . '">' . esc_html($timestamp > 0 ? wp_date(get_option('date_format'), $timestamp) : '—') . '</td>';
            echo '<td data-title="' . esc_attr__('Payment', 'woo-subzero') . '">';
            $order = $ended ? null : $this->pending_order($subscription);
            if ($order) {
                echo '<span>' . esc_html__('Payment due:', 'woo-subzero') . ' ' . wp_kses_post($order->get_formatted_order_total()) . '</span> ';
                echo '<a class="woocommerce-button button pay" href="' . esc_url($order->get_checkout_payment_url()) . '">' . esc_html__('Pay renewal', 'woo-subzero') . '</a>';
            } else {
                echo esc_html__('No payable renewal', 'woo-subzero');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function pending_order(WC_Order $subscription): ?WC_Order
    {
        $ids = $this->manager->get_related_orders($subscription, 'renewal');
        rsort($ids);
        foreach ($ids as $id) {
            $order = wc_get_order((int) $id);
            if ($order instanceof WC_Order && (int) $order->get_customer_id() === (int) $subscription->get_customer_id()
                && (int) $order->get_meta('_wsz_subscription_id', true) === (int) $subscription->get_id()
                && $order->needs_payment() && '' === (string) $order->get_meta('_wsz_paynl_sepa_state', true)) {
                return $order;
            }
        }
        return null;
    }

    /** Check whether an administrator may send a manual payment request for this renewal. */
    public function admin_payment_eligibility($order): string
    {
        if (!($order instanceof WC_Order) || !$order->needs_payment()) {
            return 'not_payable';
        }
        if ('' !== (string) $order->get_meta('_wsz_paynl_sepa_state', true)
            || 'yes' === (string) $order->get_meta('_wsz_async_payment_pending', true)) {
            return 'collection_pending';
        }
        $subscription_id = (int) $order->get_meta('_wsz_subscription_id', true);
        $subscription = $subscription_id > 0 ? $this->manager->get_subscription($subscription_id) : null;
        if (!($subscription instanceof WC_Order) || (int) $order->get_customer_id() <= 0
            || (int) $order->get_customer_id() !== (int) $subscription->get_customer_id()
            || !in_array((int) $order->get_id(), array_map('intval', $this->manager->get_related_orders($subscription, 'renewal')), true)
            || !in_array($subscription->get_status(), array('active', 'on-hold'), true)) {
            return 'subscription_review';
        }
        if (!$this->manager->is_manual_renewal($subscription)) {
            return 'automatic_renewal';
        }
        if (!is_email((string) $order->get_billing_email())) {
            return 'invalid_email';
        }
        return 'eligible';
    }

    /** Only offer recovery when the known retry sequence has finished and the debt is unambiguous. */
    public function manual_recovery_eligibility($order): string
    {
        $state = $this->admin_payment_eligibility($order);
        if ('automatic_renewal' !== $state) return $state;
        if ('failed' !== $order->get_status() || !is_email((string) $order->get_billing_email())) return 'recovery_review';
        $subscription = $this->manager->get_subscription((int) $order->get_meta('_wsz_subscription_id', true));
        if (!($subscription instanceof WC_Order) || $this->manager->has_completed_all_payments($subscription)
            || (int) $subscription->get_meta('_wsz_pending_renewal_order_id', true) > 0
            || '' !== (string) $subscription->get_meta('_wsz_paynl_sepa_authorization', true)
            || '' !== (string) $subscription->get_meta('_wsz_paynl_sepa_mandate_state', true)) return 'recovery_review';
        $records = json_decode((string) $order->get_meta('_wsz_retry_records', true), true);
        if (!is_array($records) || !$records || count($records) > 100) return 'recovery_review';
        $latest_attempt = 0;
        $exhausted = false;
        foreach ($records as $attempt => $record) {
            if (!ctype_digit((string) $attempt) || (int) $attempt < 1 || !is_array($record)
                || !in_array($record['status'] ?? '', array('failed', 'cancelled', 'complete'), true)) return 'recovery_review';
            if ((int) $attempt > $latest_attempt) {
                $latest_attempt = (int) $attempt;
                $exhausted = 'failed' === ($record['status'] ?? '') && 'retry_rules_exhausted' === ($record['reason'] ?? '');
            }
        }
        if (!$exhausted) return 'recovery_review';
        foreach ($this->manager->get_related_orders($subscription, 'renewal') as $related_id) {
            if ((int) $related_id === (int) $order->get_id()) continue;
            $related = wc_get_order((int) $related_id);
            if (!($related instanceof WC_Order) || $related->needs_payment() || 'on-hold' === $related->get_status()) return 'recovery_review';
        }
        return 'recovery_ready';
    }

    public function enable_manual_recovery(int $order_id): string
    {
        $order = wc_get_order($order_id);
        if ('recovery_ready' !== $this->manual_recovery_eligibility($order)) return 'recovery_review';
        $subscription_id = (int) $order->get_meta('_wsz_subscription_id', true);
        if (!$this->manager->acquire_lock('renewal', $subscription_id, 300)) return 'busy';
        $locked = array();
        try {
            $records = json_decode((string) $order->get_meta('_wsz_retry_records', true), true);
            foreach (array_keys($records) as $attempt) {
                if (!$this->manager->acquire_lock('retry_' . $order_id, (int) $attempt, 300)) return 'busy';
                $locked[] = (int) $attempt;
            }
            $fresh = wc_get_order($order_id);
            if ('recovery_ready' !== $this->manual_recovery_eligibility($fresh)
                || (int) $fresh->get_meta('_wsz_subscription_id', true) !== $subscription_id) return 'recovery_review';
            $fresh_records = json_decode((string) $fresh->get_meta('_wsz_retry_records', true), true);
            foreach (array_keys($fresh_records) as $attempt) {
                if (!in_array((int) $attempt, $locked, true)) return 'busy';
            }
            $subscription = $this->manager->get_subscription($subscription_id);
            if (!($subscription instanceof WC_Order)) return 'recovery_review';
            $this->manager->set_manual_renewal($subscription, true);
            $subscription->add_order_note(__('Administrator switched to manual renewal for an unpaid renewal order.', 'woo-subzero'));
            return 'manual_enabled';
        } finally {
            foreach ($locked as $attempt) $this->manager->release_lock('retry_' . $order_id, $attempt);
            $this->manager->release_lock('renewal', $subscription_id);
        }
    }

    /** Record mailer acceptance or failure, without changing the outcome of the send. */
    private function attempt_payment_email(WC_Order $order, string $type, callable $send): bool
    {
        try {
            $accepted = (bool) $send();
        } catch (Throwable $error) {
            $this->record_payment_email_attempt($order, $type, false);
            throw $error;
        }
        $this->record_payment_email_attempt($order, $type, $accepted);
        return $accepted;
    }

    private function record_payment_email_attempt(WC_Order $order, string $type, bool $accepted): void
    {
        try {
            $history = $order->get_meta('_wsz_manual_email_history', true);
            $history = is_array($history) ? $history : array();
            $history[] = array('type' => $type, 'result' => $accepted ? 'accepted' : 'failed', 'time' => time());
            $order->update_meta_data('_wsz_manual_email_history', $history);
            $order->save();
        } catch (Throwable $error) {
            wc_get_logger()->warning('Could not record renewal email activity.', array('source' => 'woo-subzero'));
        }
    }

    /** Explicit admin action; it does not start a backdated reminder schedule. */
    public function send_admin_payment_email(int $order_id): string
    {
        $order = wc_get_order($order_id);
        $eligibility = $this->admin_payment_eligibility($order);
        if ('eligible' !== $eligibility) {
            return $eligibility;
        }
        if (!$this->manager->acquire_lock('manual_notice', $order_id, 300)) {
            return 'busy';
        }
        try {
            $order = wc_get_order($order_id);
            $eligibility = $this->admin_payment_eligibility($order);
            if ('eligible' !== $eligibility) {
                return $eligibility;
            }
            $subscription = $this->manager->get_subscription((int) $order->get_meta('_wsz_subscription_id', true));
            $mailer = function_exists('WC') && is_object(WC()) && is_callable(array(WC(), 'mailer')) ? WC()->mailer() : null;
            $emails = is_object($mailer) && is_callable(array($mailer, 'get_emails')) ? $mailer->get_emails() : array();
            $email = $emails['WSZ_Manual_Renewal_Email'] ?? null;
            if (!($email instanceof WSZ_Manual_Renewal_Email) || !$email->is_enabled()) {
                return 'email_disabled';
            }
            // Ensure a payment made immediately after delivery completes the manual cycle.
            $order->update_meta_data('_wsz_manual_renewal_waiting', 'yes');
            $order->save();
            $order = wc_get_order($order_id);
            $eligibility = $this->admin_payment_eligibility($order);
            if ('eligible' !== $eligibility) {
                return $eligibility;
            }
            $subscription = $this->manager->get_subscription((int) $order->get_meta('_wsz_subscription_id', true));
            if (!$this->attempt_payment_email($order, 'admin', static function () use ($email, $subscription, $order) { return $email->trigger($subscription, $order); })) {
                $order->add_order_note(__('The admin-requested renewal payment email could not be sent.', 'woo-subzero'));
                return 'send_failed';
            }
            $order->update_meta_data('_wsz_manual_renewal_notice_sent', 'yes');
            $order->update_meta_data('_wsz_manual_renewal_admin_sent_at', time());
            $order->save();
            $order->add_order_note(__('An administrator sent the renewal payment email to the customer.', 'woo-subzero'));
            return 'sent';
        } catch (Throwable $error) {
            if ($order instanceof WC_Order) {
                $order->add_order_note(__('The admin-requested renewal payment email could not be sent.', 'woo-subzero'));
            }
            wc_get_logger()->warning('Admin-requested renewal payment email failed.', array('source' => 'woo-subzero'));
            return 'send_failed';
        } finally {
            $this->manager->release_lock('manual_notice', $order_id);
        }
    }

    public function notify_manual_renewal($subscription, $order): void
    {
        if (!($subscription instanceof WC_Order) || !($order instanceof WC_Order)
            || !$this->manager->is_manual_renewal($subscription) || !$order->needs_payment()
            || (int) $subscription->get_customer_id() <= 0
            || (int) $subscription->get_customer_id() !== (int) $order->get_customer_id()
            || (int) $order->get_meta('_wsz_subscription_id', true) !== (int) $subscription->get_id()) {
            return;
        }
        $order->update_meta_data('_wsz_manual_renewal_waiting', 'yes');
        if ('' === (string) $order->get_meta('_wsz_manual_payment_number', true)) {
            $total = $this->manager->get_total_payments($subscription);
            $completed = $this->manager->get_completed_payments($subscription);
            if ($total > 0 && $completed >= 0 && $completed < $total) {
                $order->update_meta_data('_wsz_manual_payment_number', $completed + 1);
                $order->update_meta_data('_wsz_manual_payment_total', $total);
            }
        }
        $order->save();
        $this->schedule_manual_reminder($subscription, $order);
        $email = (string) $order->get_billing_email();
        if (!is_email($email) || !$this->manager->acquire_lock('manual_notice', (int) $order->get_id(), 300)) {
            return;
        }
        try {
            $fresh = wc_get_order($order->get_id());
            if ($fresh instanceof WC_Order) {
                $order = $fresh;
            }
            if ('yes' === $order->get_meta('_wsz_manual_renewal_notice_sent', true) || !$order->needs_payment()
                || (int) $order->get_customer_id() !== (int) $subscription->get_customer_id()
                || (int) $order->get_meta('_wsz_subscription_id', true) !== (int) $subscription->get_id()) {
                return;
            }
            $mailer = function_exists('WC') && is_object(WC()) && is_callable(array(WC(), 'mailer')) ? WC()->mailer() : null;
            if (is_object($mailer) && is_callable(array($mailer, 'get_emails'))) {
                $emails = $mailer->get_emails();
                $notification = $emails['WSZ_Manual_Renewal_Email'] ?? null;
                if (!($notification instanceof WSZ_Manual_Renewal_Email)) {
                    throw new RuntimeException('Manual renewal email is not registered.');
                }
                if (!$notification->is_enabled()) {
                    return;
                }
                $sent = $this->attempt_payment_email($order, 'initial', static function () use ($notification, $subscription, $order) { return $notification->trigger($subscription, $order); });
            } else {
                // Minimal WordPress environments may not initialize the WooCommerce mailer.
                $message = WSZ_Renewal_Email_Template::html($subscription, $order, __('Your renewal is ready', 'woo-subzero'));
                $sent = $this->attempt_payment_email($order, 'initial', static function () use ($email, $message) { return wp_mail($email, __('Your subscription renewal is ready to pay', 'woo-subzero'), $message, array('Content-Type: text/html; charset=UTF-8')); });
            }
            if ($sent) {
                $order->update_meta_data('_wsz_manual_renewal_notice_sent', 'yes');
                $order->save();
            } else {
                $order->add_order_note(__('The manual renewal payment email could not be sent.', 'woo-subzero'));
            }
        } catch (Throwable $error) {
            $order->add_order_note(__('The manual renewal payment email could not be sent.', 'woo-subzero'));
            wc_get_logger()->warning('Manual renewal notification failed.', array('source' => 'woo-subzero'));
        } finally {
            $this->manager->release_lock('manual_notice', (int) $order->get_id());
        }
    }

    private function reminder_settings(): array
    {
        $settings = function_exists('get_option') ? get_option('woocommerce_wsz_manual_renewal_reminder_settings', array()) : array();
        return is_array($settings) ? $settings : array();
    }

    public function schedule_manual_reminder($subscription, $order): void
    {
        $settings = $this->reminder_settings();
        if (!($subscription instanceof WC_Order) || !($order instanceof WC_Order)
            || 'no' === ($settings['enabled'] ?? 'yes') || !$this->manager->is_manual_renewal($subscription)
            || !$order->needs_payment() || (int) $order->get_customer_id() <= 0
            || (int) $order->get_customer_id() !== (int) $subscription->get_customer_id()
            || (int) $order->get_meta('_wsz_subscription_id', true) !== (int) $subscription->get_id()
            || 'yes' === $order->get_meta('_wsz_manual_reminder_sent', true)) {
            return;
        }
        $due = (int) $order->get_meta('_wsz_manual_reminder_due', true);
        if ($due <= 0) {
            $configured_days = filter_var($settings['reminder_days'] ?? 15, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 365)));
            $days = false === $configured_days ? 15 : (int) $configured_days;
            $created = $order->get_date_created();
            $start = is_object($created) && is_callable(array($created, 'getTimestamp')) ? (int) $created->getTimestamp() : time();
            $due = $start + $days * DAY_IN_SECONDS;
            $order->update_meta_data('_wsz_manual_reminder_due', $due);
            $order->save();
        }
        if (!function_exists('as_schedule_single_action')) {
            $order->add_order_note(__('The renewal reminder could not be scheduled: Action Scheduler is unavailable.', 'woo-subzero'));
            return;
        }
        $args = array('order_id' => (int) $order->get_id());
        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::REMINDER_HOOK, $args, WSZ_Subscription_Manager::ACTION_GROUP)) {
            return;
        }
        if (!as_schedule_single_action(max(time() + 1, $due), self::REMINDER_HOOK, $args, WSZ_Subscription_Manager::ACTION_GROUP, false)) {
            $order->add_order_note(__('The renewal reminder could not be scheduled.', 'woo-subzero'));
        }
    }

    public function send_manual_reminder($order_id, $retry_attempt = 0): void
    {
        $order_id = (int) $order_id;
        $retry_attempt = max(0, (int) $retry_attempt);
        if ($order_id <= 0 || 'no' === ($this->reminder_settings()['enabled'] ?? 'yes')) {
            return;
        }
        if (!$this->manager->acquire_lock('manual_notice', $order_id, 300)) {
            if (function_exists('as_schedule_single_action')) {
                $hook = $retry_attempt > 0 ? self::REMINDER_RETRY_HOOK : self::REMINDER_HOOK;
                $args = $retry_attempt > 0 ? array('order_id' => $order_id, 'attempt' => $retry_attempt) : array('order_id' => $order_id);
                as_schedule_single_action(time() + 300, $hook, $args, WSZ_Subscription_Manager::ACTION_GROUP);
            }
            return;
        }
        $attempted = false;
        try {
            $order = wc_get_order($order_id);
            if (!($order instanceof WC_Order) || !$order->needs_payment()
                || 'yes' !== $order->get_meta('_wsz_manual_renewal_waiting', true)
                || 'yes' === $order->get_meta('_wsz_manual_reminder_sent', true)
                || 'yes' === $order->get_meta('_wsz_manual_reminder_retry_exhausted', true)
                || '' !== (string) $order->get_meta('_wsz_paynl_sepa_state', true)
                || (int) $order->get_meta('_wsz_manual_reminder_due', true) <= 0
                || (int) $order->get_meta('_wsz_manual_reminder_due', true) > time()) {
                return;
            }
            $recorded_attempt = (int) $order->get_meta('_wsz_manual_reminder_retry_attempt', true);
            if (($retry_attempt > 0 && ($retry_attempt !== $recorded_attempt
                    || (int) $order->get_meta('_wsz_manual_reminder_retry_due', true) > time()))
                || (0 === $retry_attempt && $recorded_attempt > 0)) {
                return;
            }
            $subscription = $this->manager->get_subscription((int) $order->get_meta('_wsz_subscription_id', true));
            if (!($subscription instanceof WC_Order) || (int) $order->get_customer_id() <= 0
                || (int) $order->get_customer_id() !== (int) $subscription->get_customer_id()
                || !in_array($subscription->get_status(), array('active', 'on-hold'), true)
                || !$this->manager->is_manual_renewal($subscription) || !is_email((string) $order->get_billing_email())) {
                return;
            }
            $mailer = function_exists('WC') && is_object(WC()) && is_callable(array(WC(), 'mailer')) ? WC()->mailer() : null;
            $emails = is_object($mailer) && is_callable(array($mailer, 'get_emails')) ? $mailer->get_emails() : array();
            $notification = $emails['WSZ_Manual_Renewal_Reminder_Email'] ?? null;
            if (!($notification instanceof WSZ_Manual_Renewal_Email) || !$notification->is_enabled()) {
                return;
            }
            // Reload immediately before sending so a paid or cancelled order is not reminded.
            $fresh = wc_get_order($order_id);
            if (!($fresh instanceof WC_Order) || !$fresh->needs_payment()
                || (int) $fresh->get_customer_id() !== (int) $subscription->get_customer_id()
                || (int) $fresh->get_meta('_wsz_subscription_id', true) !== (int) $subscription->get_id()) {
                return;
            }
            $attempted = true;
            $sent = $this->attempt_payment_email($fresh, 'reminder', static function () use ($notification, $subscription, $fresh) { return $notification->trigger($subscription, $fresh); });
            $attempted = false;
            if ($sent) {
                $fresh->update_meta_data('_wsz_manual_reminder_sent', 'yes');
                $fresh->update_meta_data('_wsz_manual_reminder_retry_due', '');
                $fresh->save();
            } else {
                $this->schedule_reminder_retry($fresh, $retry_attempt);
            }
        } catch (Throwable $error) {
            if ($attempted && isset($fresh) && $fresh instanceof WC_Order) {
                $this->schedule_reminder_retry($fresh, $retry_attempt);
            } elseif (isset($order) && $order instanceof WC_Order) {
                $order->add_order_note(__('The manual renewal reminder could not be sent.', 'woo-subzero'));
            }
            wc_get_logger()->warning('Manual renewal reminder failed.', array('source' => 'woo-subzero'));
        } finally {
            $this->manager->release_lock('manual_notice', $order_id);
        }
    }

    private function schedule_reminder_retry(WC_Order $order, int $attempt): void
    {
        $order->add_order_note(__('The manual renewal reminder could not be sent.', 'woo-subzero'));
        if ($attempt >= self::REMINDER_MAX_RETRIES) {
            $order->update_meta_data('_wsz_manual_reminder_retry_exhausted', 'yes');
            $order->update_meta_data('_wsz_manual_reminder_retry_due', '');
            $order->save();
            $order->add_order_note(__('The manual renewal reminder failed after all retry attempts. Please check email delivery.', 'woo-subzero'));
            return;
        }
        $next = $attempt + 1;
        $due = time() + HOUR_IN_SECONDS;
        $order->update_meta_data('_wsz_manual_reminder_retry_attempt', $next);
        $order->update_meta_data('_wsz_manual_reminder_retry_due', $due);
        $order->save();
        $args = array('order_id' => (int) $order->get_id(), 'attempt' => $next);
        // Action Scheduler uniqueness is scoped by hook and group, not by order.
        if (!function_exists('as_schedule_single_action') || !as_schedule_single_action($due, self::REMINDER_RETRY_HOOK, $args, WSZ_Subscription_Manager::ACTION_GROUP, false)) {
            $order->add_order_note(__('The manual renewal reminder retry could not be scheduled. Please check email delivery.', 'woo-subzero'));
        }
    }

    public function cancel_finished_reminder(int $order_id, string $old_status, string $new_status, $order): void
    {
        if ($order_id > 0 && in_array($new_status, array('processing', 'completed', 'cancelled', 'refunded'), true)
            && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::REMINDER_HOOK, array('order_id' => $order_id), WSZ_Subscription_Manager::ACTION_GROUP);
            for ($attempt = 1; $attempt <= self::REMINDER_MAX_RETRIES; $attempt++) {
                as_unschedule_all_actions(self::REMINDER_RETRY_HOOK, array('order_id' => $order_id, 'attempt' => $attempt), WSZ_Subscription_Manager::ACTION_GROUP);
            }
        }
    }

    public function complete_paid_manual_renewal(int $order_id, string $old_status, string $new_status, $order): void
    {
        if (!in_array($new_status, array('processing', 'completed'), true)) {
            return;
        }
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);
        }
        if (!($order instanceof WC_Order) || !$order->is_paid() || 'yes' !== $order->get_meta('_wsz_manual_renewal_waiting', true)
            || 'yes' === $order->get_meta('_wsz_cycle_completed', true)) {
            return;
        }
        $subscription = $this->manager->get_subscription((int) $order->get_meta('_wsz_subscription_id', true));
        if (!($subscription instanceof WC_Order) || (int) $subscription->get_customer_id() !== (int) $order->get_customer_id()) {
            return;
        }
        $this->engine->handle_async_payment_complete($subscription, $order);
    }
}
