<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/admin/class-wsz-admin-settings.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-tokenized-gateway.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';

if (!function_exists('get_option')) {
    function get_option($option_name, $default = false)
    {
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

if (!function_exists('wc_get_logger')) {
    function wc_get_logger()
    {
        return new class {
            public function error($message, $context = array())
            {
                return null;
            }

            public function warning($message, $context = array())
            {
                return null;
            }

            public function info($message, $context = array())
            {
                return null;
            }
        };
    }
}

final class TokenizedGatewayDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_test_filters'] = array();
        $GLOBALS['wsz_admin_test_options'] = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_test_filters']);
        unset($GLOBALS['wsz_admin_test_options']);

        parent::tearDown();
    }

    public function test_dispatch_scheduled_payment_routes_registered_tokenized_gateway_directly(): void
    {
        add_filter(
            'wsz_subs_tokenized_gateway_ids',
            static function (array $gateway_ids): array {
                $gateway_ids[] = 'pay_gateway_creditcardsgrouped';

                return $gateway_ids;
            }
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->method('is_manual_renewal')
            ->willReturn(false);

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->setConstructorArgs(array($subscription_manager))
            ->onlyMethods(array('is_gateway_registered'))
            ->getMock();

        $payment_handler
            ->method('is_gateway_registered')
            ->with('pay_gateway_creditcardsgrouped')
            ->willReturn(true);

        $subscription = $this->createMock(WC_Order::class);
        $subscription
            ->method('get_payment_method')
            ->willReturn('pay_gateway_creditcardsgrouped');

        $renewal_order = $this->createMock(WC_Order::class);

        $tokenized_gateway = $this->getMockBuilder(WSZ_Tokenized_Gateway::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('process_scheduled_payment'))
            ->getMock();
        $tokenized_gateway
            ->expects($this->once())
            ->method('process_scheduled_payment')
            ->with(12.34, $renewal_order);

        $property = new ReflectionProperty(WSZ_Payment_Handler::class, 'tokenized_gateway');
        $property->setAccessible(true);
        $property->setValue($payment_handler, $tokenized_gateway);

        $payment_handler->dispatch_scheduled_payment($subscription, $renewal_order, 12.34);
    }

    public function test_tokenized_gateway_catches_payment_completion_format_errors(): void
    {
        add_filter(
            'wsz_subs_recurring_charge_callback',
            static function () {
                return static function (): array {
                    return array(
                        'paid' => true,
                        'transaction_id' => 'TX-10497',
                    );
                };
            },
            10,
            6
        );

        $subscription = new TokenizedGatewayDispatchDummyOrder(10496, 'active');
        $renewal_order = new TokenizedGatewayDispatchThrowingPaymentOrder(
            10497,
            'pending',
            array('_wsz_subscription_id' => 10496)
        );

        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $subscription_manager
            ->method('get_subscription')
            ->with(10496)
            ->willReturn($subscription);

        $token = new WC_Payment_Token();
        $token->set_token('VY-9212-9171-2390');
        $token->set_gateway_id('pay_gateway_creditcardsgrouped');
        $token->set_user_id(5);
        $token->save();

        $payment_handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('get_payment_token_for_subscription'))
            ->getMock();
        $payment_handler
            ->method('get_payment_token_for_subscription')
            ->with($subscription)
            ->willReturn($token);

        $gateway = new WSZ_Tokenized_Gateway($subscription_manager, $payment_handler);

        $gateway->process_scheduled_payment(12.34, $renewal_order);

        $logs = $GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs'] ?? array();

        $this->assertSame('processing', $renewal_order->get_status());
        $this->assertSame('Tokenized recurring payment processing failed.', $logs[0]['message'] ?? '');
        $this->assertSame('Unknown format specifier ","', $logs[0]['context']['reason'] ?? '');
    }
}

class TokenizedGatewayDispatchDummyOrder extends WC_Order
{
    private int $id;

    private string $status;

    /** @var array<string,mixed> */
    private array $meta;

    public function __construct(int $id, string $status, array $meta = array())
    {
        $this->id = $id;
        $this->status = $status;
        $this->meta = $meta;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function update_status($status, $note = '', $manual = false)
    {
        $this->status = (string) $status;
    }

    public function has_status($status)
    {
        $statuses = is_array($status) ? $status : array($status);

        return in_array($this->status, $statuses, true);
    }

    public function is_paid()
    {
        return in_array($this->status, array('processing', 'completed'), true);
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }

    public function get_parent_id()
    {
        return 0;
    }

    public function get_currency()
    {
        return 'EUR';
    }
}

final class TokenizedGatewayDispatchThrowingPaymentOrder extends TokenizedGatewayDispatchDummyOrder
{
    public function payment_complete($transaction_id = '')
    {
        throw new ValueError('Unknown format specifier ","');
    }
}
