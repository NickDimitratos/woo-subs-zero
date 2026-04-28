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

        return !empty($normalized) ? $normalized : $rules;
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
            $renewal_order->update_status('failed', __('Retry rules exhausted.', 'woo-subzero'));

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
            $renewal_order->update_status('pending', sprintf(__('Retry %d queued.', 'woo-subzero'), $next_attempt));
        }

        try {
            if ('active' === $subscription->get_status()) {
                $this->subscription_manager->transition_status(
                    $subscription,
                    'on-hold',
                    __('Renewal payment failed. Retry queued.', 'woo-subzero')
                );
            }
        } catch (Throwable $throwable) {
            wc_get_logger()->warning(
                $throwable->getMessage(),
                array('source' => 'woo-subzero')
            );
        }

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

        do_action('wsz_subs_retry_queued', $subscription, $renewal_order, $next_attempt, $scheduled_at);

        $this->maybe_send_retry_email($subscription, $renewal_order, $next_attempt, 'queued');

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
                return;
            }

            $this->update_retry_record($renewal_order, $attempt, 'processing', 0, 'processing');

            $amount = (float) $renewal_order->get_total();
            $this->payment_handler->dispatch_scheduled_payment($subscription, $renewal_order, $amount);

            if ($renewal_order->is_paid()) {
                $this->update_retry_record($renewal_order, $attempt, 'complete', 0, 'paid');

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
            $renewal_order->update_status('failed', sprintf(__('Retry %d failed.', 'woo-subzero'), $attempt));
            $this->queue_retry($subscription, $renewal_order, sprintf('retry_%d_failed', $attempt));
        } catch (Throwable $throwable) {
            $this->update_retry_record($renewal_order, $attempt, 'failed', 0, $throwable->getMessage());
            wc_get_logger()->error(
                sprintf('Retry processing failed for renewal order %d: %s', $order_id, $throwable->getMessage()),
                array('source' => 'woo-subzero')
            );
        } finally {
            $this->subscription_manager->release_lock('retry_' . $order_id, $attempt);
        }
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

        $subject = sprintf(
            __('Subscription retry update (attempt %1$d, event: %2$s)', 'woo-subzero'),
            $attempt,
            $event
        );

        $message = sprintf(
            __(
                "Subscription #%1$d, renewal order #%2$d, retry attempt %3$d has event '%4$s'.",
                'woo-subzero'
            ),
            $subscription->get_id(),
            $renewal_order->get_id(),
            $attempt,
            $event
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
        );

        return wp_parse_args((array) get_option('wsz_subs_options', array()), $defaults);
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
