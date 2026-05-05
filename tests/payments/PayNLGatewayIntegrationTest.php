<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';

final class PayNLGatewayIntegrationTest extends TestCase
{
    public function test_register_gateway_ids_adds_creditcards_grouped_gateway(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();

        $this->assertContains(
            WSZ_PayNL_Gateway_Integration::GATEWAY_ID,
            $integration->register_gateway_ids(array())
        );
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
}
