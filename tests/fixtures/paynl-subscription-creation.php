<?php

// A fresh process lets the real creation path instantiate a stateful WooCommerce order double.
require dirname(__DIR__) . '/bootstrap.php';

function get_option($name, $default = false)
{
    return 'wsz_subs_options' === $name ? array('enable_paynl_tokens' => 'yes') : $default;
}

class PayNLCreationOrder extends WC_Order
{
    private array $meta = array();
    private string $gateway = '';

    public function get_id() { return $this instanceof WSZ_Order_Subscription ? 2 : 1; }
    public function get_customer_id() { return 5; }
    public function get_payment_method() { return $this->gateway; }
    public function set_payment_method($id) { $this->gateway = $id; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function __call($name, $args) { return ''; }
}

class WSZ_Order_Subscription extends PayNLCreationOrder
{
    public function get_type() { return 'shop_subscription'; }
}

require dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';

$order = new PayNLCreationOrder();
$order->set_payment_method($argv[1]);
$subscription = (new WSZ_Subscription_Manager())->create_subscription_from_order($order, array('next_payment' => time() + 3600));

echo json_encode(array(
    'manual' => $subscription->get_meta('_requires_manual_renewal'),
    'reason' => $subscription->get_meta('_wsz_manual_renewal_fallback_reason'),
    'gateway' => $subscription->get_payment_method(),
    'subscription_ids' => $order->get_meta('_wsz_subscription_ids'),
), JSON_THROW_ON_ERROR);
