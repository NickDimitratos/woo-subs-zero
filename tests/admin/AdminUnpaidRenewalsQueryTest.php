<?php
use PHPUnit\Framework\TestCase;
final class AdminUnpaidRenewalsQueryTest extends TestCase
{
 public function test_unpaid_query_filters_by_subscription_link_in_both_order_stores():void{
  $script=__DIR__.'/../fixtures/unpaid-list.php';$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>/dev/null';exec($command,$lines,$status);
  self::assertSame(0,$status,'unpaid renewals page must provide storage-compatible queries');$result=json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);
  self::assertSame('_wsz_subscription_id',$result['legacy']['meta_query'][0]['key']);self::assertSame('EXISTS',$result['legacy']['meta_query'][0]['compare']);self::assertSame('_wsz_subscription_id',$result['hpos']['meta_query'][0]['key']);self::assertSame([],$result['unchanged']['meta_query']);
 }
}
