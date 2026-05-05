<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-payment-token.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-webhook-handler.php';

final class WebhookIdempotencyTest extends TestCase
{
    public function test_idempotency_key_is_stable_for_same_payload_and_changes_when_state_changes(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);
        $order_meta = array();

        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn(77);

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'build_idempotency_key');
        $method->setAccessible(true);

        $payload = array(
            'transaction_id' => 'TRX-001',
            'state' => 'paid',
        );

        $same_payload_key = $method->invoke($handler, $order, $payload);
        $same_payload_key_again = $method->invoke($handler, $order, $payload);

        $changed_state_key = $method->invoke(
            $handler,
            $order,
            array(
                'transaction_id' => 'TRX-001',
                'state' => 'failed',
            )
        );

        $this->assertSame($same_payload_key, $same_payload_key_again);
        $this->assertNotSame($same_payload_key, $changed_state_key);
    }

    public function test_order_key_validation_rejects_missing_order_key(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);

        $order = $this->createMock(WC_Order::class);
        $order->method('get_order_key')->willReturn('wc_order_abc');

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'is_order_key_valid');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($handler, $order, array()));
    }

    public function test_order_key_validation_accepts_matching_order_key(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);

        $order = $this->createMock(WC_Order::class);
        $order->method('get_order_key')->willReturn('wc_order_abc');

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'is_order_key_valid');
        $method->setAccessible(true);

        $this->assertTrue(
            $method->invoke(
                $handler,
                $order,
                array('order_key' => 'wc_order_abc')
            )
        );
    }

    public function test_paynl_token_exchange_payload_is_detected(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'is_token_exchange_payload');
        $method->setAccessible(true);

        $this->assertTrue(
            $method->invoke(
                $handler,
                array(
                    'action' => 'token',
                    'recurring_id' => 'VY-9212-9171-2390',
                )
            )
        );
    }

    public function test_paynl_token_exchange_payload_accepts_token_id_alias(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'is_token_exchange_payload');
        $method->setAccessible(true);

        $payload = WSZ_PayNL_Token_Support::normalize_payload(
            array(
                'action' => 'token',
                'payment' => array(
                    'token' => array(
                        'id' => 'VY-9212-9171-2390',
                    ),
                ),
            )
        );

        $this->assertTrue($method->invoke($handler, $payload));
    }

    public function test_paynl_token_exchange_stores_payment_token_on_order_and_subscription(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);

        $order = $this->createMock(WC_Order::class);
        $order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $order->method('get_customer_id')->willReturn(8);
        $order
            ->method('get_meta')
            ->with('_wsz_subscription_ids', true)
            ->willReturn(array(10473));

        $order
            ->expects($this->exactly(5))
            ->method('update_meta_data')
            ->willReturnCallback(
                static function ($key, $value) use (&$order_meta): void {
                    $order_meta[(string) $key] = $value;
                }
            );

        $order
            ->expects($this->once())
            ->method('add_payment_token')
            ->with($this->isInstanceOf(WC_Payment_Token::class));

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
            ->with(10473)
            ->willReturn($subscription);

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, $this->greaterThan(0));

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'store_recurring_payment_token');
        $method->setAccessible(true);

        $token_id = $method->invoke(
            $handler,
            $order,
            array(
                'action' => 'token',
                'recurring_id' => 'VY-9212-9171-2390',
                'transactionid' => 'EX-2345-2238-9812',
            )
        );

        $this->assertGreaterThan(0, $token_id);
        $this->assertGreaterThan(0, $order_meta['_payment_token_id'] ?? 0);
        $this->assertSame('VY-9212-9171-2390', $order_meta['_wsz_paynl_recurring_id'] ?? '');
        $this->assertSame('recurring_id', $order_meta['_wsz_paynl_recurring_source'] ?? '');
        $this->assertNotEmpty($order_meta['_wsz_paynl_recurring_captured_at'] ?? '');
        $this->assertSame('no', $order_meta['_wsz_paynl_recurring_missing_logged'] ?? '');
    }
}
