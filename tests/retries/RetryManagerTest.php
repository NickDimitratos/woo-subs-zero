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

if (!function_exists('get_option')) {
    function get_option($option_name, $default = false)
    {
        if ('wsz_subs_options' === $option_name) {
            if (isset($GLOBALS['wsz_subs_test_options'])) {
                return $GLOBALS['wsz_subs_test_options'];
            }

            return $GLOBALS['wsz_retry_test_options'] ?? $default;
        }

        if (isset($GLOBALS['wsz_admin_test_options']) && is_array($GLOBALS['wsz_admin_test_options']) && array_key_exists($option_name, $GLOBALS['wsz_admin_test_options'])) {
            return $GLOBALS['wsz_admin_test_options'][$option_name];
        }

        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option_name, $value, $autoload = null)
    {
        if (!isset($GLOBALS['wsz_admin_test_options']) || !is_array($GLOBALS['wsz_admin_test_options'])) {
            $GLOBALS['wsz_admin_test_options'] = array();
        }

        $GLOBALS['wsz_admin_test_options'][$option_name] = $value;

        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '', $unique = false)
    {
        $action = array(
            'timestamp' => (int) $timestamp,
            'hook' => (string) $hook,
            'args' => $args,
            'group' => (string) $group,
            'unique' => (bool) $unique,
        );

        $GLOBALS['wsz_retry_test_scheduled_actions'][] = $action;
        $GLOBALS['wsz_test_scheduled_actions'][] = $action;

        return 1;
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id)
    {
        $order_id = (int) $order_id;

        if (isset($GLOBALS['wsz_retry_test_orders'][$order_id])) {
            return $GLOBALS['wsz_retry_test_orders'][$order_id];
        }

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

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/admin/class-wsz-admin-settings.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-retry-manager.php';

final class RetryManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_retry_test_options'] = array();
        $GLOBALS['wsz_subs_test_options'] = array();
        $GLOBALS['wsz_retry_test_scheduled_actions'] = array();
        $GLOBALS['wsz_test_scheduled_actions'] = array();
        $GLOBALS['wsz_retry_test_orders'] = array();
        $GLOBALS['wsz_test_orders'] = array();
        $GLOBALS['wsz_subs_test_orders'] = array();
        $GLOBALS['wsz_test_card_orders'] = array();
        $GLOBALS['wsz_admin_test_options'] = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_retry_test_options']);
        unset($GLOBALS['wsz_subs_test_options']);
        unset($GLOBALS['wsz_retry_test_scheduled_actions']);
        unset($GLOBALS['wsz_test_scheduled_actions']);
        unset($GLOBALS['wsz_retry_test_orders']);
        unset($GLOBALS['wsz_test_orders']);
        unset($GLOBALS['wsz_subs_test_orders']);
        unset($GLOBALS['wsz_test_card_orders']);
        unset($GLOBALS['wsz_admin_test_options']);

        parent::tearDown();
    }

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

    public function test_retry_profile_uses_test_cycle_minutes_when_test_mode_enabled(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_test_mode' => 'yes',
            'test_cycle_minutes' => 1,
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $retry_manager = new WSZ_Retry_Manager($subscription_manager, $payment_handler);
        $rules = $retry_manager->get_retry_rules();

        $this->assertCount(5, $rules);
        $this->assertSame(array_fill(0, 5, array('interval' => 60)), $rules);
    }

    public function test_queue_retry_returns_false_when_retries_are_disabled(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array('enable_retries' => 'no');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->never())
            ->method('transition_status');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $retry_manager = new WSZ_Retry_Manager($subscription_manager, $payment_handler);
        $subscription = new RetryManagerDummyOrder(10, 'active');
        $renewal_order = new RetryManagerDummyOrder(20, 'failed', array(), true);

        $this->assertFalse($retry_manager->queue_retry($subscription, $renewal_order, 'renewal_failed'));
        $this->assertSame(array(), $GLOBALS['wsz_retry_test_scheduled_actions']);
        $this->assertSame('', $renewal_order->get_meta('_wsz_retry_attempt', true));
        $this->assertSame('failed', $renewal_order->get_status());
    }

    public function test_queue_retry_records_first_attempt_and_schedules_action(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array('enable_retries' => 'yes');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('transition_status')
            ->with(
                $this->isInstanceOf(RetryManagerDummyOrder::class),
                'on-hold',
                'Renewal payment failed. Retry queued.'
            );

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $retry_manager = new WSZ_Retry_Manager($subscription_manager, $payment_handler);
        $subscription = new RetryManagerDummyOrder(10, 'active');
        $renewal_order = new RetryManagerDummyOrder(20, 'failed', array(), true);

        $queued_after = time();

        $this->assertTrue($retry_manager->queue_retry($subscription, $renewal_order, 'renewal_failed'));

        $records = $renewal_order->get_retry_records();
        $scheduled_actions = $this->getScheduledActions();
        $diagnostic_logs = $GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs'] ?? array();

        $this->assertSame(1, $renewal_order->get_meta('_wsz_retry_attempt', true));
        $this->assertSame('pending', $renewal_order->get_status());
        $this->assertSame('pending', $records[1]['status']);
        $this->assertSame('renewal_failed', $records[1]['reason']);
        $this->assertGreaterThanOrEqual($queued_after + 12 * HOUR_IN_SECONDS - 1, $records[1]['scheduled_at']);
        $this->assertCount(1, $scheduled_actions);
        $this->assertSame('wsz_subs_process_retry', $scheduled_actions[0]['hook']);
        $this->assertSame(
            array('subscription_id' => 10, 'order_id' => 20, 'attempt' => 1),
            $scheduled_actions[0]['args']
        );
        $this->assertSame(WSZ_Subscription_Manager::ACTION_GROUP, $scheduled_actions[0]['group']);
        $this->assertTrue($scheduled_actions[0]['unique']);
        $this->assertSame('Retry payment queued.', $diagnostic_logs[0]['message']);
        $this->assertSame('20', $diagnostic_logs[0]['context']['renewal_order_id']);
    }

    public function test_queue_retry_schedules_one_minute_attempt_and_logs_test_mode_context(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_retries' => 'yes',
            'enable_test_mode' => 'yes',
            'test_cycle_minutes' => 1,
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('transition_status');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $retry_manager = new WSZ_Retry_Manager($subscription_manager, $payment_handler);
        $subscription = new RetryManagerDummyOrder(10, 'active');
        $renewal_order = new RetryManagerDummyOrder(20, 'failed', array(), true);
        $queued_after = time();

        $this->assertTrue($retry_manager->queue_retry($subscription, $renewal_order, 'renewal_failed'));

        $records = $renewal_order->get_retry_records();
        $diagnostic_logs = $GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs'] ?? array();

        $this->assertGreaterThanOrEqual($queued_after + 59, $records[1]['scheduled_at']);
        $this->assertLessThanOrEqual($queued_after + 61, $records[1]['scheduled_at']);
        $this->assertSame('60', $diagnostic_logs[0]['context']['interval']);
        $this->assertSame('yes', $diagnostic_logs[0]['context']['test_mode']);
    }

    public function test_queue_retry_exhausts_after_last_retry_rule(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array('enable_retries' => 'yes');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->never())
            ->method('transition_status');

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $retry_manager = new WSZ_Retry_Manager($subscription_manager, $payment_handler);
        $subscription = new RetryManagerDummyOrder(10, 'on-hold');
        $renewal_order = new RetryManagerDummyOrder(
            20,
            'failed',
            array('_wsz_retry_attempt' => 5),
            true
        );

        $this->assertFalse($retry_manager->queue_retry($subscription, $renewal_order, 'retry_5_failed'));

        $records = $renewal_order->get_retry_records();

        $this->assertSame('failed', $renewal_order->get_status());
        $this->assertSame('failed', $records[5]['status']);
        $this->assertSame('retry_rules_exhausted', $records[5]['reason']);
        $this->assertSame(array(), $GLOBALS['wsz_retry_test_scheduled_actions']);
    }

    public function test_process_retry_marks_paid_order_complete_and_reactivates_subscription(): void
    {
        $subscription = new RetryManagerDummyOrder(10, 'on-hold');
        $renewal_order = new RetryManagerDummyOrder(
            20,
            'pending',
            array(
                '_wsz_retry_records' => wp_json_encode(
                    array(
                        1 => array('attempt' => 1, 'status' => 'pending'),
                    )
                ),
            ),
            true,
            true
        );
        $GLOBALS['wsz_retry_test_orders'][20] = $renewal_order;
        $GLOBALS['wsz_test_orders'][20] = $renewal_order;
        $GLOBALS['wsz_subs_test_orders'][20] = $renewal_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(10)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('acquire_lock')
            ->with('retry_20', 1, 300)
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('transition_status')
            ->with($subscription, 'active', 'Retry payment succeeded.');
        $subscription_manager
            ->expects($this->once())
            ->method('release_lock')
            ->with('retry_20', 1);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->once())
            ->method('dispatch_scheduled_payment')
            ->with($subscription, $renewal_order, 42.5);

        $retry_manager = new WSZ_Retry_Manager($subscription_manager, $payment_handler);
        $retry_manager->process_retry(10, 20, 1);

        $records = $renewal_order->get_retry_records();

        $this->assertSame('complete', $records[1]['status']);
        $this->assertSame('paid', $records[1]['reason']);
    }

    public function test_process_retry_cancels_ineligible_attempt_without_dispatching_payment(): void
    {
        $subscription = new RetryManagerDummyOrder(10, 'cancelled');
        $renewal_order = new RetryManagerDummyOrder(
            20,
            'pending',
            array(
                '_wsz_retry_records' => wp_json_encode(
                    array(
                        1 => array('attempt' => 1, 'status' => 'pending'),
                    )
                ),
            ),
            true
        );
        $GLOBALS['wsz_retry_test_orders'][20] = $renewal_order;
        $GLOBALS['wsz_test_orders'][20] = $renewal_order;
        $GLOBALS['wsz_subs_test_orders'][20] = $renewal_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(10)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('acquire_lock')
            ->with('retry_20', 1, 300)
            ->willReturn(true);
        $subscription_manager
            ->expects($this->never())
            ->method('transition_status');
        $subscription_manager
            ->expects($this->once())
            ->method('release_lock')
            ->with('retry_20', 1);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->never())
            ->method('dispatch_scheduled_payment');

        $retry_manager = new WSZ_Retry_Manager($subscription_manager, $payment_handler);
        $retry_manager->process_retry(10, 20, 1);

        $records = $renewal_order->get_retry_records();

        $this->assertSame('cancelled', $records[1]['status']);
        $this->assertSame('not_eligible', $records[1]['reason']);
    }

    public function test_process_retry_continues_when_failed_status_update_hooks_throw(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array('enable_retries' => 'yes');

        $subscription = new RetryManagerDummyOrder(10, 'on-hold');
        $renewal_order = new RetryManagerThrowingStatusOrder(
            20,
            'pending',
            array(
                '_wsz_retry_records' => wp_json_encode(
                    array(
                        1 => array('attempt' => 1, 'status' => 'pending'),
                    )
                ),
            ),
            true
        );
        $GLOBALS['wsz_retry_test_orders'][20] = $renewal_order;
        $GLOBALS['wsz_test_orders'][20] = $renewal_order;
        $GLOBALS['wsz_subs_test_orders'][20] = $renewal_order;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->method('get_subscription')
            ->with(10)
            ->willReturn($subscription);
        $subscription_manager
            ->method('acquire_lock')
            ->with('retry_20', 1, 300)
            ->willReturn(true);
        $subscription_manager
            ->expects($this->once())
            ->method('release_lock')
            ->with('retry_20', 1);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $payment_handler
            ->expects($this->once())
            ->method('dispatch_scheduled_payment')
            ->with($subscription, $renewal_order, 42.5);

        $retry_manager = new WSZ_Retry_Manager($subscription_manager, $payment_handler);
        $retry_manager->process_retry(10, 20, 1);

        $logs = $GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs'] ?? array();
        $messages = array_column($logs, 'message');

        $this->assertContains('Retry order status update failed.', $messages);
        $this->assertContains('Retry payment failed.', $messages);
        $this->assertNotContains('Retry payment processing failed.', $messages);
    }

    private function getScheduledActions(): array
    {
        if (!empty($GLOBALS['wsz_retry_test_scheduled_actions']) && is_array($GLOBALS['wsz_retry_test_scheduled_actions'])) {
            return $GLOBALS['wsz_retry_test_scheduled_actions'];
        }

        if (!empty($GLOBALS['wsz_test_scheduled_actions']) && is_array($GLOBALS['wsz_test_scheduled_actions'])) {
            return $GLOBALS['wsz_test_scheduled_actions'];
        }

        return array();
    }
}

class RetryManagerDummyOrder extends WC_Order
{
    private int $id;

    private string $status;

    private array $meta;

    private bool $needs_payment;

    private bool $paid;

    public function __construct(
        int $id,
        string $status,
        array $meta = array(),
        bool $needs_payment = false,
        bool $paid = false
    ) {
        $this->id = $id;
        $this->status = $status;
        $this->meta = $meta;
        $this->needs_payment = $needs_payment;
        $this->paid = $paid;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function update_status($status, $note = '', $manual = false)
    {
        $this->status = (string) $status;
    }

    public function has_status($status)
    {
        $statuses = is_array($status) ? $status : array($status);

        return in_array($this->status, $statuses, true);
    }

    public function needs_payment()
    {
        return $this->needs_payment;
    }

    public function is_paid()
    {
        return $this->paid;
    }

    public function get_total()
    {
        return 42.5;
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

    public function get_billing_email()
    {
        return 'customer@example.test';
    }

    public function get_retry_records(): array
    {
        $records = json_decode((string) $this->get_meta('_wsz_retry_records', true), true);

        return is_array($records) ? $records : array();
    }
}

final class RetryManagerThrowingStatusOrder extends RetryManagerDummyOrder
{
    public function update_status($status, $note = '', $manual = false)
    {
        if ('failed' === $status) {
            throw new ValueError('Unknown format specifier ","');
        }

        parent::update_status($status, $note, $manual);
    }
}
