<?php

define('WP_CLI', true);
function get_option($name, $default = false) { return 'wsz_subs_options' === $name ? array('enable_paynl_tokens' => 'yes') : $default; }
function wc_get_order_statuses() { return array('wc-active' => 'Active', 'wc-cancelled' => 'Cancelled'); }
function remove_action($hook, $callback) { $GLOBALS['audit_removed_cron'] = 'shutdown' === $hook && '_wp_cron' === $callback; }
function wc_get_orders($args) {
    $GLOBALS['audit_queries'][] = $args;
    return array_slice($GLOBALS['audit_orders'], ($args['paged'] - 1) * $args['limit'], $args['limit']);
}
class WP_CLI {
    public static function line($text) { echo $text . "\n"; }
    public static function error($text) { throw new RuntimeException($text); }
}
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
class PayNLAuditFixtureOrder extends WC_Order {
    public static int $writes = 0;
    public function __construct(private int $id, private string $gateway) {}
    public function get_id() { return $this->id; }
    public function get_type() { return 'shop_subscription'; }
    public function get_status() { return 'active'; }
    public function get_customer_id() { return 5; }
    public function get_payment_method() { return $this->gateway; }
    public function get_meta($key, $single = true) { return '_requires_manual_renewal' === $key ? 'no' : ''; }
    public function update_meta_data($key, $value) { ++self::$writes; }
    public function save() { ++self::$writes; }
}
$GLOBALS['audit_queries'] = array();
$GLOBALS['audit_orders'] = array();
for ($id = 1; $id <= 101; ++$id) {
    $gateway = 1 === $id ? 'pay_gateway_ideal' : (101 === $id ? 'pay_gateway_incasso' : 'stripe');
    $GLOBALS['audit_orders'][] = new PayNLAuditFixtureOrder($id, $gateway);
}
require dirname(__DIR__, 2) . '/scripts/audit-paynl-subscriptions.php';
fwrite(STDERR, json_encode(array('queries' => $GLOBALS['audit_queries'], 'writes' => PayNLAuditFixtureOrder::$writes, 'cron_disabled' => $GLOBALS['audit_removed_cron'] ?? false)));
