<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return 'wsz_subs_options' === $name ? ($GLOBALS['wsz_subs_test_options'] ?? $default) : ($GLOBALS['wsz_admin_test_options'][$name] ?? $default);
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
require_once dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-sepa-context.php';
$audit_file = dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-audit.php';
if (is_file($audit_file)) { require_once $audit_file; }

final class PayNLAuditTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wsz_test_filters'] = array();
        $GLOBALS['wsz_subs_test_options'] = array('enable_paynl_tokens' => 'yes');
        WC_Payment_Tokens::reset_test_tokens();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_test_filters'], $GLOBALS['wsz_subs_test_options']);
        WC_Payment_Tokens::reset_test_tokens();
    }

    public function test_cli_audit_is_read_only_and_scans_all_pages(): void
    {
        $process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/paynl-read-only-audit.php'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]); $diagnostics = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $diagnostics);
        $rows = array_map(static fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_filter(explode("\n", trim($output))));
        $data = json_decode($diagnostics, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(array(1, 101), array_column($rows, 'subscription_id'));
        $this->assertSame(array('pay_gateway_ideal', 'pay_gateway_incasso'), array_column($rows, 'gateway'));
        $this->assertSame(array(1, 2), array_column($data['queries'], 'paged'));
        $this->assertSame(array(100, 100), array_column($data['queries'], 'limit'));
        $this->assertSame(0, $data['writes']);
        $this->assertTrue($data['cron_disabled']);
    }

    public function test_legacy_incasso_is_reported_as_manual_only(): void
    {
        $audit = new WSZ_PayNL_Audit(new WSZ_Subscription_Manager());
        $row = $audit->inspect(new PayNLAuditOrder(20, 'pay_gateway_incasso', array('_requires_manual_renewal' => 'no')));
        $this->assertSame('manual_only', $row['local_context']);
        $this->assertTrue($row['automatic_needs_review']);
    }

    public function test_audit_flags_disabled_card_adapter_even_with_a_valid_saved_token(): void
    {
        $GLOBALS['wsz_subs_test_options']['enable_paynl_tokens'] = 'no';
        $token = new WC_Payment_Token();
        $token->set_gateway_id('pay_gateway_visa'); $token->set_user_id(5); $token->set_token('VY-9212-9171-2390'); $token->save();
        $sub = new PayNLAuditOrder(8, 'pay_gateway_visa', array('_requires_manual_renewal' => 'no', '_payment_token_id' => $token->get_id()));
        $row = (new WSZ_PayNL_Audit(new WSZ_Subscription_Manager()))->inspect($sub);
        $this->assertSame('card_adapter_disabled', $row['local_context']);
        $this->assertTrue($row['automatic_needs_review']);
        $this->assertSame(0, $sub->writes);
    }

    public function test_audit_distinguishes_legacy_ideal_incasso_cards_and_custom_extensions_without_writes(): void
    {
        $this->assertTrue(class_exists('WSZ_PayNL_Audit'), 'A read-only PAY.nl audit must be available.');
        $audit = new WSZ_PayNL_Audit(new WSZ_Subscription_Manager());
        $legacy = new PayNLAuditOrder(1, 'pay_gateway_ideal', array('_requires_manual_renewal' => 'no'));
        $incasso = new PayNLAuditOrder(2, 'pay_gateway_incasso', array('_requires_manual_renewal' => 'no'));
        $manual = new PayNLAuditOrder(3, 'pay_gateway_ideal', array('_requires_manual_renewal' => 'yes'));
        $invalid = new PayNLAuditOrder(4, 'pay_gateway_visa', array('_requires_manual_renewal' => 'no', '_payment_token_id' => 0));
        $token = new WC_Payment_Token();
        $token->set_gateway_id('pay_gateway_visa'); $token->set_user_id(5); $token->set_token('VY-9212-9171-2390'); $token->save();
        $valid = new PayNLAuditOrder(5, 'pay_gateway_visa', array('_requires_manual_renewal' => 'no', '_payment_token_id' => $token->get_id()));
        add_filter('wsz_subs_tokenized_gateway_ids', static fn ($ids) => array_merge($ids, array('pay_gateway_custom')));
        $custom = new PayNLAuditOrder(6, 'pay_gateway_custom', array('_requires_manual_renewal' => 'no'));
        $stripe = new PayNLAuditOrder(7, 'stripe', array());
        $this->assertSame('manual_only', $audit->inspect($legacy)['local_context']);
        $this->assertSame('manual_only', $audit->inspect($incasso)['local_context']);
        $this->assertTrue($audit->inspect($legacy)['automatic_needs_review']);
        $this->assertFalse($audit->inspect($manual)['automatic_needs_review']);
        $this->assertSame('stored_card_token_unverified', $audit->inspect($invalid)['local_context']);
        $this->assertSame('stored_card_token_valid', $audit->inspect($valid)['local_context']);
        $this->assertFalse($audit->inspect($valid)['automatic_needs_review']);
        $this->assertSame('custom_adapter_review', $audit->inspect($custom)['local_context']);
        $this->assertTrue($audit->inspect($custom)['automatic_needs_review']);
        $this->assertNull($audit->inspect($stripe));
        foreach (array($legacy, $incasso, $manual, $invalid, $valid, $custom, $stripe) as $order) {
            $this->assertSame(0, $order->writes, 'Audit must never mutate subscriptions.');
            $this->assertStringNotContainsString('VY-9212-9171-2390', json_encode($audit->inspect($order)));
        }
    }
}

final class PayNLAuditOrder extends WC_Order
{
    public int $writes = 0;
    public function __construct(private int $id, private string $gateway, private array $meta) {}
    public function get_id() { return $this->id; }
    public function get_type() { return 'shop_subscription'; }
    public function get_status() { return 'active'; }
    public function get_customer_id() { return 5; }
    public function get_currency() { return 'EUR'; }
    public function get_payment_method() { return $this->gateway; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { ++$this->writes; $this->meta[$key] = $value; }
    public function save() { ++$this->writes; }
}
