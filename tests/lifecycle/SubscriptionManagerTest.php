<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array())
    {
        return array_merge((array) $defaults, (array) $args);
    }
}

if (!function_exists('get_option')) {
    function get_option($option_name, $default = array())
    {
        if ('wsz_subs_options' !== $option_name) {
            return $default;
        }

        return $GLOBALS['wsz_subs_test_options'] ?? $default;
    }
}

if (!function_exists('_x')) {
    function _x($text, $context = null, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id)
    {
        $order_id = (int) $order_id;

        if (isset($GLOBALS['wsz_subs_test_orders'][$order_id])) {
            return $GLOBALS['wsz_subs_test_orders'][$order_id];
        }

        if (isset($GLOBALS['wsz_test_orders'][$order_id])) {
            return $GLOBALS['wsz_test_orders'][$order_id];
        }

        if (isset($GLOBALS['wsz_test_card_orders'][$order_id])) {
            return $GLOBALS['wsz_test_card_orders'][$order_id];
        }

        return null;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';

final class SubscriptionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_subs_test_options'] = array();
        $GLOBALS['wsz_subs_test_orders'] = array();
    }

    public function test_valid_transition_matrix_accepts_expected_routes(): void
    {
        $this->assertTrue(WSZ_Subscription_Manager::is_valid_transition('pending', 'active'));
        $this->assertTrue(WSZ_Subscription_Manager::is_valid_transition('active', 'on-hold'));
        $this->assertTrue(WSZ_Subscription_Manager::is_valid_transition('pending-cancel', 'cancelled'));
    }

    public function test_valid_transition_matrix_rejects_invalid_routes(): void
    {
        $this->assertFalse(WSZ_Subscription_Manager::is_valid_transition('pending', 'expired'));
        $this->assertFalse(WSZ_Subscription_Manager::is_valid_transition('expired', 'active'));
        $this->assertFalse(WSZ_Subscription_Manager::is_valid_transition('cancelled', 'active'));
    }

    public function test_same_status_is_considered_idempotent_transition(): void
    {
        $this->assertTrue(WSZ_Subscription_Manager::is_valid_transition('active', 'active'));
    }

    public function test_calculate_end_timestamp_for_four_month_monthly_term(): void
    {
        $start = gmmktime(0, 0, 0, 1, 15, 2026);

        $end = WSZ_Subscription_Manager::calculate_end_timestamp($start, 1, 'month', 4);

        $this->assertSame('2026-05-15 00:00:00', gmdate('Y-m-d H:i:s', $end));
    }

    public function test_calculate_end_timestamp_returns_zero_for_open_ended_length(): void
    {
        $start = gmmktime(0, 0, 0, 1, 15, 2026);

        $this->assertSame(0, WSZ_Subscription_Manager::calculate_end_timestamp($start, 1, 'month', 0));
    }

    public function test_calculate_next_payment_for_profile_respects_test_cycle_minutes(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_test_mode' => 'yes',
            'test_cycle_minutes' => 1,
        );

        $manager = new WSZ_Subscription_Manager();

        $next = $manager->calculate_next_payment_for_profile(1700000000, 1, 'month');

        $this->assertSame(1700000060, $next);
    }

    public function test_calculate_end_timestamp_for_profile_respects_test_cycle_minutes(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_test_mode' => 'yes',
            'test_cycle_minutes' => 1,
        );

        $manager = new WSZ_Subscription_Manager();

        $end = $manager->calculate_end_timestamp_for_profile(1700000000, 1, 'month', 4);

        $this->assertSame(1700000240, $end);
    }

    public function test_inject_custom_statuses_includes_active_pending_cancel_and_expired(): void
    {
        $manager = new WSZ_Subscription_Manager();

        $statuses = $manager->inject_custom_statuses(array('wc-pending' => 'Pending payment'));

        $this->assertArrayHasKey('wc-active', $statuses);
        $this->assertArrayHasKey('wc-pending-cancel', $statuses);
        $this->assertArrayHasKey('wc-expired', $statuses);
    }

    public function test_wsz_order_subscription_type_is_shop_subscription(): void
    {
        $subscription = new WSZ_Order_Subscription();

        $this->assertSame('shop_subscription', $subscription->get_type());
    }

    public function test_get_deferred_start_timestamp_reads_utc_meta_value(): void
    {
        $manager = new WSZ_Subscription_Manager();

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_meta')
            ->with('_wsz_deferred_activation_at', true)
            ->willReturn('2030-05-01 00:00:00');

        $this->assertSame(
            strtotime('2030-05-01 00:00:00 UTC'),
            $manager->get_deferred_start_timestamp($subscription)
        );
    }

    public function test_get_test_deferred_start_minutes_returns_zero_when_test_mode_disabled(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_test_mode' => 'no',
            'enable_test_deferred_start' => 'yes',
            'test_deferred_start_minutes' => 3,
        );

        $manager = new WSZ_Subscription_Manager();

        $this->assertSame(0, $manager->get_test_deferred_start_minutes());
    }

    public function test_get_test_deferred_start_minutes_returns_configured_value_when_enabled(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_test_mode' => 'yes',
            'enable_test_deferred_start' => 'yes',
            'test_deferred_start_minutes' => 3,
        );

        $manager = new WSZ_Subscription_Manager();

        $this->assertSame(3, $manager->get_test_deferred_start_minutes());
    }

    public function test_get_deferred_activation_schedule_timestamp_uses_minute_delay_in_test_mode(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_test_mode' => 'yes',
            'enable_test_deferred_start' => 'yes',
            'test_deferred_start_minutes' => 2,
        );

        $manager = new WSZ_Subscription_Manager();

        $reference = strtotime('2026-04-29 10:00:00 UTC');
        $requested = strtotime('2026-05-01 00:00:00 UTC');

        $this->assertSame(
            $reference + 120,
            $manager->get_deferred_activation_schedule_timestamp($requested, $reference)
        );
    }

    public function test_parent_order_cancelled_cancels_active_subscription(): void
    {
        $manager = $this->getMockBuilder(WSZ_Subscription_Manager::class)
            ->onlyMethods(array('get_subscription', 'transition_status'))
            ->getMock();

        $order = $this->createMock(WC_Order::class);
        $order
            ->method('get_meta')
            ->with('_wsz_subscription_ids', true)
            ->willReturn(array(111));

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_status')
            ->willReturn('active');

        $manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(111)
            ->willReturn($subscription);

        $manager
            ->expects($this->once())
            ->method('transition_status')
            ->with(
                $subscription,
                'cancelled',
                $this->stringContains('Parent order was cancelled or refunded.')
            )
            ->willReturn(true);

        $manager->maybe_activate_subscriptions_from_parent_order(50, 'processing', 'cancelled', $order);
    }

    public function test_parent_order_on_hold_does_not_activate_pending_subscription(): void
    {
        $manager = $this->getMockBuilder(WSZ_Subscription_Manager::class)
            ->onlyMethods(array('get_subscription', 'transition_status'))
            ->getMock();

        $order = $this->createMock(WC_Order::class);
        $order
            ->expects($this->never())
            ->method('get_meta');

        $manager
            ->expects($this->never())
            ->method('get_subscription');

        $manager
            ->expects($this->never())
            ->method('transition_status');

        $manager->maybe_activate_subscriptions_from_parent_order(51, 'pending', 'on-hold', $order);
    }

    public function test_process_expiration_keeps_pending_subscription_unchanged(): void
    {
        $manager = $this->getMockBuilder(WSZ_Subscription_Manager::class)
            ->onlyMethods(array('get_subscription', 'transition_status'))
            ->getMock();

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_status')
            ->willReturn('pending');
        $subscription
            ->method('get_meta')
            ->willReturnCallback(
                static function ($key, $single = true) {
                    if ('_wsz_end_date' === $key) {
                        return gmdate('Y-m-d H:i:s', current_time('timestamp', true) - 60);
                    }

                    return '';
                }
            );

        $manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(52)
            ->willReturn($subscription);

        $manager
            ->expects($this->never())
            ->method('transition_status');

        $manager->process_expiration(52);
        $this->assertTrue(true);
    }

    public function test_cancelled_renewal_order_cancels_linked_subscription(): void
    {
        $manager = $this->getMockBuilder(WSZ_Subscription_Manager::class)
            ->onlyMethods(array('get_subscription', 'transition_status'))
            ->getMock();

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order
            ->method('get_meta')
            ->with('_wsz_subscription_id', true)
            ->willReturn(901);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_status')
            ->willReturn('active');

        $manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(901)
            ->willReturn($subscription);

        $manager
            ->expects($this->once())
            ->method('transition_status')
            ->with(
                $subscription,
                'cancelled',
                $this->stringContains('Renewal order was cancelled or refunded.')
            )
            ->willReturn(true);

        $manager->maybe_cancel_subscription_from_renewal_order($renewal_order, $renewal_order);
    }

    public function test_cancelled_renewal_order_does_not_recancel_already_cancelled_subscription(): void
    {
        $manager = $this->getMockBuilder(WSZ_Subscription_Manager::class)
            ->onlyMethods(array('get_subscription', 'transition_status'))
            ->getMock();

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order
            ->method('get_meta')
            ->with('_wsz_subscription_id', true)
            ->willReturn(902);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_status')
            ->willReturn('cancelled');

        $manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(902)
            ->willReturn($subscription);

        $manager
            ->expects($this->never())
            ->method('transition_status');

        $manager->maybe_cancel_subscription_from_renewal_order($renewal_order, $renewal_order);
    }

    public function test_activate_deferred_subscription_waits_when_parent_order_not_paid(): void
    {
        $manager = $this->getMockBuilder(WSZ_Subscription_Manager::class)
            ->onlyMethods(array('get_subscription', 'schedule_deferred_activation', 'activate_subscription_after_payment', 'get_test_cycle_minutes'))
            ->getMock();

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_id')
            ->willReturn(1201);
        $subscription
            ->method('get_status')
            ->willReturn('pending');
        $subscription
            ->method('get_meta')
            ->willReturnCallback(
                static function ($key, $single = true) {
                    if ('_wsz_deferred_activation_at' === $key) {
                        return gmdate('Y-m-d H:i:s', current_time('timestamp', true) - 60);
                    }

                    if ('_wsz_parent_order_id' === $key) {
                        return 7701;
                    }

                    return '';
                }
            );

        $parent_order = $this->createMock(WC_Order::class);
        $parent_order
            ->method('get_status')
            ->willReturn('pending');
        $parent_order
            ->method('is_paid')
            ->willReturn(false);

        $GLOBALS['wsz_subs_test_orders'][7701] = $parent_order;

        $manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(1201)
            ->willReturn($subscription);

        $manager
            ->expects($this->atLeastOnce())
            ->method('get_test_cycle_minutes')
            ->willReturn(1);

        $manager
            ->expects($this->once())
            ->method('schedule_deferred_activation')
            ->with(
                1201,
                $this->callback(static fn ($timestamp): bool => is_int($timestamp) && $timestamp >= current_time('timestamp', true) + 60)
            );

        $manager
            ->expects($this->never())
            ->method('activate_subscription_after_payment');

        $manager->activate_deferred_subscription(1201);
    }

    public function test_activate_deferred_subscription_activates_when_parent_order_paid(): void
    {
        $manager = $this->getMockBuilder(WSZ_Subscription_Manager::class)
            ->onlyMethods(array('get_subscription', 'schedule_deferred_activation', 'activate_subscription_after_payment', 'get_test_cycle_minutes'))
            ->getMock();

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_id')
            ->willReturn(1202);
        $subscription
            ->method('get_status')
            ->willReturn('pending');
        $subscription
            ->method('get_meta')
            ->willReturnCallback(
                static function ($key, $single = true) {
                    if ('_wsz_deferred_activation_at' === $key) {
                        return gmdate('Y-m-d H:i:s', current_time('timestamp', true) - 60);
                    }

                    if ('_wsz_parent_order_id' === $key) {
                        return 7702;
                    }

                    return '';
                }
            );

        $parent_order = $this->createMock(WC_Order::class);
        $parent_order
            ->method('get_status')
            ->willReturn('processing');
        $parent_order
            ->method('is_paid')
            ->willReturn(true);

        $GLOBALS['wsz_subs_test_orders'][7702] = $parent_order;

        $manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(1202)
            ->willReturn($subscription);

        $manager
            ->expects($this->atLeastOnce())
            ->method('get_test_cycle_minutes')
            ->willReturn(0);

        $manager
            ->expects($this->never())
            ->method('schedule_deferred_activation');

        $manager
            ->expects($this->once())
            ->method('activate_subscription_after_payment')
            ->with(
                $subscription,
                $this->stringContains('Subscription activated on scheduled start date.')
            )
            ->willReturn(true);

        $manager->activate_deferred_subscription(1202);
    }
}
