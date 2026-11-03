<?php

defined('ABSPATH') || exit;

class WSZ_Renewal_Engine
{
    private WSZ_Subscription_Manager $subscription_manager;

    private WSZ_Payment_Handler $payment_handler;

    private WSZ_Retry_Manager $retry_manager;

    public function __construct(
        WSZ_Subscription_Manager $subscription_manager,
        WSZ_Payment_Handler $payment_handler,
        WSZ_Retry_Manager $retry_manager
    ) {
        $this->subscription_manager = $subscription_manager;
        $this->payment_handler = $payment_handler;
        $this->retry_manager = $retry_manager;
    }

    public function init(): void
    {
        add_action('wsz_subs_subscription_activated', array($this, 'schedule_first_renewal'), 10, 1);
        add_action('wsz_subs_process_renewal', array($this, 'process_renewal'), 10, 2);
        add_action('wsz_subs_paynl_sepa_prepare', array($this, 'prepare_sepa_renewal'), 10, 2);

        add_action('woocommerce_subscription_status_active', array($this, 'schedule_if_missing'), 10, 1);
        add_action('woocommerce_subscription_status_cancelled', array($this, 'clear_scheduled_renewals'), 10, 1);
        add_action('woocommerce_subscription_status_expired', array($this, 'clear_scheduled_renewals'), 10, 1);

        add_action('wsz_subs_retry_payment_complete', array($this, 'handle_retry_payment_complete'), 10, 3);
        add_action('wsz_subs_async_renewal_payment_complete', array($this, 'handle_async_payment_complete'), 10, 2);
    }

    /**
     * @param WC_Order $subscription
     */
    public function schedule_first_renewal($subscription): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $this->ensure_finite_term_end_timestamp($subscription);

        if ($this->subscription_manager->has_completed_all_payments($subscription)) {
            $this->handle_finite_term_boundary($subscription, 0);
            return;
        }

        $next_timestamp = $this->subscription_manager->get_next_payment_timestamp($subscription);

        if ($next_timestamp <= 0) {
            $next_timestamp = $this->calculate_next_payment_timestamp($subscription, 'initial');
            $this->subscription_manager->update_next_payment_timestamp($subscription, $next_timestamp);
        }

        if ($this->should_finalize_before_renewal($subscription, $next_timestamp)) {
            $this->handle_finite_term_boundary($subscription, $next_timestamp);
            return;
        }

        if ($this->has_scheduled_renewal_action((int) $subscription->get_id(), $next_timestamp)) {
            return;
        }

        $this->schedule_renewal_for_timestamp($subscription, $next_timestamp);
        $this->maybe_add_test_cycle_notification($subscription, $next_timestamp);
    }

    /**
     * @param WC_Order $subscription
     */
    public function schedule_if_missing($subscription): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $this->ensure_finite_term_end_timestamp($subscription);

        if ($this->subscription_manager->has_completed_all_payments($subscription)) {
            $this->handle_finite_term_boundary($subscription, 0);
            return;
        }

        $next_timestamp = $this->subscription_manager->get_next_payment_timestamp($subscription);
        if ($next_timestamp <= 0) {
            return;
        }

        if ($this->should_finalize_before_renewal($subscription, $next_timestamp)) {
            $this->handle_finite_term_boundary($subscription, $next_timestamp);
            return;
        }

        if ($this->has_scheduled_renewal_action((int) $subscription->get_id(), $next_timestamp)) {
            return;
        }

        $this->schedule_renewal_for_timestamp($subscription, $next_timestamp);
    }

    public function prepare_sepa_renewal($subscription_id, $schedule_key = ''): void
    {
        $subscription = $this->subscription_manager->get_subscription((int) $subscription_id);
        if ($subscription instanceof WC_Order && WSZ_PayNL_SEPA_Context::valid_for_subscription($subscription)
            && !$this->subscription_manager->is_manual_renewal($subscription)) {
            $this->process_renewal($subscription_id, $schedule_key);
        }
    }

    public function process_renewal($subscription_id, $schedule_key = ''): void
    {
        $subscription_id = (int) $subscription_id;
        $schedule_key = is_string($schedule_key) ? $schedule_key : '';

        if ($subscription_id <= 0) {
            return;
        }

        $subscription = $this->subscription_manager->get_subscription($subscription_id);

        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $this->ensure_finite_term_end_timestamp($subscription);

        $status = $subscription->get_status();
        $normalized_status = preg_replace('/^wc-/', '', sanitize_key((string) $status));

        // Renewals only run for active subscriptions.
        if ('active' !== $normalized_status) {
            return;
        }

        if (in_array($status, array('cancelled', 'expired'), true)) {
            return;
        }

        if ($this->subscription_manager->has_completed_all_payments($subscription)) {
            $this->handle_finite_term_boundary($subscription, 0);
            return;
        }

        $next_payment_timestamp = $this->subscription_manager->get_next_payment_timestamp($subscription);

        if ($next_payment_timestamp <= 0) {
            if ($this->is_past_subscription_end($subscription)) {
                $this->finalize_finite_term_if_due($subscription);
            }
            return;
        }

        if ($this->should_finalize_before_renewal($subscription, $next_payment_timestamp)) {
            $this->handle_finite_term_boundary($subscription, $next_payment_timestamp);
            return;
        }

        if (!$this->subscription_manager->acquire_lock('renewal', $subscription_id, 300)) {
            return;
        }

        try {
            // Re-read after locking: a previous worker may have submitted or completed this cycle.
            $subscription = $this->subscription_manager->get_subscription($subscription_id);
            if (!($subscription instanceof WC_Order) || 'active' !== preg_replace('/^wc-/', '', sanitize_key((string) $subscription->get_status()))
                || $this->subscription_manager->get_next_payment_timestamp($subscription) !== $next_payment_timestamp
                || $this->subscription_manager->has_completed_all_payments($subscription)) {
                return;
            }
            // A bank collection can remain pending for days. Never create another order for it.
            if ((int) $subscription->get_meta('_wsz_pending_renewal_order_id', true) > 0) {
                return;
            }

            if ($this->is_duplicate_schedule($subscription, $schedule_key)) {
                return;
            }
            $current_key = (string) $subscription->get_meta('_wsz_next_schedule_key', true);
            $due_key = $this->build_schedule_key($subscription_id, $next_payment_timestamp);
            if ('' !== $schedule_key && '' !== $current_key && !hash_equals($current_key, $schedule_key)
                && !hash_equals($due_key, $schedule_key)) {
                return; // An older job must not collect the next cycle before its due date.
            }

            if ('' !== $schedule_key) {
                $subscription->update_meta_data('_wsz_next_schedule_key', '');
                $subscription->save();
            }

            $renewal_order = $this->create_renewal_order($subscription);

            if (!($renewal_order instanceof WC_Order)) {
                $this->log_diagnostic(
                    'error',
                    __('Unable to create renewal order for subscription.', 'woo-subzero'),
                    array('subscription_id' => $subscription_id)
                );
                wc_get_logger()->error(
                    sprintf('Unable to create renewal order for subscription %d.', $subscription_id),
                    array('source' => 'woo-subzero')
                );
                return;
            }

            $amount = (float) $renewal_order->get_total();
            $amount = (float) apply_filters('wsz_subs_renewal_amount', $amount, $renewal_order, $subscription);

            if ($amount < 0) {
                $amount = 0;
            }

            if (abs(((float) $renewal_order->get_total()) - $amount) > 0.00001) {
                $renewal_order->set_total($amount);
                $renewal_order->save();
            }

            $renewal_order->update_meta_data('_wsz_renewal_schedule_key', $schedule_key);
            $renewal_order->save();
            $this->safe_dispatch_scheduled_payment($subscription, $renewal_order, $amount);

            if ('yes' === $renewal_order->get_meta('_wsz_async_payment_pending', true)) {
                return;
            }

            // Gateway fallback and concurrent status changes may have been persisted on another instance.
            $subscription = $this->subscription_manager->get_subscription($subscription_id);
            if (!($subscription instanceof WC_Order)) {
                return;
            }
            if ($renewal_order->is_paid()) {
                $this->complete_renewal_cycle($subscription, $renewal_order, $schedule_key);
                return;
            }
            if (!in_array($subscription->get_status(), array('active', 'on-hold'), true)) {
                return;
            }

            if ($this->subscription_manager->is_manual_renewal($subscription)) {
                if (!$renewal_order->has_status(array('pending'))) {
                    $renewal_order->update_status('pending', __('Awaiting customer payment for manual renewal.', 'woo-subzero'));
                }

                if ('active' === $subscription->get_status()) {
                    $this->subscription_manager->transition_status(
                        $subscription,
                        'on-hold',
                        __('Awaiting manual renewal payment.', 'woo-subzero')
                    );
                }

                do_action('wsz_subs_manual_renewal_pending_payment', $subscription, $renewal_order);
                return;
            }

            if (!$renewal_order->has_status(array('failed'))) {
                $this->safe_update_renewal_order_status(
                    $subscription,
                    $renewal_order,
                    'failed',
                    __('Scheduled renewal payment failed.', 'woo-subzero')
                );
            }

            if ('active' === $subscription->get_status()) {
                $this->safe_transition_subscription_status(
                    $subscription,
                    'on-hold',
                    __('Renewal payment failed.', 'woo-subzero'),
                    array(
                        'renewal_order_id' => $renewal_order->get_id(),
                        'reason' => 'renewal_payment_failed',
                    )
                );
            }

            $this->safe_queue_retry($subscription, $renewal_order, 'renewal_failed');
            $this->safe_do_renewal_payment_failed($subscription, $renewal_order);
        } catch (Throwable $throwable) {
            $this->log_diagnostic(
                'error',
                $throwable->getMessage(),
                array('subscription_id' => $subscription_id)
            );
            wc_get_logger()->error(
                sprintf('Renewal processing failed for subscription %d: %s', $subscription_id, $throwable->getMessage()),
                array('source' => 'woo-subzero')
            );
        } finally {
            $this->subscription_manager->release_lock('renewal', $subscription_id);
        }
    }

    public function handle_async_payment_complete($subscription, WC_Order $renewal_order): void
    {
        if (!($subscription instanceof WC_Order) || !$renewal_order->is_paid()
            || (int) $renewal_order->get_meta('_wsz_subscription_id', true) !== $subscription->get_id()
            || !$this->subscription_manager->acquire_lock('renewal', $subscription->get_id(), 300)) {
            return;
        }
        $subscription_id = (int) $subscription->get_id();
        try {
            $preserve_suspension = WSZ_PayNL_SEPA::RENEWAL_GATEWAY_ID === $renewal_order->get_payment_method();
            if ($preserve_suspension) {
                // The customer may have suspended or cancelled while the bank settled the debit.
                $subscription = $this->subscription_manager->get_subscription($subscription_id);
                if (!($subscription instanceof WC_Order) || $subscription_id !== (int) $subscription->get_id()) {
                    return;
                }
            }
            $this->complete_renewal_cycle($subscription, $renewal_order, (string) $renewal_order->get_meta('_wsz_renewal_schedule_key', true), $preserve_suspension);
        } finally {
            $this->subscription_manager->release_lock('renewal', $subscription_id);
        }
    }

    private function complete_renewal_cycle(WC_Order $subscription, WC_Order $order, string $schedule_key, bool $preserve_suspension = false): void
    {
        if ('yes' === $order->get_meta('_wsz_cycle_completed', true)) {
            return;
        }
        $subscription->update_meta_data('_wsz_last_processed_schedule_key', $schedule_key);
        if ((int) $subscription->get_meta('_wsz_pending_renewal_order_id', true) === $order->get_id()) {
            $subscription->update_meta_data('_wsz_pending_renewal_order_id', 0);
        }
        $counted_orders = array_map('absint', (array) $subscription->get_meta('_wsz_counted_renewal_ids', true));
        if ($order->get_id() <= 0 || (!in_array($order->get_id(), $counted_orders, true)
            && (int) $subscription->get_meta('_wsz_last_counted_renewal_id', true) !== $order->get_id())) {
            // Keep the per-order ledger on the same subscription save as its payment counter.
            if ($order->get_id() > 0) {
                $counted_orders[] = $order->get_id();
                $subscription->update_meta_data('_wsz_counted_renewal_ids', array_values(array_filter(array_unique($counted_orders))));
                $subscription->update_meta_data('_wsz_last_counted_renewal_id', $order->get_id());
            }
            $this->subscription_manager->increment_completed_payments($subscription, 1);
        }
        $subscription->save();
        $this->retry_manager->cancel_pending_retries($order);
        // A late bank confirmation must record the payment without reviving a cancelled contract.
        if (in_array($subscription->get_status(), array('cancelled', 'expired', 'pending-cancel'), true)) {
            $order->update_meta_data('_wsz_cycle_completed', 'yes');
            $order->save();
            do_action('wsz_subs_renewal_payment_complete', $subscription, $order);
            return;
        }
        $suspended = $preserve_suspension && 'on-hold' === $subscription->get_status();
        if (!$suspended && 'active' !== $subscription->get_status()) {
            $this->subscription_manager->transition_status($subscription, 'active', __('Renewal payment succeeded.', 'woo-subzero'));
        }
        if ($this->subscription_manager->has_completed_all_payments($subscription)) {
            $this->ensure_finite_term_end_timestamp($subscription);
            $this->handle_finite_term_boundary($subscription, 0);
        } else {
            $target = (int) $order->get_meta('_wsz_cycle_next_payment_at', true);
            if ($target <= 0) {
                $target = $this->calculate_next_payment_timestamp($subscription, 'renewal');
                $order->update_meta_data('_wsz_cycle_next_payment_at', $target);
                $order->save();
            }
            if ($suspended) {
                // Keep the cadence but leave collection unscheduled until explicitly reactivated.
                $this->subscription_manager->update_next_payment_timestamp($subscription, $target);
            } else {
                $this->advance_next_payment_and_schedule($subscription, $target);
            }
        }
        $order->update_meta_data('_wsz_cycle_completed', 'yes');
        $order->save();
        do_action('wsz_subs_renewal_payment_complete', $subscription, $order);
    }

    /**
     * @param WC_Order $subscription
     */
    public function clear_scheduled_renewals($subscription): void
    {
        if (!($subscription instanceof WC_Order) || !function_exists('as_unschedule_all_actions')) {
            return;
        }

        $subscription_id = (int) $subscription->get_id();
        foreach (array('wsz_subs_process_renewal', 'wsz_subs_paynl_sepa_prepare') as $hook) {
            if (function_exists('as_get_scheduled_actions') && function_exists('as_unschedule_action')) {
                $matching_args = array();
                $offset = 0;
                do {
                    $actions = as_get_scheduled_actions(array(
                        'hook' => $hook, 'group' => WSZ_Subscription_Manager::ACTION_GROUP,
                        'status' => 'pending', 'per_page' => 100, 'offset' => $offset,
                    ));
                    foreach ($actions as $action) {
                        $args = $action->get_args();
                        if ((int) ($args['subscription_id'] ?? 0) === $subscription_id) {
                            $matching_args[] = $args;
                        }
                    }
                    $offset += 100;
                } while (count($actions) === 100);
                // Cancel after reading pages so removals cannot shift later offsets.
                foreach ($matching_args as $args) {
                    as_unschedule_action($hook, $args, WSZ_Subscription_Manager::ACTION_GROUP);
                }
            }
            as_unschedule_all_actions($hook, array('subscription_id' => $subscription_id), WSZ_Subscription_Manager::ACTION_GROUP);
        }

        $subscription->update_meta_data('_wsz_next_schedule_key', '');
        $subscription->save();
    }

    /**
     * @param WC_Order $subscription
     */
    public function handle_retry_payment_complete($subscription, WC_Order $renewal_order, int $attempt): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        $subscription->add_order_note(
            sprintf(__('Retry attempt %d succeeded for renewal order %d.', 'woo-subzero'), $attempt, $renewal_order->get_id())
        );

        $this->complete_renewal_cycle($subscription, $renewal_order, (string) $renewal_order->get_meta('_wsz_renewal_schedule_key', true));
    }

    private function create_renewal_order(WC_Order $subscription): ?WC_Order
    {
        if (function_exists('wcs_create_renewal_order')) {
            $renewal_order = wcs_create_renewal_order($subscription);
            if ($renewal_order instanceof WC_Order) {
                if (!$this->is_incomplete_wcs_renewal_order($subscription, $renewal_order)) {
                    return $this->finalize_renewal_order($subscription, $renewal_order);
                }

                $this->hydrate_renewal_order_from_subscription($subscription, $renewal_order);

                wc_get_logger()->warning(
                    sprintf(
                        'Hydrated incomplete WCS renewal order %d for subscription %d.',
                        (int) $renewal_order->get_id(),
                        (int) $subscription->get_id()
                    ),
                    array('source' => 'woo-subzero')
                );

                return $this->finalize_renewal_order($subscription, $renewal_order);
            }
        }

        if (!function_exists('wc_create_order')) {
            return null;
        }

        $renewal_order = wc_create_order(
            array(
                'customer_id' => $subscription->get_customer_id(),
                'parent' => $subscription->get_id(),
                'created_via' => 'woo-subzero-renewal',
            )
        );

        if (!($renewal_order instanceof WC_Order)) {
            return null;
        }

        $this->hydrate_renewal_order_from_subscription($subscription, $renewal_order);

        return $this->finalize_renewal_order($subscription, $renewal_order);
    }

    private function hydrate_renewal_order_from_subscription(WC_Order $subscription, WC_Order $renewal_order): void
    {
        $renewal_items = $renewal_order->get_items(array('line_item'));

        if (is_array($renewal_items) && count($renewal_items) <= 0) {
            foreach ($subscription->get_items(array('line_item', 'fee', 'shipping', 'tax', 'coupon')) as $item) {
                $clone = clone $item;
                $clone->set_id(0);
                $renewal_order->add_item($clone);
            }
        }

        if (is_callable(array($renewal_order, 'set_customer_id'))) {
            $renewal_order->set_customer_id($subscription->get_customer_id());
        }

        $renewal_order->set_payment_method($subscription->get_payment_method());
        $renewal_order->set_payment_method_title($subscription->get_payment_method_title());
        $renewal_order->set_currency($subscription->get_currency());

        if (
            is_callable(array($subscription, 'get_prices_include_tax'))
            && is_callable(array($renewal_order, 'set_prices_include_tax'))
        ) {
            $renewal_order->set_prices_include_tax($subscription->get_prices_include_tax());
        }

        $this->copy_order_address_context($subscription, $renewal_order);
        $renewal_order->set_total($subscription->get_total());
        $renewal_order->calculate_totals(false);
    }

    private function finalize_renewal_order(WC_Order $subscription, WC_Order $renewal_order): ?WC_Order
    {
        $subscription_payment_method = sanitize_key((string) $subscription->get_payment_method());
        $renewal_payment_method = sanitize_key((string) $renewal_order->get_payment_method());

        if ('' === $renewal_payment_method && '' !== $subscription_payment_method) {
            $renewal_order->set_payment_method($subscription_payment_method);
            $renewal_order->set_payment_method_title($subscription->get_payment_method_title());
        }

        if ('' === (string) $renewal_order->get_currency()) {
            $renewal_order->set_currency($subscription->get_currency());
        }

        $this->copy_payment_context_to_renewal($subscription, $renewal_order);
        $this->clear_inherited_transaction_context($renewal_order);

        $renewal_order->update_meta_data('_wsz_subscription_id', $subscription->get_id());
        $renewal_order->save();

        $this->subscription_manager->add_related_order($subscription, (int) $renewal_order->get_id(), 'renewal');

        return $renewal_order;
    }

    private function copy_payment_context_to_renewal(WC_Order $subscription, WC_Order $renewal_order): void
    {
        $this->subscription_manager->copy_payment_context_meta($subscription, $renewal_order);

        $parent_order = $this->resolve_parent_order_for_payment_context($subscription);
        if (!($parent_order instanceof WC_Order)) {
            $this->copy_resolved_payment_token_to_renewal($subscription, $renewal_order);
            return;
        }

        if ($this->subscription_manager->copy_payment_context_meta($parent_order, $subscription)) {
            $subscription->save();
        }

        $this->subscription_manager->copy_payment_context_meta($parent_order, $renewal_order);
        $this->copy_resolved_payment_token_to_renewal($subscription, $renewal_order);
    }

    private function clear_inherited_transaction_context(WC_Order $renewal_order): void
    {
        foreach (array('_transaction_id', 'transaction_id', 'transactionId', 'transactionid') as $meta_key) {
            if (is_callable(array($renewal_order, 'delete_meta_data'))) {
                $renewal_order->delete_meta_data($meta_key);
            }
        }
    }

    private function copy_resolved_payment_token_to_renewal(WC_Order $subscription, WC_Order $renewal_order): void
    {
        $token = $this->payment_handler->get_payment_token_for_subscription($subscription);
        if ($token instanceof WC_Payment_Token) {
            $token_id = (int) $token->get_id();
            $renewal_order->update_meta_data('_payment_token_id', $token_id);
            WSZ_Subscription_Manager::attach_payment_token_to_order($renewal_order, $token_id);
        }
    }

    private function resolve_parent_order_for_payment_context(WC_Order $subscription): ?WC_Order
    {
        $parent_order_id = (int) $subscription->get_meta('_wsz_parent_order_id', true);

        if ($parent_order_id <= 0 && is_callable(array($subscription, 'get_parent_id'))) {
            $parent_order_id = (int) $subscription->get_parent_id();
        }

        if ($parent_order_id <= 0 || !function_exists('wc_get_order')) {
            return null;
        }

        $parent_order = wc_get_order($parent_order_id);

        return $parent_order instanceof WC_Order ? $parent_order : null;
    }

    private function is_incomplete_wcs_renewal_order(WC_Order $subscription, WC_Order $renewal_order): bool
    {
        $subscription_items = $subscription->get_items(array('line_item'));
        $renewal_items = $renewal_order->get_items(array('line_item'));

        if (!is_array($subscription_items) || !is_array($renewal_items)) {
            return false;
        }

        if (count($subscription_items) > 0 && count($renewal_items) <= 0) {
            return true;
        }

        if ($this->is_missing_customer_context($subscription, $renewal_order)) {
            return true;
        }

        return false;
    }

    private function is_missing_customer_context(WC_Order $subscription, WC_Order $renewal_order): bool
    {
        if ((int) $subscription->get_customer_id() > 0 && (int) $renewal_order->get_customer_id() <= 0) {
            return true;
        }

        foreach ($this->get_address_fields() as $type => $fields) {
            foreach ($fields as $field) {
                $source_value = $this->get_order_address_value($subscription, $type, $field);
                $target_value = $this->get_order_address_value($renewal_order, $type, $field);

                if ('' !== $source_value && '' === $target_value) {
                    return true;
                }
            }
        }

        return false;
    }

    private function copy_order_address_context(WC_Order $subscription, WC_Order $renewal_order): void
    {
        foreach ($this->get_address_fields() as $type => $fields) {
            foreach ($fields as $field) {
                $getter = sprintf('get_%s_%s', $type, $field);
                $setter = sprintf('set_%s_%s', $type, $field);

                if (!is_callable(array($subscription, $getter)) || !is_callable(array($renewal_order, $setter))) {
                    continue;
                }

                $renewal_order->{$setter}($subscription->{$getter}());
            }
        }
    }

    private function get_order_address_value(WC_Order $order, string $type, string $field): string
    {
        $getter = sprintf('get_%s_%s', $type, $field);

        if (!is_callable(array($order, $getter))) {
            return '';
        }

        return trim((string) $order->{$getter}());
    }

    private function get_address_fields(): array
    {
        return array(
            'billing' => array(
                'first_name',
                'last_name',
                'company',
                'address_1',
                'address_2',
                'city',
                'postcode',
                'country',
                'state',
                'email',
                'phone',
            ),
            'shipping' => array(
                'first_name',
                'last_name',
                'company',
                'address_1',
                'address_2',
                'city',
                'postcode',
                'country',
                'state',
            ),
        );
    }

    private function advance_next_payment_and_schedule(WC_Order $subscription, ?int $next_payment = null): void
    {
        $next_payment = $next_payment ?? $this->calculate_next_payment_timestamp($subscription, 'renewal');

        if ($next_payment <= 0) {
            return;
        }

        if ($this->should_finalize_before_renewal($subscription, $next_payment)) {
            $this->handle_finite_term_boundary($subscription, $next_payment);
            return;
        }

        $this->subscription_manager->update_next_payment_timestamp($subscription, $next_payment);
        $this->schedule_renewal_for_timestamp($subscription, $next_payment);
        $this->maybe_add_test_cycle_notification($subscription, $next_payment);
    }

    private function should_skip_renewal_at_timestamp(WC_Order $subscription, int $renewal_timestamp): bool
    {
        if ($renewal_timestamp <= 0) {
            return false;
        }

        $end_timestamp = $this->subscription_manager->get_end_timestamp($subscription);

        if ($end_timestamp <= 0) {
            return false;
        }

        return $renewal_timestamp >= $end_timestamp;
    }

    private function should_finalize_before_renewal(WC_Order $subscription, int $renewal_timestamp): bool
    {
        $total_payments = $this->subscription_manager->get_total_payments($subscription);

        if ($total_payments > 0) {
            return $this->subscription_manager->has_completed_all_payments($subscription);
        }

        return $this->should_skip_renewal_at_timestamp($subscription, $renewal_timestamp);
    }

    private function is_past_subscription_end(WC_Order $subscription): bool
    {
        $end_timestamp = $this->subscription_manager->get_end_timestamp($subscription);

        if ($end_timestamp <= 0) {
            return false;
        }

        return current_time('timestamp', true) >= $end_timestamp;
    }

    private function finalize_finite_term_if_due(WC_Order $subscription): void
    {
        $end_timestamp = $this->subscription_manager->get_end_timestamp($subscription);

        if ($end_timestamp <= 0) {
            return;
        }

        if ($end_timestamp <= current_time('timestamp', true)) {
            $this->subscription_manager->process_expiration($subscription->get_id());
            return;
        }

        $this->subscription_manager->schedule_expiration($subscription->get_id(), $end_timestamp);
    }

    private function handle_finite_term_boundary(WC_Order $subscription, int $boundary_timestamp): void
    {
        if ($this->subscription_manager->has_completed_all_payments($subscription)) {
            $this->subscription_manager->update_next_payment_timestamp($subscription, 0);
            $this->clear_scheduled_renewals($subscription);
            if ('yes' !== $subscription->get_meta('_wsz_payments_completed', true)) {
                $subscription->update_meta_data('_wsz_payments_completed', 'yes');
                $subscription->add_order_note(__('All required payments received. No further renewals will be charged; service continues until the subscription end date.', 'woo-subzero'));
                $subscription->save();
                do_action('wsz_subs_subscription_payments_completed', $subscription);
            }
            $this->finalize_finite_term_if_due($subscription);
            return;
        }

        $end_timestamp = $this->subscription_manager->get_end_timestamp($subscription);

        if ($end_timestamp > 0) {
            $boundary_timestamp = max($boundary_timestamp, $end_timestamp);

            if ($boundary_timestamp > 0) {
                $this->subscription_manager->update_next_payment_timestamp($subscription, $boundary_timestamp);
            }
        }

        $this->finalize_finite_term_if_due($subscription);
    }

    private function ensure_finite_term_end_timestamp(WC_Order $subscription): void
    {
        $subscription_length = $this->resolve_subscription_length($subscription);

        if ($subscription_length <= 0) {
            return;
        }

        $current_end_timestamp = $this->subscription_manager->get_end_timestamp($subscription);

        if ($current_end_timestamp > 0) {
            return;
        }

        $start_timestamp = strtotime((string) $subscription->get_meta('_wsz_start_date', true) . ' UTC');

        if ($start_timestamp <= 0 && is_callable(array($subscription, 'get_date_created'))) {
            $created = $subscription->get_date_created();

            if ($created instanceof WC_DateTime) {
                $start_timestamp = (int) $created->getTimestamp();
            }
        }

        if ($start_timestamp <= 0) {
            $start_timestamp = current_time('timestamp', true);
        }

        $billing_interval = $this->subscription_manager->get_billing_interval($subscription);
        $billing_period = $this->subscription_manager->get_billing_period($subscription);

        $computed_end_timestamp = $this->subscription_manager->calculate_end_timestamp_for_profile(
            $start_timestamp,
            $billing_interval,
            $billing_period,
            $subscription_length
        );

        if ($computed_end_timestamp <= 0) {
            return;
        }

        $this->subscription_manager->update_end_timestamp($subscription, $computed_end_timestamp);
    }

    private function resolve_subscription_length(WC_Order $subscription): int
    {
        $subscription_length = $this->subscription_manager->get_subscription_length($subscription);

        if ($subscription_length > 0) {
            return $subscription_length;
        }

        if (!function_exists('wc_get_order')) {
            return 0;
        }

        $parent_order_id = (int) $subscription->get_meta('_wsz_parent_order_id', true);

        if ($parent_order_id <= 0 && is_callable(array($subscription, 'get_parent_id'))) {
            $parent_order_id = (int) $subscription->get_parent_id();
        }

        if ($parent_order_id <= 0) {
            return 0;
        }

        $parent_order = wc_get_order($parent_order_id);

        if (!($parent_order instanceof WC_Order) || !is_callable(array($parent_order, 'get_items'))) {
            return 0;
        }

        foreach ($parent_order->get_items('line_item') as $item) {
            if (!is_object($item) || !is_callable(array($item, 'get_meta'))) {
                continue;
            }

            $length = (int) $item->get_meta('_wsz_subscription_length', true);

            if ($length <= 0) {
                $length = (int) $item->get_meta('_subscription_length', true);
            }

            if ($length <= 0 && is_callable(array($item, 'get_product'))) {
                $product = $item->get_product();

                if ($product instanceof WC_Product) {
                    $length = (int) $product->get_meta('_wsz_subscription_length', true);

                    if ($length <= 0) {
                        $length = (int) $product->get_meta('_subscription_length', true);
                    }

                    if ($length <= 0 && is_callable(array($product, 'get_parent_id')) && function_exists('wc_get_product')) {
                        $parent_product_id = (int) $product->get_parent_id();

                        if ($parent_product_id > 0) {
                            $parent_product = wc_get_product($parent_product_id);

                            if ($parent_product instanceof WC_Product) {
                                $length = (int) $parent_product->get_meta('_wsz_subscription_length', true);

                                if ($length <= 0) {
                                    $length = (int) $parent_product->get_meta('_subscription_length', true);
                                }
                            }
                        }
                    }
                }
            }

            if ($length <= 0) {
                continue;
            }

            $subscription->update_meta_data('_wsz_subscription_length', $length);
            $subscription->save();

            return $length;
        }

        return 0;
    }

    private function calculate_next_payment_timestamp(WC_Order $subscription, string $reason = 'renewal'): int
    {
        $current_next = $this->subscription_manager->get_next_payment_timestamp($subscription);
        if ($current_next <= 0) {
            $current_next = current_time('timestamp', true);
        }

        // In test mode, always advance from the last scheduled payment anchor to keep strict cadence.
        if ($this->subscription_manager->get_test_cycle_minutes() > 0) {
            $calculated = $this->subscription_manager->calculate_next_payment_from_timestamp($subscription, $current_next);

            if ($calculated <= 0) {
                return 0;
            }

            return (int) apply_filters(
                'wsz_subs_next_payment_timestamp',
                $calculated,
                $subscription,
                array('reason' => $reason)
            );
        }

        if (is_callable(array($subscription, 'calculate_date'))) {
            try {
                $next_payment = $subscription->calculate_date('next_payment');
                if (is_string($next_payment) && '' !== $next_payment) {
                    $timestamp = strtotime($next_payment . ' UTC');
                    if ($timestamp > 0) {
                        return (int) apply_filters(
                            'wsz_subs_next_payment_timestamp',
                            $timestamp,
                            $subscription,
                            array('reason' => $reason)
                        );
                    }
                }
            } catch (Throwable $throwable) {
                wc_get_logger()->warning(
                    $throwable->getMessage(),
                    array('source' => 'woo-subzero')
                );
            }
        }

        $calculated = $this->subscription_manager->calculate_next_payment_from_timestamp($subscription, $current_next);

        if ($calculated <= 0) {
            return 0;
        }

        return (int) apply_filters(
            'wsz_subs_next_payment_timestamp',
            $calculated,
            $subscription,
            array('reason' => $reason)
        );
    }

    private function schedule_renewal_for_timestamp(WC_Order $subscription, int $timestamp): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        $now = current_time('timestamp', true);

        // If the schedule is already due, enqueue it immediately instead of dropping it.
        if ($timestamp <= $now) {
            $timestamp = $now + 1;
        }

        $schedule_key = $this->build_schedule_key($subscription->get_id(), $timestamp);

        $result = as_schedule_single_action(
            $timestamp,
            'wsz_subs_process_renewal',
            array(
                'subscription_id' => $subscription->get_id(),
                'schedule_key' => $schedule_key,
            ),
            WSZ_Subscription_Manager::ACTION_GROUP,
            true
        );

        $action_id = is_numeric($result) ? (int) $result : (true === $result ? 1 : 0);

        // Action Scheduler "unique" checks hook+group, so a stale/pending renewal for another
        // subscription can block this schedule. Retry non-unique to avoid dropping renewals.
        if ($action_id <= 0) {
            $result = as_schedule_single_action(
                $timestamp,
                'wsz_subs_process_renewal',
                array(
                    'subscription_id' => $subscription->get_id(),
                    'schedule_key' => $schedule_key,
                ),
                WSZ_Subscription_Manager::ACTION_GROUP,
                false
            );

            $action_id = is_numeric($result) ? (int) $result : (true === $result ? 1 : 0);
        }

        if ($action_id <= 0) {
            return;
        }

        $subscription->update_meta_data('_wsz_next_schedule_key', $schedule_key);
        $subscription->save();
        do_action('wsz_subs_renewal_scheduled', $subscription);
    }

    private function is_duplicate_schedule(WC_Order $subscription, string $schedule_key): bool
    {
        if ('' === $schedule_key) {
            return false;
        }

        $last_schedule_key = (string) $subscription->get_meta('_wsz_last_processed_schedule_key', true);

        return hash_equals($last_schedule_key, $schedule_key);
    }

    private function build_schedule_key(int $subscription_id, int $timestamp): string
    {
        return hash('sha256', $subscription_id . '|' . $timestamp);
    }

    private function has_scheduled_renewal_action(int $subscription_id, int $timestamp): bool
    {
        if ($subscription_id <= 0 || $timestamp <= 0 || !function_exists('as_has_scheduled_action')) {
            return false;
        }

        $schedule_key = $this->build_schedule_key($subscription_id, $timestamp);

        return as_has_scheduled_action(
            'wsz_subs_process_renewal',
            array(
                'subscription_id' => $subscription_id,
                'schedule_key' => $schedule_key,
            ),
            WSZ_Subscription_Manager::ACTION_GROUP
        );
    }

    private function maybe_add_test_cycle_notification(WC_Order $subscription, int $next_payment_timestamp): void
    {
        if ($next_payment_timestamp <= 0 || !$this->subscription_manager->should_send_test_cycle_notifications()) {
            return;
        }

        $cycle_minutes = $this->subscription_manager->get_test_cycle_minutes();

        if ($cycle_minutes <= 0) {
            return;
        }

        $subscription->add_order_note(
            sprintf(
                __('Test mode cycle: next renewal in %1$d minute(s) at %2$s UTC.', 'woo-subzero'),
                $cycle_minutes,
                gmdate('Y-m-d H:i:s', $next_payment_timestamp)
            )
        );

        do_action('wsz_subs_test_cycle_notification', $subscription, $next_payment_timestamp, $cycle_minutes);
    }

    private function safe_update_renewal_order_status(
        WC_Order $subscription,
        WC_Order $renewal_order,
        string $status,
        string $note
    ): void {
        try {
            $renewal_order->update_status($status, $note);
        } catch (Throwable $throwable) {
            $this->log_diagnostic(
                'warning',
                __('Renewal order status update failed.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'renewal_order_id' => $renewal_order->get_id(),
                    'target_status' => sanitize_key($status),
                    'reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                )
            );

            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    sprintf('Failed to mark renewal order %d as %s: %s', $renewal_order->get_id(), $status, $throwable->getMessage()),
                    array('source' => 'woo-subzero')
                );
            }
        }
    }

    private function safe_dispatch_scheduled_payment(WC_Order $subscription, WC_Order $renewal_order, float $amount): void
    {
        try {
            $this->payment_handler->dispatch_scheduled_payment($subscription, $renewal_order, $amount);
        } catch (Throwable $throwable) {
            $this->log_diagnostic(
                'warning',
                __('Scheduled renewal payment dispatch failed.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'renewal_order_id' => $renewal_order->get_id(),
                    'reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                )
            );

            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    sprintf('Scheduled renewal payment dispatch failed for renewal order %d: %s', $renewal_order->get_id(), $throwable->getMessage()),
                    array('source' => 'woo-subzero')
                );
            }
        }
    }

    private function safe_transition_subscription_status(
        WC_Order $subscription,
        string $status,
        string $note,
        array $context = array()
    ): void {
        try {
            $this->subscription_manager->transition_status($subscription, $status, $note);
        } catch (Throwable $throwable) {
            $this->log_diagnostic(
                'warning',
                __('Subscription status update failed after renewal processing.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'target_status' => sanitize_key($status),
                    'status_update_reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                ) + $context
            );

            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    sprintf('Failed to mark subscription %d as %s: %s', $subscription->get_id(), $status, $throwable->getMessage()),
                    array('source' => 'woo-subzero')
                );
            }
        }
    }

    private function safe_queue_retry(WC_Order $subscription, WC_Order $renewal_order, string $reason): void
    {
        try {
            $this->retry_manager->queue_retry($subscription, $renewal_order, $reason);
        } catch (Throwable $throwable) {
            $this->log_diagnostic(
                'warning',
                __('Retry queue failed after renewal payment failure.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'renewal_order_id' => $renewal_order->get_id(),
                    'reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                )
            );

            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    sprintf('Failed to queue retry for renewal order %d: %s', $renewal_order->get_id(), $throwable->getMessage()),
                    array('source' => 'woo-subzero')
                );
            }
        }
    }

    private function safe_do_renewal_payment_failed(WC_Order $subscription, WC_Order $renewal_order): void
    {
        try {
            do_action('wsz_subs_renewal_payment_failed', $subscription, $renewal_order);
        } catch (Throwable $throwable) {
            $this->log_diagnostic(
                'warning',
                __('Renewal payment failed hook threw an exception.', 'woo-subzero'),
                array(
                    'subscription_id' => $subscription->get_id(),
                    'renewal_order_id' => $renewal_order->get_id(),
                    'reason' => $throwable->getMessage(),
                    'exception_class' => get_class($throwable),
                )
            );
        }
    }

    private function log_diagnostic(string $level, string $message, array $context = array()): void
    {
        if (!class_exists('WSZ_Admin_Settings')) {
            return;
        }

        $context['source'] = 'woo-subzero';
        WSZ_Admin_Settings::log_diagnostic($level, $message, $context);
    }
}
