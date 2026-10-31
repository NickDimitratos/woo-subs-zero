<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
        if (!empty($GLOBALS['wsz_retry_test_wp_mail_throws'])) { throw new ValueError('Unknown format specifier'); }
        $GLOBALS['wsz_retry_test_mail'][] = compact('to', 'subject', 'message');
        $GLOBALS['wsz_manual_test_mail'][] = compact('to', 'subject', 'message');
        return $GLOBALS['wsz_manual_test_mail_result'] ?? true;
    }
}
if (!function_exists('is_email')) {
    function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags($text); }
}

require_once __DIR__ . '/../../src/Email/class-wsz-renewal-email-template.php';
require_once __DIR__ . '/../../includes/class-wsz-subscription-manager.php';
require_once __DIR__ . '/../../includes/class-wsz-renewal-engine.php';
if (file_exists(__DIR__ . '/../../includes/class-wsz-customer-subscriptions.php')) {
    require_once __DIR__ . '/../../includes/class-wsz-customer-subscriptions.php';
}

final class ManualRenewalExperienceTest extends TestCase
{
    public function test_email_shows_payment_position_remaining_and_contract_in_both_formats(): void
    {
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $sub->items = array(new ManualContractItem('PUBLIC-12345678'));
        $order = new ManualExperienceOrder(20, 7, array('_wsz_manual_payment_number' => 2, '_wsz_manual_payment_total' => 6), false);
        foreach (array(WSZ_Renewal_Email_Template::html($sub, $order, 'Renewal'), WSZ_Renewal_Email_Template::plain($sub, $order, 'Renewal')) as $message) {
            self::assertStringContainsString('2 / 6', $message);
            self::assertStringContainsString('Remaining after this payment', $message);
            self::assertStringContainsString('PUBLIC-12345678', $message);
        }
    }

    public function test_unknown_schedule_and_contract_are_not_invented(): void
    {
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $order = new ManualExperienceOrder(20, 7, array(), false);
        $message = WSZ_Renewal_Email_Template::html($sub, $order, 'Renewal');
        self::assertStringNotContainsString('Remaining after this payment', $message);
        self::assertStringNotContainsString('Contract number', $message);
    }

    public function test_final_payment_and_contract_escaping(): void
    {
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $sub->items = array(new ManualContractItem('<unsafe>'));
        $order = new ManualExperienceOrder(20, 7, array('_wsz_manual_payment_number' => 6, '_wsz_manual_payment_total' => 6), false);
        $message = WSZ_Renewal_Email_Template::html($sub, $order, 'Renewal');
        self::assertStringContainsString('6 / 6', $message);
        self::assertMatchesRegularExpression('/Remaining after this payment.*?>0<\/td>/', $message);
        self::assertStringContainsString('&lt;unsafe&gt;', $message);
    }

    public function test_contract_reference_can_be_supplied_by_any_store(): void
    {
        $sub = new ManualExperienceOrder(10, 7, array('_wsz_contract_reference' => 'CONTRACT-A-103'), false);
        $order = new ManualExperienceOrder(20, 7, array(), false);
        self::assertStringContainsString('CONTRACT-A-103', WSZ_Renewal_Email_Template::html($sub, $order, 'Renewal'));
        $GLOBALS['wsz_test_filters']['wsz_subs_renewal_contract_reference'][10][] = array('callback' => static function ($reference, $subscription, $renewal) {
            return $subscription->get_id() === 10 && $renewal->get_id() === 20 ? 'EXTERNAL-REF-7' : $reference;
        }, 'accepted_args' => 3);
        try {
            self::assertStringContainsString('EXTERNAL-REF-7', WSZ_Renewal_Email_Template::plain($sub, $order, 'Renewal'));
        } finally {
            unset($GLOBALS['wsz_test_filters']['wsz_subs_renewal_contract_reference']);
        }
    }

    public function test_unrelated_item_metadata_is_not_treated_as_a_contract_reference(): void
    {
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $sub->items = array(new class {
            public function get_meta($key, $single = true) { return 'some-other-provider-key' === $key ? 'DO-NOT-DISPLAY' : ''; }
        });
        $order = new ManualExperienceOrder(20, 7, array(), false);
        self::assertStringNotContainsString('DO-NOT-DISPLAY', WSZ_Renewal_Email_Template::html($sub, $order, 'Renewal'));
    }

    public function test_notice_records_payment_position_once(): void
    {
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('acquire_lock')->willReturn(true);
        $manager->method('is_manual_renewal')->willReturn(true);
        $manager->method('get_total_payments')->willReturn(6);
        $manager->method('get_completed_payments')->willReturn(1);
        $customer = new WSZ_Customer_Subscriptions($manager, $this->createMock(WSZ_Renewal_Engine::class));
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $order = new ManualExperienceOrder(20, 7, array('_wsz_subscription_id' => 10), false);
        $customer->notify_manual_renewal($sub, $order);
        self::assertSame(2, $order->get_meta('_wsz_manual_payment_number', true));
        self::assertSame(6, $order->get_meta('_wsz_manual_payment_total', true));
        $order->update_meta_data('_wsz_manual_renewal_notice_sent', '');
        $manager2 = $this->createMock(WSZ_Subscription_Manager::class);
        $manager2->method('is_manual_renewal')->willReturn(true);
        $manager2->method('acquire_lock')->willReturn(true);
        $manager2->method('get_total_payments')->willReturn(6);
        $manager2->method('get_completed_payments')->willReturn(3);
        (new WSZ_Customer_Subscriptions($manager2, $this->createMock(WSZ_Renewal_Engine::class)))->notify_manual_renewal($sub, $order);
        self::assertSame(2, $order->get_meta('_wsz_manual_payment_number', true));
    }
    public function test_pending_renewal_sends_one_payment_link_and_ignores_duplicate_events(): void
    {
        self::assertTrue(class_exists(WSZ_Customer_Subscriptions::class), "Customer subscriptions integration is missing");
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('acquire_lock')->willReturn(true);
        $manager->method('is_manual_renewal')->willReturn(true);
        $engine = $this->createMock(WSZ_Renewal_Engine::class);
        $customer = new WSZ_Customer_Subscriptions($manager, $engine);
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $order = new ManualExperienceOrder(20, 7, array('_wsz_subscription_id' => 10), false);
        $GLOBALS['wsz_manual_test_mail'] = array();
        $customer->notify_manual_renewal($sub, $order);
        $customer->notify_manual_renewal($sub, $order);
        self::assertCount(1, $GLOBALS['wsz_manual_test_mail']);
        self::assertStringContainsString('https://example.test/pay/20', $GLOBALS['wsz_manual_test_mail'][0]['message']);
        self::assertStringContainsString('>Renew Now</a>', $GLOBALS['wsz_manual_test_mail'][0]['message']);
        self::assertStringContainsString('#111827', $GLOBALS['wsz_manual_test_mail'][0]['message']);
        self::assertSame('yes', $order->get_meta('_wsz_manual_renewal_notice_sent', true));
    }

    public function test_email_template_escapes_customer_content_and_preserves_plain_text_link(): void
    {
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $order = new ManualExperienceOrder(20, 7, array(), false, '<script>unsafe</script>');
        $html = WSZ_Renewal_Email_Template::html($sub, $order, 'Renewal <heading>', 'Contact <store>');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
        self::assertStringContainsString('Renewal &lt;heading&gt;', $html);
        self::assertStringContainsString('Contact &lt;store&gt;', $html);
        self::assertStringContainsString('https://example.test/pay/20', WSZ_Renewal_Email_Template::plain($sub, $order, 'Renewal'));
    }

    public function test_custom_email_body_appears_in_html_and_plain_text(): void
    {
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $order = new ManualExperienceOrder(20, 7, array(), false);
        $html = WSZ_Renewal_Email_Template::html($sub, $order, 'Heading', '', 'Please pay <soon>.');
        $plain = WSZ_Renewal_Email_Template::plain($sub, $order, 'Heading', '', 'Please pay <soon>.');
        self::assertStringContainsString('Please pay &lt;soon&gt;.', $html);
        self::assertStringContainsString('Please pay <soon>.', $plain);
    }

    public function test_reminder_has_a_separate_scheduler_and_unpaid_check(): void
    {
        self::assertTrue(method_exists(WSZ_Customer_Subscriptions::class, 'schedule_manual_reminder'));
        self::assertTrue(method_exists(WSZ_Customer_Subscriptions::class, 'send_manual_reminder'));
    }

    public function test_reminder_is_due_fifteen_days_after_order_creation(): void
    {
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('is_manual_renewal')->willReturn(true);
        $customer = new WSZ_Customer_Subscriptions($manager, $this->createMock(WSZ_Renewal_Engine::class));
        $sub = new ManualExperienceOrder(821, 7, array(), false);
        $order = new ManualExperienceOrder(822, 7, array('_wsz_subscription_id' => 821), false);
        $before = time();
        $customer->schedule_manual_reminder($sub, $order);
        $due = (int) $order->get_meta('_wsz_manual_reminder_due', true);
        self::assertGreaterThanOrEqual($before + 15 * DAY_IN_SECONDS, $due);
        self::assertLessThanOrEqual(time() + 15 * DAY_IN_SECONDS, $due);
    }

    public function test_paid_order_does_not_send_a_reminder(): void
    {
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('acquire_lock')->willReturn(true);
        $manager->expects(self::never())->method('get_subscription');
        $customer = new WSZ_Customer_Subscriptions($manager, $this->createMock(WSZ_Renewal_Engine::class));
        $order = new ManualExperienceOrder(823, 7, array('_wsz_subscription_id' => 821, '_wsz_manual_renewal_waiting' => 'yes', '_wsz_manual_reminder_due' => time() - 1), true);
        $GLOBALS['wsz_subs_test_orders'][823] = $order;
        $GLOBALS['wsz_manual_test_mail'] = array();
        $customer->send_manual_reminder(823);
        self::assertCount(0, $GLOBALS['wsz_manual_test_mail']);
        unset($GLOBALS['wsz_subs_test_orders'][823]);
    }

    public function test_cancelled_subscription_does_not_receive_a_reminder(): void
    {
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('acquire_lock')->willReturn(true);
        $sub = new ManualExperienceOrder(824, 7, array(), false);
        $sub->status = 'cancelled';
        $manager->method('get_subscription')->willReturn($sub);
        $manager->expects(self::never())->method('is_manual_renewal');
        $customer = new WSZ_Customer_Subscriptions($manager, $this->createMock(WSZ_Renewal_Engine::class));
        $order = new ManualExperienceOrder(825, 7, array('_wsz_subscription_id' => 824, '_wsz_manual_renewal_waiting' => 'yes', '_wsz_manual_reminder_due' => time() - 1), false);
        $GLOBALS['wsz_subs_test_orders'][825] = $order;
        $GLOBALS['wsz_manual_test_mail'] = array();
        $customer->send_manual_reminder(825);
        self::assertCount(0, $GLOBALS['wsz_manual_test_mail']);
        unset($GLOBALS['wsz_subs_test_orders'][825]);
    }

    public function test_other_customers_order_is_never_emailed(): void
    {
        self::assertTrue(class_exists(WSZ_Customer_Subscriptions::class), "Customer subscriptions integration is missing");
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('is_manual_renewal')->willReturn(true);
        $engine = $this->createMock(WSZ_Renewal_Engine::class);
        $customer = new WSZ_Customer_Subscriptions($manager, $engine);
        $GLOBALS['wsz_manual_test_mail'] = array();
        $customer->notify_manual_renewal(new ManualExperienceOrder(10, 7, array(), false), new ManualExperienceOrder(20, 8, array('_wsz_subscription_id'=>10), false));
        self::assertCount(0, $GLOBALS['wsz_manual_test_mail']);
    }

    public function test_failed_email_is_not_marked_as_sent_and_can_be_retried(): void
    {
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('acquire_lock')->willReturn(true);
        $manager->method('is_manual_renewal')->willReturn(true);
        $customer = new WSZ_Customer_Subscriptions($manager, $this->createMock(WSZ_Renewal_Engine::class));
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $order = new ManualExperienceOrder(20, 7, array('_wsz_subscription_id' => 10), false);
        $GLOBALS['wsz_manual_test_mail'] = array();
        $GLOBALS['wsz_manual_test_mail_result'] = false;
        $customer->notify_manual_renewal($sub, $order);
        self::assertSame('', $order->get_meta('_wsz_manual_renewal_notice_sent', true));
        $GLOBALS['wsz_manual_test_mail_result'] = true;
        $customer->notify_manual_renewal($sub, $order);
        self::assertCount(2, $GLOBALS['wsz_manual_test_mail']);
        self::assertSame('yes', $order->get_meta('_wsz_manual_renewal_notice_sent', true));
        unset($GLOBALS['wsz_manual_test_mail_result']);
    }

    public function test_unpaid_or_mismatched_payment_cannot_complete_subscription(): void
    {
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $manager->method('get_subscription')->willReturn(new ManualExperienceOrder(10, 7, array(), false));
        $engine = $this->createMock(WSZ_Renewal_Engine::class);
        $engine->expects(self::never())->method('handle_async_payment_complete');
        $customer = new WSZ_Customer_Subscriptions($manager, $engine);
        $customer->complete_paid_manual_renewal(20, 'pending', 'processing', new ManualExperienceOrder(20, 7, array('_wsz_subscription_id'=>10,'_wsz_manual_renewal_waiting'=>'yes'), false));
        $customer->complete_paid_manual_renewal(21, 'pending', 'processing', new ManualExperienceOrder(21, 8, array('_wsz_subscription_id'=>10,'_wsz_manual_renewal_waiting'=>'yes'), true));
    }

    public function test_successful_manual_payment_completes_cycle_once(): void
    {
        self::assertTrue(class_exists(WSZ_Customer_Subscriptions::class), "Customer subscriptions integration is missing");
        $manager = $this->createMock(WSZ_Subscription_Manager::class);
        $sub = new ManualExperienceOrder(10, 7, array(), false);
        $order = new ManualExperienceOrder(20, 7, array('_wsz_subscription_id'=>10,'_wsz_manual_renewal_waiting'=>'yes'), true);
        $manager->method('get_subscription')->with(10)->willReturn($sub);
        $engine = $this->createMock(WSZ_Renewal_Engine::class);
        $engine->expects(self::once())->method('handle_async_payment_complete')->with($sub, $order);
        $customer = new WSZ_Customer_Subscriptions($manager, $engine);
        $customer->complete_paid_manual_renewal(20, 'pending', 'processing', $order);
    }
}

final class ManualExperienceOrder extends WC_Order
{
    public array $items = array();
    private int $id; private int $customer; private array $meta; private bool $paid; private string $first_name; public string $status = 'pending';
    public function __construct(int $id, int $customer, array $meta, bool $paid, string $first_name = '') { $this->id=$id; $this->customer=$customer; $this->meta=$meta; $this->paid=$paid; $this->first_name=$first_name; }
    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function get_customer_id() { return $this->customer; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function get_items($types = array()) { return $this->items; }
    public function update_meta_data($key, $value) { $this->meta[$key]=$value; }
    public function is_paid() { return $this->paid; }
    public function needs_payment() { return !$this->paid; }
    public function get_billing_first_name() { return $this->first_name; }
    public function get_billing_email() { return 'customer@example.test'; }
    public function get_formatted_order_total() { return '€50.00'; }
    public function get_checkout_payment_url($on_checkout = false) { return 'https://example.test/pay/'.$this->id; }
}

final class ManualContractItem
{
    private string $contract;
    public function __construct(string $contract) { $this->contract = $contract; }
    public function get_meta($key, $single = true) { return '_wsz_contract_reference' === $key ? $this->contract : ''; }
}
