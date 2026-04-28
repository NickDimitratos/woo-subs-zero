<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-test-card-gateway.php';

final class ManualRenewalDispatchTest extends TestCase
{
    public function test_dispatch_scheduled_payment_marks_order_pending_when_manual_renewal_enabled(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $renewal_order = $this->createMock(WC_Order::class);

        $subscription_manager
            ->expects($this->once())
            ->method('is_manual_renewal')
            ->with($subscription)
            ->willReturn(true);

        $renewal_order
            ->expects($this->once())
            ->method('update_status')
            ->with(
                'pending',
                $this->stringContains('Manual renewal required')
            );

        $handler->dispatch_scheduled_payment($subscription, $renewal_order, 29.99);
    }

    public function test_dispatch_scheduled_payment_for_test_card_bypasses_manual_fallback_when_gateway_registry_is_unavailable(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('wsz_test_card');

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order
            ->expects($this->never())
            ->method('update_status');

        $subscription_manager
            ->expects($this->once())
            ->method('is_manual_renewal')
            ->with($subscription)
            ->willReturn(false);

        $subscription_manager
            ->expects($this->never())
            ->method('set_manual_renewal');

        $handler->dispatch_scheduled_payment($subscription, $renewal_order, 29.99);
    }
}
