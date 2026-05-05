<?php

defined('ABSPATH') || exit;

class WSZ_Retry_Manager
{
    private const ATTEMPT_META_KEY = '_wsz_retry_attempt';

    private const RECORDS_META_KEY = '_wsz_retry_records';

    private WSZ_Subscription_Manager $subscription_manager;

    private WSZ_Payment_Handler $payment_handler;

    public function __construct(WSZ_Subscription_Manager $subscription_manager, WSZ_Payment_Handler $payment_handler)
    {
        $this->subscription_manager = $subscription_manager;
        $this->payment_handler = $payment_handler;
    }

    public function init(): void
    {
        add_action('wsz_subs_process_retry', array($this, 'process_retry'), 10, 3);
    }

    public function get_retry_rules(): array
    {
        $rules = array(
            array('interval' => 12 * HOUR_IN_SECONDS),
            array('interval' => 12 * HOUR_IN_SECONDS),
            array('interval' => 24 * HOUR_IN_SECONDS),
            array('interval' => 48 * HOUR_IN_SECONDS),
            array('interval' => 72 * HOUR_IN_SECONDS),
        );

        $filtered = apply_filters('wsz_subs_retry_rules', $rules);

        if (!is_array($filtered) || empty($filtered)) {
            return $rules;
        }

        $normalized = array();

        foreach ($filtered as $rule) {
            if (!is_array($rule) || !isset($rule['interval'])) {
                continue;
            }

            $interval = max(1, (int) $rule['interval']);
            $normalized[] = array('interval' => $interval);
        }

        $rules = !empty($normalized) ? $normalized : $rules;
        $test_interval = $this->get_test_retry_interval();

        if ($test_interval > 0) {
            foreach ($rules as $index => $rule) {
                $rules[$index]['interval'] = $test_interval;
            }
        }

        return $rules;
    }

    /**
     * @param WC_Order $subscription
     * @param WC_Order $renewal_order
     */
    public function queue_retry($subscription, $renewal_order, string $reason = ''): bool
    {
        if (!($subscription instanceof WC_Order) || !($renewal_order instanceof WC_Order)) {
            return false;
        }

        $settings = (array) get_option('wsz_subs_options', array());
        $retries_enabled = $settings['enable_retries'] ?? 'yes';

        if ('yes' !== $retries_enabled) {
            return false;
        }

        if (!$renewal_order->needs_payment()) {
            return false;
        }

        $rules = $this->get_retry_rules();
        $next_attempt = ((int) $renewal_order->get_meta(self::ATTEMPT_META_KEY, true)) + 1;

        if ($next_attempt > count($rules)) {
            $this->update_retry_record($renewal_order, $next_attempt - 1, 'failed', 0, 'retry_rules_exhausted');
            $this->safe_update_order_status(
                $subscription,
                $renewal_order,
                $next_attempt - 1,
                'failed',
                __('Retry rules exhausted.', 'woo-subzero')
            );
            $this->log_retry_event(
                'warning',
                __('Retry rules exhausted.', 'woo-subzero'),
                $subscription,
                $renewal_order,
                $next_attempt - 1,
                array('reason' => 'retry_rules_exhausted')
            );

            $this->maybe_send_retry_email(
                $subscription,
                $renewal_order,
                $next_attempt - 1,
                'exhausted'
            );

            do_action('wsz_subs_retry_exhausted', $subscription, $renewal_order);
            return false;
        }

        $scheduled_at = time() + (int) $rules[$next_attempt - 1]['interval'];

        $this->update_retry_record($renewal_order, $next_attempt, 'pending', $scheduled_at, $reason);
        $renewal_order->update_meta_data(self::ATTEMPT_META_KEY, $next_attempt);
        $renewal_order->save();

        if ($renewal_order->has_status(array('failed'))) {
            $this->safe_update_order_status(
                $subscription,
                $renewal_order,
                $next_attempt,
                'pending',
                $this->format_retry_note('queued', $next_attempt)
            );
        }

        $this->safe_transition_subscription_status($subscription, $renewal_order, $next_attempt);

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                $scheduled_at,
                'wsz_subs_process_retry',
                array(
                    'subscription_id' => $subscription->get_id(),
                    'order_id' => $renewal_order->get_id(),
                    'attempt' => $next_attempt,
                ),
                WSZ_Subscription_Manager::ACTION_GROUP,
                true
            );
        }

        $this->log_retry_event(
            'info',
            __('Retry payment queued.', 'woo-subzero'),
            $subscription,
            $renewal_order,
            $next_attempt,
            array(
                'scheduled_at' => $scheduled_at,
                'interval' => (int) $rules[$next_attempt - 1]['interval'],
                'reason' => $reason,
                'test_mode' => $this->get_test_retry_interval() > 0 ? 'yes' : 'no',
            )
        );

        $this->safe_do_retry_queued($subscription, $renewal_order, $next_attempt, $scheduled_at);

        $this->safe_send_retry_email($subscription, $renewal_order, $next_attempt, 'queued');

        return true;
    }

    public function process_retry(int $subscription_id, int $order_id, int $attempt): void
    {
        $subscription = $this->subscription_manager->get_subscription($subscription_id);
        $renewal_order = wc_get_order($order_id);

        if (!($subscription instanceof WC_Order) || !($renewal_order instanceof WC_Order)) {
            return;
        }

        if (!$this->subscription_manager->acquire_lock('retry_' . $order_id, $attempt, 300)) {
            return;
        }

        try {
            if (!$this->is_retry_eligible($subscription, $renewal_order, $attempt)) {
                $this->update_retry_record($renewal_order, $attempt, 'cancelled', 0, 'not_eligible');
                $this->log_retry_event(
                    'notice',
                    __('Retry payment cancelled as ineligible.', 'woo-subzero'),
                    $subscription,
                    $renewal_order,
                    $attempt,
                    array('reason' => 'not_eligible')
                );
                return;
            }

            $this->update_retry_record($renewal_order, $attempt, 'processing', 0, 'processing');
            $this->log_retry_event(
                'info',
                __('Retry payment processing started.', 'woo-subzero'),
                $subscription,
                $renewal_order,
                $attempt,
                array('amount' => (float) $renewal_order->get_total())
            );

            $amount = (float) $renewal_order->get_total();
            $this->payment_handler->dispatch_scheduled_payment($subscription, $renewal_order, $amount);

            if ($renewal_order->is_paid()) {
                $this->update_retry_record($renewal_order, $attempt, 'complete', 0, 'paid');
                $this->log_retry_event(
                    'info',
                    __('Retry payment succeeded.', 'woo-subzero'),
                    $subscription,
                    $renewal_order,
                    $attempt,
                    array('reason' => 'paid')
                );

                $this->maybe_send_retry_email($subscription, $renewal_order, $attempt, 'paid');

                if ('active' !== $subscription->get_status()) {
                    $this->subscription_manager->transition_status(
                        $subscription,
                        'active',
                        __('Retry payment succeeded.', 'woo-subzero')
                    );
                }

                do_action('wsz_subs_retry_payment_complete', $subscription, $renewal_order, $attempt);

                return;
            }

            $this->update_retry_record($renewal_order, $attempt, 'failed', 0, 'payment_not_completed');
            $this->safe_update_order_status(
                $subscription,
                $renewal_order,
                $attempt,
                'failed',
                $this->format_retry_note('failed', $attempt)
            );
            $this->log_retry_event(
                'warning',
                __('Retry payment failed.', 'woo-subzero'),
                $subscription,
                $renewal_order,
                $attempt,
                array('reason' => 'payment_not_completed')
            );
            $this->queue_retry($subscription, $renewal_order, sprintf('retry_%d_failed', $attempt));
        } catch (Throwable $throwable) {
            $this->update_retry_record($renewal_order, $attempt, 'failed', 0, $throwable->getMessage());
            $this->log_retry_event(
                'error',
                __('Retry payment processing failed.', 'woo-subzero'),
                $subscription,
                $renewal_order,
                $attempt,
                array('reason' => $throwable->getMessage())
            );
            wc_get_logger()->error(
                sprintf('Retry processing failed for renewal order %d: %s', $order_id, $throwable->getMessage()),
                array('source' => 'woo-subzero')
            );
        } finally {
            $this->subscription_manager->release_lock('retry_' . $order_id, $attempt);
        }
    }

    private function safe_update_order_status(
        WC_Order $subscription,
        WC_Order $renewal_order,
        int $attempt,
        string $status,
        string $note
    ): void {
        try {
            $renewal_order->update_status($status, $note);
        } catch (Throwable $throwable) {
            $this->log_retry_event(
                'warning',
                __('Retry order status update failed.', 'woo-subzero'),
                $subscription,
                $renewal_order,
                $attempt,
                array(
                    'target_status' => sanitize_key($status),
                    'reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                )
            );
        }
    }

    private function format_retry_note(string $event, int $attempt): string
    {
        $template = 'queued' === $event
            ? __('Retry {attempt} queued.', 'woo-subzero')
            : __('Retry {attempt} failed.', 'woo-subzero');

        return strtr($template, array('{attempt}' => (string) $attempt));
    }

    /**
     * @param WC_Order $renewal_order
     */
    public function cancel_pending_retries($renewal_order): void
    {
        if (!($renewal_order instanceof WC_Order)) {
            return;
        }

        $records = $this->get_retry_records($renewal_order);

        foreach ($records as $attempt => $record) {
            if (!isset($record['status']) || 'pending' !== $record['status']) {
                continue;
            }

            $records[$attempt]['status'] = 'cancelled';
            $records[$attempt]['updated_at'] = time();
            $records[$attempt]['reason'] = 'order_paid_or_closed';
        }

        $renewal_order->update_meta_data(self::RECORDS_META_KEY, wp_json_encode($records));
        $renewal_order->save();
    }

    /**
     * @param WC_Order $subscription
     * @param WC_Order $renewal_order
     */
    private function is_retry_eligible($subscription, $renewal_order, int $attempt): bool
    {
        if (!$renewal_order->needs_payment()) {
            return false;
        }

        $status = $renewal_order->get_status();
        if (!in_array($status, array('pending', 'failed', 'on-hold'), true)) {
            return false;
        }

        $subscription_status = $subscription->get_status();
        if (!in_array($subscription_status, array('on-hold', 'active'), true)) {
            return false;
        }

        $records = $this->get_retry_records($renewal_order);
        if (!isset($records[$attempt])) {
            return false;
        }

        return in_array($records[$attempt]['status'] ?? '', array('pending', 'processing'), true);
    }

    private function update_retry_record(
        WC_Order $renewal_order,
        int $attempt,
        string $status,
        int $scheduled_at,
        string $reason
    ): void {
        $records = $this->get_retry_records($renewal_order);

        $records[$attempt] = array(
            'attempt' => $attempt,
            'status' => sanitize_key($status),
            'scheduled_at' => $scheduled_at,
            'updated_at' => time(),
            'reason' => sanitize_text_field($reason),
        );

        $renewal_order->update_meta_data(self::RECORDS_META_KEY, wp_json_encode($records));
        $renewal_order->save();
    }

    private function maybe_send_retry_email(
        WC_Order $subscription,
        WC_Order $renewal_order,
        int $attempt,
        string $event
    ): void {
        if (!function_exists('wp_mail')) {
            return;
        }

        $options = $this->get_options();

        $subject = strtr(
            __('Subscription retry update (attempt {attempt}, event: {event})', 'woo-subzero'),
            array(
                '{attempt}' => (string) $attempt,
                '{event}' => sanitize_key($event),
            )
        );

        $message = strtr(
            __('Subscription #{subscription_id}, renewal order #{renewal_order_id}, retry attempt {attempt} has event "{event}".', 'woo-subzero'),
            array(
                '{subscription_id}' => (string) $subscription->get_id(),
                '{renewal_order_id}' => (string) $renewal_order->get_id(),
                '{attempt}' => (string) $attempt,
                '{event}' => sanitize_key($event),
            )
        );

        if ('yes' === $options['enable_retry_emails_customer']) {
            $customer_email = $subscription->get_billing_email();

            if (is_email($customer_email)) {
                wp_mail($customer_email, $subject, $message);
            }
        }

        if ('yes' === $options['enable_retry_emails_admin']) {
            $admin_email = get_option('admin_email');

            if (is_email($admin_email)) {
                wp_mail($admin_email, $subject, $message);
            }
        }
    }

    private function get_options(): array
    {
        $defaults = array(
            'enable_retry_emails_customer' => 'no',
            'enable_retry_emails_admin' => 'no',
            'enable_test_mode' => 'no',
            'test_cycle_minutes' => 1,
        );

        return wp_parse_args((array) get_option('wsz_subs_options', array()), $defaults);
    }

    private function get_test_retry_interval(): int
    {
        $options = $this->get_options();

        if ('yes' !== $options['enable_test_mode']) {
            return 0;
        }

        return max(1, (int) $options['test_cycle_minutes']) * 60;
    }

    private function log_retry_event(
        string $level,
        string $message,
        WC_Order $subscription,
        WC_Order $renewal_order,
        int $attempt,
        array $context = array()
    ): void {
        $context = array_merge(
            array(
                'source' => 'woo-subzero',
                'subscription_id' => $subscription->get_id(),
                'renewal_order_id' => $renewal_order->get_id(),
                'attempt' => $attempt,
            ),
            $context
        );

        try {
            if (class_exists('WSZ_Admin_Settings')) {
                WSZ_Admin_Settings::log_diagnostic($level, $message, $context);
            }
        } catch (Throwable $throwable) {
            // Diagnostic logging must not break renewal retries.
        }

        if (!function_exists('wc_get_logger')) {
            return;
        }

        try {
            $logger = wc_get_logger();
            $level = sanitize_key($level);

            if (is_object($logger) && is_callable(array($logger, $level))) {
                $logger->{$level}($message, $context);
            }
        } catch (Throwable $throwable) {
            // WooCommerce logger failures are non-critical for retry state.
        }
    }

    private function safe_transition_subscription_status(WC_Order $subscription, WC_Order $renewal_order, int $attempt): void
    {
        try {
            if ('active' !== $subscription->get_status()) {
                return;
            }

            $this->subscription_manager->transition_status(
                $subscription,
                'on-hold',
                __('Renewal payment failed. Retry queued.', 'woo-subzero')
            );
        } catch (Throwable $throwable) {
            $this->log_retry_event(
                'warning',
                __('Retry subscription status update failed.', 'woo-subzero'),
                $subscription,
                $renewal_order,
                $attempt,
                array(
                    'target_status' => 'on-hold',
                    'reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                )
            );
        }
    }

    private function safe_do_retry_queued(WC_Order $subscription, WC_Order $renewal_order, int $attempt, int $scheduled_at): void
    {
        try {
            do_action('wsz_subs_retry_queued', $subscription, $renewal_order, $attempt, $scheduled_at);
        } catch (Throwable $throwable) {
            $this->log_retry_event(
                'warning',
                __('Retry queued hook failed.', 'woo-subzero'),
                $subscription,
                $renewal_order,
                $attempt,
                array(
                    'reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                )
            );
        }
    }

    private function safe_send_retry_email(WC_Order $subscription, WC_Order $renewal_order, int $attempt, string $event): void
    {
        try {
            $this->maybe_send_retry_email($subscription, $renewal_order, $attempt, $event);
        } catch (Throwable $throwable) {
            $this->log_retry_event(
                'warning',
                __('Retry notification failed.', 'woo-subzero'),
                $subscription,
                $renewal_order,
                $attempt,
                array(
                    'event' => sanitize_key($event),
                    'reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                )
            );
        }
    }

    private function get_retry_records(WC_Order $renewal_order): array
    {
        $stored = $renewal_order->get_meta(self::RECORDS_META_KEY, true);
        $records = is_string($stored) ? json_decode($stored, true) : array();

        if (!is_array($records)) {
            return array();
        }

        return $records;
    }
}
