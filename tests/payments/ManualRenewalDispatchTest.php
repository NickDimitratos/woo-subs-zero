<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('WC_Payment_Token')) {
    class WC_Payment_Token
    {
        public function get_id()
        {
            return 0;
        }

        public function get_user_id($context = 'view')
        {
            return 0;
        }
    }
}

if (!class_exists('WC_Payment_Tokens')) {
    class WC_Payment_Tokens
    {
        private static array $tokens = array();

        private static array $customer_tokens = array();

        public static function reset_test_tokens(): void
        {
            self::$tokens = array();
            self::$customer_tokens = array();
        }

        public static function set_test_tokens(array $tokens, array $customer_tokens = array()): void
        {
            self::$tokens = $tokens;
            self::$customer_tokens = $customer_tokens;
        }

        public static function get($token_id)
        {
            return self::$tokens[(int) $token_id] ?? null;
        }

        public static function get_customer_tokens($customer_id, $gateway_id = '')
        {
            return self::$customer_tokens[(int) $customer_id . '|' . (string) $gateway_id] ?? array();
        }
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-test-card-gateway.php';

final class ManualRenewalDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wsz_wc_test_container'] = null;

        if (is_callable(array('WC_Payment_Tokens', 'reset_test_tokens'))) {
            WC_Payment_Tokens::reset_test_tokens();
        }
    }

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

    public function test_dispatch_scheduled_payment_does_not_fallback_to_manual_for_registered_gateway(): void
    {
        $GLOBALS['wsz_wc_test_container'] = new ManualRenewalDispatchWooContainer(
            new ManualRenewalDispatchGatewayLoader(
                array(
                    'stripe' => (object) array('enabled' => 'no'),
                ),
                array()
            )
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('stripe');

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

    public function test_dispatch_scheduled_payment_uses_saved_token_when_gateway_registry_is_unavailable(): void
    {
        $token = new ManualRenewalDispatchToken(321, 44);
        $set_tokens = new ReflectionMethod('WC_Payment_Tokens', 'set_test_tokens');

        if ($set_tokens->getNumberOfParameters() >= 2) {
            WC_Payment_Tokens::set_test_tokens(array(321 => $token), array());
        } else {
            WC_Payment_Tokens::set_test_tokens(array(321 => $token));
        }

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('stripe');

        $subscription
            ->expects($this->atLeastOnce())
            ->method('get_customer_id')
            ->willReturn(44);

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
            ->expects($this->atLeastOnce())
            ->method('get_payment_token_id')
            ->with($subscription)
            ->willReturn(321);

        $subscription_manager
            ->expects($this->never())
            ->method('set_manual_renewal');

        $handler->dispatch_scheduled_payment($subscription, $renewal_order, 29.99);
    }
}

final class ManualRenewalDispatchToken extends WC_Payment_Token
{
    private int $id;

    private int $user_id;

    public function __construct(int $id, int $user_id)
    {
        $this->id = $id;
        $this->user_id = $user_id;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_user_id($context = 'view')
    {
        return $this->user_id;
    }
}

final class ManualRenewalDispatchWooContainer
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

final class ManualRenewalDispatchGatewayLoader
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
