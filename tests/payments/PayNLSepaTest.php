<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
$sepaFile = dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-sepa.php';
if (is_file($sepaFile)) {
    require_once $sepaFile;
}
require_once dirname(__DIR__, 2) . '/includes/class-wsz-checkout-handler.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-retry-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-sepa-checkout.php';
if (!defined('WC_VERSION')) { define('WC_VERSION', '10.7.0'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('get_woocommerce_currency')) {
    function get_woocommerce_currency() { return 'EUR'; }
}
if (!function_exists('woocommerce_register_additional_checkout_field')) {
    function woocommerce_register_additional_checkout_field($field) { $GLOBALS['wsz_sepa_fields'][] = $field; }
}
if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
    function woocommerce_store_api_register_endpoint_data($data) { $GLOBALS['wsz_sepa_cart_data'] = $data; }
}
if (!class_exists('Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartSchema')) {
    class SepaTestCartSchema { public const IDENTIFIER = 'cart'; }
    class_alias(SepaTestCartSchema::class, 'Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartSchema');
}
if (!class_exists('Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')) {
    class SepaTestRouteException extends RuntimeException {
        public function __construct($code, $message, $status) { parent::__construct($message, $status); }
    }
    class_alias(SepaTestRouteException::class, 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException');
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '') {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return 'wsz_subs_options' === $name ? ($GLOBALS['wsz_subs_test_options'] ?? $default) : ($GLOBALS['wsz_admin_test_options'][$name] ?? $default);
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') { return 'unit-test-salt-for-sepa-not-a-real-site-secret'; }
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array()) {
        $GLOBALS['wsz_sepa_http'][] = array('url' => $url, 'args' => $args);
        return ($GLOBALS['wsz_sepa_response'])($url, $args);
    }
}

final class PayNLSepaTest extends TestCase
{
    private $manager;
    private $adapter;
    private $parent;
    private $subscription;
    private $renewal;

    protected function setUp(): void
    {
        $GLOBALS['wsz_test_filters'] = array();
        $GLOBALS['wsz_test_actions'] = array();
        $GLOBALS['wsz_test_execute_actions'] = true;
        $GLOBALS['wsz_sepa_http'] = array();
        $GLOBALS['wsz_wc_test_container'] = new class {
            public $cart = null;
            public function api_request_url($endpoint) { return 'https://shop.example/?wc-api=' . $endpoint; }
        };
        $GLOBALS['wsz_admin_test_options'] = array();
        $GLOBALS['wsz_subs_test_options'] = array('enable_paynl_sepa' => 'yes', 'paynl_sepa_creditor_name' => 'Example Shop', 'paynl_sepa_creditor_id' => 'NL00ZZZ123456780000', 'paynl_sepa_terms_url' => 'https://shop.example/mandate', 'paynl_sepa_notice_days' => 14);
        $this->assertTrue(class_exists('WSZ_PayNL_SEPA'), 'PAY.nl SEPA integration is missing.');
        $this->manager = $this->getMockBuilder(WSZ_Subscription_Manager::class)->onlyMethods(array('get_subscription', 'acquire_lock', 'release_lock'))->getMock();
        $this->manager->method('acquire_lock')->willReturn(true);
        $this->manager->method('get_subscription')->willReturnCallback(fn ($id) => $id === 2 ? $this->subscription : null);
        $this->adapter = new WSZ_PayNL_SEPA($this->manager);
        $this->parent = new SepaTestOrder(1, 'shop_order', 18.75, 'processing');
        $this->subscription = new SepaTestOrder(2, 'shop_subscription', 18.75, 'active');
        $this->renewal = new SepaTestOrder(3, 'shop_order', 23.47);
        $this->parent->update_meta_data('_wsz_subscription_ids', array(2));
        $this->subscription->update_meta_data('_wsz_parent_order_id', 1);
        $this->subscription->update_meta_data('_requires_manual_renewal', 'yes');
        $this->subscription->update_meta_data('_wsz_next_payment', '2026-11-22 00:00:00');
        $this->renewal->update_meta_data('_wsz_subscription_id', 2);
        $GLOBALS['wsz_subs_test_orders'] = array(1 => $this->parent, 2 => $this->subscription, 3 => $this->renewal);
        add_filter('wsz_subs_paynl_recurring_credentials', static fn () => array('service_id' => 'SL-1111-2222', 'username' => 'AT-1111-2222', 'password' => 'mock-only'));
        add_filter('wsz_subs_paynl_sepa_send_notice', static fn () => true);
        $GLOBALS['wsz_sepa_response'] = fn ($url, $args) => $this->response($url, $args);
    }

    protected function tearDown(): void
    {
        foreach (array('wsz_test_filters', 'wsz_test_actions', 'wsz_test_execute_actions', 'wsz_sepa_http', 'wsz_subs_test_options', 'wsz_admin_test_options', 'wsz_subs_test_orders', 'wsz_sepa_response', 'wsz_wc_test_container', 'wsz_sepa_status', 'wsz_sepa_fields', 'wsz_sepa_cart_data') as $key) { unset($GLOBALS[$key]); }
    }

    private function authorize(): void
    {
        WSZ_PayNL_SEPA_Context::capture($this->parent, array('iban' => 'NL91ABNA0417164300', 'bic' => 'ABNANL2A', 'owner' => 'Test Customer', 'consent' => true));
        $this->adapter->sync_paid_parent(1);
    }

    public function test_rejected_status_97_stops_automatic_collection(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $GLOBALS['wsz_sepa_status'] = 97;
        $this->assertTrue($this->adapter->handle_exchange(array('extra1' => '3', 'mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333')));
        $this->assertSame('failed', $this->renewal->get_meta('_wsz_paynl_sepa_state'));
        $this->assertSame('no', $this->renewal->get_meta('_wsz_async_payment_pending'));
        $this->assertSame('on-hold', $this->subscription->get_status());
        $this->assertSame('yes', $this->subscription->get_meta('_requires_manual_renewal'));
    }

    public function test_first_bank_exchange_can_identify_order_without_provider_extra1(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $creation = json_decode($GLOBALS['wsz_sepa_http'][0]['args']['body'], true);
        parse_str((string) parse_url($creation['exchangeUrl'], PHP_URL_QUERY), $query);
        $this->assertSame('3', (string) ($query['wsz_order_id'] ?? ''), 'The notification URL must identify the candidate order.');
        $GLOBALS['wsz_sepa_status'] = 100;
        $GLOBALS['wsz_sepa_response'] = function ($url, $args) {
            $response = $this->response($url, $args);
            if (str_contains($url, '/directdebits/IL-')) {
                $data = json_decode($response['body'], true);
                $data['stats']['extra1'] = '';
                $response['body'] = json_encode($data);
            }
            return $response;
        };
        $this->assertTrue($this->adapter->handle_exchange(array('mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333'), 3));
        $this->assertTrue($this->renewal->is_paid());
        $gets = array_filter($GLOBALS['wsz_sepa_http'], static fn ($r) => $r['args']['method'] === 'GET');
        $this->assertCount(2, $gets, 'Missing bank reference requires independently verified mandate and debit.');
    }

    public function test_first_exchange_without_extra1_still_rejects_a_different_mandate_reference(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $GLOBALS['wsz_sepa_status'] = 100;
        $GLOBALS['wsz_sepa_response'] = function ($url, $args) {
            $response = $this->response($url, $args);
            $data = json_decode($response['body'], true);
            if (str_contains($url, '/directdebits/IL-')) { $data['stats']['extra1'] = ''; }
            if (str_contains($url, '/directdebits/mandates/')) { $data['reference'] = 'WSZ-R999'; }
            $response['body'] = json_encode($data);
            return $response;
        };
        $this->assertFalse($this->adapter->handle_exchange(array('mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333'), 3));
        $this->assertFalse($this->renewal->is_paid());
    }

    public function test_missing_first_reference_can_recover_an_ambiguous_create_without_a_second_post(): void
    {
        $this->authorize();
        $GLOBALS['wsz_sepa_response'] = static fn () => array('response' => array('code' => 504), 'body' => '{}');
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $GLOBALS['wsz_sepa_status'] = 100;
        $GLOBALS['wsz_sepa_response'] = function ($url, $args) {
            $response = $this->response($url, $args);
            if (str_contains($url, '/directdebits/IL-')) {
                $data = json_decode($response['body'], true);
                $data['stats']['extra1'] = '';
                $response['body'] = json_encode($data);
            }
            return $response;
        };
        $this->assertTrue($this->adapter->handle_exchange(array('mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333'), 3));
        $this->assertTrue($this->renewal->is_paid());
        $posts = array_filter($GLOBALS['wsz_sepa_http'], static fn ($r) => 'POST' === $r['args']['method']);
        $this->assertCount(1, $posts);
    }

    public function test_merchant_can_supply_and_record_authorization_wording(): void
    {
        add_filter('wsz_subs_paynl_sepa_consent_text', static fn () => 'Merchant-approved authorization for this subscription.');
        $this->authorize();
        $auth = WSZ_PayNL_SEPA_Context::read($this->parent);
        $this->assertSame('Merchant-approved authorization for this subscription.', $auth['consent_text']);
    }

    public function test_native_provider_option_137_controls_sepa_capability(): void
    {
        $process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/paynl-sepa-provider-availability.php'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($result['unavailable']);
        $this->assertTrue($result['available']);
        $this->assertTrue($result['explicit']);
        $this->assertSame(array(137, 137, 137), $result['checked_ids']);
    }

    public function test_audit_reports_valid_local_bank_authorization_without_exposing_it(): void
    {
        require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-audit.php';
        $this->authorize();
        $before = $this->subscription->meta;
        $row = (new WSZ_PayNL_Audit($this->manager))->inspect($this->subscription);
        $this->assertSame('sepa_authorization_valid_locally', $row['local_context']);
        $this->assertFalse($row['automatic_needs_review']);
        $this->assertStringNotContainsString('NL91ABNA0417164300', json_encode($row));
        $this->assertSame($before, $this->subscription->meta);
    }

    public function test_audit_flags_an_unavailable_provider_without_changing_authorization(): void
    {
        require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-audit.php';
        $this->authorize();
        $before = $this->subscription->meta;
        add_filter('wsz_subs_paynl_sepa_provider_available', static fn () => false);
        $row = (new WSZ_PayNL_Audit($this->manager))->inspect($this->subscription);
        $this->assertSame('sepa_provider_unavailable', $row['local_context']);
        $this->assertTrue($row['automatic_needs_review']);
        $this->assertSame($before, $this->subscription->meta);
    }

    public function test_unavailable_provider_sepa_is_not_promised_at_checkout(): void
    {
        add_filter('wsz_subs_paynl_sepa_provider_available', static fn () => false);
        $this->assertFalse(WSZ_PayNL_SEPA_Checkout::available('pay_gateway_ideal'));
        $this->assertNotContains('pay_gateway_ideal', (new WSZ_PayNL_SEPA_Checkout($this->manager))->register_automatic_gateway(array()));
    }

    public function test_provider_unavailable_after_checkout_cannot_enable_automatic_renewal(): void
    {
        WSZ_PayNL_SEPA_Context::capture($this->parent, array('iban' => 'NL91ABNA0417164300', 'bic' => 'ABNANL2A', 'owner' => 'Test Customer', 'consent' => true));
        add_filter('wsz_subs_paynl_sepa_provider_available', static fn () => false);
        $this->adapter->sync_paid_parent(1);
        $this->assertTrue($this->manager->is_manual_renewal($this->subscription));
        $this->assertSame('', $this->subscription->get_meta('_wsz_paynl_sepa_setup_complete'));
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
    }

    public function test_provider_unavailable_at_renewal_falls_back_without_a_bank_request(): void
    {
        $this->authorize();
        add_filter('wsz_subs_paynl_sepa_provider_available', static fn () => false);
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
        $this->assertTrue($this->manager->is_manual_renewal($this->subscription));
        $this->assertSame('pending', $this->renewal->get_status());
        $this->assertStringContainsString('unavailable', strtolower(implode(' ', $this->renewal->notes)));
    }

    public function test_custom_sepa_credentials_are_bound_to_the_authorization_and_mandate(): void
    {
        add_filter('wsz_subs_paynl_sepa_credentials', static fn () => array('service_id' => 'SL-2222-3333', 'username' => 'AT-2222-3333', 'password' => 'custom-mock'));
        $this->authorize();
        $this->assertSame('SL-2222-3333', WSZ_PayNL_SEPA_Context::read($this->subscription)['service_id']);
        $GLOBALS['wsz_sepa_response'] = function ($url, $args) {
            $response = $this->response($url, $args);
            $data = json_decode($response['body'], true);
            $data['service']['code'] = 'SL-2222-3333';
            $response['body'] = json_encode($data);
            return $response;
        };
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $payload = json_decode($GLOBALS['wsz_sepa_http'][0]['args']['body'], true);
        $this->assertSame('SL-2222-3333', $payload['serviceId']);
        $this->assertSame('pending', $this->renewal->get_meta('_wsz_paynl_sepa_state'));
    }

    public function test_renewal_cannot_submit_to_a_different_sales_location_than_authorized(): void
    {
        add_filter('wsz_subs_paynl_sepa_credentials', static function ($credentials, $order) {
            if ($order->get_id() === 3) { $credentials['service_id'] = 'SL-2222-3333'; }
            return $credentials;
        }, 10, 2);
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
        $this->assertTrue($this->manager->is_manual_renewal($this->subscription));
        $this->assertStringContainsString('credentials', strtolower(implode(' ', $this->renewal->notes)));
    }

    public function test_live_authorization_records_its_environment(): void
    {
        $this->authorize();
        $this->assertSame('live', WSZ_PayNL_SEPA_Context::read($this->subscription)['environment'] ?? '');
    }

    public function test_existing_authorization_without_environment_remains_compatible(): void
    {
        $this->authorize();
        $auth = WSZ_PayNL_SEPA_Context::read($this->subscription);
        unset($auth['environment']);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = hash('sha256', 'wsz-paynl-sepa:' . wp_salt('auth'), true);
        $cipher = base64_encode($nonce . sodium_crypto_secretbox(json_encode($auth), $nonce, $key));
        $this->parent->update_meta_data(WSZ_PayNL_SEPA_Context::AUTH_META, $cipher);
        $this->subscription->update_meta_data(WSZ_PayNL_SEPA_Context::AUTH_META, $cipher);
        $this->assertTrue(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
    }

    public function test_test_mode_never_contacts_live_mandate_for_status_or_revocation(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $GLOBALS['wsz_admin_test_options']['paynl_test_mode'] = 'yes';
        $GLOBALS['wsz_sepa_status'] = 100;
        $this->assertFalse($this->adapter->handle_exchange(array('extra1' => '3', 'mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333')));
        $this->adapter->subscription_status_changed($this->subscription, 'cancelled', 'active');
        $this->assertCount(1, $GLOBALS['wsz_sepa_http'], 'A test site must not contact a live bank mandate.');
        $this->assertFalse($this->renewal->is_paid());
        $this->assertSame('', $this->subscription->get_meta('_wsz_paynl_sepa_revoked_at'));
    }

    public function test_empty_merchant_authorization_wording_disables_sepa_checkout(): void
    {
        add_filter('wsz_subs_paynl_sepa_consent_text', static fn () => '');
        $this->assertFalse(WSZ_PayNL_SEPA_Context::enabled());
        $this->assertFalse(WSZ_PayNL_SEPA_Checkout::available('pay_gateway_ideal'));
        $this->expectException(InvalidArgumentException::class);
        WSZ_PayNL_SEPA_Context::capture($this->parent, array('iban' => 'NL91ABNA0417164300', 'bic' => 'ABNANL2A', 'owner' => 'Test Customer', 'consent' => true));
    }

    public function test_a_new_sales_location_cannot_reconcile_the_old_mandate(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        add_filter('wsz_subs_paynl_sepa_credentials', static fn () => array('service_id' => 'SL-9999-9999', 'username' => 'AT-9999-9999', 'password' => 'other-mock'), 20);
        $GLOBALS['wsz_sepa_status'] = 100;
        $this->assertFalse($this->adapter->handle_exchange(array('extra1' => '3', 'mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333')));
        $this->assertFalse($this->renewal->is_paid());
        $this->assertCount(1, $GLOBALS['wsz_sepa_http'], 'No bank request may use credentials for a different sales location.');
    }

    public function test_mandate_revocation_does_not_treat_404_as_success(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $GLOBALS['wsz_sepa_response'] = static fn () => array('response' => array('code' => 404), 'body' => '{}');
        $this->adapter->subscription_status_changed($this->subscription, 'cancelled', 'active');
        $this->assertSame('', $this->subscription->get_meta('_wsz_paynl_sepa_revoked_at'));
        $this->assertStringContainsString('revocation is pending', implode(' ', $this->subscription->notes));
    }

    public function test_mandate_revocation_never_uses_a_different_sales_location(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        add_filter('wsz_subs_paynl_sepa_credentials', static fn () => array('service_id' => 'SL-9999-9999', 'username' => 'AT-9999-9999', 'password' => 'other-mock'), 20);
        $this->adapter->subscription_status_changed($this->subscription, 'cancelled', 'active');
        $this->assertCount(1, $GLOBALS['wsz_sepa_http'], 'The original mandate must not be sent to a different merchant context.');
        $this->assertSame('', $this->subscription->get_meta('_wsz_paynl_sepa_revoked_at'));
        $this->assertStringContainsString('revocation is pending', implode(' ', $this->subscription->notes));
    }

    public function test_late_paid_status_after_failure_holds_manual_payment_for_review(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $payload = array('extra1' => '3', 'mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333');
        $GLOBALS['wsz_sepa_status'] = 97;
        $this->adapter->handle_exchange($payload);
        $GLOBALS['wsz_sepa_status'] = 100;
        $this->adapter->handle_exchange($payload);
        $this->assertSame('failed', $this->renewal->get_meta('_wsz_paynl_sepa_state'));
        $this->assertSame('yes', $this->renewal->get_meta('_wsz_async_payment_pending'));
        $this->assertFalse($this->adapter->filter_needs_payment(true, $this->renewal));
        $this->assertStringContainsString('review', strtolower(implode(' ', $this->renewal->notes)));
        $this->assertSame('yes', $this->subscription->get_meta('_requires_manual_renewal'));
    }

    public function test_forged_hint_cannot_link_a_different_renewal_or_mandate(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $GLOBALS['wsz_sepa_status'] = 100;
        $this->assertFalse($this->adapter->handle_exchange(array('extra1' => '999', 'mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333'), 3));
        $this->assertFalse($this->renewal->is_paid());
    }

    public function test_stale_workers_cannot_submit_a_second_bank_collection(): void
    {
        $this->authorize();
        $stale_sub = clone $this->subscription;
        $stale_order = clone $this->renewal;
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->adapter->process_payment($stale_sub, $stale_order);
        $posts = array_filter($GLOBALS['wsz_sepa_http'], static fn ($request) => 'POST' === $request['args']['method']);
        $this->assertCount(1, $posts);
    }

    public function test_stale_or_suspended_subscription_cannot_submit_a_bank_collection(): void
    {
        $this->authorize();
        $stale_sub = clone $this->subscription;
        $this->subscription->status = 'on-hold';
        $this->adapter->process_payment($stale_sub, $this->renewal);
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
    }

    public function test_authorization_is_encrypted_and_bound_only_after_initial_ideal_is_paid(): void
    {
        $this->parent->status = 'pending';
        $this->authorize();
        $this->assertSame('yes', $this->subscription->get_meta('_requires_manual_renewal'));
        $this->assertStringNotContainsString('NL91ABNA0417164300', json_encode($this->parent->meta));
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
        $this->parent->status = 'processing';
        $this->adapter->sync_paid_parent(1);
        $this->assertSame('no', $this->subscription->get_meta('_requires_manual_renewal'));
        $this->assertTrue(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
        $this->assertCount(0, $GLOBALS['wsz_sepa_http'], 'Signup must not create an extra bank debit.');
        $this->subscription->customer = 99;
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
    }

    public function test_first_renewal_creates_flexible_mandate_with_actual_order_total_once(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $requests = $GLOBALS['wsz_sepa_http'];
        $posts = array_values(array_filter($requests, fn ($r) => 'POST' === $r['args']['method']));
        $this->assertCount(1, $posts);
        $this->assertSame('https://rest.pay.nl/v2/directdebits/mandates', $posts[0]['url']);
        $payload = json_decode($posts[0]['args']['body'], true);
        $this->assertSame('FLEXIBLE', $payload['type']);
        $this->assertSame(array('value' => 2347, 'currency' => 'EUR'), $payload['amount']);
        $this->assertArrayNotHasKey('interval', $payload, 'Only WSZ owns the billing schedule.');
        $this->assertGreaterThanOrEqual(time() + 13 * DAY_IN_SECONDS, strtotime($payload['processDate']));
        $this->assertFalse($this->renewal->is_paid());
        $this->assertSame('on-hold', $this->renewal->status);
        $this->assertSame('yes', $this->renewal->get_meta('_wsz_async_payment_pending'));
    }

    public function test_verified_bank_collection_completes_cycle_once_and_next_renewal_uses_new_total(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $completed = 0;
        add_action('wsz_subs_async_renewal_payment_complete', static function ($sub, $order) use (&$completed) {
            ++$completed;
            $order->update_meta_data('_wsz_cycle_completed', 'yes');
        }, 10, 2);
        $this->adapter->handle_exchange(array('mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333', 'extra1' => '3', 'action' => 'incassocollected'));
        $this->assertFalse($this->renewal->is_paid(), 'An unverified collected action cannot mark paid.');
        $GLOBALS['wsz_sepa_status'] = 100;
        $payload = array('mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333', 'extra1' => '3');
        $this->adapter->handle_exchange($payload);
        $this->adapter->handle_exchange($payload);
        $this->assertTrue($this->renewal->is_paid());
        $this->assertSame(1, $completed);
        $this->assertSame('active', $this->subscription->get_meta('_wsz_paynl_sepa_mandate_state'));
        // The renewal engine clears this only after accounting for the settled cycle.
        $this->subscription->update_meta_data('_wsz_pending_renewal_order_id', 0);
        $this->renewal = new SepaTestOrder(4, 'shop_order', 79.91);
        $this->renewal->update_meta_data('_wsz_subscription_id', 2);
        $GLOBALS['wsz_subs_test_orders'][4] = $this->renewal;
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $last = end($GLOBALS['wsz_sepa_http']);
        $this->assertSame('https://rest.pay.nl/v2/directdebits', $last['url']);
        $this->assertSame(array('value' => 7991, 'currency' => 'EUR'), json_decode($last['args']['body'], true)['amount']);
        unset($GLOBALS['wsz_sepa_status']);
    }

    public function test_unknown_request_outcome_is_held_without_resubmission(): void
    {
        $this->authorize();
        $GLOBALS['wsz_sepa_response'] = static fn () => array('response' => array('code' => 504), 'body' => '{}');
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(1, $GLOBALS['wsz_sepa_http']);
        $this->assertSame('review', $this->renewal->get_meta('_wsz_paynl_sepa_state'));
        $this->assertSame('yes', $this->renewal->get_meta('_wsz_async_payment_pending'));
    }

    public function test_test_mode_and_legacy_ideal_never_send_bank_requests(): void
    {
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
        $this->authorize();
        $GLOBALS['wsz_admin_test_options']['paynl_test_mode'] = 'yes';
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
    }

    public static function invalidAuthorizationProvider(): array
    {
        return array('no consent' => array('consent', false), 'bad IBAN' => array('iban', 'NL00INVALID0000000000'),
            'bad BIC' => array('bic', 'invalid'), 'no holder' => array('owner', ''));
    }

    /** @dataProvider invalidAuthorizationProvider */
    public function test_invalid_authorization_is_rejected(string $key, $value): void
    {
        $input = array('iban' => 'NL91ABNA0417164300', 'bic' => 'ABNANL2A', 'owner' => 'Test', 'consent' => true);
        $input[$key] = $value;
        $this->expectException(InvalidArgumentException::class);
        WSZ_PayNL_SEPA_Context::capture($this->parent, $input);
    }

    public function test_changed_gateway_currency_or_parent_cannot_reuse_bank_authorization(): void
    {
        $this->authorize();
        $this->subscription->gateway = 'pay_gateway_visa';
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
        $this->adapter->sync_paid_parent(1);
        $this->assertSame('pay_gateway_visa', $this->subscription->gateway);
        $this->subscription->gateway = 'pay_gateway_ideal';
        $this->subscription->currency = 'USD';
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
        $this->subscription->currency = 'EUR';
        $this->subscription->update_meta_data('_wsz_parent_order_id', 99);
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
    }

    public function test_multiple_subscriptions_linked_to_the_paid_parent_can_share_checkout_consent(): void
    {
        $this->authorize();
        $other = new SepaTestOrder(4, 'shop_subscription', 18.75, 'active');
        $other->update_meta_data('_wsz_parent_order_id', 1);
        $other->update_meta_data(WSZ_PayNL_SEPA_Context::AUTH_META, $this->parent->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META));
        $this->parent->update_meta_data('_wsz_subscription_ids', array(2, 4));
        $this->assertTrue(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
        $this->assertTrue(WSZ_PayNL_SEPA_Context::valid_for_subscription($other));
    }

    public function test_copied_bank_authorization_cannot_be_used_by_unlinked_subscription_of_same_customer(): void
    {
        $this->authorize();
        $other = new SepaTestOrder(4, 'shop_subscription', 18.75, 'active');
        $other->update_meta_data('_wsz_parent_order_id', 1);
        $other->update_meta_data(WSZ_PayNL_SEPA_Context::AUTH_META, $this->parent->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META));
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($other));
        $other_renewal = new SepaTestOrder(5, 'shop_order', 23.47);
        $other_renewal->update_meta_data('_wsz_subscription_id', 4);
        $this->adapter->process_payment($other, $other_renewal);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
    }

    public function test_tampered_or_copied_authorization_fails_closed(): void
    {
        $this->authorize();
        $cipher = $this->subscription->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META);
        $this->subscription->update_meta_data(WSZ_PayNL_SEPA_Context::AUTH_META, substr($cipher, 0, -5) . 'AAAAA');
        $this->assertSame(array(), WSZ_PayNL_SEPA_Context::read($this->subscription));
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
    }

    public static function mismatchedDebitProvider(): array
    {
        return array('amount' => array('amount', array('value' => 1, 'currency' => 'EUR')),
            'currency' => array('amount', array('value' => 2347, 'currency' => 'USD')),
            'merchant service' => array('service', array('code' => 'SL-9999-9999')),
            'order reference' => array('stats', array('extra1' => '999')),
            'mandate' => array('mandate', array('code' => 'IO-9999-9999-9999')));
    }

    /** @dataProvider mismatchedDebitProvider */
    public function test_forged_or_mismatched_exchange_cannot_settle(string $key, array $value): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $GLOBALS['wsz_sepa_response'] = function ($url, $args) use ($key, $value) {
            $response = $this->response($url, $args);
            $data = json_decode($response['body'], true);
            $data['status'] = array('code' => 100);
            $data[$key] = $value;
            $response['body'] = json_encode($data);
            return $response;
        };
        $this->assertFalse($this->adapter->handle_exchange(array('extra1' => '3', 'mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333')));
        $this->assertFalse($this->renewal->is_paid());
    }

    public function test_collection_notice_failure_prevents_any_submission(): void
    {
        $this->authorize();
        add_filter('wsz_subs_paynl_sepa_send_notice', static fn () => false, 20);
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
        $this->assertSame('yes', $this->subscription->get_meta('_requires_manual_renewal'));
    }

    public function test_zero_total_renewal_completes_without_a_bank_debit(): void
    {
        $this->authorize();
        $this->renewal->total = 0;
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertTrue($this->renewal->is_paid());
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
    }

    public function test_fully_paid_service_revokes_the_bank_mandate_once_without_expiring(): void
    {
        $this->authorize();
        $this->subscription->update_meta_data('_wsz_paynl_sepa_mandate_id', 'IO-1111-2222-3333');
        $this->subscription->update_meta_data('_wsz_total_payments', 6);
        $this->subscription->update_meta_data('_wsz_completed_payments', 6);
        $this->adapter->init();
        do_action('wsz_subs_subscription_payments_completed', $this->subscription);
        do_action('wsz_subs_subscription_payments_completed', $this->subscription);
        $this->assertSame('active', $this->subscription->get_status());
        $this->assertSame('revoked', $this->subscription->get_meta('_wsz_paynl_sepa_mandate_state'));
        $this->assertCount(1, $GLOBALS['wsz_sepa_http']);
        $this->assertSame('DELETE', $GLOBALS['wsz_sepa_http'][0]['args']['method']);
        $this->adapter->schedule_preparation($this->subscription);
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(1, $GLOBALS['wsz_sepa_http'], 'A stale job cannot submit another debit.');
    }

    public function test_single_payment_signup_needs_no_bank_collection_or_renewal_context(): void
    {
        $this->subscription->update_meta_data('_wsz_total_payments', 1);
        $this->subscription->update_meta_data('_wsz_completed_payments', 1);
        $this->authorize();
        $this->assertSame('active', $this->subscription->get_status());
        $this->assertSame('revoked', $this->subscription->get_meta('_wsz_paynl_sepa_mandate_state'));
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
    }

    public function test_cancelled_subscription_revokes_mandate_and_late_payment_never_restores_it(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->subscription->status = 'cancelled';
        $this->adapter->subscription_status_changed($this->subscription, 'cancelled', 'active');
        $this->assertSame('DELETE', end($GLOBALS['wsz_sepa_http'])['args']['method']);
        $GLOBALS['wsz_sepa_status'] = 100;
        $this->adapter->handle_exchange(array('extra1' => '3', 'mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333'));
        $this->assertSame('cancelled', $this->subscription->status);
        $this->assertSame('revoked', $this->subscription->get_meta('_wsz_paynl_sepa_mandate_state'));
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
    }

    public function test_bank_reversal_stops_further_collection_without_retry(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $GLOBALS['wsz_sepa_status'] = 100;
        $payload = array('extra1' => '3', 'mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333');
        $this->adapter->handle_exchange($payload);
        $GLOBALS['wsz_sepa_status'] = 106;
        $this->adapter->handle_exchange($payload);
        $this->assertSame('reversed', $this->renewal->get_meta('_wsz_paynl_sepa_state'));
        $this->assertSame('on-hold', $this->subscription->status);
        $this->assertSame('yes', $this->subscription->get_meta('_requires_manual_renewal'));
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
        $GLOBALS['wsz_sepa_status'] = 100;
        $this->adapter->handle_exchange($payload);
        $this->assertSame('failed', $this->renewal->status, 'An old paid event cannot reopen a reversed debit.');
    }

    public function test_subsequent_debit_is_blocked_until_the_first_one_settles(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $other = new SepaTestOrder(4, 'shop_order', 42.99);
        $other->update_meta_data('_wsz_subscription_id', 2);
        $this->adapter->process_payment($this->subscription, $other);
        $this->assertCount(1, $GLOBALS['wsz_sepa_http']);
        $this->assertFalse($other->is_paid());
    }

    private function subscription_cart(): void
    {
        $GLOBALS['wsz_wc_test_container']->cart = new class {
            public function get_cart() { return array(array('_wsz_subscription_period' => 'month')); }
        };
    }

    public function test_classic_checkout_requires_explicit_sepa_consent_only_for_subscription_ideal(): void
    {
        $this->subscription_cart();
        $checkout = new WSZ_PayNL_SEPA_Checkout($this->manager);
        $fields = $checkout->classic_fields(array());
        $this->assertCount(4, $fields['order']);
        $this->assertStringContainsString('eight weeks', $fields['order']['wsz_sepa_consent']['label']);
        $errors = new class {
            public array $codes = array();
            public function add($code, $message) { $this->codes[] = $code; }
        };
        $checkout->validate_classic(array('payment_method' => 'pay_gateway_visa'), $errors);
        $this->assertSame(array(), $errors->codes);
        $checkout->validate_classic(array('payment_method' => 'pay_gateway_ideal'), $errors);
        $this->assertSame(array('wsz_sepa_consent_required'), $errors->codes);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
    }

    public function test_classic_sepa_fields_render_as_required_without_changing_checkout_validation_rules(): void
    {
        $this->subscription_cart();
        $checkout = new WSZ_PayNL_SEPA_Checkout($this->manager);
        $checkout->init();
        $fields = $checkout->classic_fields(array());
        foreach (array('owner', 'iban', 'bic', 'consent') as $name) {
            $key = 'wsz_sepa_' . $name;
            $this->assertFalse($fields['order'][$key]['required'], 'WooCommerce must not require SEPA inputs for card payments.');
            $render_args = apply_filters('woocommerce_form_field_args', $fields['order'][$key], $key, null);
            $this->assertTrue($render_args['required'], 'The visible iDEAL SEPA input must be marked required.');
        }
        $other = array('required' => false, 'class' => array('form-row-wide'));
        $this->assertSame($other, apply_filters('woocommerce_form_field_args', $other, 'billing_phone', null));
        $this->assertSame($other, apply_filters('woocommerce_form_field_args', $other, 'wsz_sepa_iban', null), 'Unrelated fields must remain unchanged.');
    }

    public function test_store_api_captures_encrypted_authorization_and_removes_plaintext_extra_fields(): void
    {
        $this->subscription_cart();
        $this->parent->status = 'pending';
        $this->parent->update_meta_data('_wc_other/woo-subs-zero/sepa-iban', 'NL91ABNA0417164300');
        $checkout = new WSZ_PayNL_SEPA_Checkout($this->manager);
        $checkout->save_store_api($this->parent, array('payment_method' => 'pay_gateway_ideal', 'additional_fields' => array(
            'woo-subs-zero/sepa-iban' => 'NL91ABNA0417164300', 'woo-subs-zero/sepa-bic' => 'ABNANL2A',
            'woo-subs-zero/sepa-owner' => 'Test Customer', 'woo-subs-zero/sepa-consent' => true,
        )));
        $this->assertSame('', $this->parent->get_meta('_wc_other/woo-subs-zero/sepa-iban'));
        $this->assertNotEmpty(WSZ_PayNL_SEPA_Context::read($this->parent));
        $this->assertStringNotContainsString('NL91ABNA0417164300', json_encode($this->parent->meta));
        $this->assertSame('yes', $this->subscription->get_meta('_requires_manual_renewal'));
    }

    public function test_store_api_cannot_bypass_mandate_consent(): void
    {
        $this->subscription_cart();
        $this->expectExceptionMessage('explicit mandate consent');
        (new WSZ_PayNL_SEPA_Checkout($this->manager))->save_store_api($this->parent, array('payment_method' => 'pay_gateway_ideal'));
    }

    public function test_store_api_draft_encrypts_fields_without_accepting_a_mandate(): void
    {
        $this->subscription_cart();
        $this->parent->status = 'pending';
        $checkout = new WSZ_PayNL_SEPA_Checkout($this->manager);
        $checkout->init();
        $values = array('woo-subs-zero/sepa-iban' => 'NL91ABNA0417164300', 'woo-subs-zero/sepa-bic' => 'ABNANL2A', 'woo-subs-zero/sepa-owner' => 'Test Customer', 'woo-subs-zero/sepa-consent' => true);
        foreach ($values as $key => $value) { $this->parent->update_meta_data('_wc_other/' . $key, $value); }
        $request = new class(array('payment_method' => 'pay_gateway_ideal', 'additional_fields' => $values)) extends ArrayObject { public function get_method() { return 'PUT'; } };
        $checkout->save_store_api($this->parent, $request);
        $this->assertSame('', $this->parent->get_meta('_wc_other/woo-subs-zero/sepa-iban'));
        $this->assertSame('', $this->parent->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META), 'A draft is not mandate acceptance.');
        $this->assertStringNotContainsString('NL91ABNA0417164300', json_encode($this->parent->meta));
        $this->assertSame('NL91ABNA0417164300', apply_filters('woocommerce_get_default_value_for_woo-subs-zero/sepa-iban', null, 'other', $this->parent));
        $this->assertSame('yes', $this->subscription->get_meta('_requires_manual_renewal'));
    }

    public function test_partial_store_api_drafts_merge_and_final_post_clears_the_draft(): void
    {
        $this->subscription_cart();
        $this->parent->status = 'pending';
        $checkout = new WSZ_PayNL_SEPA_Checkout($this->manager);
        $checkout->init();
        $first = new class(array('payment_method' => 'pay_gateway_ideal', 'additional_fields' => array(
            'woo-subs-zero/sepa-owner' => 'Test Customer', 'woo-subs-zero/sepa-iban' => 'NL91ABNA0417164300',
        ))) extends ArrayObject { public function get_method() { return 'PUT'; } };
        $checkout->save_store_api($this->parent, $first);
        $second = new class(array('payment_method' => 'pay_gateway_ideal', 'additional_fields' => array(
            'woo-subs-zero/sepa-bic' => 'ABNANL2A', 'woo-subs-zero/sepa-consent' => true,
        ))) extends ArrayObject { public function get_method() { return 'PUT'; } };
        $checkout->save_store_api($this->parent, $second);
        $this->assertSame('NL91ABNA0417164300', apply_filters('woocommerce_get_default_value_for_woo-subs-zero/sepa-iban', null, 'other', $this->parent));
        $this->assertSame('1', apply_filters('woocommerce_get_default_value_for_woo-subs-zero/sepa-consent', null, 'other', $this->parent));
        $all = array('payment_method' => 'pay_gateway_ideal', 'additional_fields' => array(
            'woo-subs-zero/sepa-owner' => 'Test Customer', 'woo-subs-zero/sepa-iban' => 'NL91ABNA0417164300',
            'woo-subs-zero/sepa-bic' => 'ABNANL2A', 'woo-subs-zero/sepa-consent' => true,
        ));
        $checkout->save_store_api($this->parent, $all);
        $this->assertNotEmpty(WSZ_PayNL_SEPA_Context::read($this->parent));
        $this->assertSame('', $this->parent->get_meta(WSZ_PayNL_SEPA_Context::DRAFT_META));
        $this->assertStringNotContainsString('NL91ABNA0417164300', json_encode($this->parent->meta));
    }

    public function test_store_api_switching_away_from_ideal_clears_encrypted_draft(): void
    {
        $this->subscription_cart();
        $this->parent->status = 'pending';
        $checkout = new WSZ_PayNL_SEPA_Checkout($this->manager);
        $draft = new class(array('payment_method' => 'pay_gateway_ideal', 'additional_fields' => array('woo-subs-zero/sepa-iban' => 'NL91ABNA0417164300'))) extends ArrayObject { public function get_method() { return 'PUT'; } };
        $checkout->save_store_api($this->parent, $draft);
        $this->assertNotEmpty($this->parent->get_meta('_wsz_paynl_sepa_checkout_draft'));
        $changed = new class(array('payment_method' => 'pay_gateway_visa')) extends ArrayObject { public function get_method() { return 'PUT'; } };
        $checkout->save_store_api($this->parent, $changed);
        $this->assertSame('', $this->parent->get_meta('_wsz_paynl_sepa_checkout_draft'));
        $this->assertSame('', $this->parent->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META));
    }

    public function test_updating_a_draft_checkout_does_not_accept_a_mandate(): void
    {
        $this->subscription_cart();
        (new WSZ_PayNL_SEPA_Checkout($this->manager))->save_store_api($this->parent, new class { public function get_method() { return 'PUT'; } });
        $this->assertSame('', $this->parent->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META));
    }

    public function test_regular_checkout_and_card_token_classification_remain_unchanged(): void
    {
        $checkout = new WSZ_PayNL_SEPA_Checkout($this->manager);
        $this->assertSame(array('billing' => array()), $checkout->classic_fields(array('billing' => array())));
        $this->assertFalse(WSZ_PayNL_Gateway_Integration::is_supported_gateway_id('pay_gateway_ideal'));
        $this->assertContains('pay_gateway_ideal', $checkout->register_automatic_gateway(array()));
        $GLOBALS['wsz_admin_test_options']['paynl_test_mode'] = 'yes';
        $this->assertNotContains('pay_gateway_ideal', $checkout->register_automatic_gateway(array()));
    }

    public function test_block_fields_are_conditional_on_both_subscription_and_ideal(): void
    {
        $checkout = new WSZ_PayNL_SEPA_Checkout($this->manager);
        $checkout->register_block_fields();
        $this->assertCount(4, $GLOBALS['wsz_sepa_fields']);
        $field = $GLOBALS['wsz_sepa_fields'][0];
        $this->assertSame('pay_gateway_ideal', $field['required']['properties']['checkout']['properties']['payment_method']['const']);
        $this->assertTrue($field['required']['properties']['cart']['properties']['extensions']['properties']['woo-subs-zero']['properties']['has_subscription']['const']);
        $this->assertSame($field['required'], $field['hidden']['not']);
        $this->assertSame(array('has_subscription' => false), ($GLOBALS['wsz_sepa_cart_data']['data_callback'])());
        $this->subscription_cart();
        $this->assertSame(array('has_subscription' => true), ($GLOBALS['wsz_sepa_cart_data']['data_callback'])());
    }

    public function test_original_notice_period_and_creditor_are_preserved(): void
    {
        $this->authorize();
        $GLOBALS['wsz_subs_test_options']['paynl_sepa_notice_days'] = 1;
        $this->assertSame(14, WSZ_PayNL_SEPA_Context::authorized_notice_days($this->subscription));
        $GLOBALS['wsz_subs_test_options']['paynl_sepa_creditor_id'] = 'NL00ZZZ999999990000';
        $this->assertFalse(WSZ_PayNL_SEPA_Context::valid_for_subscription($this->subscription));
    }

    public function test_pending_bank_order_does_not_offer_a_second_customer_payment(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertFalse($this->adapter->filter_needs_payment(true, $this->renewal));
        $this->assertTrue($this->adapter->filter_needs_payment(true, $this->parent));
    }

    public function test_non_eur_renewal_requires_manual_recovery_without_a_bank_request(): void
    {
        $this->authorize();
        $this->renewal->currency = 'USD';
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertCount(0, $GLOBALS['wsz_sepa_http']);
        $this->assertSame('yes', $this->subscription->get_meta('_requires_manual_renewal'));
    }

    public function test_bank_orders_have_a_separate_gateway_from_initial_ideal(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertSame('pay_gateway_ideal', $this->parent->get_payment_method());
        $this->assertSame('wsz_paynl_sepa', $this->renewal->get_payment_method());
    }

    public function test_payment_handler_dispatches_sepa_using_renewal_total_without_card_tokens(): void
    {
        $this->authorize();
        (new WSZ_Payment_Handler($this->manager))->dispatch_scheduled_payment($this->subscription, $this->renewal, 9999.0);
        $payload = json_decode($GLOBALS['wsz_sepa_http'][0]['args']['body'], true);
        $this->assertSame(2347, $payload['amount']['value']);
        $this->assertSame('FLEXIBLE', $payload['type']);
        $this->assertSame('', $this->subscription->get_meta('_payment_token_id'));
    }

    public function test_ambiguous_create_can_recover_from_independently_verified_bank_exchange(): void
    {
        $this->authorize();
        $GLOBALS['wsz_sepa_response'] = static fn () => array('response' => array('code' => 504), 'body' => '{}');
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $GLOBALS['wsz_sepa_response'] = fn ($url, $args) => $this->response($url, $args);
        $GLOBALS['wsz_sepa_status'] = 100;
        $this->assertTrue($this->adapter->handle_exchange(array('extra1' => '3', 'mandateId' => 'IO-1111-2222-3333', 'referenceId' => 'IL-1111-2222-3333')));
        $this->assertTrue($this->renewal->is_paid());
        $posts = array_filter($GLOBALS['wsz_sepa_http'], static fn ($r) => $r['args']['method'] === 'POST');
        $this->assertCount(1, $posts, 'Recovery must never create a second mandate/debit.');
    }

    public function test_bank_pending_orders_are_excluded_from_card_retry_queue(): void
    {
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $retry = new WSZ_Retry_Manager($this->manager, new WSZ_Payment_Handler($this->manager));
        $this->assertFalse($retry->queue_retry($this->subscription, $this->renewal));
        $retry->process_retry(2, 3, 1);
        $this->assertCount(1, $GLOBALS['wsz_sepa_http']);
    }

    public function test_generic_webhook_cannot_mark_a_bank_order_paid_even_with_order_key_and_filter(): void
    {
        $process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/paynl-sepa-generic-webhook.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $this->assertStringContainsString('sepa_requires_verified_bank_exchange', $output);
        $this->assertStringContainsString('status=on-hold', $output);
    }

    public function test_collection_notice_uses_actual_total_creditor_and_reference_without_full_iban(): void
    {
        $this->authorize();
        $notice = '';
        add_filter('wsz_subs_paynl_sepa_send_notice', static function ($result, $to, $message) use (&$notice) { $notice = $message; return true; }, 20, 3);
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertStringContainsString('EUR 23.47', $notice);
        $this->assertStringContainsString('creditor ID NL00ZZZ123456780000', $notice);
        $this->assertStringContainsString('Mandate reference: WSZ-R3', $notice);
        $this->assertStringNotContainsString('NL91ABNA0417164300', $notice);
        $this->assertStringContainsString("\nSubscription:", $notice);
    }

    public function test_status_refresh_action_is_offered_only_for_bank_renewal_orders(): void
    {
        $this->assertSame(array(), $this->adapter->order_actions(array(), $this->parent));
        $this->authorize();
        $this->adapter->process_payment($this->subscription, $this->renewal);
        $this->assertArrayHasKey('wsz_paynl_sepa_reconcile', $this->adapter->order_actions(array(), $this->renewal));
    }

    public function test_replayed_signup_callback_preserves_a_later_manual_renewal_choice(): void
    {
        $this->authorize();
        $this->manager->set_manual_renewal($this->subscription, true);
        $this->adapter->sync_paid_parent(1);
        (new WSZ_Payment_Handler($this->manager))->update_subscription_payment_context($this->subscription, 0, 'pay_gateway_ideal');
        $this->assertSame('yes', $this->subscription->get_meta('_requires_manual_renewal'));
    }

    private function response($url, $args): array
    {
        $data = array('code' => 'IO-1111-2222-3333', 'type' => 'FLEXIBLE', 'reference' => 'WSZ-R3', 'service' => array('code' => 'SL-1111-2222'), 'customer' => array('bankAccount' => array('iban' => 'NL91ABNA0417164300')));
        if (str_contains($url, '/directdebits/IL-') || str_ends_with($url, '/directdebits')) {
            $data = array('id' => 'IL-1111-2222-3333', 'mandate' => array('code' => 'IO-1111-2222-3333'), 'service' => array('code' => 'SL-1111-2222'), 'amount' => array('value' => (int) round($this->renewal->total * 100), 'currency' => 'EUR'), 'stats' => array('extra1' => (string) $this->renewal->get_id()), 'status' => array('code' => $GLOBALS['wsz_sepa_status'] ?? 91), 'declined' => false);
        }
        return array('response' => array('code' => 200), 'body' => json_encode($data));
    }
}

final class SepaTestOrder extends WC_Order
{
    public array $meta = array();
    public array $notes = array();
    public int $customer = 5;
    public string $gateway = 'pay_gateway_ideal';
    public string $currency = 'EUR';
    public function __construct(private int $id, private string $type, public float $total, public string $status = 'pending') {}
    public function get_id() { return $this->id; }
    public function get_type() { return $this->type; }
    public function get_payment_method() { return $this->gateway; }
    public function set_payment_method($id) { $this->gateway = $id; }
    public function get_customer_id() { return $this->customer; }
    public function get_currency() { return $this->currency; }
    public function get_total() { return $this->total; }
    public function set_total($total) { $this->total = $total; }
    public function get_billing_email() { return 'test@example.com'; }
    public function get_customer_ip_address() { return '192.0.2.1'; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function get_status() { return $this->status; }
    public function update_status($status, $note = '', $manual = false) { $this->status = $status; $this->notes[] = $note; }
    public function has_status($status) { return in_array($this->status, (array) $status, true); }
    public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    public function payment_complete($transaction_id = '') { $this->status = 'processing'; }
    public function add_order_note($note) { $this->notes[] = $note; }
}
