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

require_once dirname(__DIR__, 2) . '/includes/admin/class-wsz-admin-settings.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-stripe-gateway.php';

final class StripeGatewayIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_test_filters'] = array();
        $GLOBALS['wsz_stripe_test_http_requests'] = array();
        $GLOBALS['wsz_admin_test_options'] = array(
            'wsz_subs_options' => array(
                'enable_stripe_tokens' => 'yes',
            ),
            'woocommerce_stripe_settings' => array(
                'enabled' => 'yes',
                'testmode' => 'no',
                'secret_key' => 'sk_live_wsz',
            ),
        );
        unset($GLOBALS['wsz_stripe_test_http_response']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_test_filters']);
        unset($GLOBALS['wsz_stripe_test_http_requests']);
        unset($GLOBALS['wsz_stripe_test_http_response']);
        unset($GLOBALS['wsz_admin_test_options']);
        unset($GLOBALS['wsz_subs_test_options']);

        parent::tearDown();
    }

    public function test_register_gateway_ids_adds_official_stripe_gateway(): void
    {
        $integration = new WSZ_Stripe_Gateway_Integration();

        $this->assertContains('stripe', $integration->register_gateway_ids(array()));
    }

    public function test_integration_is_disabled_by_default(): void
    {
        unset($GLOBALS['wsz_admin_test_options']['wsz_subs_options']);

        $integration = new WSZ_Stripe_Gateway_Integration();

        $method = new ReflectionMethod(WSZ_Stripe_Gateway_Integration::class, 'is_stripe_tokens_enabled');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($integration));
    }

    public function test_recurring_callback_is_provided_for_stripe_renewal_orders(): void
    {
        $integration = new WSZ_Stripe_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn('stripe');

        $callback = $integration->provide_recurring_charge_callback(
            null,
            'pm_card_visa',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertIsCallable($callback);
    }

    public function test_recurring_charge_creates_confirmed_off_session_payment_intent(): void
    {
        $integration = new WSZ_Stripe_Gateway_Integration();
        $renewal_order = $this->stripeOrder(10488, 'stripe', array('_stripe_customer_id' => 'cus_wsz_123'));
        $subscription = $this->stripeOrder(10487, 'stripe', array('_stripe_customer_id' => 'cus_wsz_123'));

        $result = $integration->charge_recurring_payment(
            'pm_card_visa',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $request = $GLOBALS['wsz_stripe_test_http_requests'][0] ?? array();
        parse_str((string) ($request['args']['body'] ?? ''), $body);

        $this->assertTrue($result['paid']);
        $this->assertSame('pi_wsz_test', $result['transaction_id'] ?? '');
        $this->assertSame('https://api.stripe.com/v1/payment_intents', $request['url'] ?? '');
        $this->assertSame('Bearer sk_live_wsz', $request['args']['headers']['Authorization'] ?? '');
        $this->assertSame('application/x-www-form-urlencoded', $request['args']['headers']['Content-Type'] ?? '');
        $this->assertSame('wsz-renewal-10488-pm_card_visa-1234-eur', $request['args']['headers']['Idempotency-Key'] ?? '');
        $this->assertSame('1234', $body['amount'] ?? '');
        $this->assertSame('eur', $body['currency'] ?? '');
        $this->assertSame('cus_wsz_123', $body['customer'] ?? '');
        $this->assertSame('pm_card_visa', $body['payment_method'] ?? '');
        $this->assertSame('true', $body['off_session'] ?? '');
        $this->assertSame('true', $body['confirm'] ?? '');
        $this->assertSame('10488', $body['metadata']['renewal_order_id'] ?? '');
        $this->assertSame('10487', $body['metadata']['subscription_id'] ?? '');
    }

    public function test_recurring_charge_uses_zero_decimal_amount_for_jpy(): void
    {
        $integration = new WSZ_Stripe_Gateway_Integration();
        $renewal_order = $this->stripeOrder(10488, 'stripe', array('_stripe_customer_id' => 'cus_wsz_123'));
        $subscription = $this->stripeOrder(10487, 'stripe', array('_stripe_customer_id' => 'cus_wsz_123'));

        $integration->charge_recurring_payment(
            'pm_card_visa',
            1200.0,
            'JPY',
            $renewal_order,
            $subscription
        );

        $request = $GLOBALS['wsz_stripe_test_http_requests'][0] ?? array();
        parse_str((string) ($request['args']['body'] ?? ''), $body);

        $this->assertSame('1200', $body['amount'] ?? '');
    }

    public function test_recurring_charge_reports_customer_authentication_required(): void
    {
        $GLOBALS['wsz_stripe_test_http_response'] = array(
            'response' => array('code' => 402),
            'body' => '{"error":{"message":"Authentication required","payment_intent":{"id":"pi_requires_action","status":"requires_action"}}}',
        );

        $integration = new WSZ_Stripe_Gateway_Integration();
        $renewal_order = $this->stripeOrder(10488, 'stripe', array('_stripe_customer_id' => 'cus_wsz_123'));
        $subscription = $this->stripeOrder(10487, 'stripe', array('_stripe_customer_id' => 'cus_wsz_123'));

        $result = $integration->charge_recurring_payment(
            'pm_card_visa',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertFalse($result['paid']);
        $this->assertSame('pi_requires_action', $result['transaction_id'] ?? '');
        $this->assertStringContainsString('authentication', strtolower($result['message'] ?? ''));
    }

    public function test_recurring_charge_reports_missing_customer_context(): void
    {
        $integration = new WSZ_Stripe_Gateway_Integration();
        $renewal_order = $this->stripeOrder(10488, 'stripe', array());
        $subscription = $this->stripeOrder(10487, 'stripe', array());

        $result = $integration->charge_recurring_payment(
            'pm_card_visa',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertFalse($result['paid']);
        $this->assertStringContainsString('customer', strtolower($result['message'] ?? ''));
        $this->assertSame(array(), $GLOBALS['wsz_stripe_test_http_requests']);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function stripeOrder(int $id, string $gateway_id, array $meta): WC_Order
    {
        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn($id);
        $order->method('get_payment_method')->willReturn($gateway_id);
        $order->method('get_meta')->willReturnCallback(
            static function (string $key) use ($meta) {
                return $meta[$key] ?? '';
            }
        );

        return $order;
    }
}
