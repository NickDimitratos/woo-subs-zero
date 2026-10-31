<?php
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-webhook-handler.php';
function status_header($status) {}
function get_transient($key) { return false; }
function set_transient($key, $value, $expiration) { return true; }
$order = new class extends WC_Order {
    public string $status = 'on-hold';
    public function get_id() { return 44; }
    public function get_payment_method() { return 'wsz_paynl_sepa'; }
    public function get_order_key() { return 'wc_customer_known_key'; }
    public function get_meta($key, $single = true) { return '_wsz_paynl_sepa_state' === $key ? 'pending' : ''; }
    public function is_paid() { return $this->status === 'processing'; }
    public function payment_complete($transaction_id = '') { $this->status = 'processing'; }
};
$GLOBALS['wsz_subs_test_orders'][44] = $order;
$_REQUEST = array('order_id' => 44, 'order_key' => 'wc_customer_known_key', 'status' => 'paid');
add_filter('wsz_subs_verify_gateway_exchange', static fn () => true);
register_shutdown_function(static function () use ($order) { echo "\nstatus=" . $order->status; });
(new WSZ_Webhook_Handler(new WSZ_Subscription_Manager()))->handle_gateway_exchange();
