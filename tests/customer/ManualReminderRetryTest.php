<?php
use PHPUnit\Framework\TestCase;
final class ManualReminderRetryTest extends TestCase
{
    private function scenario(string $case): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/manual-reminder-retry.php') . ' ' . escapeshellarg($case);
        exec($command, $lines, $status);
        self::assertSame(0, $status, 'The isolated reminder scenario must run');
        return json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
    }
    public function test_failed_send_schedules_one_retry_and_later_success_is_recorded(): void
    {
        $result=$this->scenario('success');
        self::assertSame(2,$result['attempts']);
        self::assertSame('yes',$result['meta']['_wsz_manual_reminder_sent']??'');
        self::assertSame(['failed','accepted'],array_column($result['meta']['_wsz_manual_email_history']??[],'result'));
        self::assertSame(['reminder','reminder'],array_column($result['meta']['_wsz_manual_email_history']??[],'type'));
        self::assertCount(0,$result['scheduled']);
    }
    public function test_retries_stop_after_three_additional_attempts(): void
    {
        $result=$this->scenario('exhaust');
        self::assertSame(4,$result['attempts']);
        self::assertCount(0,$result['scheduled']);
        self::assertStringContainsString('failed',strtolower(implode(' ', $result['notes'])));
    }
    public function test_payment_cancels_pending_retry(): void
    {
        $result=$this->scenario('paid');self::assertSame(1,$result['attempts']);self::assertCount(0,$result['scheduled']);
    }
    public function test_cancelled_subscription_and_duplicate_jobs_never_resend(): void
    {
        $cancelled=$this->scenario('cancelled');self::assertSame(1,$cancelled['attempts']);
        $duplicate=$this->scenario('duplicate');self::assertSame(2,$duplicate['attempts']);
    }
    public function test_transport_exception_is_retried(): void
    {
        $result=$this->scenario('exception');self::assertSame(2,$result['attempts']);self::assertSame('yes',$result['meta']['_wsz_manual_reminder_sent']??'');self::assertSame(['failed','accepted'],array_column($result['meta']['_wsz_manual_email_history']??[],'result'));
    }
    public function test_retry_scheduling_failure_is_noted(): void
    {
        $result=$this->scenario('schedule_failure');self::assertSame(1,$result['attempts']);self::assertStringContainsString('schedule',strtolower(implode(' ', $result['notes'])));
    }
    public function test_two_customers_can_each_have_a_scheduled_reminder(): void
    {
        $result=$this->scenario('multi_order_schedule');self::assertCount(2,$result['scheduled']);
    }
}
