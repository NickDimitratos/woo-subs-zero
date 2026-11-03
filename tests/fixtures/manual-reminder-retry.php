<?php
// Isolated Action Scheduler and mailer doubles exercise the production handler.
define('ABSPATH', __DIR__ . '/'); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400);
function __($s, $d=null){return $s;} function is_email($s){return (bool)filter_var($s,FILTER_VALIDATE_EMAIL);} function get_option($k,$d=false){return $d;}
function wc_get_order($id){return $GLOBALS['orders'][$id]??null;}
function WC(){return $GLOBALS['wc'];}
function wc_get_logger(){return new class {public function warning($m,$c=array()) {}};}
function as_schedule_single_action($time,$hook,$args=array(),$group='',$unique=false){if(!empty($GLOBALS['schedule_fail']))return 0;if($unique){foreach($GLOBALS['scheduled'] as $a){if($a['hook']===$hook&&$a['group']===$group)return 0;}}$GLOBALS['scheduled'][]=compact('time','hook','args','group','unique');return count($GLOBALS['scheduled']);}
function as_has_scheduled_action($hook,$args=null,$group=''){foreach($GLOBALS['scheduled'] as $a){if($a['hook']===$hook&&$a['args']===$args&&$a['group']===$group)return true;}return false;}
function as_unschedule_all_actions($hook,$args=array(),$group=''){$GLOBALS['scheduled']=array_values(array_filter($GLOBALS['scheduled'],fn($a)=>!($a['hook']===$hook&&$a['args']===$args&&$a['group']===$group)));}
class WC_Order {public int $id;public int $customer=7;public string $status='on-hold';public bool $paid=false;public array $meta=array();public array $notes=array();public function __construct($id){$this->id=$id;}public function get_id(){return $this->id;}public function get_customer_id(){return $this->customer;}public function get_status(){return $this->status;}public function needs_payment(){return !$this->paid;}public function get_meta($k,$single=true){return $this->meta[$k]??'';}public function update_meta_data($k,$v){$this->meta[$k]=$v;}public function save(){}public function get_billing_email(){return 'test@example.test';}public function add_order_note($n){$this->notes[]=$n;}public function get_date_created(){return new DateTimeImmutable();}}
class WSZ_Subscription_Manager {const ACTION_GROUP='wsz-subscriptions';public WC_Order $sub;public function acquire_lock($n,$id,$ttl){return true;}public function release_lock($n,$id){}public function get_subscription($id){return $this->sub;}public function is_manual_renewal($s){return true;}}
class WSZ_Renewal_Engine {}
class WSZ_Manual_Renewal_Email {public function is_enabled(){return true;}public function trigger($s,$o){$GLOBALS['attempts']++;$v=array_shift($GLOBALS['results']);if($v==='throw')throw new RuntimeException('transport failure');return $v;}}
class FakeMailer {public function get_emails(){return array('WSZ_Manual_Renewal_Reminder_Email'=>new WSZ_Manual_Renewal_Email());}}
class FakeWC {public function mailer(){return new FakeMailer();}}
require __DIR__.'/../../includes/class-wsz-customer-subscriptions.php';
$case=$argv[1]??'';$GLOBALS['scheduled']=array();$GLOBALS['attempts']=0;$GLOBALS['wc']=new FakeWC();$GLOBALS['results']=array(false,true);
$sub=new WC_Order(12);$order=new WC_Order(21);$order->meta=array('_wsz_subscription_id'=>12,'_wsz_manual_renewal_waiting'=>'yes','_wsz_manual_reminder_due'=>time()-10);$GLOBALS['orders'][21]=$order;$manager=new WSZ_Subscription_Manager();$manager->sub=$sub;$handler=new WSZ_Customer_Subscriptions($manager,new WSZ_Renewal_Engine());
if($case==='multi_order_schedule'){$second=new WC_Order(22);$second->meta['_wsz_subscription_id']=12;$handler->schedule_manual_reminder($sub,$order);$handler->schedule_manual_reminder($sub,$second);echo json_encode(array('scheduled'=>$GLOBALS['scheduled'])).PHP_EOL;exit;}
if($case==='exhaust')$GLOBALS['results']=array(false,false,false,false,false);
if($case==='exception')$GLOBALS['results']=array('throw',true);
if($case==='schedule_failure')$GLOBALS['schedule_fail']=true;
$handler->send_manual_reminder(21);
if($case==='paid'){$order->paid=true;$handler->cancel_finished_reminder(21,'pending','completed',$order);}
if($case==='cancelled'){$sub->status='cancelled';}
if(in_array($case,array('success','paid','cancelled','exhaust','exception','duplicate'),true)) {
 for($i=1;$i<=4;$i++) {if(empty($GLOBALS['scheduled']))break;$action=array_shift($GLOBALS['scheduled']);$order->meta['_wsz_manual_reminder_retry_due']=time()-1;$handler->send_manual_reminder(21,$action['args']['attempt']??0);if($case==='duplicate')$handler->send_manual_reminder(21,$action['args']['attempt']??0);}
}
if($case==='exhaust')$handler->send_manual_reminder(21,3);
echo json_encode(array('attempts'=>$GLOBALS['attempts'],'scheduled'=>$GLOBALS['scheduled'],'meta'=>$order->meta,'notes'=>$order->notes)).PHP_EOL;
