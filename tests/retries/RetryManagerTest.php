<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value)
    {
        return $value;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-retry-manager.php';

final class RetryManagerTest extends TestCase
{
    public function test_default_retry_profile_matches_expected_intervals(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $retry_manager = new WSZ_Retry_Manager($subscription_manager, $payment_handler);
        $rules = $retry_manager->get_retry_rules();

        $this->assertCount(5, $rules);
        $this->assertSame(12 * HOUR_IN_SECONDS, $rules[0]['interval']);
        $this->assertSame(12 * HOUR_IN_SECONDS, $rules[1]['interval']);
        $this->assertSame(24 * HOUR_IN_SECONDS, $rules[2]['interval']);
        $this->assertSame(48 * HOUR_IN_SECONDS, $rules[3]['interval']);
        $this->assertSame(72 * HOUR_IN_SECONDS, $rules[4]['interval']);
    }
}
