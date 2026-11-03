<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return 'wsz_subs_options' === $name ? ($GLOBALS['wsz_subs_test_options'] ?? $default) : ($GLOBALS['wsz_admin_test_options'][$name] ?? $default);
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-checkout-handler.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-webhook-handler.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-retry-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-payment-token.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-tokenized-gateway.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-test-card-gateway.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-stripe-gateway.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-mollie-gateway.php';
require_once dirname(__DIR__, 2) . '/src/Payment/class-wsz-payment-handler.php';

final class PayNLSubscriptionSafetyTest extends TestCase
{
    private WSZ_Subscription_Manager $manager;
    private WSZ_Payment_Handler $handler;

    protected function setUp(): void
    {
        $GLOBALS['wsz_test_filters'] = array();
        $GLOBALS['wsz_subs_test_orders'] = array();
        $GLOBALS['wsz_subs_test_options'] = array('enable_paynl_tokens' => 'yes');
        $GLOBALS['wsz_admin_test_options'] = array('wsz_subs_options' => $GLOBALS['wsz_subs_test_options']);
        $GLOBALS['wsz_paynl_test_http_requests'] = array();
        $GLOBALS['wsz_wc_test_container'] = null;
        WC_Payment_Tokens::reset_test_tokens();
        $this->manager = new WSZ_Subscription_Manager();
        $this->handler = $this->getMockBuilder(WSZ_Payment_Handler::class)
            ->setConstructorArgs(array($this->manager))
            ->onlyMethods(array('is_gateway_registered'))->getMock();
        $this->handler->method('is_gateway_registered')->willReturn(true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_test_filters'], $GLOBALS['wsz_subs_test_orders'], $GLOBALS['wsz_subs_test_options'],
            $GLOBALS['wsz_admin_test_options'], $GLOBALS['wsz_paynl_test_http_requests'], $GLOBALS['wsz_wc_test_container'],
            $GLOBALS['wsz_test_execute_actions'], $GLOBALS['wsz_test_actions']);
        WC_Payment_Tokens::reset_test_tokens();
    }

    public function test_unsigned_token_exchange_cannot_store_a_token_at_either_ingress(): void
    {
        foreach (array('native', 'generic') as $entrypoint) {
            $process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/paynl-token-unsigned-exchange.php', $entrypoint),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $this->assertStringContainsString('TOKEN_SAVED=no', $output, $entrypoint);
        }
    }

    public function test_signed_token_ingresses_use_body_and_ignore_conflicting_query_values(): void
    {
        foreach (array('native', 'generic') as $entrypoint) {
            $process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/paynl-token-unsigned-exchange.php', $entrypoint, 'signed'),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $this->assertStringContainsString('TOKEN_SAVED=yes', $output, $entrypoint);
            $this->assertStringContainsString('TOKEN_VALUE=VY-9212-9171-2390', $output, $entrypoint);
            $this->assertStringNotContainsString('TOKEN_VALUE=VY-9999-9999-9999', $output, $entrypoint);
        }
    }

    public function test_signed_late_card_token_is_verified_bound_and_enables_renewal(): void
    {
        $this->assertTrue(method_exists(WSZ_PayNL_Gateway_Integration::class, 'verified_token_exchange_payload'));
        $parent = new PayNLSafetyOrder(10, 'pay_gateway_visa', 'processing');
        $parent->update_meta_data('transactionId', 'EX-2345-2238-9812');
        $parent->update_meta_data('_wsz_subscription_ids', array(1));
        $sub = new PayNLSafetyOrder(1, 'pay_gateway_visa', 'active');
        $sub->update_meta_data('_wsz_parent_order_id', 10);
        $sub->update_meta_data('_requires_manual_renewal', 'yes');
        $sub->update_meta_data('_wsz_manual_renewal_fallback_reason', 'paynl_token_pending');
        $GLOBALS['wsz_test_execute_actions'] = true;
        $GLOBALS['wsz_test_actions'] = array();
        add_action('wsz_subs_paynl_token_exchange_saved', array($this->handler, 'sync_subscriptions_from_paid_parent_order'), 20, 1);
        add_filter('wsz_subs_paynl_recurring_credentials', static fn () => array('service_id'=>'SL-1111-2222','username'=>'AT-1111-2222','password'=>'mock-signing-secret'));
        $body = json_encode(array('action'=>'token','order_id'=>'EX-2345-2238-9812','extra1'=>'10','recurring_id'=>'VY-9212-9171-2390'));
        $headers = array('signature-method'=>'HMAC','signature-algorithm'=>'SHA256','signature-keyid'=>'AT-1111-2222','signature'=>hash_hmac('sha256', $body, 'mock-signing-secret'));
        $integration = new WSZ_PayNL_Gateway_Integration($this->manager);
        $verified = $integration->verified_token_exchange_payload($parent, $body, $headers);
        $this->assertIsArray($verified);
        $id = $integration->store_recurring_payment_token($parent, $verified);
        $this->assertGreaterThan(0, $id);
        $this->assertFalse($this->manager->is_manual_renewal($sub));
        $this->assertSame($id, $integration->store_recurring_payment_token($parent, $verified));
        $this->assertSame(array(), $GLOBALS['wsz_paynl_test_http_requests']);
        $this->assertNull($integration->verified_token_exchange_payload($parent, str_replace('2390', '2391', $body), $headers));
        $wrong_order = new PayNLSafetyOrder(11, 'pay_gateway_visa', 'processing');
        $wrong_order->update_meta_data('transactionId', 'EX-9999-9999-9999');
        $this->assertNull($integration->verified_token_exchange_payload($wrong_order, $body, $headers));
        $headers['signature-keyid'] = 'AT-9999-9999';
        $this->assertNull($integration->verified_token_exchange_payload($parent, $body, $headers));
        $headers['signature-keyid'] = 'AT-1111-2222';
        $headers['signature-algorithm'] = 'SHA512';
        $headers['signature'] = hash_hmac('sha512', $body, 'mock-signing-secret');
        $this->assertIsArray($integration->verified_token_exchange_payload($parent, $body, $headers));
        $headers['signature-algorithm'] = 'MD5';
        $headers['signature'] = hash_hmac('md5', $body, 'mock-signing-secret');
        $this->assertNull($integration->verified_token_exchange_payload($parent, $body, $headers));
    }

    public function test_signed_token_must_match_the_authorized_sales_location(): void
    {
        $order = new PayNLSafetyOrder(10, 'pay_gateway_visa', 'processing');
        $order->update_meta_data('transactionId', 'EX-2345-2238-9812');
        add_filter('wsz_subs_paynl_recurring_credentials', static fn () => array('service_id'=>'SL-1111-2222','username'=>'AT-1111-2222','password'=>'mock-signing-secret'));
        add_filter('wsz_subs_paynl_token_signing_keys', static fn ($keys) => $keys + array('SL-1111-2222'=>'mock-sl-secret', 'SL-9999-9999'=>'other-sl-secret'));
        $makeBody = static fn ($service) => json_encode(array('action'=>'token','order_id'=>'EX-2345-2238-9812','extra1'=>'10','serviceId'=>$service,'recurring_id'=>'VY-9212-9171-2390'));
        $body = $makeBody('SL-1111-2222');
        $headers = array('signature-method'=>'HMAC','signature-algorithm'=>'SHA256','signature-keyid'=>'SL-1111-2222','signature'=>hash_hmac('sha256', $body, 'mock-sl-secret'));
        $integration = new WSZ_PayNL_Gateway_Integration($this->manager);
        $this->assertIsArray($integration->verified_token_exchange_payload($order, $body, $headers));
        $other = $makeBody('SL-9999-9999');
        $headers['signature-keyid'] = 'SL-9999-9999'; $headers['signature'] = hash_hmac('sha256', $other, 'other-sl-secret');
        $this->assertNull($integration->verified_token_exchange_payload($order, $other, $headers));
    }

    public function test_signed_token_resolves_documented_order_and_transaction_identifiers(): void
    {
        $order = new PayNLSafetyOrder(10, 'pay_gateway_visa', 'processing');
        $order->update_meta_data('transactionId', 'EX-2345-2238-9812');
        $GLOBALS['wsz_subs_test_order_queries']['transactionId|EX-2345-2238-9812'] = array($order);
        add_filter('wsz_subs_paynl_recurring_credentials', static fn () => array('service_id'=>'SL-1111-2222','username'=>'AT-1111-2222','password'=>'mock-signing-secret'));
        $body = json_encode(array('action'=>'token','order_id'=>'50009100087X3465','payment_session_id'=>'3116527583',
            'transactionId'=>'EX-2345-2238-9812','recurring_id'=>'VY-9212-9171-2390'));
        $headers = array('signature-method'=>'HMAC','signature-algorithm'=>'SHA256','signature-keyid'=>'AT-1111-2222','signature'=>hash_hmac('sha256', $body, 'mock-signing-secret'));
        $integration = new WSZ_PayNL_Gateway_Integration($this->manager);
        $payload = WSZ_PayNL_Gateway_Integration::token_payload_from_body($body);
        $this->assertSame($order, $integration->resolve_order_from_token_exchange($payload));
        $this->assertIsArray($integration->verified_token_exchange_payload($order, $body, $headers));
        unset($GLOBALS['wsz_subs_test_order_queries']);
    }

    public function test_ideal_and_unknown_paynl_methods_do_not_receive_card_charge_callbacks(): void
    {
        $integration = new WSZ_PayNL_Gateway_Integration();
        foreach (array('pay_gateway_ideal', 'pay_gateway_incasso', 'pay_gateway_banktransfer', 'pay_gateway_future') as $gateway_id) {
            $order = new PayNLSafetyOrder(1, $gateway_id);
            $this->assertFalse(WSZ_PayNL_Gateway_Integration::is_supported_gateway_id($gateway_id));
            $this->assertNull($integration->provide_recurring_charge_callback(null, '', 50, 'EUR', $order, $order));
        }
    }

    public function test_card_gateway_extensions_remain_explicit(): void
    {
        add_filter('wsz_subs_paynl_gateway_ids', static function (array $ids): array {
            $ids[] = 'pay_gateway_amex';
            return $ids;
        });
        $integration = new WSZ_PayNL_Gateway_Integration();
        foreach (array('pay_gateway_creditcardsgrouped', 'pay_gateway_visamastercard', 'pay_gateway_visa', 'pay_gateway_mastercard', 'pay_gateway_amex') as $id) {
            $order = new PayNLSafetyOrder(1, $id);
            $this->assertIsCallable($integration->provide_recurring_charge_callback(null, '', 50, 'EUR', $order, $order));
        }
    }

    public function test_paynl_subscription_creation_waits_for_valid_context_and_preserves_other_gateways(): void
    {
        foreach (array('pay_gateway_ideal' => 'yes', 'pay_gateway_visa' => 'yes', 'stripe' => 'no') as $gateway => $manual) {
            $process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/paynl-subscription-creation.php', $gateway),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $created = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($manual, $created['manual'], $gateway);
            $this->assertSame($gateway, $created['gateway']);
            $this->assertSame(array(2), $created['subscription_ids']);
            if ('pay_gateway_visa' === $gateway) {
                $this->assertSame('paynl_token_pending', $created['reason']);
            }
        }
    }

    public function test_registered_gateway_with_zero_token_cannot_enable_automatic_renewal(): void
    {
        foreach (array('pay_gateway_ideal', 'pay_gateway_visa', 'stripe', 'mollie_wc_gateway_creditcard') as $id) {
            $subscription = new PayNLSafetyOrder(1, $id);
            $subscription->update_meta_data('_requires_manual_renewal', 'yes');
            $this->handler->update_subscription_payment_context($subscription, 0, $id);
            $this->assertTrue($this->manager->is_manual_renewal($subscription), $id);
        }
    }

    public static function invalidCardContextProvider(): array
    {
        return array(
            'missing token' => array(false, 'pay_gateway_visa', 5, 'VY-9212-9171-2390'),
            'wrong owner' => array(true, 'pay_gateway_visa', 9, 'VY-9212-9171-2390'),
            'wrong gateway' => array(true, 'stripe', 5, 'VY-9212-9171-2390'),
            'invalid recurring id' => array(true, 'pay_gateway_visa', 5, 'one-time-payment'),
        );
    }

    /** @dataProvider invalidCardContextProvider */
    public function test_invalid_card_context_cannot_enable_automatic_renewal(bool $exists, string $gateway, int $owner, string $reference): void
    {
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_visa');
        $subscription->update_meta_data('_requires_manual_renewal', 'yes');
        $id = $exists ? $this->token($gateway, $owner, $reference) : 9999;
        $this->handler->update_subscription_payment_context($subscription, $id, 'pay_gateway_visa');
        $this->assertTrue($this->manager->is_manual_renewal($subscription));
    }

    public function test_ideal_cannot_become_automatic_even_with_a_saved_reference(): void
    {
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_ideal');
        $subscription->update_meta_data('_requires_manual_renewal', 'yes');
        $this->handler->update_subscription_payment_context($subscription, $this->token('pay_gateway_ideal'), 'pay_gateway_ideal');
        $this->assertTrue($this->manager->is_manual_renewal($subscription));
    }

    public function test_legacy_ideal_renewals_stay_payable_without_any_card_charge(): void
    {
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_ideal');
        $subscription->update_meta_data('_requires_manual_renewal', 'no');
        $renewal = new PayNLSafetyOrder(2, 'pay_gateway_ideal');
        $renewal->update_meta_data('_wsz_subscription_id', 1);
        $this->handler->dispatch_scheduled_payment($subscription, $renewal, 50);
        $this->handler->dispatch_scheduled_payment($subscription, $renewal, 50);
        $this->assertTrue($this->manager->is_manual_renewal($subscription));
        $this->assertSame('pending', $renewal->get_status());
        $this->assertSame(array(), $GLOBALS['wsz_paynl_test_http_requests']);
        $this->assertStringContainsString('manual', strtolower(implode(' ', $renewal->notes)));
    }

    public function test_ideal_safety_fallback_does_not_reopen_a_paid_renewal(): void
    {
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_ideal');
        $renewal = new PayNLSafetyOrder(2, 'pay_gateway_ideal', 'processing');
        $renewal->update_meta_data('_wsz_subscription_id', 1);
        $this->handler->dispatch_scheduled_payment($subscription, $renewal, 50);
        $this->assertTrue($renewal->is_paid());
        $this->assertSame(array(), $GLOBALS['wsz_paynl_test_http_requests']);
    }

    public function test_direct_tokenized_dispatch_cannot_charge_legacy_ideal_even_with_a_card_reference(): void
    {
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_ideal');
        $subscription->update_meta_data('_payment_token_id', $this->token('pay_gateway_ideal'));
        $renewal = new PayNLSafetyOrder(2, 'pay_gateway_ideal');
        $renewal->update_meta_data('_wsz_subscription_id', 1);
        add_filter('wsz_subs_recurring_charge_callback', static function () {
            return static function (): array {
                throw new RuntimeException('iDEAL must never reach the card-charge callback');
            };
        });
        (new WSZ_Tokenized_Gateway($this->manager, $this->handler))->process_scheduled_payment(50, $renewal);
        $this->assertSame('pending', $renewal->get_status());
        $this->assertTrue($this->manager->is_manual_renewal($subscription));
    }

    public function test_valid_card_context_enables_and_pays_a_mocked_renewal(): void
    {
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_visa');
        $subscription->update_meta_data('_requires_manual_renewal', 'yes');
        $this->handler->update_subscription_payment_context($subscription, $this->token('pay_gateway_visa'), 'pay_gateway_visa');
        $this->assertFalse($this->manager->is_manual_renewal($subscription));
        add_filter('wsz_subs_paynl_recurring_credentials', static function (): array {
            return array('service_id' => 'SL-1234-5678', 'username' => 'AT-test', 'password' => 'mock-secret');
        });
        $integration = new WSZ_PayNL_Gateway_Integration();
        add_filter('wsz_subs_recurring_charge_callback', array($integration, 'provide_recurring_charge_callback'), 10, 6);
        $renewal = new PayNLSafetyOrder(2, 'pay_gateway_visa');
        $renewal->update_meta_data('_wsz_subscription_id', 1);
        $this->handler->dispatch_scheduled_payment($subscription, $renewal, 50);
        $this->assertSame('processing', $renewal->get_status());
        $this->assertCount(1, $GLOBALS['wsz_paynl_test_http_requests']);
        $body = json_decode($GLOBALS['wsz_paynl_test_http_requests'][0]['args']['body'], true);
        $this->assertSame('VY-9212-9171-2390', $body['payment']['token']['id']);
        $this->assertSame(5000, $body['transaction']['amount']);
    }

    public function test_delayed_card_token_establishes_automatic_context_without_a_charge(): void
    {
        $parent = new PayNLSafetyOrder(10, 'pay_gateway_visa', 'processing');
        $parent->update_meta_data('_wsz_subscription_ids', array(1));
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_visa');
        $this->handler->sync_subscriptions_from_paid_parent_order($parent);
        $this->assertTrue($this->manager->is_manual_renewal($subscription));
        $integration = new WSZ_PayNL_Gateway_Integration($this->manager);
        $token = $integration->store_recurring_payment_token($parent, array('recurring_id' => 'VY-9212-9171-2390'));
        $this->assertGreaterThan(0, $token);
        $this->handler->process_delayed_paynl_parent_order_token_check(10);
        $this->assertFalse($this->manager->is_manual_renewal($subscription));
        $this->assertSame($token, $this->manager->get_payment_token_id($subscription));
        $this->assertSame(array(), $GLOBALS['wsz_paynl_test_http_requests']);
    }

    public function test_late_card_callback_does_not_replace_a_new_payment_method(): void
    {
        $parent = new PayNLSafetyOrder(10, 'pay_gateway_visa', 'processing');
        $parent->update_meta_data('_wsz_subscription_ids', array(1));
        $subscription = new PayNLSafetyOrder(1, 'stripe');
        $subscription->update_meta_data('_requires_manual_renewal', 'yes');
        $integration = new WSZ_PayNL_Gateway_Integration($this->manager);
        $integration->store_recurring_payment_token($parent, array('recurring_id' => 'VY-9212-9171-2390'));
        $this->handler->process_delayed_paynl_parent_order_token_check(10);
        $this->assertSame('stripe', $subscription->get_payment_method());
        $this->assertSame(0, $this->manager->get_payment_token_id($subscription));
        $this->assertTrue($this->manager->is_manual_renewal($subscription));
    }

    public function test_late_card_callback_does_not_replace_a_newer_saved_card(): void
    {
        $parent = new PayNLSafetyOrder(10, 'pay_gateway_visa', 'processing');
        $parent->update_meta_data('_wsz_subscription_ids', array(1));
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_visa');
        $new_token = $this->token('pay_gateway_visa', 5, 'VY-1111-2222-3333');
        $subscription->update_meta_data('_payment_token_id', $new_token);
        (new WSZ_PayNL_Gateway_Integration($this->manager))->store_recurring_payment_token($parent, array('recurring_id' => 'VY-9212-9171-2390'));
        $this->handler->process_delayed_paynl_parent_order_token_check(10);
        $this->assertSame($new_token, $this->manager->get_payment_token_id($subscription));
    }

    public function test_ideal_token_exchanges_are_ignored_by_both_ingress_paths(): void
    {
        $order = new PayNLSafetyOrder(10, 'pay_gateway_ideal');
        $integration = new WSZ_PayNL_Gateway_Integration($this->manager);
        $this->assertSame(0, $integration->store_recurring_payment_token($order, array('recurring_id' => 'VY-9212-9171-2390')));
        $webhook = new WSZ_Webhook_Handler($this->manager);
        $method = new ReflectionMethod($webhook, 'store_recurring_payment_token');
        $this->assertSame(0, $method->invoke($webhook, $order, array('recurring_id' => 'VY-9212-9171-2390')));
        $this->assertSame('', $order->get_meta('_payment_token_id'));
    }

    public function test_card_callback_after_the_delayed_check_has_finished_restores_automatic_renewal(): void
    {
        $GLOBALS['wsz_test_execute_actions'] = true;
        $GLOBALS['wsz_test_actions'] = array();
        $this->handler->init();
        $parent = new PayNLSafetyOrder(10, 'pay_gateway_visa', 'processing');
        $parent->update_meta_data('_wsz_subscription_ids', array(1));
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_visa');
        $this->handler->sync_subscriptions_from_paid_parent_order($parent);
        $this->handler->process_delayed_paynl_parent_order_token_check(10);
        $this->assertTrue($this->manager->is_manual_renewal($subscription));
        (new WSZ_PayNL_Gateway_Integration($this->manager))->store_recurring_payment_token($parent, array('recurring_id' => 'VY-9212-9171-2390'));
        $this->assertFalse($this->manager->is_manual_renewal($subscription));
        $this->assertSame(array(), $GLOBALS['wsz_paynl_test_http_requests']);
    }

    public function test_initial_card_token_can_complete_setup_when_automatic_recovery_is_disabled(): void
    {
        $GLOBALS['wsz_subs_test_options']['auto_restore_automatic_renewals'] = 'no';
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_visa');
        $subscription->update_meta_data('_requires_manual_renewal', 'yes');
        $subscription->update_meta_data('_wsz_manual_renewal_fallback_reason', 'paynl_token_pending');
        $this->handler->update_subscription_payment_context($subscription, $this->token('pay_gateway_visa'), 'pay_gateway_visa');
        $this->assertFalse($this->manager->is_manual_renewal($subscription));
        $this->assertSame('', $subscription->get_meta('_wsz_manual_renewal_fallback_reason'));
    }

    public function test_established_manual_card_subscription_respects_disabled_automatic_recovery(): void
    {
        $GLOBALS['wsz_subs_test_options']['auto_restore_automatic_renewals'] = 'no';
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_visa');
        $subscription->update_meta_data('_requires_manual_renewal', 'yes');
        $this->handler->update_subscription_payment_context($subscription, $this->token('pay_gateway_visa'), 'pay_gateway_visa');
        $this->assertTrue($this->manager->is_manual_renewal($subscription));
    }

    public function test_valid_stripe_and_mollie_tokens_still_restore_automatic_renewal(): void
    {
        foreach (array('stripe' => 'pm_card_saved', 'mollie_wc_gateway_creditcard' => 'cst_saved:mdt_saved') as $id => $reference) {
            $subscription = new PayNLSafetyOrder(1, $id);
            $subscription->update_meta_data('_requires_manual_renewal', 'yes');
            $this->handler->update_subscription_payment_context($subscription, $this->token($id, 5, $reference), $id);
            $this->assertFalse($this->manager->is_manual_renewal($subscription));
        }
    }

    public function test_existing_ideal_retry_is_cancelled_without_queueing_another_charge(): void
    {
        $subscription = new PayNLSafetyOrder(1, 'pay_gateway_ideal', 'active');
        $renewal = new PayNLSafetyOrder(2, 'pay_gateway_ideal', 'failed');
        $renewal->update_meta_data('_wsz_subscription_id', 1);
        $renewal->update_meta_data('_wsz_retry_records', wp_json_encode(array(1 => array('status' => 'pending'))));
        $manager = $this->getMockBuilder(WSZ_Subscription_Manager::class)
            ->onlyMethods(array('acquire_lock', 'release_lock'))->getMock();
        $manager->method('acquire_lock')->willReturn(true);
        $retry = new WSZ_Retry_Manager($manager, $this->handler);
        $retry->process_retry(1, 2, 1);
        $records = json_decode($renewal->get_meta('_wsz_retry_records'), true);
        $this->assertSame('cancelled', $records[1]['status']);
        $this->assertCount(1, $records);
        $this->assertSame('pending', $renewal->get_status());
        $this->assertSame(array(), $GLOBALS['wsz_paynl_test_http_requests']);
    }

    public function test_explicit_card_adapter_still_works_when_builtin_paynl_tokens_are_disabled(): void
    {
        $GLOBALS['wsz_subs_test_options']['enable_paynl_tokens'] = 'no';
        add_filter('wsz_subs_tokenized_gateway_ids', static fn ($ids) => array_merge($ids, array('pay_gateway_visa')));
        $sub = new PayNLSafetyOrder(1, 'pay_gateway_visa', 'active');
        $sub->update_meta_data('_requires_manual_renewal', 'yes');
        $this->handler->update_subscription_payment_context($sub, $this->token('pay_gateway_visa'), 'pay_gateway_visa');
        $this->assertFalse($this->manager->is_manual_renewal($sub));
        $this->assertFalse(WSZ_PayNL_Gateway_Integration::is_manual_only_gateway_id('pay_gateway_visa'));
    }

    public function test_disabled_paynl_card_adapter_is_treated_as_manual_at_checkout_and_dispatch(): void
    {
        $GLOBALS['wsz_subs_test_options']['enable_paynl_tokens'] = 'no';
        $checkout = $this->checkout(true);
        $checkout->init();
        $gateways = array('pay_gateway_visa' => new stdClass(), 'stripe' => new stdClass());
        $filtered = apply_filters('woocommerce_available_payment_gateways', $gateways);
        $this->assertArrayNotHasKey('pay_gateway_visa', $filtered);
        $this->assertArrayHasKey('stripe', $filtered);
        $GLOBALS['wsz_subs_test_options']['enable_manual_renewals'] = 'yes';
        $this->assertStringContainsString('manual', apply_filters('woocommerce_gateway_title', 'Visa', 'pay_gateway_visa'));
        $sub = new PayNLSafetyOrder(1, 'pay_gateway_visa', 'active');
        $sub->update_meta_data('_requires_manual_renewal', 'no');
        $order = new PayNLSafetyOrder(2, 'pay_gateway_visa');
        $order->update_meta_data('_wsz_subscription_id', 1);
        $this->handler->dispatch_scheduled_payment($sub, $order, 50.0);
        $this->assertTrue($this->manager->is_manual_renewal($sub));
        $this->assertSame('pending', $order->get_status());
        $this->assertSame(array(), $GLOBALS['wsz_paynl_test_http_requests']);
    }

    public function test_subscription_checkout_hides_ideal_when_manual_payments_are_disabled(): void
    {
        $checkout = $this->checkout(true);
        $checkout->init();
        $gateways = array('pay_gateway_ideal' => new stdClass(), 'pay_gateway_visa' => new stdClass(), 'stripe' => new stdClass());
        $filtered = apply_filters('woocommerce_available_payment_gateways', $gateways);
        $this->assertArrayNotHasKey('pay_gateway_ideal', $filtered);
        $this->assertArrayHasKey('pay_gateway_visa', $filtered);
        $this->assertArrayHasKey('stripe', $filtered);
    }

    public function test_manual_subscription_checkout_discloses_no_automatic_collection(): void
    {
        $GLOBALS['wsz_subs_test_options']['enable_manual_renewals'] = 'yes';
        $checkout = $this->checkout(true);
        $checkout->init();
        $this->assertArrayHasKey('pay_gateway_ideal', apply_filters('woocommerce_available_payment_gateways', array('pay_gateway_ideal' => new stdClass())));
        $description = apply_filters('woocommerce_gateway_description', 'Pay with your bank.', 'pay_gateway_ideal');
        $this->assertStringContainsString('each renewal', $description);
        $this->assertStringContainsString('not collected automatically', $description);
        $this->assertStringContainsString('manual', apply_filters('woocommerce_gateway_title', 'iDEAL', 'pay_gateway_ideal'));
    }

    public function test_ordinary_product_checkout_is_unchanged(): void
    {
        $this->checkout(false)->init();
        $gateways = array('pay_gateway_ideal' => new stdClass());
        $this->assertSame($gateways, apply_filters('woocommerce_available_payment_gateways', $gateways));
        $this->assertSame('iDEAL', apply_filters('woocommerce_gateway_title', 'iDEAL', 'pay_gateway_ideal'));
    }

    public function test_posted_ideal_method_is_rejected_before_classic_checkout_payment(): void
    {
        $errors = new class {
            public array $errors = array();
            public function add($code, $message): void { $this->errors[$code] = $message; }
        };
        $this->checkout(true)->validate_subscription_account_checkout(array('payment_method' => 'pay_gateway_ideal', 'createaccount' => 1), $errors);
        $this->assertArrayHasKey('wsz_subs_manual_payment_disabled', $errors->errors);
    }

    public function test_store_api_rejects_ideal_before_payment_when_manual_payments_are_disabled(): void
    {
        $GLOBALS['wsz_test_execute_actions'] = true;
        $GLOBALS['wsz_test_actions'] = array();
        $this->checkout(true)->init();
        $this->expectException(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class);
        do_action('woocommerce_store_api_checkout_update_order_from_request', new PayNLSafetyOrder(10, 'pay_gateway_ideal'), array('payment_method' => 'pay_gateway_ideal'));
    }

    public function test_store_api_accepts_card_checkout_before_a_token_exists(): void
    {
        $GLOBALS['wsz_test_execute_actions'] = true;
        $GLOBALS['wsz_test_actions'] = array();
        $this->checkout(true)->init();
        $order = new PayNLSafetyOrder(10, 'pay_gateway_visa');
        do_action('woocommerce_store_api_checkout_update_order_from_request', $order, array('payment_method' => 'pay_gateway_visa'));
        $this->assertSame('', $order->get_meta('_payment_token_id'));
        $this->assertSame(array(), $GLOBALS['wsz_paynl_test_http_requests']);
    }

    private function checkout(bool $contains_subscription): WSZ_Checkout_Handler
    {
        $checkout = $this->getMockBuilder(WSZ_Checkout_Handler::class)
            ->setConstructorArgs(array($this->manager))
            ->onlyMethods(array('cart_contains_subscription_items'))->getMock();
        $checkout->method('cart_contains_subscription_items')->willReturn($contains_subscription);
        return $checkout;
    }

    private function token(string $gateway, int $owner = 5, string $reference = 'VY-9212-9171-2390'): int
    {
        $token = new WC_Payment_Token_PayNL();
        $token->set_gateway_id($gateway);
        $token->set_user_id($owner);
        $token->set_token($reference);
        $token->save();
        return (int) $token->get_id();
    }
}

final class PayNLSafetyRouteException extends Exception
{
    public function __construct(string $error_code, string $message, int $status)
    {
        parent::__construct($message, $status);
    }
}

if (!class_exists('Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
    class_alias(PayNLSafetyRouteException::class, 'Automattic\WooCommerce\StoreApi\Exceptions\RouteException');
}

final class PayNLSafetyOrder extends WC_Order
{
    public array $notes = array();
    private array $meta = array();
    private int $id;
    private string $gateway;
    private string $status;

    public function __construct(int $id, string $gateway, string $status = 'pending')
    {
        $this->id = $id;
        $this->gateway = $gateway;
        $this->status = $status;
        $GLOBALS['wsz_subs_test_orders'][$id] = $this;
    }

    public function get_id() { return $this->id; }
    public function get_customer_id() { return 5; }
    public function get_payment_method() { return $this->gateway; }
    public function set_payment_method($gateway_id) { $this->gateway = $gateway_id; }
    public function get_status() { return $this->status; }
    public function set_status($status) { $this->status = $status; }
    public function needs_payment() { return !$this->is_paid(); }
    public function get_total() { return 50.0; }
    public function get_currency() { return 'EUR'; }
    public function get_type() { return 10 === $this->id ? 'shop_order' : 'shop_subscription'; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function get_meta_data() { return array_map(static fn ($key, $value) => array('key' => $key, 'value' => $value), array_keys($this->meta), $this->meta); }
    public function update_status($status, $note = '', $manual = false) { $this->status = $status; $this->notes[] = $note; }
    public function add_order_note($note) { $this->notes[] = $note; }
    public function payment_complete($transaction_id = '') { $this->status = 'processing'; }
    public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    public function has_status($status) { return in_array($this->status, (array) $status, true); }
}
