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
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-mollie-gateway.php';

final class MollieGatewayIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_test_filters'] = array();
        $GLOBALS['wsz_mollie_test_http_requests'] = array();
        $GLOBALS['wsz_admin_test_options'] = array(
            'wsz_subs_options' => array(
                'enable_mollie_tokens' => 'yes',
            ),
            'woocommerce_mollie_wc_gateway_creditcard_settings' => array(
                'enabled' => 'yes',
                'api_key' => 'live_mollie_key',
            ),
        );
        unset($GLOBALS['wsz_mollie_test_http_response']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_test_filters']);
        unset($GLOBALS['wsz_mollie_test_http_requests']);
        unset($GLOBALS['wsz_mollie_test_http_response']);
        unset($GLOBALS['wsz_admin_test_options']);
        unset($GLOBALS['wsz_subs_test_options']);

        parent::tearDown();
    }

    public function test_register_gateway_ids_adds_official_mollie_card_gateway(): void
    {
        $integration = new WSZ_Mollie_Gateway_Integration();

        $this->assertContains('mollie_wc_gateway_creditcard', $integration->register_gateway_ids(array()));
    }

    public function test_integration_is_disabled_by_default(): void
    {
        unset($GLOBALS['wsz_admin_test_options']['wsz_subs_options']);

        $integration = new WSZ_Mollie_Gateway_Integration();

        $method = new ReflectionMethod(WSZ_Mollie_Gateway_Integration::class, 'is_mollie_tokens_enabled');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($integration));
    }

    public function test_recurring_callback_is_provided_for_mollie_renewal_orders(): void
    {
        $integration = new WSZ_Mollie_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn('mollie_wc_gateway_creditcard');

        $callback = $integration->provide_recurring_charge_callback(
            null,
            'mdt_wsz_123',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertIsCallable($callback);
    }

    public function test_recurring_charge_creates_customer_recurring_payment(): void
    {
        add_filter(
            'wsz_subs_mollie_webhook_url',
            static fn (): string => 'https://example.test/wc-api/wsz_mollie_renewal'
        );

        $integration = new WSZ_Mollie_Gateway_Integration();
        $renewal_order = $this->mollieOrder(10488, 'mollie_wc_gateway_creditcard', array('_mollie_customer_id' => 'cst_wsz_123'));
        $subscription = $this->mollieOrder(10487, 'mollie_wc_gateway_creditcard', array('_mollie_customer_id' => 'cst_wsz_123'));

        $result = $integration->charge_recurring_payment(
            'mdt_wsz_123',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $request = $GLOBALS['wsz_mollie_test_http_requests'][0] ?? array();
        $body = json_decode((string) ($request['args']['body'] ?? ''), true);

        $this->assertTrue($result['paid']);
        $this->assertSame('tr_wsz_test', $result['transaction_id'] ?? '');
        $this->assertSame('https://api.mollie.com/v2/customers/cst_wsz_123/payments', $request['url'] ?? '');
        $this->assertSame('Bearer live_mollie_key', $request['args']['headers']['Authorization'] ?? '');
        $this->assertSame('application/json', $request['args']['headers']['Content-Type'] ?? '');
        $this->assertSame('wsz-renewal-10488-mdt_wsz_123-12-34-eur', $request['args']['headers']['Idempotency-Key'] ?? '');
        $this->assertSame(array('value' => '12.34', 'currency' => 'EUR'), $body['amount'] ?? array());
        $this->assertSame('recurring', $body['sequenceType'] ?? '');
        $this->assertSame('mdt_wsz_123', $body['mandateId'] ?? '');
        $this->assertSame('https://example.test/wc-api/wsz_mollie_renewal', $body['webhookUrl'] ?? '');
        $this->assertSame(10488, $body['metadata']['renewal_order_id'] ?? 0);
        $this->assertSame(10487, $body['metadata']['subscription_id'] ?? 0);
    }

    public function test_recurring_charge_reports_pending_status(): void
    {
        $GLOBALS['wsz_mollie_test_http_response'] = array(
            'response' => array('code' => 201),
            'body' => '{"id":"tr_pending","status":"pending","mandateId":"mdt_wsz_123"}',
        );

        $integration = new WSZ_Mollie_Gateway_Integration();
        $renewal_order = $this->mollieOrder(10488, 'mollie_wc_gateway_creditcard', array('_mollie_customer_id' => 'cst_wsz_123'));
        $subscription = $this->mollieOrder(10487, 'mollie_wc_gateway_creditcard', array('_mollie_customer_id' => 'cst_wsz_123'));

        $result = $integration->charge_recurring_payment(
            'mdt_wsz_123',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertFalse($result['paid']);
        $this->assertTrue($result['pending']);
        $this->assertSame('tr_pending', $result['transaction_id'] ?? '');
    }

    public function test_recurring_charge_reports_missing_customer_context(): void
    {
        $integration = new WSZ_Mollie_Gateway_Integration();
        $renewal_order = $this->mollieOrder(10488, 'mollie_wc_gateway_creditcard', array());
        $subscription = $this->mollieOrder(10487, 'mollie_wc_gateway_creditcard', array());

        $result = $integration->charge_recurring_payment(
            'mdt_wsz_123',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertFalse($result['paid']);
        $this->assertStringContainsString('customer', strtolower($result['message'] ?? ''));
        $this->assertSame(array(), $GLOBALS['wsz_mollie_test_http_requests']);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function mollieOrder(int $id, string $gateway_id, array $meta): WC_Order
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
