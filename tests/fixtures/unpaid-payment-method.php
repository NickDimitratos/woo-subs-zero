<?php
define('ABSPATH', __DIR__ . '/');
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_attr__($s, $d = null) { return esc_html($s); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function wp_kses_post($s) { return $s; }
function current_user_can($cap) { return true; }
function get_option($name) { return 'Y-m-d'; }
function wp_date($format, $timestamp) { return gmdate('Y-m-d H:i', $timestamp); }
function wc_get_order_status_name($status) { return $status; }
function admin_url($path) { return '/wp-admin/' . $path; }
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="' . esc_attr($action) . '">'; }
function wc_get_orders($args) {
    return (object) array('orders' => ($GLOBALS['argv'][1] ?? '') === 'empty' ? array() : array(new WC_Order('Visa', 'visa', array('_wsz_manual_email_history' => array(array('type' => 'admin', 'result' => 'accepted', 'time' => 1700000000), array('type' => 'reminder', 'result' => 'failed', 'time' => 1700000060)))), new WC_Order('', 'gateway_id', array('_wsz_manual_renewal_notice_sent' => 'yes')), new WC_Order('', '')), 'max_num_pages' => 1);
}
class WC_Order {
    private string $title;
    private string $method;
    private array $meta;
    public function __construct(string $title, string $method, array $meta = array()) { $this->title = $title; $this->method = $method; $this->meta = $meta; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function get_id() { return 1; }
    public function get_date_created() { return null; }
    public function get_edit_order_url() { return '/order'; }
    public function get_order_number() { return '1'; }
    public function get_formatted_billing_full_name() { return ''; }
    public function get_billing_email() { return ''; }
    public function get_formatted_order_total() { return '0'; }
    public function get_status() { return 'failed'; }
    public function get_payment_method_title() { return $this->title; }
    public function get_payment_method() { return $this->method; }
}
class WSZ_Subscription_Manager { public function get_subscription($id) { return null; } }
class WSZ_Customer_Subscriptions { public function admin_payment_eligibility($order) { return 'automatic_renewal'; } public function manual_recovery_eligibility($order) { return 'Visa' === $order->get_payment_method_title() ? 'recovery_ready' : 'recovery_review'; } }
require __DIR__ . '/../../includes/admin/class-wsz-admin-unpaid-renewals.php';
(new WSZ_Admin_Unpaid_Renewals(new WSZ_Subscription_Manager(), new WSZ_Customer_Subscriptions()))->render();
