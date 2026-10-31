<?php
require dirname(__DIR__) . '/bootstrap.php';
function get_option($name, $default = false) { return 'wsz_subs_options' === $name ? array('enable_paynl_tokens'=>'yes') : $default; }
function status_header($status) {}
function get_transient($key) { return false; }
function set_transient($key, $value, $expiration) { return true; }
require dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-webhook-handler.php';
add_filter('wsz_subs_paynl_recurring_credentials', static fn()=>array('service_id'=>'SL-1111-2222','username'=>'AT-1111-2222','password'=>'mock-signing-secret'));
$order = new class extends WC_Order {
    public string $status = 'pending';
    public function get_id() { return 44; }
    public function get_type() { return 'shop_order'; }
    public function get_customer_id() { return 5; }
    public function get_status() { return $this->status; }
    public function get_payment_method() { return 'pay_gateway_visa'; }
    public function get_order_key() { return 'wc_customer_known_key'; }
    public function get_total() { return 50.0; }
    public function get_currency() { return 'EUR'; }
    public function get_meta($key, $single = true) { return ''; }
    public function is_paid() { return $this->status === 'processing'; }
    public function payment_complete($transaction_id = '') { $this->status = 'processing'; }
    public function update_status($status, $note = '', $manual = false) { $this->status = $status; }
};
$GLOBALS['wsz_subs_test_orders'][44] = $order;
$_REQUEST = array('order_id'=>44,'order_key'=>'wc_customer_known_key','status'=>'paid');
if (str_starts_with((string)($argv[1] ?? ''), 'signed')) {
    $case = $argv[1];
    if ('signed' === $case) { unset($_REQUEST['order_key'], $_REQUEST['order_id']); }
    if ('signed_after_paid' === $case) { $order->status = 'processing'; }
    $GLOBALS['wsz_mock_raw_body'] = json_encode(array('type'=>'order','event'=>'status_changed','object'=>array(
        'serviceId'=>$case==='signed_wrong_service'?'SL-9999-9999':'SL-1111-2222',
        'reference'=>$case==='signed_wrong_reference'?'WSZ-R45':'WSZ-R44',
        'status'=>array('code'=>$case==='signed_pending'?50:($case==='signed_after_paid'?-60:100)),
        'amount'=>array('value'=>$case==='signed_wrong_amount'?1:5000,'currency'=>$case==='signed_wrong_currency'?'USD':'EUR'),
    )));
    $_SERVER['HTTP_SIGNATURE_METHOD']='HMAC';$_SERVER['HTTP_SIGNATURE_ALGORITHM']='SHA256';$_SERVER['HTTP_SIGNATURE_KEYID']='AT-1111-2222';
    $_SERVER['HTTP_SIGNATURE']=hash_hmac('sha256',$GLOBALS['wsz_mock_raw_body'],$case==='signed_tampered'?'wrong-secret':'mock-signing-secret');
    class WSZ_Mock_Card_Input_Stream {
        public $context;private int $offset=0;
        public function stream_open($path,$mode,$options,&$opened_path){$this->offset=0;return $path==='php://input';}
        public function stream_read($count){$part=substr($GLOBALS['wsz_mock_raw_body'],$this->offset,$count);$this->offset+=strlen($part);return $part;}
        public function stream_eof(){return $this->offset>=strlen($GLOBALS['wsz_mock_raw_body']);}
        public function stream_stat(){return array();}
        public function stream_seek($offset,$whence){if(SEEK_SET===$whence && 0===$offset){$this->offset=0;return true;}return false;}
    }
    stream_wrapper_unregister('php');stream_wrapper_register('php',WSZ_Mock_Card_Input_Stream::class);
}
register_shutdown_function(static function()use($order){echo "\nORDER_STATUS=".$order->status;});
(new WSZ_Webhook_Handler(new WSZ_Subscription_Manager()))->handle_gateway_exchange();
