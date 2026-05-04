<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('get_option')) {
    function get_option($option_name, $default = false)
    {
        if ('wsz_subs_options' === $option_name && isset($GLOBALS['wsz_subs_test_options'])) {
            return $GLOBALS['wsz_subs_test_options'];
        }

        return $default;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';

final class PaymentMethodRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_subs_test_options'] = array(
            'auto_restore_automatic_renewals' => 'yes',
        );

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

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_subs_test_options']);
        parent::tearDown();
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

    public function test_failing_payment_method_update_restores_automatic_renewal_when_enabled(): void
    {
        $GLOBALS['wsz_subs_test_options']['auto_restore_automatic_renewals'] = 'yes';

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);

        $subscription_manager
            ->expects($this->once())
            ->method('set_manual_renewal')
            ->with($subscription, false);

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 789);

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
            array('token_id' => 789)
        );
    }

    public function test_failing_payment_method_update_does_not_restore_when_disabled(): void
    {
        $GLOBALS['wsz_subs_test_options']['auto_restore_automatic_renewals'] = 'no';

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);

        $subscription_manager
            ->expects($this->never())
            ->method('set_manual_renewal');

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 789);

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
            array('token_id' => 789)
        );
    }

    public function test_failing_payment_method_update_accepts_renewal_order_signature(): void
    {
        $GLOBALS['wsz_subs_test_options']['auto_restore_automatic_renewals'] = 'yes';

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $renewal_order = $this->createMock(WC_Order::class);

        $subscription
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('stripe');

        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with('stripe');

        $subscription
            ->expects($this->once())
            ->method('save');

        $renewal_order
            ->expects($this->once())
            ->method('get_meta')
            ->with('_payment_token_id', true)
            ->willReturn('654');

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 654);

        $subscription_manager
            ->expects($this->once())
            ->method('set_manual_renewal')
            ->with($subscription, false);

        $handler->handle_failing_payment_method_update($subscription, $renewal_order, array());
    }

    public function test_manual_renewal_payment_complete_restores_automatic_renewal_when_enabled(): void
    {
        $GLOBALS['wsz_subs_test_options']['auto_restore_automatic_renewals'] = 'yes';

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $renewal_order = $this->createMock(WC_Order::class);

        $subscription_manager
            ->expects($this->once())
            ->method('is_manual_renewal')
            ->with($subscription)
            ->willReturn(true);

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 321);

        $subscription_manager
            ->expects($this->once())
            ->method('set_manual_renewal')
            ->with($subscription, false);

        $subscription
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('stripe');

        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with('stripe');

        $subscription
            ->expects($this->once())
            ->method('save');

        $renewal_order
            ->expects($this->once())
            ->method('get_meta')
            ->with('_payment_token_id', true)
            ->willReturn('321');

        $handler->handle_manual_renewal_payment_complete($subscription, $renewal_order);
    }

    public function test_manual_renewal_payment_complete_skips_restore_when_disabled(): void
    {
        $GLOBALS['wsz_subs_test_options']['auto_restore_automatic_renewals'] = 'no';

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $renewal_order = $this->createMock(WC_Order::class);

        $subscription_manager
            ->expects($this->never())
            ->method('is_manual_renewal');

        $subscription_manager
            ->expects($this->never())
            ->method('set_manual_renewal');

        $handler->handle_manual_renewal_payment_complete($subscription, $renewal_order);
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
