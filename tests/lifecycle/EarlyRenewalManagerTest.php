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

        if ('wsz_subs_paynl_card_transactions' === $option_name && isset($GLOBALS['wsz_subs_paynl_card_transactions'])) {
            return $GLOBALS['wsz_subs_paynl_card_transactions'];
        }

        if (isset($GLOBALS['wsz_admin_test_options']) && is_array($GLOBALS['wsz_admin_test_options']) && array_key_exists($option_name, $GLOBALS['wsz_admin_test_options'])) {
            return $GLOBALS['wsz_admin_test_options'][$option_name];
        }

        return $default;
    }
}

if (!function_exists('wc_get_logger')) {
    function wc_get_logger()
    {
        return new class {
            public function warning($message, $context = array())
            {
                return null;
            }

            public function info($message, $context = array())
            {
                return null;
            }
        };
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-early-renewal-manager.php';

final class EarlyRenewalManagerTest extends TestCase
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

    public function test_filter_can_user_renew_early_rejects_when_feature_disabled(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array('enable_early_renewal' => 'no');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->never())
            ->method('is_customer_subscription_owner');

        $manager = new WSZ_Early_Renewal_Manager($subscription_manager);

        $this->assertFalse(
            $manager->filter_can_user_renew_early(true, new EarlyRenewalDummyOrder(10, 'active'), 5, '')
        );
    }

    public function test_filter_can_user_renew_early_rejects_non_owner(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array('enable_early_renewal' => 'yes');

        $subscription = new EarlyRenewalDummyOrder(10, 'active');
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('is_customer_subscription_owner')
            ->with($subscription, 5)
            ->willReturn(false);
        $subscription_manager
            ->expects($this->never())
            ->method('get_next_payment_timestamp');

        $manager = new WSZ_Early_Renewal_Manager($subscription_manager);

        $this->assertFalse($manager->filter_can_user_renew_early(true, $subscription, 5, ''));
    }

    public function test_filter_can_user_renew_early_allows_active_subscription_inside_window(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_early_renewal' => 'yes',
            'early_renewal_window_days' => 30,
        );

        $subscription = new EarlyRenewalDummyOrder(10, 'active');
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('is_customer_subscription_owner')
            ->with($subscription, 5)
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn(current_time('timestamp', true) + (10 * DAY_IN_SECONDS));

        $manager = new WSZ_Early_Renewal_Manager($subscription_manager);

        $this->assertTrue($manager->filter_can_user_renew_early(true, $subscription, 5, ''));
    }

    public function test_filter_can_user_renew_early_rejects_subscription_outside_window(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_early_renewal' => 'yes',
            'early_renewal_window_days' => 3,
        );

        $subscription = new EarlyRenewalDummyOrder(10, 'active');
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->method('is_customer_subscription_owner')
            ->willReturn(true);
        $subscription_manager
            ->method('get_next_payment_timestamp')
            ->willReturn(current_time('timestamp', true) + (10 * DAY_IN_SECONDS));

        $manager = new WSZ_Early_Renewal_Manager($subscription_manager);

        $this->assertFalse($manager->filter_can_user_renew_early(true, $subscription, 5, ''));
    }

    public function test_paid_early_renewal_advances_next_payment_and_marks_order_processed_once(): void
    {
        $now = current_time('timestamp', true);
        $subscription = new EarlyRenewalDummyOrder(77, 'active');
        $order = new EarlyRenewalDummyOrder(
            20,
            'processing',
            array(
                '_wsz_is_early_renewal_order' => 'yes',
                '_wsz_early_renewal_processed' => 'no',
                '_wsz_subscription_id' => 77,
            )
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(77)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($now + DAY_IN_SECONDS);
        $subscription_manager
            ->expects($this->once())
            ->method('calculate_next_payment_from_timestamp')
            ->with($subscription, $now + DAY_IN_SECONDS)
            ->willReturn($now + (31 * DAY_IN_SECONDS));
        $subscription_manager
            ->expects($this->once())
            ->method('update_next_payment_timestamp')
            ->with($subscription, $now + (31 * DAY_IN_SECONDS));
        $subscription_manager
            ->expects($this->never())
            ->method('transition_status');

        $manager = new WSZ_Early_Renewal_Manager($subscription_manager);
        $manager->maybe_process_paid_early_renewal(20, 'pending', 'processing', $order);

        $this->assertSame('yes', $order->get_meta('_wsz_early_renewal_processed', true));

        $manager->maybe_process_paid_early_renewal(20, 'processing', 'completed', $order);
    }

    public function test_paid_early_renewal_reactivates_non_active_subscription(): void
    {
        $now = current_time('timestamp', true);
        $subscription = new EarlyRenewalDummyOrder(77, 'on-hold');
        $order = new EarlyRenewalDummyOrder(
            20,
            'completed',
            array(
                '_wsz_is_early_renewal_order' => 'yes',
                '_wsz_early_renewal_processed' => 'no',
                '_wsz_subscription_id' => 77,
            )
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->method('get_subscription')
            ->willReturn($subscription);
        $subscription_manager
            ->method('get_next_payment_timestamp')
            ->willReturn($now - DAY_IN_SECONDS);
        $subscription_manager
            ->method('calculate_next_payment_from_timestamp')
            ->with($subscription, $now)
            ->willReturn($now + DAY_IN_SECONDS);
        $subscription_manager
            ->expects($this->once())
            ->method('update_next_payment_timestamp')
            ->with($subscription, $now + DAY_IN_SECONDS);
        $subscription_manager
            ->expects($this->once())
            ->method('transition_status')
            ->with(
                $subscription,
                'active',
                'Subscription reactivated by early renewal payment.'
            );

        $manager = new WSZ_Early_Renewal_Manager($subscription_manager);
        $manager->maybe_process_paid_early_renewal(20, 'pending', 'completed', $order);

        $this->assertSame('yes', $order->get_meta('_wsz_early_renewal_processed', true));
    }
}

final class EarlyRenewalDummyOrder extends WC_Order
{
    private int $id;

    private string $status;

    private array $meta;

    public function __construct(int $id, string $status, array $meta = array())
    {
        $this->id = $id;
        $this->status = $status;
        $this->meta = $meta;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data($key, $value)
    {
        $this->meta[$key] = $value;
    }

    public function save()
    {
    }
}
