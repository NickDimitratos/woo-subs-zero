<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('get_option')) {
    function get_option($option_name, $default = false)
    {
        if ('wsz_subs_options' === $option_name && isset($GLOBALS['wsz_subs_test_options'])) {
            return $GLOBALS['wsz_subs_test_options'];
        }

        if ('wsz_subs_test_card_transactions' === $option_name && isset($GLOBALS['wsz_subs_test_card_transactions'])) {
            return $GLOBALS['wsz_subs_test_card_transactions'];
        }

        if (isset($GLOBALS['wsz_admin_test_options']) && is_array($GLOBALS['wsz_admin_test_options']) && array_key_exists($option_name, $GLOBALS['wsz_admin_test_options'])) {
            return $GLOBALS['wsz_admin_test_options'][$option_name];
        }

        return $default;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-switching-manager.php';

final class SwitchingManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_subs_test_options'] = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_subs_test_options']);

        parent::tearDown();
    }

    public function test_calculate_proration_breakdown_handles_upgrade_and_signup_fee(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager = new WSZ_Switching_Manager($subscription_manager);

        $breakdown = $manager->calculate_proration_breakdown(
            10.00,
            30.00,
            15 * DAY_IN_SECONDS,
            30 * DAY_IN_SECONDS,
            true,
            true,
            12.00
        );

        $this->assertSame(500, $breakdown['old_credit_cents']);
        $this->assertSame(1500, $breakdown['new_charge_cents']);
        $this->assertSame(600, $breakdown['signup_fee_cents']);
        $this->assertSame(1600, $breakdown['extra_to_pay_cents']);
        $this->assertSame(0, $breakdown['credit_cents']);
    }

    public function test_calculate_proration_breakdown_can_generate_credit_for_downgrade(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager = new WSZ_Switching_Manager($subscription_manager);

        $breakdown = $manager->calculate_proration_breakdown(
            40.00,
            10.00,
            20 * DAY_IN_SECONDS,
            30 * DAY_IN_SECONDS,
            true,
            false,
            0.00
        );

        $this->assertSame(2667, $breakdown['old_credit_cents']);
        $this->assertSame(667, $breakdown['new_charge_cents']);
        $this->assertSame(0, $breakdown['extra_to_pay_cents']);
        $this->assertSame(2000, $breakdown['credit_cents']);
    }

    public function test_filter_proration_extra_to_pay_preserves_charge_when_proration_disabled(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array('enable_proration' => 'no');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager = new WSZ_Switching_Manager($subscription_manager);

        $this->assertSame(12.34, $manager->filter_proration_extra_to_pay(12.34, null, array(), 30));
    }

    public function test_filter_proration_extra_to_pay_waives_charge_inside_free_switch_window(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_proration' => 'yes',
            'free_switch_window_days' => 7,
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager = new WSZ_Switching_Manager($subscription_manager);
        $subscription = new SwitchingManagerDummySubscription(current_time('timestamp', true) - (2 * DAY_IN_SECONDS));

        $this->assertSame(0.0, $manager->filter_proration_extra_to_pay(19.99, $subscription, array(), 30));
    }

    public function test_filter_days_in_old_cycle_uses_subscription_cycle_when_enabled(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array('proration_subscription_length' => 'yes');

        $subscription = new SwitchingManagerDummySubscription(current_time('timestamp', true));
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_cycle_length_in_seconds')
            ->with($subscription)
            ->willReturn(14 * DAY_IN_SECONDS);

        $manager = new WSZ_Switching_Manager($subscription_manager);

        $this->assertSame(14, $manager->filter_days_in_old_cycle(30, $subscription, array()));
    }

    public function test_filter_days_in_new_cycle_uses_subscription_month_and_year_intervals(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array('proration_subscription_length' => 'yes');

        $subscription = new SwitchingManagerDummySubscription(current_time('timestamp', true));
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->exactly(2))
            ->method('get_billing_period')
            ->with($subscription)
            ->willReturnOnConsecutiveCalls('month', 'year');
        $subscription_manager
            ->expects($this->exactly(2))
            ->method('get_billing_interval')
            ->with($subscription)
            ->willReturnOnConsecutiveCalls(2, 1);

        $manager = new WSZ_Switching_Manager($subscription_manager);

        $this->assertSame(60, $manager->filter_days_in_new_cycle(10, $subscription, array(), 30));
        $this->assertSame(365, $manager->filter_days_in_new_cycle(10, $subscription, array(), 30));
    }
}

final class SwitchingManagerDummySubscription extends WC_Order
{
    private int $created_timestamp;

    public function __construct(int $created_timestamp)
    {
        $this->created_timestamp = $created_timestamp;
    }

    public function get_date_created()
    {
        return new DateTimeImmutable('@' . $this->created_timestamp);
    }
}
