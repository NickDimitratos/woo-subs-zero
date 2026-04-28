<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';

final class WooCommercePaymentSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['km_wc_test_container'] = null;
    }

    public function test_gateway_available_when_registered_and_enabled(): void
    {
        $this->setGatewayContext(
            array(
                'stripe' => (object) array('enabled' => 'yes'),
            ),
            array()
        );

        $handler = new WSZ_Payment_Handler($this->createMock(WSZ_Subscription_Manager::class));

        $this->assertTrue($handler->is_gateway_available('stripe'));
    }

    public function test_gateway_unavailable_when_registered_but_disabled(): void
    {
        $this->setGatewayContext(
            array(
                'stripe' => (object) array('enabled' => 'no'),
            ),
            array(
                'stripe' => (object) array(),
            )
        );

        $handler = new WSZ_Payment_Handler($this->createMock(WSZ_Subscription_Manager::class));

        $this->assertFalse($handler->is_gateway_available('stripe'));
    }

    public function test_update_payment_context_ignores_unknown_non_woocommerce_gateway(): void
    {
        $this->setGatewayContext(
            array(
                'stripe' => (object) array('enabled' => 'yes'),
            ),
            array()
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);

        $subscription
            ->expects($this->never())
            ->method('set_payment_method');

        $subscription
            ->expects($this->never())
            ->method('save');

        $handler->update_subscription_payment_context($subscription, 0, 'custom_gateway');
    }

    public function test_update_payment_context_accepts_registered_woocommerce_gateway(): void
    {
        $this->setGatewayContext(
            array(
                'stripe' => (object) array('enabled' => 'yes'),
            ),
            array()
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);

        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with('stripe');

        $subscription
            ->expects($this->once())
            ->method('save');

        $handler->update_subscription_payment_context($subscription, 0, 'stripe');
    }

    private function setGatewayContext(array $registered, array $available): void
    {
        $GLOBALS['km_wc_test_container'] = new WooGatewayTestContainer(
            new WooGatewayTestLoader($registered, $available)
        );
    }
}

final class WooGatewayTestContainer
{
    public $payment_gateways = true;

    private $gateway_loader;

    public function __construct($gateway_loader)
    {
        $this->gateway_loader = $gateway_loader;
    }

    public function payment_gateways()
    {
        return $this->gateway_loader;
    }
}

final class WooGatewayTestLoader
{
    private $registered;

    private $available;

    public function __construct(array $registered, array $available)
    {
        $this->registered = $registered;
        $this->available = $available;
    }

    public function payment_gateways(): array
    {
        return $this->registered;
    }

    public function get_available_payment_gateways(): array
    {
        return $this->available;
    }
}
