<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-switching-manager.php';

final class SwitchingManagerTest extends TestCase
{
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
}
