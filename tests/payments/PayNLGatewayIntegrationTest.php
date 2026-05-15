<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('get_option')) {
    function get_option($option_name, $default = false)
    {
        if ('wsz_subs_options' === $option_name && isset($GLOBALS['wsz_subs_test_options'])) {
            return $GLOBALS['wsz_subs_test_options'];
        }

        if ('wsz_subs_paynl_card_transactions' === $option_name && isset($GLOBALS['wsz_subs_paynl_card_transactions'])) {
            return $GLOBALS['wsz_subs_paynl_card_transactions'];
        }

        if ('wsz_subs_test_card_transactions' === $option_name && isset($GLOBALS['wsz_subs_test_card_transactions'])) {
            return $GLOBALS['wsz_subs_test_card_transactions'];
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
        if ('wsz_subs_paynl_card_transactions' === $option_name) {
            $GLOBALS['wsz_subs_paynl_card_transactions'] = is_array($value) ? $value : array();
        }

        if ('wsz_subs_test_card_transactions' === $option_name) {
            $GLOBALS['wsz_subs_test_card_transactions'] = is_array($value) ? $value : array();
        }

        if (!isset($GLOBALS['wsz_admin_test_options']) || !is_array($GLOBALS['wsz_admin_test_options'])) {
            $GLOBALS['wsz_admin_test_options'] = array();
        }

        $GLOBALS['wsz_admin_test_options'][$option_name] = $value;

        return true;
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array())
    {
        $GLOBALS['wsz_paynl_test_http_requests'][] = array(
            'url' => (string) $url,
            'args' => $args,
        );

        return $GLOBALS['wsz_paynl_test_http_response'] ?? array(
            'response' => array('code' => 200),
            'body' => '{"state":"paid","transactionId":"PAY-RENEWAL-1"}',
        );
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return (int) ($response['response']['code'] ?? 0);
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return (string) ($response['body'] ?? '');
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/admin/class-wsz-admin-settings.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-payment-token.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';

if (!class_exists('PPMFWC_Helper_Transaction')) {
    class PPMFWC_Helper_Transaction
    {
        public static function getTransaction(string $transactionId): mixed
        {
            return $GLOBALS['wsz_paynl_test_transactions'][$transactionId] ?? false;
        }
    }
}

if (!class_exists('PPMFWC_Helper_Config')) {
    class PPMFWC_Helper_Config
    {
        public static function getTokenCode(): string
        {
            return (string) ($GLOBALS['wsz_paynl_test_plugin_credentials']['token_code'] ?? '');
        }

        public static function getApiToken(): string
        {
            return (string) ($GLOBALS['wsz_paynl_test_plugin_credentials']['api_token'] ?? '');
        }

        public static function getServiceId(): string
        {
            return (string) ($GLOBALS['wsz_paynl_test_plugin_credentials']['service_id'] ?? '');
        }

        public static function getServiceSecret(): string
        {
            return (string) ($GLOBALS['wsz_paynl_test_plugin_credentials']['service_secret'] ?? '');
        }
    }
}

final class PayNLGatewayIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_subs_test_orders'] = array();
        $GLOBALS['wsz_subs_test_order_queries'] = array();
        $GLOBALS['wsz_paynl_test_transactions'] = array();
        $GLOBALS['wsz_subs_paynl_card_transactions'] = array();
        $GLOBALS['wsz_paynl_test_http_requests'] = array();
        unset($GLOBALS['wsz_paynl_test_http_response']);
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_paynl_tokens' => 'yes',
        );
        $GLOBALS['wsz_admin_test_options']['wsz_subs_options'] = $GLOBALS['wsz_subs_test_options'];
        $GLOBALS['wsz_admin_test_options']['wsz_subs_paynl_card_transactions'] = array();
        $GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs'] = array();
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array();

        if (is_callable(array('WC_Payment_Tokens', 'reset_test_tokens'))) {
            WC_Payment_Tokens::reset_test_tokens();
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_subs_test_orders']);
        unset($GLOBALS['wsz_subs_test_order_queries']);
        unset($GLOBALS['wsz_paynl_test_transactions']);
        unset($GLOBALS['wsz_subs_paynl_card_transactions']);
        unset($GLOBALS['wsz_paynl_test_http_requests']);
        unset($GLOBALS['wsz_paynl_test_http_response']);
        unset($GLOBALS['wsz_subs_test_options']);
        unset($GLOBALS['wsz_admin_test_options']['wsz_subs_options']);
        unset($GLOBALS['wsz_admin_test_options']['wsz_subs_paynl_card_transactions']);
        unset($GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs']);
        unset($GLOBALS['wsz_paynl_test_plugin_credentials']);

        parent::tearDown();
    }

    public function test_register_gateway_ids_adds_creditcards_grouped_gateway(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $gateway_ids = $integration->register_gateway_ids(array());

        $this->assertContains(
            WSZ_PayNL_Gateway_Integration::GATEWAY_ID,
            $gateway_ids
        );
        $this->assertContains('pay_gateway_visamastercard', $gateway_ids);
    }

    public function test_integration_is_disabled_by_default(): void
    {
        unset($GLOBALS['wsz_subs_test_options']);
        unset($GLOBALS['wsz_admin_test_options']['wsz_subs_options']);

        $integration = new WSZ_PayNL_Gateway_Integration();

        $method = new ReflectionMethod(WSZ_PayNL_Gateway_Integration::class, 'is_paynl_tokens_enabled');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($integration));
    }

    public function test_recurring_callback_is_provided_for_paynl_renewal_orders(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $callback = $integration->provide_recurring_charge_callback(
            null,
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertIsCallable($callback);
    }

    public function test_recurring_callback_is_provided_for_paynl_visa_mastercard_renewal_orders(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn('pay_gateway_visamastercard');

        $callback = $integration->provide_recurring_charge_callback(
            null,
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertIsCallable($callback);
    }

    public function test_recurring_charge_reports_missing_credentials(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertFalse($result['paid']);
        $this->assertStringContainsString('credentials', $result['message']);
    }

    public function test_recurring_charge_uses_paynl_plugin_credentials(): void
    {
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array(
            'token_code' => 'AT-1234-5678',
            'api_token' => 'test-api-token',
            'service_id' => 'SL-1234-5678',
        );

        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertTrue($result['paid']);
        $this->assertNotEmpty($GLOBALS['wsz_paynl_test_http_requests']);
    }

    public function test_recurring_charge_prefers_api_token_for_authorize_request(): void
    {
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array(
            'token_code' => 'AT-1234-5678',
            'api_token' => 'test-api-token',
            'service_id' => 'SL-1234-5678',
            'service_secret' => 'sales-location-secret',
        );

        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $request = $GLOBALS['wsz_paynl_test_http_requests'][0] ?? array();

        $this->assertTrue($result['paid']);
        $this->assertNotEmpty($request);
        $this->assertSame(
            'Basic ' . base64_encode('AT-1234-5678:test-api-token'),
            $request['args']['headers']['Authorization'] ?? ''
        );
    }

    public function test_recurring_charge_falls_back_to_api_token_when_service_secret_missing(): void
    {
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array(
            'token_code' => 'AT-1234-5678',
            'api_token' => 'test-api-token',
            'service_id' => 'SL-1234-5678',
        );

        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order
            ->method('get_payment_method')
            ->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $request = $GLOBALS['wsz_paynl_test_http_requests'][0] ?? array();

        $this->assertTrue($result['paid']);
        $this->assertSame(
            'Basic ' . base64_encode('AT-1234-5678:test-api-token'),
            $request['args']['headers']['Authorization'] ?? ''
        );
    }

    public function test_authorize_payload_uses_paynl_authorize_token_shape(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order->method('get_id')->willReturn(10474);
        $subscription->method('get_id')->willReturn(10473);

        $method = new ReflectionMethod(WSZ_PayNL_Gateway_Integration::class, 'build_authorize_payload');
        $method->setAccessible(true);

        $payload = $method->invoke(
            $integration,
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription,
            'SL-1234-1234'
        );

        $this->assertSame('MIT', $payload['transaction']['type']);
        $this->assertSame('SL-1234-1234', $payload['transaction']['serviceId']);
        $this->assertSame('WSZ-R10474', $payload['transaction']['reference']);
        $this->assertSame(1234, $payload['transaction']['amount']);
        $this->assertSame('EUR', $payload['transaction']['currency']);
        $this->assertSame(1, $payload['options']['tokenization']);
        $this->assertSame('token', $payload['payment']['method']);
        $this->assertSame('VY-9212-9171-2390', $payload['payment']['token']['id']);
        $this->assertSame('subscription_10473', $payload['stats']['extra1']);
        $this->assertSame('renewal_10474', $payload['stats']['extra2']);
    }

    public function test_recurring_charge_posts_json_to_paynl_authorize_endpoint(): void
    {
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array(
            'token_code' => 'AT-1234-5678',
            'api_token' => 'test-api-token',
            'service_id' => 'SL-1234-5678',
        );

        $integration = new WSZ_PayNL_Gateway_Integration();
        $renewal_order = $this->createMock(WC_Order::class);
        $subscription = $this->createMock(WC_Order::class);

        $renewal_order->method('get_id')->willReturn(10474);
        $renewal_order
            ->method('get_payment_method')
            ->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $subscription->method('get_id')->willReturn(10473);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $request = $GLOBALS['wsz_paynl_test_http_requests'][0] ?? array();
        $body = json_decode((string) ($request['args']['body'] ?? ''), true);

        $this->assertTrue($result['paid']);
        $this->assertSame('https://payment.pay.nl/v1/Payment/authorize/json', $request['url'] ?? '');
        $this->assertSame('application/json', $request['args']['headers']['Content-Type'] ?? '');
        $this->assertIsArray($body);
        $this->assertSame('MIT', $body['transaction']['type'] ?? '');
        $this->assertSame('token', $body['payment']['method'] ?? '');
        $this->assertSame('VY-9212-9171-2390', $body['payment']['token']['id'] ?? '');
    }

    public function test_paynl_token_exchange_payload_is_detected(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();

        $this->assertTrue(
            $integration->is_token_exchange_payload(
                array(
                    'action' => 'token',
                    'recurring_id' => 'VY-9212-9171-2390',
                )
            )
        );
    }

    public function test_paynl_token_exchange_payload_accepts_nested_token_alias(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
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

        $this->assertTrue($integration->is_token_exchange_payload($payload));
    }

    public function test_paynl_token_exchange_does_not_treat_recurring_token_hash_as_recurring_id(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();

        $this->assertFalse(
            $integration->is_token_exchange_payload(
                array(
                    'action' => 'token',
                    'recurring_token' => 'c1747bf4d38cd6af76ca0d2ffe373987b666305e27dd8b5501a5facf90a99bffe',
                )
            )
        );
    }

    public function test_paynl_token_exchange_resolves_order_through_paynl_transaction_table(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $order = $this->createMock(WC_Order::class);

        $GLOBALS['wsz_paynl_test_transactions']['EX-2345-2238-9812'] = array(
            'order_id' => 10486,
        );
        $GLOBALS['wsz_subs_test_orders'][10486] = $order;

        $this->assertSame(
            $order,
            $integration->resolve_order_from_token_exchange(
                array(
                    'action' => 'token',
                    'order_id' => 'EX-2345-2238-9812',
                    'recurring_id' => 'VY-9212-9171-2390',
                )
            )
        );
    }

    public function test_paynl_token_exchange_resolves_order_from_normalized_payment_id(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $order = $this->createMock(WC_Order::class);

        $GLOBALS['wsz_paynl_test_transactions']['EX-2345-2238-9812'] = array(
            'order_id' => 10486,
        );
        $GLOBALS['wsz_subs_test_orders'][10486] = $order;

        $payload = WSZ_PayNL_Token_Support::normalize_payload(
            array(
                'action' => 'token',
                'payment' => array(
                    'id' => 'EX-2345-2238-9812',
                    'token' => array(
                        'id' => 'VY-9212-9171-2390',
                    ),
                ),
            )
        );

        $this->assertSame($order, $integration->resolve_order_from_token_exchange($payload));
    }

    public function test_paynl_token_exchange_stores_token_and_syncs_subscription(): void
    {
        $subscription_manager = $this->createMock(WSZ_Subscription_Manager::class);
        $integration = new WSZ_PayNL_Gateway_Integration($subscription_manager);
        $order_meta = array();

        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn(10486);
        $order->method('get_customer_id')->willReturn(5);
        $order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $order
            ->method('get_meta')
            ->willReturnMap(
                array(
                    array('_wsz_subscription_ids', true, array(10487)),
                    array('_wsz_subscription_id', true, ''),
                )
            );
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
            ->with(10487)
            ->willReturn($subscription);
        $subscription_manager
            ->expects($this->once())
            ->method('set_payment_token_id')
            ->with($subscription, $this->greaterThan(0));

        $token_id = $integration->store_recurring_payment_token(
            $order,
            array(
                'action' => 'token',
                'order_id' => 'EX-2345-2238-9812',
                'recurring_id' => 'VY-9212-9171-2390',
            )
        );

        $this->assertGreaterThan(0, $token_id);
        $this->assertGreaterThan(0, $order_meta['_payment_token_id'] ?? 0);
        $this->assertSame('VY-9212-9171-2390', $order_meta['_wsz_paynl_recurring_id'] ?? '');
        $this->assertSame('recurring_id', $order_meta['_wsz_paynl_recurring_source'] ?? '');
        $this->assertNotEmpty($order_meta['_wsz_paynl_recurring_captured_at'] ?? '');
        $this->assertSame('no', $order_meta['_wsz_paynl_recurring_missing_logged'] ?? '');
    }

    public function test_paynl_token_exchange_logs_initial_card_transaction_for_subscription(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $order_meta = array();

        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn(10486);
        $order->method('get_customer_id')->willReturn(5);
        $order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $order->method('get_status')->willReturn('processing');
        $order->method('get_total')->willReturn(39.95);
        $order->method('get_currency')->willReturn('EUR');
        $order->method('get_transaction_id')->willReturn('41712240079X15d0');
        $order
            ->method('get_meta')
            ->willReturnMap(
                array(
                    array('_wsz_subscription_ids', true, array(10487)),
                    array('_wsz_subscription_id', true, ''),
                )
            );
        $order
            ->method('update_meta_data')
            ->willReturnCallback(
                static function ($key, $value) use (&$order_meta): void {
                    $order_meta[(string) $key] = $value;
                }
            );

        $token_id = $integration->store_recurring_payment_token(
            $order,
            array(
                'action' => 'token',
                'order_id' => '10486',
                'transactionid' => 'EX-2345-2238-9812',
                'recurring_id' => 'VY-9212-9171-2390',
            )
        );

        $transactions = WSZ_PayNL_Gateway_Integration::get_transactions(10487, 10);

        $this->assertGreaterThan(0, $token_id);
        $this->assertCount(1, $transactions);
        $this->assertSame('initial', $transactions[0]['context'] ?? '');
        $this->assertSame('PAY.nl', $transactions[0]['gateway'] ?? '');
        $this->assertSame(10487, (int) ($transactions[0]['subscription_id'] ?? 0));
        $this->assertSame(10486, (int) ($transactions[0]['order_id'] ?? 0));
        $this->assertSame('EX-2345-2238-9812', $transactions[0]['transaction_id'] ?? '');
    }

    public function test_paynl_recurring_charge_reads_transaction_id_alias_from_response(): void
    {
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array(
            'token_code' => 'AT-1234-5678',
            'api_token' => 'test-api-token',
            'service_id' => 'SL-1234-5678',
        );
        $GLOBALS['wsz_paynl_test_http_response'] = array(
            'response' => array('code' => 200),
            'body' => '{"state":"paid","id":"PAY-ID-ALIAS-1"}',
        );

        $integration = new WSZ_PayNL_Gateway_Integration();

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order->method('get_id')->willReturn(10488);
        $renewal_order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $renewal_order->method('get_status')->willReturn('processing');
        $renewal_order->method('get_total')->willReturn(12.34);
        $renewal_order->method('get_currency')->willReturn('EUR');
        $renewal_order
            ->method('get_meta')
            ->willReturnMap(
                array(
                    array('_wsz_subscription_id', true, 10487),
                    array('_wsz_subscription_ids', true, ''),
                )
            );

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(10487);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertTrue($result['paid']);
        $this->assertSame('PAY-ID-ALIAS-1', $result['transaction_id'] ?? '');
        $this->assertSame(array(), WSZ_PayNL_Gateway_Integration::get_transactions(10487, 10));
    }

    public function test_paynl_recurring_charge_reads_documented_transaction_response(): void
    {
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array(
            'token_code' => 'AT-1234-5678',
            'api_token' => 'test-api-token',
            'service_id' => 'SL-1234-5678',
        );
        $GLOBALS['wsz_paynl_test_http_response'] = array(
            'response' => array('code' => 200),
            'body' => '{"request":{"result":1,"errorId":"","errorMessage":""},"payment":{"bankCode":"00","bankMessage":"Approved"},"transaction":{"transactionId":"EX-6582-4371-5560","orderId":"1606895211Xcfe87","state":"100","stateName":"PAID"}}',
        );

        $integration = new WSZ_PayNL_Gateway_Integration();

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order->method('get_id')->willReturn(10488);
        $renewal_order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $renewal_order
            ->method('get_meta')
            ->willReturnMap(
                array(
                    array('_wsz_subscription_id', true, 10487),
                    array('_wsz_subscription_ids', true, ''),
                )
            );

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(10487);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $this->assertTrue($result['paid']);
        $this->assertSame('EX-6582-4371-5560', $result['transaction_id'] ?? '');
    }

    public function test_paynl_recurring_charge_logs_declined_response_details(): void
    {
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array(
            'token_code' => 'AT-1234-5678',
            'api_token' => 'test-api-token',
            'service_id' => 'SL-1234-5678',
        );
        $GLOBALS['wsz_paynl_test_http_response'] = array(
            'response' => array('code' => 200),
            'body' => '{"request":{"result":0,"errorId":"PAY-1404","errorTag":"permissionDenied","errorMessage":"No rights to perform this action"}}',
        );

        $integration = new WSZ_PayNL_Gateway_Integration();

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order->method('get_id')->willReturn(10488);
        $renewal_order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(10487);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $logs = $GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs'] ?? array();

        $this->assertFalse($result['paid']);
        $this->assertSame('No rights to perform this action', $result['message'] ?? '');
        $this->assertSame('PAY.nl recurring charge was not approved.', $logs[0]['message'] ?? '');
        $this->assertSame('warning', $logs[0]['level'] ?? '');
        $this->assertSame('10488', $logs[0]['context']['renewal_order_id'] ?? '');
        $this->assertSame('10487', $logs[0]['context']['subscription_id'] ?? '');
        $this->assertSame('Payment/authorize', $logs[0]['context']['paynl_api'] ?? '');
        $this->assertSame('https://payment.pay.nl/v1/Payment/authorize/json', $logs[0]['context']['paynl_endpoint'] ?? '');
        $this->assertSame('MIT', $logs[0]['context']['transaction_type'] ?? '');
        $this->assertSame('token', $logs[0]['context']['payment_method'] ?? '');
        $this->assertSame('yes', $logs[0]['context']['has_token_id'] ?? '');
        $this->assertSame('1234', $logs[0]['context']['request_amount'] ?? '');
        $this->assertSame('EUR', $logs[0]['context']['request_currency'] ?? '');
        $this->assertSame('WSZ-R10488', $logs[0]['context']['request_reference'] ?? '');
        $this->assertSame('200', $logs[0]['context']['status_code'] ?? '');
        $this->assertSame('PAY-1404', $logs[0]['context']['request_error_id'] ?? '');
        $this->assertSame('permissionDenied', $logs[0]['context']['request_error_tag'] ?? '');
        $this->assertSame('No rights to perform this action', $logs[0]['context']['request_error_message'] ?? '');
    }

    public function test_paynl_recurring_charge_logs_warning_when_paid_response_has_no_transaction_id(): void
    {
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array(
            'token_code' => 'AT-1234-5678',
            'api_token' => 'test-api-token',
            'service_id' => 'SL-1234-5678',
        );
        $GLOBALS['wsz_paynl_test_http_response'] = array(
            'response' => array('code' => 200),
            'body' => '{"state":"paid","links":{"status":"https:\/\/example.test\/status"}}',
        );

        $integration = new WSZ_PayNL_Gateway_Integration();

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order->method('get_id')->willReturn(10488);
        $renewal_order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(10487);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $logs = $GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs'] ?? array();

        $this->assertTrue($result['paid']);
        $this->assertSame('', $result['transaction_id'] ?? '');
        $this->assertSame('PAY.nl recurring charge approved without a transaction identifier.', $logs[0]['message'] ?? '');
        $this->assertSame('10488', $logs[0]['context']['renewal_order_id'] ?? '');
        $this->assertSame('10487', $logs[0]['context']['subscription_id'] ?? '');
        $this->assertSame('state', $logs[0]['context']['response_keys'][0] ?? '');
        $this->assertSame('links', $logs[0]['context']['response_keys'][1] ?? '');
    }

    public function test_paynl_recurring_charge_empty_transaction_warning_includes_request_context(): void
    {
        $GLOBALS['wsz_paynl_test_plugin_credentials'] = array(
            'token_code' => 'AT-1234-5678',
            'api_token' => 'test-api-token',
            'service_id' => 'SL-1234-5678',
        );
        $GLOBALS['wsz_paynl_test_http_response'] = array(
            'response' => array('code' => 200),
            'body' => '{"request":{"result":1,"errorId":"","errorMessage":""}}',
        );

        $integration = new WSZ_PayNL_Gateway_Integration();

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order->method('get_id')->willReturn(10488);
        $renewal_order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);

        $subscription = $this->createMock(WC_Order::class);
        $subscription->method('get_id')->willReturn(10487);

        $result = $integration->charge_recurring_payment(
            'VY-9212-9171-2390',
            12.34,
            'EUR',
            $renewal_order,
            $subscription
        );

        $logs = $GLOBALS['wsz_admin_test_options']['wsz_subs_diagnostic_logs'] ?? array();

        $this->assertTrue($result['paid']);
        $this->assertSame('', $result['transaction_id'] ?? '');
        $this->assertSame('1', $logs[0]['context']['request_result'] ?? '');
        $this->assertSame('request', $logs[0]['context']['response_keys'][0] ?? '');
    }

    public function test_paynl_token_can_be_recovered_from_parent_order_meta(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        $order_meta = array();

        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn(10486);
        $order->method('get_customer_id')->willReturn(5);
        $order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $order
            ->method('get_meta_data')
            ->willReturn(
                array(
                    array(
                        'key' => '_paynl_recurring_id',
                        'value' => 'VY-9212-9171-2390',
                    ),
                )
            );
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

        $token_id = $integration->store_recurring_payment_token_from_order_meta($order);

        $this->assertGreaterThan(0, $token_id);
        $this->assertGreaterThan(0, $order_meta['_payment_token_id'] ?? 0);
        $this->assertSame('VY-9212-9171-2390', $order_meta['_wsz_paynl_recurring_id'] ?? '');
        $this->assertSame('order_meta', $order_meta['_wsz_paynl_recurring_source'] ?? '');
        $this->assertNotEmpty($order_meta['_wsz_paynl_recurring_captured_at'] ?? '');
        $this->assertSame('no', $order_meta['_wsz_paynl_recurring_missing_logged'] ?? '');
    }

    public function test_paynl_token_recovery_ignores_recurring_token_hash_without_recurring_id(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();

        $order = $this->createMock(WC_Order::class);
        $order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $order
            ->method('get_meta_data')
            ->willReturn(
                array(
                    array(
                        'key' => '_paynl_recurring_token',
                        'value' => 'c1747bf4d38cd6af76ca0d2ffe373987b666305e27dd8b5501a5facf90a99bffe',
                    ),
                )
            );
        $order
            ->expects($this->never())
            ->method('update_meta_data');

        $this->assertSame(0, $integration->store_recurring_payment_token_from_order_meta($order));
    }

    public function test_paynl_token_recovery_ignores_internal_missing_token_markers(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();

        $order = $this->createMock(WC_Order::class);
        $order->method('get_payment_method')->willReturn(WSZ_PayNL_Gateway_Integration::GATEWAY_ID);
        $order
            ->method('get_meta_data')
            ->willReturn(
                array(
                    array(
                        'key' => '_wsz_paynl_recurring_missing_logged',
                        'value' => 'yes',
                    ),
                    array(
                        'key' => '_wsz_paynl_recurring_missing_check_scheduled',
                        'value' => 'yes',
                    ),
                )
            );
        $order
            ->expects($this->never())
            ->method('update_meta_data');

        $this->assertSame(0, $integration->store_recurring_payment_token_from_order_meta($order));
    }
}
