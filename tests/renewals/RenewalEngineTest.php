<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '', $unique = false)
    {
        $return = $GLOBALS['wsz_test_schedule_return'] ?? 1;

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

if (!function_exists('wcs_create_renewal_order')) {
    function wcs_create_renewal_order($subscription)
    {
        return $GLOBALS['wsz_test_wcs_renewal_order'] ?? null;
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
        $GLOBALS['wsz_test_orders'] = array();
        $GLOBALS['wsz_test_card_orders'] = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_test_scheduled_actions']);
        unset($GLOBALS['wsz_test_schedule_return']);
        unset($GLOBALS['wsz_test_wcs_renewal_order']);
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
            ->expects($this->once())
            ->method('update_meta_data')
            ->with('_wsz_subscription_id', 901);
        $renewal_order
            ->expects($this->once())
            ->method('save');
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
}
