<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-webhook-handler.php';

final class WebhookIdempotencyTest extends TestCase
{
    public function test_idempotency_key_is_stable_for_same_payload_and_changes_when_state_changes(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);

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
}
