<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('get_option')) {
    function get_option($option_name, $default = false)
    {
        if ('wsz_subs_options' === $option_name && isset($GLOBALS['wsz_subs_test_options'])) {
            return $GLOBALS['wsz_subs_test_options'];
        }

        if (isset($GLOBALS['wsz_admin_test_options']) && is_array($GLOBALS['wsz_admin_test_options']) && array_key_exists($option_name, $GLOBALS['wsz_admin_test_options'])) {
            return $GLOBALS['wsz_admin_test_options'][$option_name];
        }

        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option_name, $value, $autoload = null)
    {
        if (!isset($GLOBALS['wsz_admin_test_options']) || !is_array($GLOBALS['wsz_admin_test_options'])) {
            $GLOBALS['wsz_admin_test_options'] = array();
        }

        $GLOBALS['wsz_admin_test_options'][$option_name] = $value;

        return true;
    }
}

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

        public function is_default()
        {
            return false;
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

        public static function set_test_tokens(array $tokens, array $customer_tokens): void
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

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id)
    {
        return $GLOBALS['wsz_subs_test_orders'][(int) $order_id] ?? null;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-paynl-token-support.php';
require_once dirname(__DIR__, 2) . '/includes/admin/class-wsz-admin-settings.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-payment-token.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';

final class PaymentMethodRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_subs_test_options'] = array(
            'auto_restore_automatic_renewals' => 'yes',
        );
        $GLOBALS['wsz_admin_test_options'] = array();

        if (is_callable(array('WC_Payment_Tokens', 'reset_test_tokens'))) {
            WC_Payment_Tokens::reset_test_tokens();
        }

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
        unset($GLOBALS['wsz_subs_test_orders']);
        unset($GLOBALS['wsz_admin_test_options']);
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

    public function test_get_payment_token_for_subscription_falls_back_to_customer_gateway_tokens(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $token = new PaymentMethodRecoveryToken(778, 44, true);

        WC_Payment_Tokens::set_test_tokens(
            array(778 => $token),
            array('44|stripe' => array($token))
        );

        $subscription_manager
            ->expects($this->once())
            ->method('get_payment_token_id')
            ->with($subscription)
            ->willReturn(0);

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 778);

        $subscription
            ->expects($this->exactly(2))
            ->method('get_customer_id')
            ->willReturn(44);

        $subscription
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('stripe');

        $this->assertSame($token, $handler->get_payment_token_for_subscription($subscription));
    }

    public function test_get_payment_token_for_subscription_recovers_paynl_token_from_parent_order_meta(): void
    {
        $GLOBALS['wsz_subs_test_options']['enable_paynl_tokens'] = 'yes';

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);
        $parent_order = new PaymentMethodRecoveryPayNLParentOrder(
            10523,
            9,
            WSZ_PayNL_Gateway_Integration::GATEWAY_ID,
            array(
                array('key' => '_paynl_recurring_id', 'value' => 'VY-9212-9171-2390'),
            )
        );

        $GLOBALS['wsz_subs_test_orders'][10523] = $parent_order;

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_customer_id')
            ->willReturn(9);
        $subscription
            ->expects($this->once())
            ->method('get_meta')
            ->with('_wsz_parent_order_id', true)
            ->willReturn(10523);
        $subscription
            ->method('get_payment_method')
            ->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $subscription
            ->expects($this->once())
            ->method('save');

        $subscription_manager
            ->expects($this->once())
            ->method('get_payment_token_id')
            ->with($subscription)
            ->willReturn(0);
        $subscription_manager
            ->expects($this->once())
            ->method('copy_payment_context_meta')
            ->with($parent_order, $subscription)
            ->willReturn(false);
        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, $this->greaterThan(0));
        $subscription_manager
            ->expects($this->once())
            ->method('set_manual_renewal')
            ->with($subscription, false);

        $token = $handler->get_payment_token_for_subscription($subscription);

        $this->assertInstanceOf(WC_Payment_Token::class, $token);
        $this->assertSame('VY-9212-9171-2390', $token->get_token('edit'));
        $this->assertSame($token->get_id(), $parent_order->get_meta('_payment_token_id', true));
        $this->assertCount(1, $parent_order->get_payment_tokens());
    }

    public function test_paid_paynl_parent_order_logs_when_recurring_token_is_missing(): void
    {
        $GLOBALS['wsz_subs_test_options']['enable_paynl_tokens'] = 'yes';

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);
        $parent_order = new PaymentMethodRecoveryPayNLParentOrder(
            10529,
            9,
            WSZ_PayNL_Gateway_Integration::GATEWAY_ID,
            array()
        );
        $parent_order->update_meta_data('_wsz_subscription_ids', array(10530));

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(10530);

        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(10530)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('copy_payment_context_meta')
            ->with($parent_order, $subscription)
            ->willReturn(false);
        $subscription_manager
            ->expects($this->never())
            ->method('set_payment_token_id');

        $handler->sync_subscriptions_from_paid_parent_order($parent_order);

        $logs = $GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs'] ?? array();
        $messages = array_column($logs, 'message');

        $this->assertContains('PAY.nl recurring token is not available on the paid parent order.', $messages);
        $this->assertSame('yes', $parent_order->get_meta('_wsz_paynl_recurring_missing_logged', true));
        $this->assertSame('no', $logs[0]['context']['parent_order_has_paynl_recurring_meta'] ?? '');
        $this->assertSame('10529', $logs[0]['context']['parent_order_id'] ?? '');
    }

    public function test_sync_subscriptions_from_paid_parent_order_updates_linked_subscription_payment_context(): void
    {
        $GLOBALS['wsz_subs_test_options']['auto_restore_automatic_renewals'] = 'yes';

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $parent_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $parent_order
            ->expects($this->exactly(2))
            ->method('get_meta')
            ->willReturnCallback(
                static function ($key, $single = true) {
                    if ('_wsz_subscription_ids' === $key) {
                        return array(842);
                    }

                    if ('_payment_token_id' === $key) {
                        return '90210';
                    }

                    return '';
                }
            );

        $parent_order
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('stripe');

        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(842)
            ->willReturn($subscription);

        $subscription_manager
            ->expects($this->once())
            ->method('copy_payment_context_meta')
            ->with($parent_order, $subscription)
            ->willReturn(true);

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 90210);

        $subscription_manager
            ->expects($this->once())
            ->method('set_manual_renewal')
            ->with($subscription, false);

        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with('stripe');

        $subscription
            ->expects($this->once())
            ->method('save');

        $handler->sync_subscriptions_from_paid_parent_order($parent_order);
    }

    public function test_sync_subscriptions_from_paid_parent_order_accepts_order_payment_token_objects(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $parent_order = $this->createMock(PaymentMethodRecoveryOrderWithTokens::class);
        $subscription = $this->createMock(WC_Order::class);
        $token = new PaymentMethodRecoveryToken(778);

        $parent_order
            ->expects($this->exactly(2))
            ->method('get_meta')
            ->willReturnCallback(
                static function ($key, $single = true) {
                    if ('_wsz_subscription_ids' === $key) {
                        return array(843);
                    }

                    return '';
                }
            );

        $parent_order
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('stripe');

        $parent_order
            ->expects($this->once())
            ->method('get_payment_tokens')
            ->willReturn(array($token));

        $subscription_manager
            ->expects($this->once())
            ->method('get_subscription')
            ->with(843)
            ->willReturn($subscription);

        $subscription_manager
            ->expects($this->once())
            ->method('copy_payment_context_meta')
            ->with($parent_order, $subscription)
            ->willReturn(true);

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 778);

        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with('stripe');

        $subscription
            ->expects($this->once())
            ->method('save');

        $handler->sync_subscriptions_from_paid_parent_order($parent_order);
    }

    public function test_subscription_activation_syncs_parent_order_token_context(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $parent_order = $this->createMock(PaymentMethodRecoveryOrderWithTokens::class);
        $GLOBALS['wsz_subs_test_orders'][501] = $parent_order;

        $subscription
            ->expects($this->once())
            ->method('get_meta')
            ->with('_wsz_parent_order_id', true)
            ->willReturn(501);

        $subscription
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('');

        $subscription
            ->expects($this->once())
            ->method('set_payment_method')
            ->with('stripe');

        $subscription
            ->expects($this->once())
            ->method('save');

        $parent_order
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('stripe');

        $parent_order
            ->expects($this->once())
            ->method('get_meta')
            ->with('_payment_token_id', true)
            ->willReturn('');

        $parent_order
            ->expects($this->once())
            ->method('get_payment_tokens')
            ->willReturn(array(new PaymentMethodRecoveryToken(778)));

        $subscription_manager
            ->expects($this->once())
            ->method('copy_payment_context_meta')
            ->with($parent_order, $subscription)
            ->willReturn(true);

        $subscription_manager
            ->expects($this->once())
            ->method('get_payment_token_id')
            ->with($subscription)
            ->willReturn(0);

        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, 778);

        $subscription_manager
            ->expects($this->once())
            ->method('set_manual_renewal')
            ->with($subscription, false);

        $handler->sync_subscription_from_parent_order($subscription);
    }

    public function test_register_subscription_payment_meta_exposes_token_id_for_gateway(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $handler = new WSZ_Payment_Handler($subscription_manager);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->expects($this->once())
            ->method('get_payment_method')
            ->willReturn('stripe');

        $subscription_manager
            ->expects($this->once())
            ->method('get_payment_token_id')
            ->with($subscription)
            ->willReturn(778);

        $meta = $handler->register_subscription_payment_meta(array(), $subscription);

        $this->assertSame(778, $meta['stripe']['post_meta']['_payment_token_id']['value'] ?? null);
    }
}

final class PaymentMethodRecoveryToken extends WC_Payment_Token
{
    private int $id;

    private int $user_id;

    private bool $is_default;

    public function __construct(int $id, int $user_id = 0, bool $is_default = false)
    {
        $this->id = $id;
        $this->user_id = $user_id;
        $this->is_default = $is_default;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_user_id($context = 'view')
    {
        return $this->user_id;
    }

    public function is_default()
    {
        return $this->is_default;
    }
}

class PaymentMethodRecoveryOrderWithTokens extends WC_Order
{
    public function get_payment_tokens()
    {
        return array();
    }
}

final class PaymentMethodRecoveryPayNLParentOrder extends WC_Order
{
    private int $id;

    private int $customer_id;

    private string $payment_method;

    private array $meta_rows;

    private array $meta = array();

    private array $payment_tokens = array();

    public function __construct(int $id, int $customer_id, string $payment_method, array $meta_rows)
    {
        $this->id = $id;
        $this->customer_id = $customer_id;
        $this->payment_method = $payment_method;
        $this->meta_rows = $meta_rows;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_customer_id()
    {
        return $this->customer_id;
    }

    public function get_payment_method()
    {
        return $this->payment_method;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }

    public function get_meta_data()
    {
        return $this->meta_rows;
    }

    public function update_meta_data($key, $value)
    {
        $this->meta[$key] = $value;
    }

    public function save()
    {
    }

    public function add_payment_token($token)
    {
        if (!($token instanceof WC_Payment_Token)) {
            return false;
        }

        $this->payment_tokens[(int) $token->get_id()] = $token;

        return $token->get_id();
    }

    public function get_payment_tokens()
    {
        return array_values($this->payment_tokens);
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
