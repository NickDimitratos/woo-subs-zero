<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';

final class PaymentMethodRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_wc_test_container'] = new PaymentMethodRecoveryWooContainer(
            new PaymentMethodRecoveryGatewayLoader(
                array(
                    'tokenized_gateway' => (object) array('enabled' => 'yes'),
                    'stripe' => (object) array('enabled' => 'yes'),
                ),
                array()
            )
        );
    }

    public function test_failing_payment_method_update_refreshes_token_and_gateway_context(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 123);

        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with('tokenized_gateway');

        $subscription
            ->expects($this->once())
            ->method('save');

        $handler->handle_failing_payment_method_update(
            $subscription,
            'tokenized_gateway',
            array('token_id' => 123)
        );
    }

    public function test_failing_card_payment_method_update_parses_woocommerce_post_meta_token(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 456);

        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with('stripe');

        $subscription
            ->expects($this->once())
            ->method('save');

        $handler->handle_failing_payment_method_update(
            $subscription,
            'stripe',
            array(
                'post_meta' => array(
                    array(
                        'meta_key' => '_payment_token_id',
                        'meta_value' => '456',
                    ),
                ),
            )
        );
    }
}

final class PaymentMethodRecoveryWooContainer
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

final class PaymentMethodRecoveryGatewayLoader
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
