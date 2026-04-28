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

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';

final class SubscriptionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_subs_test_options'] = array();
    }

    public function test_valid_transition_matrix_accepts_expected_routes(): void
    {
        $this->assertTrue(WSZ_Subscription_Manager::is_valid_transition('pending', 'active'));
        $this->assertTrue(WSZ_Subscription_Manager::is_valid_transition('active', 'on-hold'));
        $this->assertTrue(WSZ_Subscription_Manager::is_valid_transition('pending-cancel', 'cancelled'));
    }

    public function test_valid_transition_matrix_rejects_invalid_routes(): void
    {
        $this->assertFalse(WSZ_Subscription_Manager::is_valid_transition('active', 'cancelled'));
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
}
