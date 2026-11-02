<?php
use PHPUnit\Framework\TestCase;
final class AdminUnpaidRenewalTest extends TestCase
{
 private function scenario(string $case):array {$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../fixtures/manual-admin-send.php').' '.escapeshellarg($case);exec($command,$lines,$status);self::assertSame(0,$status);return json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);}
 public function test_eligible_order_gets_one_payment_email_and_audit_record():void{$r=$this->scenario('eligible');self::assertSame('sent',$r['result']);self::assertSame(1,$r['sends']);self::assertSame('yes',$r['meta']['_wsz_manual_renewal_waiting']??'');self::assertNotEmpty($r['notes']);}
 public function test_explicit_resend_is_allowed():void{$r=$this->scenario('resend');self::assertSame('sent',$r['result']);self::assertSame(1,$r['sends']);}
 public function test_paid_cancelled_other_customer_and_automatic_collections_cannot_be_emailed():void{foreach(['paid','paid_during_save','customer','cancelled','bank','async','automatic'] as $case){$r=$this->scenario($case);self::assertNotSame('sent',$r['result'],$case);self::assertSame(0,$r['sends'],$case);}}
 public function test_disabled_and_failed_emails_are_reported_without_recording_success():void{foreach(['disabled','failure'] as $case){$r=$this->scenario($case);self::assertNotSame('sent',$r['result']);self::assertNotSame('yes',$r['meta']['_wsz_manual_renewal_notice_sent']??'');}}
 public function test_admin_payment_attempts_are_recorded_with_their_outcome():void{
  foreach(['eligible'=>'accepted','failure'=>'failed'] as $case=>$outcome){$r=$this->scenario($case);$history=$r['meta']['_wsz_manual_email_history']??[];self::assertCount(1,$history);self::assertSame('admin',$history[0]['type']);self::assertSame($outcome,$history[0]['result']);self::assertGreaterThan(0,$history[0]['time']);}
 }
 public function test_failed_exhausted_retry_can_be_switched_to_manual_without_sending_email():void{
  $r=$this->scenario('recover');self::assertSame('manual_enabled',$r['result']);self::assertTrue($r['manual']);self::assertSame(0,$r['sends']);
 }
 public function test_manual_recovery_rejects_unsafe_orders_and_active_retries():void{
  foreach(['recover_paid','recover_cancelled','recover_bank','recover_pending_retry','recover_newer_failed','recover_multiple','recover_sepa','recover_busy'] as $case){$r=$this->scenario($case);self::assertNotSame('manual_enabled',$r['result'],$case);self::assertFalse($r['manual'],$case);self::assertSame(0,$r['sends'],$case);}
 }
}
