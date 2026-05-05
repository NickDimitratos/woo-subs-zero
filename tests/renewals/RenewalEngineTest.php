<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '', $unique = false)
    {
        $return = $GLOBALS['wsz_test_schedule_return'] ?? 1;

        if (is_array($return)) {
            if (empty($return)) {
                $return = 0;
            } else {
                $next = array_shift($return);
                $GLOBALS['wsz_test_schedule_return'] = $return;
                $return = $next;
            }
        }

        if (is_numeric($return) ? ((int) $return > 0) : (true === $return)) {
            $GLOBALS['wsz_test_scheduled_actions'][] = array(
                'timestamp' => (int) $timestamp,
                'hook' => (string) $hook,
                'args' => $args,
                'group' => (string) $group,
                'unique' => (bool) $unique,
            );
        }

        return $return;
    }
}

if (!function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action($hook, $args = null, $group = '')
    {
        $scheduled = $GLOBALS['wsz_test_scheduled_actions'] ?? array();

        if (!is_array($scheduled)) {
            return false;
        }

        foreach ($scheduled as $entry) {
            if (($entry['hook'] ?? '') !== $hook) {
                continue;
            }

            if (($entry['group'] ?? '') !== $group) {
                continue;
            }

            if (null !== $args && ($entry['args'] ?? array()) !== $args) {
                continue;
            }

            return true;
        }

        return false;
    }
}

if (!function_exists('wcs_create_renewal_order')) {
    function wcs_create_renewal_order($subscription)
    {
        return $GLOBALS['wsz_test_wcs_renewal_order'] ?? null;
    }
}

if (!function_exists('wc_create_order')) {
    function wc_create_order($args = array())
    {
        return $GLOBALS['wsz_test_wc_created_order'] ?? null;
    }
}

if (!function_exists('wc_get_logger')) {
    function wc_get_logger()
    {
        return new class {
            public function error($message, $context = array())
            {
                return null;
            }

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

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id)
    {
        if ($order_id instanceof WC_Order) {
            return $order_id;
        }

        $order_id = (int) $order_id;

        if (isset($GLOBALS['wsz_test_orders'][$order_id])) {
            return $GLOBALS['wsz_test_orders'][$order_id];
        }

        if (isset($GLOBALS['wsz_subs_test_orders'][$order_id])) {
            return $GLOBALS['wsz_subs_test_orders'][$order_id];
        }

        if (isset($GLOBALS['wsz_test_card_orders'][$order_id])) {
            return $GLOBALS['wsz_test_card_orders'][$order_id];
        }

        return null;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-retry-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-renewal-engine.php';

final class RenewalEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_test_scheduled_actions'] = array();
        $GLOBALS['wsz_test_schedule_return'] = 1;
        $GLOBALS['wsz_test_wcs_renewal_order'] = null;
        $GLOBALS['wsz_test_wc_created_order'] = null;
        $GLOBALS['wsz_test_orders'] = array();
        $GLOBALS['wsz_test_card_orders'] = array();

        if (is_callable(array('WC_Payment_Tokens', 'reset_test_tokens'))) {
            WC_Payment_Tokens::reset_test_tokens();
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_test_scheduled_actions']);
        unset($GLOBALS['wsz_test_schedule_return']);
        unset($GLOBALS['wsz_test_wcs_renewal_order']);
        unset($GLOBALS['wsz_test_wc_created_order']);
        unset($GLOBALS['wsz_test_orders']);
        unset($GLOBALS['wsz_test_card_orders']);

        parent::tearDown();
    }

    public function test_schedule_key_is_deterministic_for_same_inputs(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'build_schedule_key');
        $method->setAccessible(true);

        $first = $method->invoke($engine, 123, 1714200000);
        $second = $method->invoke($engine, 123, 1714200000);
        $third = $method->invoke($engine, 123, 1714200600);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $third);
    }

    public function test_schedule_first_renewal_expires_when_next_payment_hits_past_term_end(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(101);

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn(time() - 60);
        $subscription_manager
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturn(time() - 60);
        $subscription_manager
            ->expects($this->once())
            ->method('process_expiration')
            ->with(101);
        $subscription_manager
            ->expects($this->never())
            ->method('schedule_expiration');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $engine->schedule_first_renewal($subscription);
    }

    public function test_schedule_first_renewal_schedules_expiration_when_term_end_is_future(): void
    {
        $end_timestamp = time() + DAY_IN_SECONDS;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(202);

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($end_timestamp);
        $subscription_manager
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturn($end_timestamp);
        $subscription_manager
            ->expects($this->never())
            ->method('process_expiration');
        $subscription_manager
            ->expects($this->once())
            ->method('schedule_expiration')
            ->with(202, $end_timestamp);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $engine->schedule_first_renewal($subscription);
    }

    public function test_schedule_first_renewal_does_not_duplicate_existing_pending_action(): void
    {
        $next_timestamp = current_time('timestamp', true) + 60;
        $subscription_id = 206;
        $schedule_key = hash('sha256', $subscription_id . '|' . $next_timestamp);

        $GLOBALS['wsz_test_scheduled_actions'][] = array(
            'timestamp' => $next_timestamp,
            'hook' => 'wsz_subs_process_renewal',
            'args' => array(
                'subscription_id' => $subscription_id,
                'schedule_key' => $schedule_key,
            ),
            'group' => 'wsz-subscriptions',
            'unique' => true,
        );

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn($subscription_id);

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($next_timestamp);
        $subscription_manager
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturn(0);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $engine->schedule_first_renewal($subscription);

        $this->assertCount(1, $GLOBALS['wsz_test_scheduled_actions']);
    }

    public function test_should_skip_renewal_only_at_or_after_end_timestamp(): void
    {
        $subscription = $this->createMock(WC_Order::class);

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->exactly(3))
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturn(2000);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'should_skip_renewal_at_timestamp');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($engine, $subscription, 1999));
        $this->assertTrue($method->invoke($engine, $subscription, 2000));
        $this->assertTrue($method->invoke($engine, $subscription, 2001));
    }

    public function test_should_finalize_before_renewal_uses_payment_completion_for_finite_plans(): void
    {
        $subscription = $this->createMock(WC_Order::class);

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->exactly(2))
            ->method('get_total_payments')
            ->with($subscription)
            ->willReturnOnConsecutiveCalls(1, 4);
        $subscription_manager
            ->expects($this->exactly(2))
            ->method('has_completed_all_payments')
            ->with($subscription)
            ->willReturnOnConsecutiveCalls(true, false);
        $subscription_manager
            ->expects($this->never())
            ->method('get_end_timestamp');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'should_finalize_before_renewal');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($engine, $subscription, 2000));
        $this->assertFalse($method->invoke($engine, $subscription, 2000));
    }

    public function test_calculate_next_payment_timestamp_uses_strict_cycle_anchor_in_test_mode(): void
    {
        $current_next = 1700000000;
        $strict_next = 1700000060;

        $subscription = $this->getMockBuilder(WC_Order::class)
            ->addMethods(array('calculate_date'))
            ->getMock();
        $subscription
            ->expects($this->never())
            ->method('calculate_date');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($current_next);
        $subscription_manager
            ->expects($this->once())
            ->method('get_test_cycle_minutes')
            ->willReturn(1);
        $subscription_manager
            ->expects($this->once())
            ->method('calculate_next_payment_from_timestamp')
            ->with($subscription, $current_next)
            ->willReturn($strict_next);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'calculate_next_payment_timestamp');
        $method->setAccessible(true);

        $this->assertSame($strict_next, $method->invoke($engine, $subscription, 'renewal'));
    }

    public function test_advance_next_payment_marks_term_boundary_without_queueing_extra_renewal(): void
    {
        $end_timestamp = current_time('timestamp', true) + 120;
        $current_next_payment = $end_timestamp - 60;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(707);

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($current_next_payment);
        $subscription_manager
            ->expects($this->once())
            ->method('calculate_next_payment_from_timestamp')
            ->with($subscription, $current_next_payment)
            ->willReturn($end_timestamp);
        $subscription_manager
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturn($end_timestamp);
        $subscription_manager
            ->expects($this->once())
            ->method('update_next_payment_timestamp')
            ->with($subscription, $end_timestamp);
        $subscription_manager
            ->expects($this->once())
            ->method('schedule_expiration')
            ->with(707, $end_timestamp);
        $subscription_manager
            ->expects($this->never())
            ->method('process_expiration');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'advance_next_payment_and_schedule');
        $method->setAccessible(true);
        $method->invoke($engine, $subscription);

        $this->assertCount(0, $GLOBALS['wsz_test_scheduled_actions']);
    }

    public function test_process_renewal_skips_charge_when_next_payment_reaches_term_end(): void
    {
        $end_timestamp = current_time('timestamp', true) + 120;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(910);
        $subscription->method('get_status')->willReturn('active');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(910)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($end_timestamp);
        $subscription_manager
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturn($end_timestamp);
        $subscription_manager
            ->expects($this->once())
            ->method('schedule_expiration')
            ->with(910, $end_timestamp);
        $subscription_manager
            ->expects($this->once())
            ->method('update_next_payment_timestamp')
            ->with($subscription, $end_timestamp);
        $subscription_manager
            ->expects($this->never())
            ->method('acquire_lock');
        $subscription_manager
            ->expects($this->never())
            ->method('process_expiration');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->never())
            ->method('dispatch_scheduled_payment');

        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);
        $retry_manager
            ->expects($this->never())
            ->method('queue_retry');

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);
        $engine->process_renewal(910, '');

        $this->assertCount(0, $GLOBALS['wsz_test_scheduled_actions']);
    }

    public function test_process_renewal_skips_when_subscription_not_active(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(911);
        $subscription->method('get_status')->willReturn('pending');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(911)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->never())
            ->method('get_next_payment_timestamp');
        $subscription_manager
            ->expects($this->never())
            ->method('acquire_lock');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->never())
            ->method('dispatch_scheduled_payment');

        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);
        $retry_manager
            ->expects($this->never())
            ->method('queue_retry');

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);
        $engine->process_renewal(911, '');
    }

    public function test_process_renewal_skips_when_subscription_is_cancelled(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(914);
        $subscription->method('get_status')->willReturn('cancelled');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(914)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->never())
            ->method('get_next_payment_timestamp');
        $subscription_manager
            ->expects($this->never())
            ->method('acquire_lock');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->never())
            ->method('dispatch_scheduled_payment');

        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);
        $engine->process_renewal(914, '');
    }

    public function test_paid_renewal_expires_subscription_when_final_required_payment_completes(): void
    {
        $next_payment = current_time('timestamp', true) + 60;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(915);
        $subscription->method('get_status')->willReturn('active');
        $subscription
            ->expects($this->exactly(2))
            ->method('update_meta_data')
            ->with(
                $this->logicalOr(
                    $this->equalTo('_wsz_next_schedule_key'),
                    $this->equalTo('_wsz_last_processed_schedule_key')
                ),
                $this->logicalOr(
                    $this->equalTo(''),
                    $this->equalTo('final-key')
                )
            );
        $subscription
            ->expects($this->exactly(2))
            ->method('save');

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order
            ->expects($this->exactly(2))
            ->method('get_total')
            ->willReturn(10.0);
        $renewal_order
            ->expects($this->once())
            ->method('is_paid')
            ->willReturn(true);

        $GLOBALS['wsz_test_wcs_renewal_order'] = $renewal_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(915)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($next_payment);
        $subscription_manager
            ->expects($this->once())
            ->method('get_total_payments')
            ->with($subscription)
            ->willReturn(4);
        $subscription_manager
            ->expects($this->exactly(2))
            ->method('has_completed_all_payments')
            ->with($subscription)
            ->willReturnOnConsecutiveCalls(false, true);
        $subscription_manager
            ->expects($this->once())
            ->method('acquire_lock')
            ->with('renewal', 915, 300)
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('is_manual_renewal')
            ->with($subscription)
            ->willReturn(false);
        $subscription_manager
            ->expects($this->once())
            ->method('increment_completed_payments')
            ->with($subscription, 1)
            ->willReturn(4);
        $subscription_manager
            ->expects($this->once())
            ->method('transition_status')
            ->with($subscription, 'expired', $this->stringContains('Subscription term completed.'))
            ->willReturn(true);
        $subscription_manager
            ->expects($this->never())
            ->method('calculate_next_payment_from_timestamp');
        $subscription_manager
            ->expects($this->once())
            ->method('release_lock')
            ->with('renewal', 915);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->once())
            ->method('dispatch_scheduled_payment')
            ->with($subscription, $renewal_order, 10.0);

        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);
        $retry_manager
            ->expects($this->once())
            ->method('cancel_pending_retries')
            ->with($renewal_order);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);
        $engine->process_renewal(915, 'final-key');
    }

    public function test_process_renewal_runs_overdue_cycle_before_expiration_boundary(): void
    {
        $now = current_time('timestamp', true);
        $next_payment = $now - 120;
        $end_timestamp = $now - 60;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(912);
        $subscription->method('get_status')->willReturn('active');

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order
            ->expects($this->exactly(2))
            ->method('get_total')
            ->willReturn(10.0);
        $renewal_order
            ->expects($this->once())
            ->method('has_status')
            ->with(array('pending'))
            ->willReturn(true);
        $renewal_order
            ->expects($this->never())
            ->method('update_status');
        $renewal_order
            ->expects($this->never())
            ->method('is_paid');

        $GLOBALS['wsz_test_wcs_renewal_order'] = $renewal_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(912)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($next_payment);
        $subscription_manager
            ->expects($this->once())
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturn($end_timestamp);
        $subscription_manager
            ->expects($this->once())
            ->method('acquire_lock')
            ->with('renewal', 912, 300)
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('is_manual_renewal')
            ->with($subscription)
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('transition_status')
            ->with(
                $subscription,
                'on-hold',
                $this->stringContains('Awaiting manual renewal payment.')
            )
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('release_lock')
            ->with('renewal', 912);
        $subscription_manager
            ->expects($this->never())
            ->method('process_expiration');
        $subscription_manager
            ->expects($this->never())
            ->method('schedule_expiration');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->once())
            ->method('dispatch_scheduled_payment')
            ->with($subscription, $renewal_order, 10.0);

        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);
        $retry_manager
            ->expects($this->never())
            ->method('queue_retry');

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);
        $engine->process_renewal(912, '');
    }

    public function test_schedule_renewal_for_timestamp_enqueues_immediate_action_when_due_now(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(777);
        $subscription
            ->expects($this->once())
            ->method('update_meta_data')
            ->with('_wsz_next_schedule_key', $this->isType('string'));
        $subscription
            ->expects($this->once())
            ->method('save');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'schedule_renewal_for_timestamp');
        $method->setAccessible(true);

        $before = current_time('timestamp', true);
        $method->invoke($engine, $subscription, $before);

        $this->assertCount(1, $GLOBALS['wsz_test_scheduled_actions']);

        $scheduled = $GLOBALS['wsz_test_scheduled_actions'][0];

        $this->assertSame('wsz_subs_process_renewal', $scheduled['hook']);
        $this->assertSame('wsz-subscriptions', $scheduled['group']);
        $this->assertTrue($scheduled['unique']);
        $this->assertSame(777, (int) ($scheduled['args']['subscription_id'] ?? 0));
        $this->assertNotSame('', (string) ($scheduled['args']['schedule_key'] ?? ''));
        $this->assertGreaterThanOrEqual($before + 1, (int) $scheduled['timestamp']);
    }

    public function test_schedule_renewal_for_timestamp_does_not_persist_key_when_scheduler_fails(): void
    {
        $GLOBALS['wsz_test_schedule_return'] = 0;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(888);
        $subscription
            ->expects($this->never())
            ->method('update_meta_data');
        $subscription
            ->expects($this->never())
            ->method('save');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'schedule_renewal_for_timestamp');
        $method->setAccessible(true);
        $method->invoke($engine, $subscription, current_time('timestamp', true));

        $this->assertCount(0, $GLOBALS['wsz_test_scheduled_actions']);
    }

    public function test_schedule_renewal_for_timestamp_retries_non_unique_when_unique_schedule_is_blocked(): void
    {
        $GLOBALS['wsz_test_schedule_return'] = array(0, 77);

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(889);
        $subscription
            ->expects($this->once())
            ->method('update_meta_data')
            ->with('_wsz_next_schedule_key', $this->isType('string'));
        $subscription
            ->expects($this->once())
            ->method('save');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'schedule_renewal_for_timestamp');
        $method->setAccessible(true);
        $method->invoke($engine, $subscription, current_time('timestamp', true));

        $this->assertCount(1, $GLOBALS['wsz_test_scheduled_actions']);
        $scheduled = $GLOBALS['wsz_test_scheduled_actions'][0];
        $this->assertFalse((bool) $scheduled['unique']);
    }

    public function test_schedule_first_renewal_backfills_missing_end_timestamp_for_finite_plan(): void
    {
        $now = current_time('timestamp', true);
        $next_payment = $now + 60;
        $computed_end = $now + 240;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(303);
        $subscription->method('get_meta')->with('_wsz_start_date', true)->willReturn(gmdate('Y-m-d H:i:s', $now));
        $subscription
            ->expects($this->once())
            ->method('update_meta_data')
            ->with('_wsz_next_schedule_key', $this->isType('string'));
        $subscription
            ->expects($this->once())
            ->method('save');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription_length')
            ->with($subscription)
            ->willReturn(4);
        $subscription_manager
            ->expects($this->exactly(2))
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturnOnConsecutiveCalls(0, $computed_end);
        $subscription_manager
            ->expects($this->once())
            ->method('get_billing_interval')
            ->with($subscription)
            ->willReturn(1);
        $subscription_manager
            ->expects($this->once())
            ->method('get_billing_period')
            ->with($subscription)
            ->willReturn('month');
        $subscription_manager
            ->expects($this->once())
            ->method('calculate_end_timestamp_for_profile')
            ->with($now, 1, 'month', 4)
            ->willReturn($computed_end);
        $subscription_manager
            ->expects($this->once())
            ->method('update_end_timestamp')
            ->with($subscription, $computed_end);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($next_payment);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $engine->schedule_first_renewal($subscription);

        $this->assertCount(1, $GLOBALS['wsz_test_scheduled_actions']);
    }

    public function test_schedule_first_renewal_recovers_missing_length_from_parent_order_item_meta(): void
    {
        $now = current_time('timestamp', true);
        $next_payment = $now + 60;
        $computed_end = $now + 240;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(304);
        $seen_meta_updates = array();
        $subscription
            ->method('get_meta')
            ->willReturnCallback(
                static function ($key, $single = true) use ($now) {
                    if ('_wsz_start_date' === $key) {
                        return gmdate('Y-m-d H:i:s', $now);
                    }

                    if ('_wsz_parent_order_id' === $key) {
                        return 555;
                    }

                    return '';
                }
            );
        $subscription
            ->expects($this->exactly(2))
            ->method('update_meta_data')
            ->willReturnCallback(
                static function ($key, $value) use (&$seen_meta_updates) {
                    $seen_meta_updates[] = array(
                        'key' => $key,
                        'value' => $value,
                    );
                }
            );
        $subscription
            ->expects($this->exactly(2))
            ->method('save');

        $order_item = $this->getMockBuilder(stdClass::class)
            ->addMethods(array('get_meta'))
            ->getMock();
        $order_item
            ->method('get_meta')
            ->willReturnCallback(
                static function ($key, $single = true) {
                    if ('_wsz_subscription_length' === $key) {
                        return 4;
                    }

                    return '';
                }
            );

        $parent_order = new class($order_item) extends WC_Order {
            private $order_item;

            public function __construct($order_item)
            {
                $this->order_item = $order_item;
            }

            public function get_items($type = 'line_item')
            {
                return array($this->order_item);
            }
        };

        $GLOBALS['wsz_test_orders'][555] = $parent_order;
        $GLOBALS['wsz_test_card_orders'][555] = $parent_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription_length')
            ->with($subscription)
            ->willReturn(0);
        $subscription_manager
            ->expects($this->exactly(2))
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturnOnConsecutiveCalls(0, $computed_end);
        $subscription_manager
            ->expects($this->once())
            ->method('get_billing_interval')
            ->with($subscription)
            ->willReturn(1);
        $subscription_manager
            ->expects($this->once())
            ->method('get_billing_period')
            ->with($subscription)
            ->willReturn('month');
        $subscription_manager
            ->expects($this->once())
            ->method('calculate_end_timestamp_for_profile')
            ->with($now, 1, 'month', 4)
            ->willReturn($computed_end);
        $subscription_manager
            ->expects($this->once())
            ->method('update_end_timestamp')
            ->with($subscription, $computed_end);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn($next_payment);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $engine->schedule_first_renewal($subscription);

        $this->assertCount(1, $GLOBALS['wsz_test_scheduled_actions']);

        $length_meta_update_found = false;

        foreach ($seen_meta_updates as $entry) {
            if ('_wsz_subscription_length' !== ($entry['key'] ?? '')) {
                continue;
            }

            if (4 === (int) ($entry['value'] ?? 0)) {
                $length_meta_update_found = true;
                break;
            }
        }

        $this->assertTrue($length_meta_update_found);

        $next_schedule_key_update_found = false;

        foreach ($seen_meta_updates as $entry) {
            if ('_wsz_next_schedule_key' !== ($entry['key'] ?? '')) {
                continue;
            }

            if (is_string($entry['value'] ?? null) && '' !== $entry['value']) {
                $next_schedule_key_update_found = true;
                break;
            }
        }

        $this->assertTrue($next_schedule_key_update_found);
    }

    public function test_process_renewal_handles_manual_mode_without_failed_retry_flow(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(901);
        $subscription->method('get_status')->willReturn('active');

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order
            ->expects($this->exactly(2))
            ->method('get_total')
            ->willReturn(10.0);
        $renewal_order
            ->expects($this->once())
            ->method('has_status')
            ->with(array('pending'))
            ->willReturn(true);
        $renewal_order
            ->expects($this->never())
            ->method('update_status');
        $renewal_order
            ->expects($this->never())
            ->method('is_paid');

        $GLOBALS['wsz_test_wcs_renewal_order'] = $renewal_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(901)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('get_next_payment_timestamp')
            ->with($subscription)
            ->willReturn(current_time('timestamp', true) + 60);
        $subscription_manager
            ->expects($this->once())
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturn(0);
        $subscription_manager
            ->expects($this->once())
            ->method('acquire_lock')
            ->with('renewal', 901, 300)
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('is_manual_renewal')
            ->with($subscription)
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('transition_status')
            ->with(
                $subscription,
                'on-hold',
                $this->stringContains('Awaiting manual renewal payment.')
            )
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('release_lock')
            ->with('renewal', 901);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->once())
            ->method('dispatch_scheduled_payment')
            ->with($subscription, $renewal_order, 10.0);

        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);
        $retry_manager
            ->expects($this->never())
            ->method('queue_retry');

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);
        $engine->process_renewal(901, '');
    }

    public function test_create_renewal_order_hydrates_incomplete_wcs_order_in_place(): void
    {
        $subscription_item = new class {
            public function set_id($id): void
            {
            }
        };

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(930);
        $subscription->method('get_customer_id')->willReturn(14);
        $subscription->method('get_payment_method')->willReturn('pay_gateway');
        $subscription->method('get_payment_method_title')->willReturn('Pay.nl');
        $subscription->method('get_currency')->willReturn('EUR');
        $subscription->method('get_total')->willReturn(49.99);
        $subscription->method('get_meta_data')->willReturn(array());
        $subscription
            ->method('get_meta')
            ->with('_wsz_parent_order_id', true)
            ->willReturn('');
        $subscription
            ->method('get_items')
            ->willReturnCallback(
                static function ($types) use ($subscription_item): array {
                    return in_array('line_item', (array) $types, true) ? array($subscription_item) : array();
                }
            );

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_payment_method = '';
        $renewal_currency = '';
        $renewal_order->method('get_id')->willReturn(444);
        $renewal_order
            ->method('get_payment_method')
            ->willReturnCallback(static function () use (&$renewal_payment_method): string {
                return $renewal_payment_method;
            });
        $renewal_order
            ->method('get_currency')
            ->willReturnCallback(static function () use (&$renewal_currency): string {
                return $renewal_currency;
            });
        $renewal_order
            ->method('get_items')
            ->with(array('line_item'))
            ->willReturn(array());
        $renewal_order
            ->expects($this->once())
            ->method('add_item');
        $renewal_order
            ->expects($this->once())
            ->method('set_payment_method')
            ->with('pay_gateway')
            ->willReturnCallback(static function ($gateway_id) use (&$renewal_payment_method): void {
                $renewal_payment_method = (string) $gateway_id;
            });
        $renewal_order
            ->expects($this->once())
            ->method('set_payment_method_title')
            ->with('Pay.nl');
        $renewal_order
            ->expects($this->once())
            ->method('set_currency')
            ->with('EUR')
            ->willReturnCallback(static function ($currency) use (&$renewal_currency): void {
                $renewal_currency = (string) $currency;
            });
        $renewal_order
            ->expects($this->once())
            ->method('set_total')
            ->with(49.99);
        $renewal_order
            ->expects($this->once())
            ->method('calculate_totals')
            ->with(false);
        $renewal_order
            ->expects($this->once())
            ->method('update_meta_data')
            ->with('_wsz_subscription_id', 930);
        $renewal_order
            ->expects($this->once())
            ->method('save');

        $GLOBALS['wsz_test_wcs_renewal_order'] = $renewal_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('copy_payment_context_meta')
            ->with($subscription, $renewal_order)
            ->willReturn(false);
        $subscription_manager
            ->expects($this->once())
            ->method('add_related_order')
            ->with($subscription, 444, 'renewal');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'create_renewal_order');
        $method->setAccessible(true);

        $this->assertSame($renewal_order, $method->invoke($engine, $subscription));
    }

    public function test_create_renewal_order_hydrates_thin_wcs_order_customer_context_in_place(): void
    {
        $subscription_item = $this->create_test_line_item();
        $renewal_item = $this->create_test_line_item();

        $subscription = $this->create_context_order(
            930,
            array($subscription_item),
            array(
                'customer_id' => 14,
                'payment_method' => 'pay_gateway',
                'payment_method_title' => 'Pay.nl',
                'currency' => 'EUR',
                'total' => 49.99,
                'billing' => array(
                    'first_name' => 'Nikos',
                    'last_name' => 'Dimitratos',
                    'company' => 'Koreli',
                    'address_1' => 'Billing Street 1',
                    'address_2' => 'Suite 2',
                    'city' => 'Athens',
                    'postcode' => '10557',
                    'country' => 'GR',
                    'state' => 'AT',
                    'email' => 'nikos@example.com',
                    'phone' => '+302100000000',
                ),
                'shipping' => array(
                    'first_name' => 'Nikos',
                    'last_name' => 'Dimitratos',
                    'company' => 'Koreli',
                    'address_1' => 'Shipping Street 3',
                    'address_2' => 'Floor 4',
                    'city' => 'Athens',
                    'postcode' => '10558',
                    'country' => 'GR',
                    'state' => 'AT',
                ),
            )
        );

        $renewal_order = $this->create_context_order(
            444,
            array($renewal_item),
            array(
                'meta' => array(
                    '_transaction_id' => 'PAYNL-TX-001',
                ),
            )
        );

        $GLOBALS['wsz_test_wcs_renewal_order'] = $renewal_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('copy_payment_context_meta')
            ->with($subscription, $renewal_order)
            ->willReturn(false);
        $subscription_manager
            ->expects($this->once())
            ->method('add_related_order')
            ->with($subscription, 444, 'renewal');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'create_renewal_order');
        $method->setAccessible(true);

        $this->assertSame($renewal_order, $method->invoke($engine, $subscription));
        $this->assertSame(14, $renewal_order->get_customer_id());
        $this->assertSame('Nikos', $renewal_order->get_billing_first_name());
        $this->assertSame('Dimitratos', $renewal_order->get_billing_last_name());
        $this->assertSame('nikos@example.com', $renewal_order->get_billing_email());
        $this->assertSame('+302100000000', $renewal_order->get_billing_phone());
        $this->assertSame('Shipping Street 3', $renewal_order->get_shipping_address_1());
        $this->assertSame('10558', $renewal_order->get_shipping_postcode());
        $this->assertSame('pay_gateway', $renewal_order->get_payment_method());
        $this->assertSame('EUR', $renewal_order->get_currency());
    }

    public function test_native_renewal_order_hydrates_customer_context_from_subscription(): void
    {
        $subscription_item = $this->create_test_line_item();
        $subscription = $this->create_context_order(
            931,
            array($subscription_item),
            array(
                'customer_id' => 22,
                'payment_method' => 'pay_gateway',
                'payment_method_title' => 'Pay.nl',
                'currency' => 'EUR',
                'total' => 29.5,
                'billing' => array(
                    'first_name' => 'Maria',
                    'last_name' => 'Papadopoulou',
                    'email' => 'maria@example.com',
                    'phone' => '+302100000001',
                ),
                'shipping' => array(
                    'first_name' => 'Maria',
                    'last_name' => 'Papadopoulou',
                    'address_1' => 'Delivery Road 9',
                    'postcode' => '54624',
                    'country' => 'GR',
                ),
            )
        );
        $renewal_order = $this->create_context_order(445, array());

        $GLOBALS['wsz_test_wcs_renewal_order'] = null;
        $GLOBALS['wsz_test_wc_created_order'] = $renewal_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('copy_payment_context_meta')
            ->with($subscription, $renewal_order)
            ->willReturn(false);
        $subscription_manager
            ->expects($this->once())
            ->method('add_related_order')
            ->with($subscription, 445, 'renewal');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);
        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);

        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'create_renewal_order');
        $method->setAccessible(true);

        $this->assertSame($renewal_order, $method->invoke($engine, $subscription));
        $this->assertSame(22, $renewal_order->get_customer_id());
        $this->assertSame('Maria', $renewal_order->get_billing_first_name());
        $this->assertSame('maria@example.com', $renewal_order->get_billing_email());
        $this->assertSame('Delivery Road 9', $renewal_order->get_shipping_address_1());
    }

    public function test_copy_resolved_payment_token_attaches_wc_token_to_renewal_order(): void
    {
        $token = new WC_Payment_Token();
        $token->set_token('tok_renewal');
        $token->set_gateway_id('pay_gateway');
        $token->set_user_id(22);
        $token->save();

        $token_id = (int) $token->get_id();
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order->method('get_customer_id')->willReturn(22);
        $renewal_order->method('get_payment_tokens')->willReturn(array());
        $renewal_order
            ->expects($this->once())
            ->method('update_meta_data')
            ->with('_payment_token_id', $token_id);
        $renewal_order
            ->expects($this->once())
            ->method('add_payment_token')
            ->with($token);

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->once())
            ->method('get_payment_token_for_subscription')
            ->with($subscription)
            ->willReturn($token);
        $retry_manager = $this->createMock(WSZ_Retry_Manager::class);

        $engine = new WSZ_Renewal_Engine($subscription_manager, $payment_handler, $retry_manager);
        $method = new ReflectionMethod(WSZ_Renewal_Engine::class, 'copy_resolved_payment_token_to_renewal');
        $method->setAccessible(true);

        $method->invoke($engine, $subscription, $renewal_order);
    }

    private function create_test_line_item()
    {
        return new class {
            private int $id = 1;

            public function set_id($id): void
            {
                $this->id = (int) $id;
            }
        };
    }

    private function create_context_order(int $id, array $items, array $context = array()): WC_Order
    {
        return new class($id, $items, $context) extends WC_Order {
            private int $id;
            private array $items;
            private array $meta;
            private int $customer_id;
            private string $payment_method;
            private string $payment_method_title;
            private string $currency;
            private float $total;
            private array $billing;
            private array $shipping;

            public function __construct(int $id, array $items, array $context)
            {
                $this->id = $id;
                $this->items = $items;
                $this->meta = $context['meta'] ?? array();
                $this->customer_id = (int) ($context['customer_id'] ?? 0);
                $this->payment_method = (string) ($context['payment_method'] ?? '');
                $this->payment_method_title = (string) ($context['payment_method_title'] ?? '');
                $this->currency = (string) ($context['currency'] ?? '');
                $this->total = (float) ($context['total'] ?? 0);
                $this->billing = $context['billing'] ?? array();
                $this->shipping = $context['shipping'] ?? array();
            }

            public function get_id()
            {
                return $this->id;
            }

            public function get_customer_id()
            {
                return $this->customer_id;
            }

            public function set_customer_id($customer_id)
            {
                $this->customer_id = (int) $customer_id;
            }

            public function get_payment_method()
            {
                return $this->payment_method;
            }

            public function set_payment_method($gateway_id)
            {
                $this->payment_method = (string) $gateway_id;
            }

            public function get_payment_method_title()
            {
                return $this->payment_method_title;
            }

            public function set_payment_method_title($title)
            {
                $this->payment_method_title = (string) $title;
            }

            public function get_currency()
            {
                return $this->currency;
            }

            public function set_currency($currency)
            {
                $this->currency = (string) $currency;
            }

            public function get_total()
            {
                return $this->total;
            }

            public function set_total($total)
            {
                $this->total = (float) $total;
            }

            public function get_items($types = array())
            {
                $types = (array) $types;

                if (empty($types) || in_array('line_item', $types, true)) {
                    return $this->items;
                }

                return array();
            }

            public function add_item($item)
            {
                $this->items[] = $item;
            }

            public function calculate_totals($and_taxes = true)
            {
            }

            public function save()
            {
            }

            public function get_meta($key, $single = true)
            {
                return $this->meta[$key] ?? '';
            }

            public function get_meta_data()
            {
                return array();
            }

            public function update_meta_data($key, $value)
            {
                $this->meta[$key] = $value;
            }

            public function get_billing_first_name()
            {
                return (string) ($this->billing['first_name'] ?? '');
            }

            public function set_billing_first_name($value)
            {
                $this->billing['first_name'] = (string) $value;
            }

            public function get_billing_last_name()
            {
                return (string) ($this->billing['last_name'] ?? '');
            }

            public function set_billing_last_name($value)
            {
                $this->billing['last_name'] = (string) $value;
            }

            public function get_billing_company()
            {
                return (string) ($this->billing['company'] ?? '');
            }

            public function set_billing_company($value)
            {
                $this->billing['company'] = (string) $value;
            }

            public function get_billing_address_1()
            {
                return (string) ($this->billing['address_1'] ?? '');
            }

            public function set_billing_address_1($value)
            {
                $this->billing['address_1'] = (string) $value;
            }

            public function get_billing_address_2()
            {
                return (string) ($this->billing['address_2'] ?? '');
            }

            public function set_billing_address_2($value)
            {
                $this->billing['address_2'] = (string) $value;
            }

            public function get_billing_city()
            {
                return (string) ($this->billing['city'] ?? '');
            }

            public function set_billing_city($value)
            {
                $this->billing['city'] = (string) $value;
            }

            public function get_billing_postcode()
            {
                return (string) ($this->billing['postcode'] ?? '');
            }

            public function set_billing_postcode($value)
            {
                $this->billing['postcode'] = (string) $value;
            }

            public function get_billing_country()
            {
                return (string) ($this->billing['country'] ?? '');
            }

            public function set_billing_country($value)
            {
                $this->billing['country'] = (string) $value;
            }

            public function get_billing_state()
            {
                return (string) ($this->billing['state'] ?? '');
            }

            public function set_billing_state($value)
            {
                $this->billing['state'] = (string) $value;
            }

            public function get_billing_email()
            {
                return (string) ($this->billing['email'] ?? '');
            }

            public function set_billing_email($value)
            {
                $this->billing['email'] = (string) $value;
            }

            public function get_billing_phone()
            {
                return (string) ($this->billing['phone'] ?? '');
            }

            public function set_billing_phone($value)
            {
                $this->billing['phone'] = (string) $value;
            }

            public function get_shipping_first_name()
            {
                return (string) ($this->shipping['first_name'] ?? '');
            }

            public function set_shipping_first_name($value)
            {
                $this->shipping['first_name'] = (string) $value;
            }

            public function get_shipping_last_name()
            {
                return (string) ($this->shipping['last_name'] ?? '');
            }

            public function set_shipping_last_name($value)
            {
                $this->shipping['last_name'] = (string) $value;
            }

            public function get_shipping_company()
            {
                return (string) ($this->shipping['company'] ?? '');
            }

            public function set_shipping_company($value)
            {
                $this->shipping['company'] = (string) $value;
            }

            public function get_shipping_address_1()
            {
                return (string) ($this->shipping['address_1'] ?? '');
            }

            public function set_shipping_address_1($value)
            {
                $this->shipping['address_1'] = (string) $value;
            }

            public function get_shipping_address_2()
            {
                return (string) ($this->shipping['address_2'] ?? '');
            }

            public function set_shipping_address_2($value)
            {
                $this->shipping['address_2'] = (string) $value;
            }

            public function get_shipping_city()
            {
                return (string) ($this->shipping['city'] ?? '');
            }

            public function set_shipping_city($value)
            {
                $this->shipping['city'] = (string) $value;
            }

            public function get_shipping_postcode()
            {
                return (string) ($this->shipping['postcode'] ?? '');
            }

            public function set_shipping_postcode($value)
            {
                $this->shipping['postcode'] = (string) $value;
            }

            public function get_shipping_country()
            {
                return (string) ($this->shipping['country'] ?? '');
            }

            public function set_shipping_country($value)
            {
                $this->shipping['country'] = (string) $value;
            }

            public function get_shipping_state()
            {
                return (string) ($this->shipping['state'] ?? '');
            }

            public function set_shipping_state($value)
            {
                $this->shipping['state'] = (string) $value;
            }
        };
    }

}
