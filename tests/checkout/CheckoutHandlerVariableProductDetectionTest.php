<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product
    {
    }
}

if (!function_exists('wc_get_product')) {
    function wc_get_product($product_id)
    {
        $product_id = (int) $product_id;

        return $GLOBALS['wsz_checkout_test_products'][$product_id] ?? null;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-checkout-handler.php';

final class CheckoutHandlerVariableProductDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_checkout_test_products'] = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_checkout_test_products']);

        parent::tearDown();
    }

    public static function subscriptionScenarioMatrixProvider(): array
    {
        return array(
            'length_1_without_start_date' => array(1, '', false),
            'length_4_without_start_date' => array(4, '', false),
            'length_1_with_start_date' => array(1, '2099-05-01', true),
            'length_4_with_start_date' => array(4, '2099-05-01', true),
        );
    }

    /**
     * @dataProvider subscriptionScenarioMatrixProvider
     */
    public function test_subscription_creation_matrix_for_length_and_start_date(
        int $subscription_length,
        string $requested_start_date,
        bool $expects_deferred_activation
    ): void
    {
        $expected_start_timestamp = '' !== $requested_start_date
            ? strtotime($requested_start_date . ' 00:00:00 UTC')
            : 0;

        $next_payment = ($expected_start_timestamp > 0 ? $expected_start_timestamp : current_time('timestamp', true)) + 60;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        if ($expected_start_timestamp > 0) {
            $subscription_manager
                ->expects($this->once())
                ->method('calculate_next_payment_for_profile')
                ->with($expected_start_timestamp, 1, 'month')
                ->willReturn($next_payment);
        } else {
            $subscription_manager
                ->expects($this->once())
                ->method('calculate_next_payment_for_profile')
                ->with($this->greaterThan(0), 1, 'month')
                ->willReturn($next_payment);
        }

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_id')
            ->willReturn(300 + $subscription_length);
        if ($expects_deferred_activation) {
            $subscription
                ->expects($this->atLeastOnce())
                ->method('update_meta_data');
        } else {
            $subscription
                ->expects($this->never())
                ->method('update_meta_data');
        }
        $subscription
            ->expects($this->atLeastOnce())
            ->method('save');
        $subscription
            ->expects($this->atLeastOnce())
            ->method('add_order_note');

        $subscription_manager
            ->expects($this->once())
            ->method('create_subscription_from_order')
            ->with(
                $this->isInstanceOf(CheckoutHandlerDummyOrder::class),
                $this->callback(
                    static function (array $args) use ($subscription_length, $expected_start_timestamp): bool {
                        $start_timestamp = (int) ($args['start_timestamp'] ?? 0);

                        if ($expected_start_timestamp > 0 && $start_timestamp !== $expected_start_timestamp) {
                            return false;
                        }

                        if ($expected_start_timestamp <= 0 && $start_timestamp <= 0) {
                            return false;
                        }

                        return (int) ($args['subscription_length'] ?? 0) === $subscription_length
                            && (int) ($args['billing_interval'] ?? 0) === 1
                            && (string) ($args['billing_period'] ?? '') === 'month'
                            && (int) ($args['next_payment'] ?? 0) > 0;
                    }
                )
            )
            ->willReturn($subscription);

        if ($expects_deferred_activation) {
            $subscription_manager
                ->expects($this->once())
                ->method('schedule_deferred_activation')
                ->with(300 + $subscription_length, $expected_start_timestamp);
            $subscription_manager
                ->expects($this->never())
                ->method('activate_subscription_after_payment');
        } else {
            $subscription_manager
                ->expects($this->never())
                ->method('schedule_deferred_activation');
            $subscription_manager
                ->expects($this->once())
                ->method('activate_subscription_after_payment')
                ->with(
                    $subscription,
                    $this->stringContains('Initial payment already captured during checkout.')
                );
        }

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            940 + $subscription_length,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $item_meta = array('_wsz_subscription_length' => (string) $subscription_length);

        if ('' !== $requested_start_date) {
            $item_meta['_wsz_requested_start_date'] = $requested_start_date;
        }

        $order = new CheckoutHandlerDummyOrder(
            array(
                new CheckoutHandlerDummyOrderItemProduct($product, $item_meta),
            ),
            array(),
            'shop_order',
            'processing'
        );

        $handler->maybe_create_subscriptions_from_order($order);
    }

    public function test_contains_subscription_items_detects_variation_using_parent_subscription_type(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $parent_product = new CheckoutHandlerDummyProduct(
            900,
            'wsz_variable_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $variation_product = new CheckoutHandlerDummyProduct(
            901,
            'variation',
            array(),
            900
        );

        $GLOBALS['wsz_checkout_test_products'][900] = $parent_product;

        $order = new CheckoutHandlerDummyOrder(
            array(new CheckoutHandlerDummyOrderItemProduct($variation_product))
        );

        $method = new ReflectionMethod(WSZ_Checkout_Handler::class, 'contains_subscription_items');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($handler, $order));
    }

    public function test_resolve_billing_profile_uses_parent_subscription_meta_for_variation_item(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->method('calculate_next_payment_for_profile')
            ->willReturn(1234567890);

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $parent_product = new CheckoutHandlerDummyProduct(
            910,
            'wsz_variable_subscription',
            array(
                '_wsz_subscription_enabled' => 'yes',
                '_wsz_subscription_interval' => '2',
                '_wsz_subscription_period' => 'week',
                '_wsz_subscription_length' => '6',
                '_wsz_sync_day' => '5',
            )
        );

        $variation_product = new CheckoutHandlerDummyProduct(
            911,
            'variation',
            array(),
            910
        );

        $GLOBALS['wsz_checkout_test_products'][910] = $parent_product;

        $order = new CheckoutHandlerDummyOrder(
            array(new CheckoutHandlerDummyOrderItemProduct($variation_product))
        );

        $method = new ReflectionMethod(WSZ_Checkout_Handler::class, 'resolve_billing_profile');
        $method->setAccessible(true);

        $profile = $method->invoke($handler, $order);

        $this->assertSame(2, $profile['interval']);
        $this->assertSame('week', $profile['period']);
        $this->assertSame(5, $profile['sync_day']);
        $this->assertSame(6, $profile['length']);
        $this->assertSame(1234567890, $profile['next_payment']);
    }

    public function test_resolve_billing_profile_falls_back_to_variation_length_when_parent_missing_length(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->method('calculate_next_payment_for_profile')
            ->willReturn(1234567890);

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $parent_product = new CheckoutHandlerDummyProduct(
            920,
            'wsz_variable_subscription',
            array(
                '_wsz_subscription_enabled' => 'yes',
                '_wsz_subscription_interval' => '1',
                '_wsz_subscription_period' => 'month',
            )
        );

        $variation_product = new CheckoutHandlerDummyProduct(
            921,
            'variation',
            array(
                '_wsz_subscription_length' => '4',
            ),
            920
        );

        $GLOBALS['wsz_checkout_test_products'][920] = $parent_product;

        $order = new CheckoutHandlerDummyOrder(
            array(new CheckoutHandlerDummyOrderItemProduct($variation_product))
        );

        $method = new ReflectionMethod(WSZ_Checkout_Handler::class, 'resolve_billing_profile');
        $method->setAccessible(true);

        $profile = $method->invoke($handler, $order);

        $this->assertSame(4, $profile['length']);
    }

    public function test_paid_status_fallback_calls_subscription_creation_when_order_is_processing(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $handler = $this->getMockBuilder(WSZ_Checkout_Handler::class)
            ->setConstructorArgs(array($subscription_manager))
            ->onlyMethods(array('maybe_create_subscriptions_from_order'))
            ->getMock();

        $order = $this->createMock(WC_Order::class);
        $order
            ->method('get_status')
            ->willReturn('processing');

        $handler
            ->expects($this->once())
            ->method('maybe_create_subscriptions_from_order')
            ->with($order);

        $handler->maybe_create_subscriptions_for_paid_status($order, $order);
    }

    public function test_paid_status_fallback_skips_subscription_creation_for_unpaid_status(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $handler = $this->getMockBuilder(WSZ_Checkout_Handler::class)
            ->setConstructorArgs(array($subscription_manager))
            ->onlyMethods(array('maybe_create_subscriptions_from_order'))
            ->getMock();

        $order = $this->createMock(WC_Order::class);
        $order
            ->method('get_status')
            ->willReturn('pending');

        $handler
            ->expects($this->never())
            ->method('maybe_create_subscriptions_from_order');

        $handler->maybe_create_subscriptions_for_paid_status($order, $order);
    }

    public function test_paid_status_fallback_calls_subscription_creation_when_order_is_on_hold(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $handler = $this->getMockBuilder(WSZ_Checkout_Handler::class)
            ->setConstructorArgs(array($subscription_manager))
            ->onlyMethods(array('maybe_create_subscriptions_from_order'))
            ->getMock();

        $order = $this->createMock(WC_Order::class);
        $order
            ->method('get_status')
            ->willReturn('on-hold');

        $handler
            ->expects($this->once())
            ->method('maybe_create_subscriptions_from_order')
            ->with($order);

        $handler->maybe_create_subscriptions_for_paid_status($order, $order);
    }

    public function test_new_order_fallback_delegates_to_core_subscription_creation_path(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $handler = $this->getMockBuilder(WSZ_Checkout_Handler::class)
            ->setConstructorArgs(array($subscription_manager))
            ->onlyMethods(array('maybe_create_subscriptions_from_order'))
            ->getMock();

        $order = $this->createMock(WC_Order::class);

        $handler
            ->expects($this->once())
            ->method('maybe_create_subscriptions_from_order')
            ->with($order, array(), $order);

        $handler->maybe_create_subscriptions_from_new_order($order, $order);
    }

    public function test_stale_subscription_ids_do_not_block_recovery_creation(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $subscription_manager
            ->method('calculate_next_payment_for_profile')
            ->willReturn(1700000060);

        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(999)
            ->willReturn(null);

        $subscription_manager
            ->expects($this->once())
            ->method('create_subscription_from_order')
            ->willReturn(null);

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            920,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $order = new CheckoutHandlerDummyOrder(
            array(new CheckoutHandlerDummyOrderItemProduct($product)),
            array('_wsz_subscription_ids' => array(999))
        );

        $handler->maybe_create_subscriptions_from_order($order);

        $this->assertSame(array(), $order->get_meta('_wsz_subscription_ids', true));
    }

    public function test_subscription_creation_skips_non_shop_order_types(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $subscription_manager
            ->expects($this->never())
            ->method('create_subscription_from_order');

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $order = $this->createMock(WC_Order::class);
        $order
            ->method('get_type')
            ->willReturn('shop_subscription');

        $order
            ->expects($this->never())
            ->method('get_meta');

        $handler->maybe_create_subscriptions_from_order($order);
    }

    public function test_subscription_creation_skips_orders_already_linked_to_subscription(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(777)
            ->willReturn($this->createMock(WC_Order::class));

        $subscription_manager
            ->expects($this->never())
            ->method('create_subscription_from_order');

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            930,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $order = new CheckoutHandlerDummyOrder(
            array(new CheckoutHandlerDummyOrderItemProduct($product)),
            array('_wsz_subscription_id' => 777)
        );

        $handler->maybe_create_subscriptions_from_order($order);
    }

    public function test_resolve_requested_start_timestamp_uses_order_item_date_meta(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            940,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $order = new CheckoutHandlerDummyOrder(
            array(
                new CheckoutHandlerDummyOrderItemProduct(
                    $product,
                    array('_wsz_requested_start_date' => '2030-05-01')
                ),
            )
        );

        $method = new ReflectionMethod(WSZ_Checkout_Handler::class, 'resolve_requested_start_date');
        $method->setAccessible(true);

        $resolved = (string) $method->invoke($handler, $order);

        $this->assertSame('2030-05-01', $resolved);
    }

    public function test_paid_order_with_future_start_date_schedules_deferred_activation(): void
    {
        $future_start = strtotime('2099-05-01 00:00:00 UTC');
        $future_next_payment = strtotime('2099-06-01 00:00:00 UTC');

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $subscription_manager
            ->expects($this->once())
            ->method('calculate_next_payment_for_profile')
            ->with($future_start, 1, 'month')
            ->willReturn($future_next_payment);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_id')
            ->willReturn(456);
        $subscription
            ->expects($this->atLeastOnce())
            ->method('update_meta_data');
        $subscription
            ->expects($this->atLeastOnce())
            ->method('save');
        $subscription
            ->expects($this->atLeastOnce())
            ->method('add_order_note');

        $subscription_manager
            ->expects($this->once())
            ->method('create_subscription_from_order')
            ->with(
                $this->isInstanceOf(CheckoutHandlerDummyOrder::class),
                $this->callback(
                    static function (array $args) use ($future_start, $future_next_payment): bool {
                        return (int) ($args['start_timestamp'] ?? 0) === $future_start
                            && (int) ($args['next_payment'] ?? 0) === $future_next_payment
                            && (int) ($args['billing_interval'] ?? 0) === 1
                            && (string) ($args['billing_period'] ?? '') === 'month';
                    }
                )
            )
            ->willReturn($subscription);

        $subscription_manager
            ->expects($this->once())
            ->method('schedule_deferred_activation')
            ->with(456, $future_start);

        $subscription_manager
            ->expects($this->never())
            ->method('activate_subscription_after_payment');

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            950,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $order = new CheckoutHandlerDummyOrder(
            array(
                new CheckoutHandlerDummyOrderItemProduct(
                    $product,
                    array('_wsz_requested_start_date' => '2099-05-01')
                ),
            ),
            array(),
            'shop_order',
            'processing'
        );

        $handler->maybe_create_subscriptions_from_order($order);
    }

    public function test_paid_order_without_selected_start_date_activates_immediately(): void
    {
        $now = current_time('timestamp', true);
        $next_payment = $now + 60;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $subscription_manager
            ->expects($this->once())
            ->method('calculate_next_payment_for_profile')
            ->with($this->greaterThan(0), 1, 'month')
            ->willReturn($next_payment);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_id')
            ->willReturn(789);
        $subscription
            ->expects($this->atLeastOnce())
            ->method('save');
        $subscription
            ->expects($this->atLeastOnce())
            ->method('add_order_note');

        $subscription_manager
            ->expects($this->once())
            ->method('create_subscription_from_order')
            ->with(
                $this->isInstanceOf(CheckoutHandlerDummyOrder::class),
                $this->callback(
                    static function (array $args): bool {
                        return (int) ($args['start_timestamp'] ?? 0) > 0
                            && (int) ($args['next_payment'] ?? 0) > 0
                            && (int) ($args['billing_interval'] ?? 0) === 1
                            && (string) ($args['billing_period'] ?? '') === 'month';
                    }
                )
            )
            ->willReturn($subscription);

        $subscription_manager
            ->expects($this->never())
            ->method('schedule_deferred_activation');

        $subscription_manager
            ->expects($this->once())
            ->method('activate_subscription_after_payment')
            ->with(
                $subscription,
                $this->stringContains('Initial payment already captured during checkout.')
            );

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            960,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $order = new CheckoutHandlerDummyOrder(
            array(
                new CheckoutHandlerDummyOrderItemProduct($product),
            ),
            array(),
            'shop_order',
            'processing'
        );

        $handler->maybe_create_subscriptions_from_order($order);
    }

    public function test_on_hold_order_without_selected_start_date_activates_immediately(): void
    {
        $now = current_time('timestamp', true);
        $next_payment = $now + 60;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $subscription_manager
            ->expects($this->once())
            ->method('calculate_next_payment_for_profile')
            ->with($this->greaterThan(0), 1, 'month')
            ->willReturn($next_payment);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_id')
            ->willReturn(790);
        $subscription
            ->expects($this->atLeastOnce())
            ->method('save');
        $subscription
            ->expects($this->atLeastOnce())
            ->method('add_order_note');

        $subscription_manager
            ->expects($this->once())
            ->method('create_subscription_from_order')
            ->willReturn($subscription);

        $subscription_manager
            ->expects($this->never())
            ->method('schedule_deferred_activation');

        $subscription_manager
            ->expects($this->never())
            ->method('activate_subscription_after_payment')
        ;

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            961,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $order = new CheckoutHandlerDummyOrder(
            array(
                new CheckoutHandlerDummyOrderItemProduct($product),
            ),
            array(),
            'shop_order',
            'on-hold'
        );

        $handler->maybe_create_subscriptions_from_order($order);
    }

    public function test_pending_order_without_selected_start_date_stays_pending(): void
    {
        $now = current_time('timestamp', true);
        $next_payment = $now + 60;

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $subscription_manager
            ->expects($this->once())
            ->method('calculate_next_payment_for_profile')
            ->with($this->greaterThan(0), 1, 'month')
            ->willReturn($next_payment);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_id')
            ->willReturn(791);
        $subscription
            ->expects($this->atLeastOnce())
            ->method('save');
        $subscription
            ->expects($this->atLeastOnce())
            ->method('add_order_note');

        $subscription_manager
            ->expects($this->once())
            ->method('create_subscription_from_order')
            ->willReturn($subscription);

        $subscription_manager
            ->expects($this->never())
            ->method('schedule_deferred_activation');

        $subscription_manager
            ->expects($this->never())
            ->method('activate_subscription_after_payment')
        ;

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            962,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $order = new CheckoutHandlerDummyOrder(
            array(
                new CheckoutHandlerDummyOrderItemProduct($product),
            ),
            array(),
            'shop_order',
            'pending'
        );

        $handler->maybe_create_subscriptions_from_order($order);
    }

    public function test_paid_status_recovery_activates_existing_subscription_ids(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $subscription_manager
            ->expects($this->once())
            ->method('maybe_activate_subscriptions_from_parent_order')
            ->with(
                997,
                '',
                'processing',
                $this->isInstanceOf(CheckoutHandlerDummyOrder::class)
            );

        $subscription_manager
            ->expects($this->never())
            ->method('create_subscription_from_order');

        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(555)
            ->willReturn($this->createMock(WC_Order::class));

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            970,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $order = new CheckoutHandlerDummyOrder(
            array(
                new CheckoutHandlerDummyOrderItemProduct($product),
            ),
            array('_wsz_subscription_ids' => array(555)),
            'shop_order',
            'processing',
            997
        );

        $handler->maybe_create_subscriptions_for_paid_status($order);
    }

    public function test_on_hold_status_recovery_does_not_activate_existing_subscription_ids(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);

        $subscription_manager
            ->expects($this->never())
            ->method('maybe_activate_subscriptions_from_parent_order')
        ;

        $subscription_manager
            ->expects($this->never())
            ->method('create_subscription_from_order');

        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(556)
            ->willReturn($this->createMock(WC_Order::class));

        $handler = new WSZ_Checkout_Handler($subscription_manager);

        $product = new CheckoutHandlerDummyProduct(
            971,
            'wsz_subscription',
            array('_wsz_subscription_enabled' => 'yes')
        );

        $order = new CheckoutHandlerDummyOrder(
            array(
                new CheckoutHandlerDummyOrderItemProduct($product),
            ),
            array('_wsz_subscription_ids' => array(556)),
            'shop_order',
            'on-hold',
            998
        );

        $handler->maybe_create_subscriptions_for_paid_status($order);
    }
}

final class CheckoutHandlerDummyProduct extends WC_Product
{
    private int $id;

    private string $type;

    private array $meta;

    private int $parent_id;

    public function __construct(int $id, string $type, array $meta = array(), int $parent_id = 0)
    {
        $this->id = $id;
        $this->type = $type;
        $this->meta = $meta;
        $this->parent_id = $parent_id;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_type()
    {
        return $this->type;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }

    public function get_parent_id()
    {
        return $this->parent_id;
    }
}

final class CheckoutHandlerDummyOrderItemProduct extends WC_Order_Item_Product
{
    private ?WC_Product $product;

    private array $meta;

    public function __construct(?WC_Product $product, array $meta = array())
    {
        $this->product = $product;
        $this->meta = $meta;
    }

    public function get_product()
    {
        return $this->product;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }
}

final class CheckoutHandlerDummyOrder extends WC_Order
{
    /** @var array<int,CheckoutHandlerDummyOrderItemProduct> */
    private array $items;

    private array $meta;

    private string $type;

    private string $status;

    private int $id;

    /**
     * @param array<int,CheckoutHandlerDummyOrderItemProduct> $items
     * @param array<string,mixed> $meta
     */
    public function __construct(
        array $items,
        array $meta = array(),
        string $type = 'shop_order',
        string $status = 'pending',
        int $id = 123
    )
    {
        $this->items = $items;
        $this->meta = $meta;
        $this->type = $type;
        $this->status = $status;
        $this->id = $id;
    }

    public function get_items($type = 'line_item')
    {
        return $this->items;
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

    public function get_type()
    {
        return $this->type;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function get_id()
    {
        return $this->id;
    }
}
