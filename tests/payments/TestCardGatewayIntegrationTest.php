<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('add_action')) {
    function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway
    {
        public string $id = '';

        public string $method_title = '';

        public string $method_description = '';

        public bool $has_fields = false;

        public string $enabled = 'no';

        public string $title = '';

        public string $description = '';

        /** @var array<int,string> */
        public array $supports = array();

        /** @var array<string,mixed> */
        public array $form_fields = array();

        /** @var array<string,mixed> */
        protected array $settings = array();

        public function init_settings(): void
        {
            $this->settings = array();
        }

        public function get_option($key, $default = '')
        {
            return $this->settings[$key] ?? $default;
        }

        public function process_admin_options(): bool
        {
            return true;
        }

        public function get_return_url($order = null): string
        {
            return 'https://example.test/order-received';
        }
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id)
    {
        return $GLOBALS['wsz_test_card_orders'][(int) $order_id] ?? null;
    }
}

require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-test-card-gateway.php';

final class TestCardGatewayIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wsz_test_card_orders'] = array();
        $GLOBALS['wsz_subs_test_card_transactions'] = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_test_card_orders']);
        unset($GLOBALS['wsz_subs_test_card_transactions']);

        parent::tearDown();
    }

    public function test_register_gateway_adds_test_card_gateway_class(): void
    {
        $integration = new WSZ_Test_Card_Gateway_Integration();

        $gateways = $integration->register_gateway(array('WC_Gateway_BACS'));

        $this->assertContains('WSZ_Test_Card_Gateway', $gateways);
        $this->assertTrue(class_exists('WSZ_Test_Card_Gateway'));
    }

    public function test_scheduled_payment_marks_unpaid_renewal_order_as_paid(): void
    {
        $integration = new WSZ_Test_Card_Gateway_Integration();

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order
            ->method('is_paid')
            ->willReturn(false);

        $renewal_order
            ->expects($this->once())
            ->method('payment_complete')
            ->with($this->stringStartsWith('wsz_test_card_renewal_'));

        $renewal_order
            ->expects($this->once())
            ->method('add_order_note');

        $integration->process_scheduled_payment(19.99, $renewal_order);
    }

    public function test_scheduled_payment_logs_transaction_for_subscription(): void
    {
        $integration = new WSZ_Test_Card_Gateway_Integration();

        $renewal_order = $this->createMock(WC_Order::class);
        $renewal_order
            ->method('is_paid')
            ->willReturn(false);

        $renewal_order
            ->method('get_id')
            ->willReturn(812);

        $renewal_order
            ->method('get_status')
            ->willReturn('completed');

        $renewal_order
            ->method('get_total')
            ->willReturn(25.0);

        $renewal_order
            ->method('get_meta')
            ->willReturnCallback(
                static function ($key, $single = true) {
                    if ('_wsz_subscription_id' === $key) {
                        return 44;
                    }

                    return '';
                }
            );

        $renewal_order
            ->expects($this->once())
            ->method('payment_complete')
            ->with($this->stringStartsWith('wsz_test_card_renewal_'));

        $renewal_order
            ->expects($this->once())
            ->method('add_order_note');

        $integration->process_scheduled_payment(25.0, $renewal_order);

        $transactions = WSZ_Test_Card_Gateway_Integration::get_transactions(44, 10);

        $this->assertCount(1, $transactions);
        $this->assertSame('renewal', $transactions[0]['context'] ?? '');
        $this->assertSame(44, (int) ($transactions[0]['subscription_id'] ?? 0));
        $this->assertSame(812, (int) ($transactions[0]['order_id'] ?? 0));
    }

    public function test_gateway_process_payment_returns_success(): void
    {
        $integration = new WSZ_Test_Card_Gateway_Integration();
        $integration->register_gateway(array());

        if (!class_exists('WSZ_Test_Card_Gateway')) {
            $this->markTestSkipped('WSZ test card gateway class is not available.');
        }

        $order = $this->createMock(WC_Order::class);
        $order
            ->method('is_paid')
            ->willReturn(false);

        $order
            ->expects($this->once())
            ->method('payment_complete')
            ->with($this->stringStartsWith('wsz_test_card_'));

        $order
            ->expects($this->once())
            ->method('add_order_note');

        $GLOBALS['wsz_test_card_orders'][77] = $order;

        $gateway = new WSZ_Test_Card_Gateway();
        $result = $gateway->process_payment(77);

        $this->assertSame('success', $result['result'] ?? '');
        $this->assertSame('https://example.test/order-received', $result['redirect'] ?? '');
    }
}
