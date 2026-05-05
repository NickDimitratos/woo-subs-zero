<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-payment-token.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';

if (!class_exists('PPMFWC_Helper_Transaction')) {
    class PPMFWC_Helper_Transaction
    {
        public static function getTransaction(string $transactionId): mixed
        {
            return $GLOBALS['wsz_paynl_test_transactions'][$transactionId] ?? false;
        }
    }
}

final class PayNLGatewayIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_subs_test_orders'] = array();
        $GLOBALS['wsz_subs_test_order_queries'] = array();
        $GLOBALS['wsz_paynl_test_transactions'] = array();
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_paynl_tokens' => 'yes',
        );

        if (is_callable(array('WC_Payment_Tokens', 'reset_test_tokens'))) {
            WC_Payment_Tokens::reset_test_tokens();
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_subs_test_orders']);
        unset($GLOBALS['wsz_subs_test_order_queries']);
        unset($GLOBALS['wsz_paynl_test_transactions']);
        unset($GLOBALS['wsz_subs_test_options']);

        parent::tearDown();
    }

    public function test_register_gateway_ids_adds_creditcards_grouped_gateway(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();

        $this->assertContains(
            WSZ_PayNL_Gateway_Integration::GATEWAY_ID,
            $integration->register_gateway_ids(array())
        );
    }

    public function test_integration_is_disabled_by_default(): void
    {
        unset($GLOBALS['wsz_subs_test_options']);

        $integration = new WSZ_PayNL_Gateway_Integration();

        $method = new ReflectionMethod(WSZ_PayNL_Gateway_Integration::class, 'is_paynl_tokens_enabled');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($integration));
    }

    public function test_recurring_callback_is_provided_for_paynl_renewal_orders(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $callback = $integration->provide_recurring_charge_callback(
            null,
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertIsCallable($callback);
    }

    public function test_recurring_charge_reports_missing_credentials(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertFalse($result['paid']);
        $this->assertStringContainsString('credentials', $result['message']);
    }

    public function test_authorize_payload_uses_merchant_initiated_token_payment(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order->method('get_id')->willReturn(10474);
        $subscription->method('get_id')->willReturn(10473);

        $method = new ReflectionMethod(WSZ_PayNL_Gateway_Integration::class, 'build_authorize_payload');
        $method->setAccessible(true);

        $payload = $method->invoke(
            $integration,
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription,
            'SL-1234-1234'
        );

        $this->assertSame('mit', $payload['transaction']['type']);
        $this->assertSame(1234, $payload['transaction']['amount']);
        $this->assertSame('token', $payload['payment']['method']);
        $this->assertSame('VY-9212-9171-2390', $payload['payment']['token']['id']);
    }

    public function test_paynl_token_exchange_payload_is_detected(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();

        $this->assertTrue(
            $integration->is_token_exchange_payload(
                array(
                    'action' => 'token',
                    'recurring_id' => 'VY-9212-9171-2390',
                )
            )
        );
    }

    public function test_paynl_token_exchange_resolves_order_through_paynl_transaction_table(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $order = $this->createMock(WC_Order::class);

        $GLOBALS['wsz_paynl_test_transactions']['EX-2345-2238-9812'] = array(
            'order_id' => 10486,
        );
        $GLOBALS['wsz_subs_test_orders'][10486] = $order;

        $this->assertSame(
            $order,
            $integration->resolve_order_from_token_exchange(
                array(
                    'action' => 'token',
                    'order_id' => 'EX-2345-2238-9812',
                    'recurring_id' => 'VY-9212-9171-2390',
                )
            )
        );
    }

    public function test_paynl_token_exchange_stores_token_and_syncs_subscription(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $integration = new WSZ_PayNL_Gateway_Integration($subscription_manager);

        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn(10486);
        $order->method('get_customer_id')->willReturn(5);
        $order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $order
            ->method('get_meta')
            ->with('_wsz_subscription_ids', true)
            ->willReturn(array(10487));
        $order
            ->expects($this->once())
            ->method('update_meta_data')
            ->with('_payment_token_id', $this->greaterThan(0));
        $order
            ->expects($this->once())
            ->method('save');

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $subscription
            ->expects($this->once())
            ->method('save');

        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(10487)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, $this->greaterThan(0));

        $token_id = $integration->store_recurring_payment_token(
            $order,
            array(
                'action' => 'token',
                'order_id' => 'EX-2345-2238-9812',
                'recurring_id' => 'VY-9212-9171-2390',
            )
        );

        $this->assertGreaterThan(0, $token_id);
    }
}
