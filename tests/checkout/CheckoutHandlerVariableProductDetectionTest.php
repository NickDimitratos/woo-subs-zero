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
