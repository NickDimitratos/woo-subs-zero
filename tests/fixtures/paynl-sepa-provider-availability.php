<?php
function get_option($name, $default = false) {
    return 'wsz_subs_options' === $name ? array('enable_paynl_sepa' => 'yes', 'paynl_sepa_creditor_name' => 'Test', 'paynl_sepa_creditor_id' => 'NL00ZZZ123456780000', 'paynl_sepa_terms_url' => 'https://shop.example/terms') : $default;
}
function wp_salt($scheme = 'auth') { return 'unit-test-salt'; }
function get_woocommerce_currency() { return 'EUR'; }
class PPMFWC_Helper_Data {
    public static bool $enabled = false;
    public static array $requested = array();
    public static function isTestMode() { return false; }
    public static function isOptionAvailable($id) { self::$requested[] = $id; return self::$enabled; }
}
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-checkout-handler.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-sepa-context.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-sepa-checkout.php';
add_filter('wsz_subs_paynl_recurring_credentials', static fn () => array('service_id' => 'SL-1111-2222', 'username' => 'AT-1111-2222', 'password' => 'mock-only'));
$unavailable = WSZ_PayNL_SEPA_Checkout::available('pay_gateway_ideal');
PPMFWC_Helper_Data::$enabled = true;
$available = WSZ_PayNL_SEPA_Checkout::available('pay_gateway_ideal');
PPMFWC_Helper_Data::$enabled = false;
add_filter('wsz_subs_paynl_sepa_provider_available', static fn () => true);
$explicit = WSZ_PayNL_SEPA_Checkout::available('pay_gateway_ideal');
echo json_encode(array('unavailable' => $unavailable, 'available' => $available, 'explicit' => $explicit, 'checked_ids' => PPMFWC_Helper_Data::$requested));
