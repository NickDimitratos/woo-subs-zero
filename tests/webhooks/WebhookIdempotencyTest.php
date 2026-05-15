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

    public function test_paynl_token_exchange_payload_ignores_undocumented_token_id_alias(): void
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

        $this->assertFalse($method->invoke($handler, $payload));
    }

    public function test_paynl_token_exchange_payload_rejects_invalid_recurring_id_shape(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'is_token_exchange_payload');
        $method->setAccessible(true);

        $this->assertFalse(
            $method->invoke(
                $handler,
                array(
                    'action' => 'token',
                    'recurring_id' => 'not-a-vy-token',
                )
            )
        );
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

    public function test_paynl_renewal_exchange_resolves_order_from_wsz_reference(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);
        $order = new WebhookPayNLRenewalOrder(10682, array('_wsz_subscription_id' => 10681));

        $GLOBALS['wsz_subs_test_orders'][10682] = $order;

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'resolve_order_from_payload');
        $method->setAccessible(true);

        $this->assertSame(
            $order,
            $method->invoke(
                $handler,
                array(
                    'reference' => 'WSZ-R10682',
                    'transactionid' => 'EX-8157-0581-4222',
                    'state' => 'paid',
                )
            )
        );
    }

    public function test_paynl_renewal_exchange_applies_transaction_id_to_already_paid_order(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);
        $order = new WebhookPayNLRenewalOrder(
            10682,
            array('_wsz_subscription_id' => 10681),
            true,
            ''
        );

        $GLOBALS['wsz_subs_paynl_card_transactions'] = array(
            array(
                'context' => 'renewal',
                'subscription_id' => 10681,
                'order_id' => 10682,
                'status' => 'processing',
                'amount' => 0.05,
                'currency' => 'EUR',
                'transaction_id' => '',
            ),
        );

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'apply_paid_exchange_to_order');
        $method->setAccessible(true);

        $method->invoke(
            $handler,
            $order,
            array(
                'reference' => 'WSZ-R10682',
                'transactionid' => 'EX-8157-0581-4222',
                'state' => 'paid',
            )
        );

        $transactions = WSZ_PayNL_Gateway_Integration::get_transactions(10681, 10);

        $this->assertSame('EX-8157-0581-4222', $order->get_transaction_id());
        $this->assertSame(1, $order->save_count);
        $this->assertCount(1, $transactions);
        $this->assertSame('EX-8157-0581-4222', $transactions[0]['transaction_id'] ?? '');
        $this->assertSame(10682, (int) ($transactions[0]['order_id'] ?? 0));
    }

    public function test_paynl_exchange_status_code_100_is_paid(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Webhook_Handler($subscription_manager);

        $method = new ReflectionMethod(WSZ_Webhook_Handler::class, 'fallback_paid_check');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($handler, array('status' => '100')));
    }
}

final class WebhookPayNLRenewalOrder extends WC_Order
{
    public int $save_count = 0;

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        private int $id,
        private array $meta = array(),
        private bool $paid = false,
        private string $transaction_id = ''
    ) {
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[(string) $key] ?? '';
    }

    public function get_payment_method()
    {
        return WSZ_PayNL_Gateway_Integration::GATEWAY_ID;
    }

    public function is_paid()
    {
        return $this->paid;
    }

    public function payment_complete($transaction_id = '')
    {
        $this->paid = true;
        $this->transaction_id = (string) $transaction_id;
    }

    public function get_transaction_id()
    {
        return $this->transaction_id;
    }

    public function update_meta_data($key, $value)
    {
        $this->meta[(string) $key] = $value;

        if ('_transaction_id' === $key) {
            $this->transaction_id = (string) $value;
        }
    }

    public function save()
    {
        ++$this->save_count;
    }

    public function get_status()
    {
        return 'processing';
    }

    public function get_total()
    {
        return 0.05;
    }

    public function get_currency()
    {
        return 'EUR';
    }
}
