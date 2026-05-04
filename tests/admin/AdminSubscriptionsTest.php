<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public string $post_type = 'shop_subscription';
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return (string) $url;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability)
    {
        return true;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '')
    {
        $separator = false === strpos((string) $url, '?') ? '?' : '&';

        return (string) $url . $separator . http_build_query((array) $args);
    }
}

if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url($actionurl, $action = -1, $name = '_wpnonce')
    {
        return add_query_arg(array((string) $name => 'nonce-' . (string) $action), (string) $actionurl);
    }
}

if (!function_exists('wc_get_page_screen_id')) {
    function wc_get_page_screen_id($order_type)
    {
        return 'woocommerce_page_wc-orders--' . (string) $order_type;
    }
}

if (!function_exists('add_meta_box')) {
    function add_meta_box($id, $title, $callback, $screen, $context = 'advanced', $priority = 'default', $callback_args = null)
    {
        $GLOBALS['wsz_admin_test_meta_boxes'][] = array(
            'id' => $id,
            'screen' => $screen,
        );
    }
}

if (!function_exists('remove_meta_box')) {
    function remove_meta_box($id, $screen, $context)
    {
        $GLOBALS['wsz_admin_test_removed_meta_boxes'][] = array(
            'id' => (string) $id,
            'screen' => (string) $screen,
            'context' => (string) $context,
        );
    }
}

if (!function_exists('as_get_scheduled_actions')) {
    function as_get_scheduled_actions($query = array(), $return_format = 'OBJECT')
    {
        $actions = $GLOBALS['wsz_admin_test_actions'] ?? array();

        if (!is_array($actions)) {
            return array();
        }

        $offset = isset($query['offset']) ? max(0, (int) $query['offset']) : 0;
        $per_page = isset($query['per_page']) ? (int) $query['per_page'] : count($actions);

        if ($per_page <= 0) {
            $per_page = count($actions);
        }

        return array_slice($actions, $offset, $per_page);
    }
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '', $unique = false)
    {
        $return = $GLOBALS['wsz_admin_schedule_return'] ?? ($GLOBALS['wsz_test_schedule_return'] ?? 1);

        if (is_array($return)) {
            if (empty($return)) {
                $return = 0;
            } else {
                $next = array_shift($return);

                if (array_key_exists('wsz_admin_schedule_return', $GLOBALS)) {
                    $GLOBALS['wsz_admin_schedule_return'] = $return;
                } else {
                    $GLOBALS['wsz_test_schedule_return'] = $return;
                }

                $return = $next;
            }
        }

        if (!(is_numeric($return) ? ((int) $return > 0) : (true === $return))) {
            return $return;
        }

        $scheduled_action = array(
            'timestamp' => (int) $timestamp,
            'hook' => (string) $hook,
            'args' => $args,
            'group' => (string) $group,
            'unique' => (bool) $unique,
        );

        $GLOBALS['wsz_admin_test_scheduled'][] = $scheduled_action;

        // Shared fallback for other tests if this stub is loaded first.
        if (isset($GLOBALS['wsz_test_scheduled_actions']) && is_array($GLOBALS['wsz_test_scheduled_actions'])) {
            $GLOBALS['wsz_test_scheduled_actions'][] = $scheduled_action;
        }

        return $return;
    }
}

if (!function_exists('as_unschedule_action')) {
    function as_unschedule_action($hook, $args = array(), $group = '')
    {
        $GLOBALS['wsz_admin_test_unscheduled_actions'][] = array(
            'hook' => (string) $hook,
            'args' => $args,
            'group' => (string) $group,
        );

        return 1;
    }
}

if (!function_exists('get_option')) {
    function get_option($option_name, $default = false)
    {
        if ('wsz_subs_options' === $option_name && isset($GLOBALS['wsz_subs_test_options'])) {
            return $GLOBALS['wsz_subs_test_options'];
        }

        if ('wsz_subs_test_card_transactions' === $option_name && isset($GLOBALS['wsz_subs_test_card_transactions'])) {
            return $GLOBALS['wsz_subs_test_card_transactions'];
        }

        if (!isset($GLOBALS['wsz_admin_test_options']) || !is_array($GLOBALS['wsz_admin_test_options'])) {
            return $default;
        }

        if (!array_key_exists($option_name, $GLOBALS['wsz_admin_test_options'])) {
            return $default;
        }

        return $GLOBALS['wsz_admin_test_options'][$option_name];
    }
}

if (!function_exists('update_option')) {
    function update_option($option_name, $value, $autoload = null)
    {
        if ('wsz_subs_test_card_transactions' === $option_name) {
            $GLOBALS['wsz_subs_test_card_transactions'] = is_array($value) ? $value : array();
        }

        if (!isset($GLOBALS['wsz_admin_test_options']) || !is_array($GLOBALS['wsz_admin_test_options'])) {
            $GLOBALS['wsz_admin_test_options'] = array();
        }

        $GLOBALS['wsz_admin_test_options'][$option_name] = $value;

        return true;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-test-card-gateway.php';
require_once dirname(__DIR__, 2) . '/includes/admin/class-wsz-admin-subscriptions.php';

final class AdminSubscriptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_admin_test_actions'] = array();
        $GLOBALS['wsz_admin_test_meta_boxes'] = array();
        $GLOBALS['wsz_admin_test_removed_meta_boxes'] = array();
        $GLOBALS['wsz_subs_test_card_transactions'] = array();
        $GLOBALS['wsz_admin_test_scheduled'] = array();
        $GLOBALS['wsz_admin_test_unscheduled_actions'] = array();
        $GLOBALS['wsz_admin_test_options'] = array();
        $GLOBALS['wsz_admin_schedule_return'] = 1;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_admin_test_actions']);
        unset($GLOBALS['wsz_admin_test_meta_boxes']);
        unset($GLOBALS['wsz_admin_test_removed_meta_boxes']);
        unset($GLOBALS['wsz_subs_test_card_transactions']);
        unset($GLOBALS['wsz_admin_test_scheduled']);
        unset($GLOBALS['wsz_admin_test_unscheduled_actions']);
        unset($GLOBALS['wsz_admin_test_options']);
        unset($GLOBALS['wsz_admin_schedule_return']);

        parent::tearDown();
    }

    public function test_get_upcoming_renewals_filters_subscription_id_and_sorts_by_date(): void
    {
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $admin = new WSZ_Admin_Subscriptions($manager);

        $GLOBALS['wsz_admin_test_actions'] = array(
            array(
                'action_id' => 33,
                'scheduled_date_local' => '2026-04-27 10:02:00',
                'scheduled_date_gmt' => '2026-04-27 08:02:00',
                'status' => 'pending',
                'args' => array(
                    'subscription_id' => 44,
                    'schedule_key' => 'key-b',
                ),
            ),
            array(
                'action_id' => 31,
                'scheduled_date_local' => '2026-04-27 10:01:00',
                'scheduled_date_gmt' => '2026-04-27 08:01:00',
                'status' => 'pending',
                'args' => array(
                    'subscription_id' => 44,
                    'schedule_key' => 'key-a',
                ),
            ),
            array(
                'action_id' => 40,
                'scheduled_date_local' => '2026-04-27 10:03:00',
                'scheduled_date_gmt' => '2026-04-27 08:03:00',
                'status' => 'pending',
                'args' => array(
                    'subscription_id' => 999,
                    'schedule_key' => 'key-x',
                ),
            ),
        );

        $rows = $admin->get_upcoming_renewals(44, 10);

        $this->assertCount(2, $rows);
        $this->assertSame('key-a', $rows[0]['schedule_key']);
        $this->assertSame('key-b', $rows[1]['schedule_key']);
    }

    public function test_register_renewals_meta_box_targets_legacy_and_hpos_screens(): void
    {
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $admin = new WSZ_Admin_Subscriptions($manager);

        $admin->register_renewals_meta_box();

        $screens = array_values(array_unique(array_map(
            static function (array $box): string {
                return (string) ($box['screen'] ?? '');
            },
            $GLOBALS['wsz_admin_test_meta_boxes']
        )));

        $this->assertContains('shop_subscription', $screens);
        $this->assertContains('woocommerce_page_wc-orders--shop_subscription', $screens);

        $meta_box_ids = array_values(array_unique(array_map(
            static function (array $box): string {
                return (string) ($box['id'] ?? '');
            },
            $GLOBALS['wsz_admin_test_meta_boxes']
        )));

        $this->assertContains('wsz_subs_meta_keys', $meta_box_ids);
        $this->assertContains('wsz_subs_subscription_actions', $meta_box_ids);

        $removed_meta_box_ids = array_values(array_unique(array_map(
            static function (array $box): string {
                return (string) ($box['id'] ?? '');
            },
            $GLOBALS['wsz_admin_test_removed_meta_boxes']
        )));

        $this->assertContains('woocommerce-order-data', $removed_meta_box_ids);
        $this->assertContains('woocommerce-order-items', $removed_meta_box_ids);
    }

    public function test_render_upcoming_renewals_meta_box_displays_empty_message_when_no_actions(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(44);

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(44)->willReturn($subscription);
        $manager->method('get_next_payment_timestamp')->with($subscription)->willReturn(0);
        $manager->method('get_related_orders')->with($subscription, 'renewal')->willReturn(array());

        $admin = new WSZ_Admin_Subscriptions($manager);

        $post = new WP_Post();
        $post->ID = 44;

        ob_start();
        $admin->render_upcoming_renewals_meta_box($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('No upcoming renewal actions are currently scheduled.', $output);
    }

    public function test_render_subscription_actions_meta_box_displays_only_valid_active_transitions(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(44);
        $subscription->method('get_status')->willReturn('active');

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(44)->willReturn($subscription);

        $admin = new WSZ_Admin_Subscriptions($manager);

        $post = new WP_Post();
        $post->ID = 44;

        ob_start();
        $admin->render_subscription_actions_meta_box($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Current status:', $output);
        $this->assertStringContainsString('Suspend subscription', $output);
        $this->assertStringContainsString('Cancel at period end', $output);
        $this->assertStringContainsString('Cancel now', $output);
        $this->assertStringContainsString('Expire subscription', $output);
        $this->assertStringNotContainsString('Reactivate subscription', $output);
    }

    public function test_render_subscription_actions_meta_box_marks_terminal_status_read_only(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(45);
        $subscription->method('get_status')->willReturn('cancelled');

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(45)->willReturn($subscription);

        $admin = new WSZ_Admin_Subscriptions($manager);

        $post = new WP_Post();
        $post->ID = 45;

        ob_start();
        $admin->render_subscription_actions_meta_box($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('No manual lifecycle actions are available for this status.', $output);
        $this->assertStringContainsString('terminal states', $output);
        $this->assertStringNotContainsString('button button-secondary', $output);
    }

    public function test_change_subscription_status_allows_valid_transition_through_manager(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_status')->willReturn('active');

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(44)->willReturn($subscription);
        $manager
            ->expects($this->once())
            ->method('transition_status')
            ->with(
                $subscription,
                'on-hold',
                $this->stringContains('Manual admin lifecycle action')
            )
            ->willReturn(true);

        $admin = new WSZ_Admin_Subscriptions($manager);

        $this->assertSame('changed', $admin->change_subscription_status(44, 'on-hold'));
    }

    public function test_change_subscription_status_rejects_invalid_transition(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_status')->willReturn('active');

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(44)->willReturn($subscription);
        $manager
            ->expects($this->never())
            ->method('transition_status');

        $admin = new WSZ_Admin_Subscriptions($manager);

        $this->assertSame('invalid', $admin->change_subscription_status(44, 'pending'));
    }

    public function test_filter_subscription_list_columns_returns_informational_shape(): void
    {
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $admin = new WSZ_Admin_Subscriptions($manager);

        $columns = $admin->filter_subscription_list_columns(
            array(
                'cb' => 'cb',
                'order_number' => 'Order',
                'order_status' => 'Status',
                'billing_address' => 'Billing',
                'order_total' => 'Total',
                'order_date' => 'Date',
                'wc_actions' => 'Actions',
            )
        );

        $this->assertArrayHasKey('order_number', $columns);
        $this->assertArrayHasKey('wsz_next_renewal', $columns);
        $this->assertArrayHasKey('wsz_renewal_orders', $columns);
        $this->assertArrayHasKey('wsz_last_test_card_tx', $columns);
        $this->assertArrayNotHasKey('billing_address', $columns);
        $this->assertArrayNotHasKey('order_total', $columns);
    }

    public function test_render_test_card_transactions_meta_box_displays_logged_transaction(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(44);

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(44)->willReturn($subscription);

        $admin = new WSZ_Admin_Subscriptions($manager);

        $GLOBALS['wsz_subs_test_card_transactions'] = array(
            array(
                'recorded_at_local' => '2026-04-27 16:00:00',
                'recorded_at_gmt' => '2026-04-27 14:00:00',
                'context' => 'renewal',
                'subscription_id' => 44,
                'order_id' => 555,
                'amount' => 25.0,
                'currency' => 'EUR',
                'status' => 'completed',
                'transaction_id' => 'wsz_test_card_renewal_x',
            ),
        );

        $post = new WP_Post();
        $post->ID = 44;

        ob_start();
        $admin->render_test_card_transactions_meta_box($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('wsz_test_card_renewal_x', $output);
        $this->assertStringContainsString('renewal', $output);
        $this->assertStringContainsString('555', $output);
    }

    public function test_render_subscription_meta_keys_meta_box_displays_subscription_meta_rows(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(44);
        $subscription->method('get_status')->willReturn('active');
        $subscription
            ->method('get_meta')
            ->willReturnMap(
                array(
                    array('_wsz_parent_order_id', true, 1001),
                    array('_wsz_start_date', true, '2026-04-28 10:00:00'),
                    array('_wsz_next_payment', true, '2026-04-28 10:01:00'),
                    array('_wsz_end_date', true, '2026-04-28 10:04:00'),
                    array('_wsz_billing_interval', true, 1),
                    array('_wsz_billing_period', true, 'month'),
                    array('_wsz_subscription_length', true, 4),
                    array('_requires_manual_renewal', true, 'no'),
                    array('_payment_token_id', true, 321),
                    array('_wsz_next_schedule_key', true, 'next-key'),
                    array('_wsz_last_processed_schedule_key', true, 'last-key'),
                    array('_wsz_related_order_ids', true, '{"renewal":[1,2]}'),
                )
            );

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(44)->willReturn($subscription);

        $admin = new WSZ_Admin_Subscriptions($manager);

        $post = new WP_Post();
        $post->ID = 44;

        ob_start();
        $admin->render_subscription_meta_keys_meta_box($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('_wsz_subscription_length', $output);
        $this->assertStringContainsString('2026-04-28 10:01:00', $output);
        $this->assertStringContainsString('Subscription Status:', $output);
        $this->assertStringContainsString('active', $output);
        $this->assertStringContainsString('Subscription profile values only (meta keys)', $output);
    }

    public function test_render_upcoming_renewals_meta_box_auto_recovers_due_active_subscription(): void
    {
        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(44);
        $subscription->method('get_status')->willReturn('active');
        $subscription
            ->expects($this->once())
            ->method('update_meta_data')
            ->with('_wsz_next_schedule_key', $this->isType('string'));
        $subscription
            ->expects($this->once())
            ->method('save');

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(44)->willReturn($subscription);
        $manager->method('get_next_payment_timestamp')->with($subscription)->willReturn(current_time('timestamp', true) - 60);
        $manager->method('get_related_orders')->with($subscription, 'renewal')->willReturn(array());

        $admin = new WSZ_Admin_Subscriptions($manager);

        $post = new WP_Post();
        $post->ID = 44;

        ob_start();
        $admin->render_upcoming_renewals_meta_box($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Recovery: queued an immediate renewal action', $output);
        $this->assertCount(1, $GLOBALS['wsz_admin_test_scheduled']);
        $this->assertSame('wsz_subs_process_renewal', $GLOBALS['wsz_admin_test_scheduled'][0]['hook']);
        $this->assertSame('wsz-subscriptions', $GLOBALS['wsz_admin_test_scheduled'][0]['group']);
    }

    public function test_render_upcoming_renewals_meta_box_does_not_report_recovery_when_scheduler_returns_zero(): void
    {
        $GLOBALS['wsz_admin_schedule_return'] = 0;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(44);
        $subscription->method('get_status')->willReturn('active');
        $subscription
            ->expects($this->never())
            ->method('update_meta_data');
        $subscription
            ->expects($this->never())
            ->method('save');

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(44)->willReturn($subscription);
        $manager->method('get_next_payment_timestamp')->with($subscription)->willReturn(current_time('timestamp', true) - 60);
        $manager->method('get_related_orders')->with($subscription, 'renewal')->willReturn(array());

        $admin = new WSZ_Admin_Subscriptions($manager);

        $post = new WP_Post();
        $post->ID = 44;

        ob_start();
        $admin->render_upcoming_renewals_meta_box($post);
        $output = (string) ob_get_clean();

        $this->assertStringNotContainsString('Recovery: queued an immediate renewal action', $output);
        $this->assertCount(0, $GLOBALS['wsz_admin_test_scheduled']);
        $this->assertStringContainsString('Renewal is due/overdue by', $output);
    }

    public function test_render_upcoming_renewals_meta_box_skips_recovery_when_term_boundary_is_reached(): void
    {
        $now = current_time('timestamp', true);

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(44);
        $subscription->method('get_status')->willReturn('active');
        $subscription
            ->expects($this->never())
            ->method('update_meta_data');
        $subscription
            ->expects($this->never())
            ->method('save');

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->with(44)->willReturn($subscription);
        $manager->method('get_next_payment_timestamp')->with($subscription)->willReturn($now - 60);
        $manager->method('get_related_orders')->with($subscription, 'renewal')->willReturn(array());
        $manager->method('get_end_timestamp')->with($subscription)->willReturn($now - 1);
        $manager
            ->expects($this->once())
            ->method('process_expiration')
            ->with(44);
        $manager
            ->expects($this->never())
            ->method('schedule_expiration');

        $admin = new WSZ_Admin_Subscriptions($manager);

        $post = new WP_Post();
        $post->ID = 44;

        ob_start();
        $admin->render_upcoming_renewals_meta_box($post);
        $output = (string) ob_get_clean();

        $this->assertCount(0, $GLOBALS['wsz_admin_test_scheduled']);
        $this->assertStringNotContainsString('Recovery: queued an immediate renewal action', $output);
    }

    public function test_run_finite_term_cleanup_unschedules_and_expires_ended_subscriptions(): void
    {
        $end_timestamp = current_time('timestamp', true) - 1;

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(44);
        $subscription
            ->expects($this->once())
            ->method('update_meta_data')
            ->with('_wsz_next_schedule_key', '');
        $subscription
            ->expects($this->once())
            ->method('save');

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(44)
            ->willReturn($subscription);
        $manager
            ->expects($this->once())
            ->method('get_end_timestamp')
            ->with($subscription)
            ->willReturn($end_timestamp);
        $manager
            ->expects($this->once())
            ->method('update_next_payment_timestamp')
            ->with($subscription, $end_timestamp);
        $manager
            ->expects($this->once())
            ->method('process_expiration')
            ->with(44);

        $admin = new WSZ_Admin_Subscriptions($manager);

        $GLOBALS['wsz_admin_test_actions'] = array(
            array(
                'action_id' => 501,
                'args' => array(
                    'subscription_id' => 44,
                    'schedule_key' => 'stale-key',
                ),
            ),
        );

        $report = $admin->run_finite_term_cleanup();

        $this->assertFalse((bool) ($report['already_completed'] ?? true));
        $this->assertSame(1, (int) ($report['scanned_actions'] ?? 0));
        $this->assertSame(1, (int) ($report['eligible_actions'] ?? 0));
        $this->assertSame(1, (int) ($report['unscheduled_actions'] ?? 0));
        $this->assertSame(1, (int) ($report['expired_subscriptions'] ?? 0));
        $this->assertCount(1, $GLOBALS['wsz_admin_test_unscheduled_actions']);
        $this->assertGreaterThan(0, (int) ($GLOBALS['wsz_admin_test_options']['wsz_subs_finite_term_cleanup_completed_at'] ?? 0));
    }

    public function test_run_finite_term_cleanup_is_one_time_without_force(): void
    {
        $GLOBALS['wsz_admin_test_options']['wsz_subs_finite_term_cleanup_completed_at'] = 1700000000;

        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager
            ->expects($this->never())
            ->method('get_subscription');

        $admin = new WSZ_Admin_Subscriptions($manager);

        $report = $admin->run_finite_term_cleanup();

        $this->assertTrue((bool) ($report['already_completed'] ?? false));
        $this->assertSame(1700000000, (int) ($report['completed_at'] ?? 0));
        $this->assertSame(0, (int) ($report['scanned_actions'] ?? -1));
        $this->assertCount(0, $GLOBALS['wsz_admin_test_unscheduled_actions']);
    }
}
