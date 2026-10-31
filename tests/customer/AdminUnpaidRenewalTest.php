<?php
use PHPUnit\Framework\TestCase;
final class AdminUnpaidRenewalTest extends TestCase
{
 private function scenario(string $case):array {$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../fixtures/manual-admin-send.php').' '.escapeshellarg($case);exec($command,$lines,$status);self::assertSame(0,$status);return json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);}
 public function test_eligible_order_gets_one_payment_email_and_audit_record():void{$r=$this->scenario('eligible');self::assertSame('sent',$r['result']);self::assertSame(1,$r['sends']);self::assertSame('yes',$r['meta']['_wsz_manual_renewal_waiting']??'');self::assertNotEmpty($r['notes']);}
 public function test_explicit_resend_is_allowed():void{$r=$this->scenario('resend');self::assertSame('sent',$r['result']);self::assertSame(1,$r['sends']);}
 public function test_paid_cancelled_other_customer_and_automatic_collections_cannot_be_emailed():void{foreach(['paid','paid_during_save','customer','cancelled','bank','async','automatic'] as $case){$r=$this->scenario($case);self::assertNotSame('sent',$r['result'],$case);self::assertSame(0,$r['sends'],$case);}}
 public function test_disabled_and_failed_emails_are_reported_without_recording_success():void{foreach(['disabled','failure'] as $case){$r=$this->scenario($case);self::assertNotSame('sent',$r['result']);self::assertNotSame('yes',$r['meta']['_wsz_manual_renewal_notice_sent']??'');}}
}
