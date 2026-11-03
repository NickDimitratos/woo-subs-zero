<?php
require dirname(__DIR__) . '/bootstrap.php';
function get_option($name, $default = false) { return 'wsz_subs_options' === $name ? array('enable_paynl_tokens'=>'yes') : $default; }
function update_option($name, $value, $autoload = null) { return true; }
function status_header($status) {}
require dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-payment-token.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-webhook-handler.php';
add_filter('wsz_subs_paynl_recurring_credentials', static fn()=>array('service_id'=>'SL-1111-2222','username'=>'AT-1111-2222','password'=>'mock-signing-secret'));
$order = new class extends WC_Order {
    public array $meta = array('transactionId'=>'EX-2345-2238-9812');
    public function get_id() { return 10; }
    public function get_type() { return 'shop_order'; }
    public function get_status() { return 'processing'; }
    public function get_customer_id() { return 5; }
    public function get_payment_method() { return 'pay_gateway_visa'; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function save() {}
    public function get_total() { return 50; }
};
$GLOBALS['wsz_subs_test_orders'][10] = $order;
$_REQUEST = array('action'=>'token','recurring_id'=>'VY-9212-9171-2390','extra1'=>'10','order_id'=>'10');
if (($argv[2] ?? '') === 'signed') {
    // CLI normally has an empty php://input; supply a repeatable signed request stream.
    $GLOBALS['wsz_mock_raw_body'] = json_encode(array('action'=>'token','order_id'=>'EX-2345-2238-9812','extra1'=>'10','recurring_id'=>'VY-9212-9171-2390'));
    $_REQUEST['recurring_id'] = 'VY-9999-9999-9999';
    $_SERVER['HTTP_SIGNATURE_METHOD'] = 'HMAC';
    $_SERVER['HTTP_SIGNATURE_ALGORITHM'] = 'SHA256';
    $_SERVER['HTTP_SIGNATURE_KEYID'] = 'AT-1111-2222';
    $_SERVER['HTTP_SIGNATURE'] = hash_hmac('sha256', $GLOBALS['wsz_mock_raw_body'], 'mock-signing-secret');
    class WSZ_Mock_Php_Input_Stream {
        public $context;
        private int $offset = 0;
        public function stream_open($path, $mode, $options, &$opened_path) { $this->offset = 0; return $path === 'php://input'; }
        public function stream_read($count) { $part = substr($GLOBALS['wsz_mock_raw_body'], $this->offset, $count); $this->offset += strlen($part); return $part; }
        public function stream_eof() { return $this->offset >= strlen($GLOBALS['wsz_mock_raw_body']); }
        public function stream_stat() { return array(); }
        public function stream_seek($offset, $whence) { if (SEEK_SET === $whence && 0 === $offset) { $this->offset = 0; return true; } return false; }
    }
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', WSZ_Mock_Php_Input_Stream::class);
}
register_shutdown_function(static function () use ($order) { $id=(int)$order->get_meta('_payment_token_id'); $token=$id>0?WC_Payment_Tokens::get($id):null; echo "\nTOKEN_SAVED=" . ($id > 0 ? 'yes' : 'no') . "\nTOKEN_VALUE=" . ($token ? $token->get_token() : ''); });
$entry = PHP_SAPI === 'cli-server' ? trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') : ($argv[1] ?? '');
if ($entry === 'generic') {
    if (!function_exists('get_transient')) { function get_transient($key) { return false; } }
    if (!function_exists('set_transient')) { function set_transient($key, $value, $expiration) { return true; } }
    (new WSZ_Webhook_Handler(new WSZ_Subscription_Manager()))->handle_gateway_exchange();
} else {
    (new WSZ_PayNL_Gateway_Integration(new WSZ_Subscription_Manager()))->intercept_paynl_token_exchange();
}
