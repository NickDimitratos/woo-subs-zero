<?php

defined('ABSPATH') || exit;

class WSZ_Synchronization_Manager
{
    private WSZ_Subscription_Manager $subscription_manager;

    public function __construct(WSZ_Subscription_Manager $subscription_manager)
    {
        $this->subscription_manager = $subscription_manager;
    }

    public function init(): void
    {
        add_filter('wsz_subs_next_payment_timestamp', array($this, 'filter_next_payment_timestamp'), 10, 3);
        add_filter('wsz_subs_renewal_amount', array($this, 'filter_renewal_amount'), 10, 3);
        add_filter('wcs_allow_synced_product_early_renewal', array($this, 'filter_allow_synced_product_early_renewal'), 10, 2);

        add_action('wsz_subs_subscription_activated', array($this, 'maybe_initialize_sync_state'), 20, 1);
    }

    public function is_synchronization_enabled(): bool
    {
        $options = $this->get_options();

        return 'yes' === $options['enable_synchronization'];
    }

    public function filter_next_payment_timestamp(int $timestamp, $subscription, array $context = array()): int
    {
        if (!$this->is_synchronization_enabled()) {
            return $timestamp;
        }

        if (!($subscription instanceof WC_Order) || $timestamp <= 0) {
            return $timestamp;
        }

        if (!$this->is_synchronized_subscription($subscription)) {
            return $timestamp;
        }

        $period = $this->subscription_manager->get_billing_period($subscription);

        if (!in_array($period, array('month', 'year'), true)) {
            return $timestamp;
        }

        $sync_day = $this->get_sync_day_of_month($subscription);

        return self::calculate_synced_timestamp($timestamp, $sync_day, $period);
    }

    public function filter_renewal_amount(float $amount, $renewal_order, $subscription): float
    {
        if (!$this->is_synchronization_enabled()) {
            return $amount;
        }

        if (!($renewal_order instanceof WC_Order) || !($subscription instanceof WC_Order)) {
            return $amount;
        }

        $options = $this->get_options();

        if ('yes' !== $options['enable_sync_first_renewal_proration']) {
            return $amount;
        }

        $factor = (float) $subscription->get_meta('_wsz_first_renewal_proration_factor', true);
        $already_applied = 'yes' === $subscription->get_meta('_wsz_first_renewal_proration_applied', true);

        if ($already_applied || $factor <= 0 || $factor >= 1) {
            return $amount;
        }

        $adjusted_amount = round($amount * $factor, 2);

        $renewal_order->set_total($adjusted_amount);
        $renewal_order->add_order_note(
            sprintf(
                __('First synchronized renewal prorated by factor %.4f.', 'woo-subzero'),
                $factor
            )
        );
        $renewal_order->save();

        $subscription->update_meta_data('_wsz_first_renewal_proration_applied', 'yes');
        $subscription->save();

        return $adjusted_amount;
    }

    /**
     * @param mixed $subscription
     */
    public function filter_allow_synced_product_early_renewal(bool $allow, $subscription): bool
    {
        $options = $this->get_options();

        if ('yes' === $options['allow_synced_early_renewal']) {
            return true;
        }

        return $allow;
    }

    /**
     * @param WC_Order $subscription
     */
    public function maybe_initialize_sync_state($subscription): void
    {
        if (!($subscription instanceof WC_Order)) {
            return;
        }

        if (!$this->is_synchronization_enabled()) {
            return;
        }

        if (!$this->is_synchronized_subscription($subscription)) {
            return;
        }

        $next_payment = $this->subscription_manager->get_next_payment_timestamp($subscription);

        if ($next_payment <= 0) {
            return;
        }

        $synchronized = $this->filter_next_payment_timestamp(
            $next_payment,
            $subscription,
            array('reason' => 'initial_sync')
        );

        if ($synchronized <= 0 || $synchronized === $next_payment) {
            return;
        }

        $this->subscription_manager->update_next_payment_timestamp($subscription, $synchronized);

        $cycle_seconds = max(1, $this->subscription_manager->get_cycle_length_in_seconds($subscription));
        $first_cycle_seconds = max(1, $synchronized - current_time('timestamp', true));

        $factor = min(1, max(0.05, $first_cycle_seconds / $cycle_seconds));

        $subscription->update_meta_data('_wsz_first_renewal_proration_factor', wc_format_decimal($factor, 4));
        $subscription->update_meta_data('_wsz_first_renewal_proration_applied', 'no');
        $subscription->save();
    }

    public static function calculate_synced_timestamp(int $reference_timestamp, int $sync_day, string $period = 'month'): int
    {
        $reference_timestamp = max(1, $reference_timestamp);
        $sync_day = min(28, max(1, $sync_day));

        $hour = (int) gmdate('G', $reference_timestamp);
        $minute = (int) gmdate('i', $reference_timestamp);
        $second = (int) gmdate('s', $reference_timestamp);

        if ('year' === $period) {
            $month = (int) gmdate('n', $reference_timestamp);
            $year = (int) gmdate('Y', $reference_timestamp);

            $candidate = gmmktime($hour, $minute, $second, $month, $sync_day, $year);

            if ($candidate <= $reference_timestamp) {
                $candidate = gmmktime($hour, $minute, $second, $month, $sync_day, $year + 1);
            }

            return $candidate;
        }

        $month = (int) gmdate('n', $reference_timestamp);
        $year = (int) gmdate('Y', $reference_timestamp);

        $candidate = gmmktime($hour, $minute, $second, $month, $sync_day, $year);

        if ($candidate <= $reference_timestamp) {
            $month += 1;
            if ($month > 12) {
                $month = 1;
                $year += 1;
            }

            $candidate = gmmktime($hour, $minute, $second, $month, $sync_day, $year);
        }

        return $candidate;
    }

    private function is_synchronized_subscription(WC_Order $subscription): bool
    {
        if ('yes' === $subscription->get_meta('_wsz_synchronized', true)) {
            return true;
        }

        if ((int) $subscription->get_meta('_wsz_sync_day', true) > 0) {
            return true;
        }

        if ((int) $subscription->get_meta('_subscription_payment_sync_date', true) > 0) {
            return true;
        }

        return false;
    }

    private function get_sync_day_of_month(WC_Order $subscription): int
    {
        $day = (int) $subscription->get_meta('_wsz_sync_day', true);

        if ($day <= 0) {
            $day = (int) $subscription->get_meta('_subscription_payment_sync_date', true);
        }

        if ($day <= 0) {
            $options = $this->get_options();
            $day = (int) $options['sync_day_of_month'];
        }

        return min(28, max(1, $day));
    }

    private function get_options(): array
    {
        $defaults = array(
            'enable_synchronization' => 'no',
            'enable_sync_first_renewal_proration' => 'yes',
            'sync_day_of_month' => 1,
            'allow_synced_early_renewal' => 'no',
        );

        return wp_parse_args((array) get_option('wsz_subs_options', array()), $defaults);
    }
}
