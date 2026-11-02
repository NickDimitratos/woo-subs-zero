<?php
use PHPUnit\Framework\TestCase;
final class AdminUnpaidRenewalsQueryTest extends TestCase
{
 public function test_unpaid_query_filters_by_subscription_link_in_both_order_stores():void{
  $script=__DIR__.'/../fixtures/unpaid-list.php';$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>/dev/null';exec($command,$lines,$status);
  self::assertSame(0,$status,'unpaid renewals page must provide storage-compatible queries');$result=json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);
  self::assertSame('_wsz_subscription_id',$result['legacy']['meta_query'][0]['key']);self::assertSame('EXISTS',$result['legacy']['meta_query'][0]['compare']);self::assertSame('_wsz_subscription_id',$result['hpos']['meta_query'][0]['key']);self::assertSame([],$result['unchanged']['meta_query']);
 }
 public function test_unpaid_list_shows_order_payment_method_and_empty_state_spans_all_columns():void{
  $script=escapeshellarg(__DIR__.'/../fixtures/unpaid-payment-method.php');
  exec(escapeshellarg(PHP_BINARY).' '.$script, $lines, $status);
  self::assertSame(0,$status);$html=implode("\n",$lines);
  self::assertStringContainsString('<th scope="col">Payment Method</th>',$html);
  self::assertStringContainsString('<td>Visa</td>',$html);
  self::assertStringContainsString('<td>gateway_id</td>',$html);
  self::assertStringContainsString('<td>Not set</td>',$html);
  self::assertStringContainsString('Payment email (admin) — Accepted by mailer',$html);
  self::assertStringContainsString('Payment reminder — Send failed',$html);
  self::assertStringContainsString('2023-11-14',$html);
  self::assertStringContainsString('Earlier payment email accepted by mailer; date unknown',$html);
  self::assertStringContainsString('No payment email activity recorded',$html);
  self::assertSame(1,substr_count($html,'name="action" value="wsz_enable_manual_recovery"'));
  self::assertStringContainsString('wsz_enable_manual_recovery_1',$html);
  self::assertStringContainsString('Changes future renewals to manual. No email is sent yet.',$html);
  exec(escapeshellarg(PHP_BINARY).' '.$script.' empty',$empty,$emptyStatus);
  self::assertSame(0,$emptyStatus);
  self::assertStringContainsString('colspan="9"',implode("\n",$empty));
 }
}
