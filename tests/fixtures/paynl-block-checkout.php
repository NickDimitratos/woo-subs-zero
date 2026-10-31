<?php

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-checkout-handler.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-sepa-context.php';
require dirname(__DIR__, 2) . '/src/Payment/Gateway/class-wsz-paynl-sepa-checkout.php';
define('WC_VERSION', $argv[4] ?? '10.7.0');
function wp_salt($scheme = 'auth') { return 'mock-only-checkout-salt'; }
function get_woocommerce_currency() { return 'EUR'; }
function has_block($name) { return true; }
function woocommerce_register_additional_checkout_field($field) {}

function get_option($name, $default = false)
{
    return 'wsz_subs_options' === $name ? array('enable_manual_renewals' => $GLOBALS['manual'], 'enable_paynl_tokens' => $GLOBALS['card_tokens'],
        'enable_paynl_sepa' => $GLOBALS['sepa'] ? 'yes' : 'no',
        'paynl_sepa_creditor_name' => 'Example Shop', 'paynl_sepa_creditor_id' => 'NL00ZZZ123456780000',
        'paynl_sepa_terms_url' => 'https://shop.example/mandate') : $default;
}

function wp_add_inline_script($handle, $script, $position = 'after')
{
    $GLOBALS['scripts'][] = array('handle' => $handle, 'script' => $script, 'position' => $position);
    return true;
}

class PayNLBlockCheckoutFixture extends WSZ_Checkout_Handler
{
    public function cart_contains_subscription_items($cart = null): bool
    {
        return $GLOBALS['subscription_cart'];
    }
}

$GLOBALS['manual'] = $argv[1] ?? 'no';
$GLOBALS['card_tokens'] = $argv[5] ?? 'yes';
$GLOBALS['sepa'] = 'sepa' === ($argv[3] ?? '');
$GLOBALS['subscription_cart'] = 'ordinary' !== ($argv[2] ?? 'subscription');
$GLOBALS['wsz_test_execute_actions'] = true;
$GLOBALS['scripts'] = array();
$GLOBALS['wsz_wc_test_container'] = new class {
    public function payment_gateways() {
        return new class {
            public function payment_gateways(): array {
                return array_fill_keys(array('pay_gateway_ideal', 'pay_gateway_incasso', 'pay_gateway_banktransfer', 'pay_gateway_visa', 'stripe', 'pay_gateway_custom'), new stdClass());
            }
        };
    }
};
add_filter('wsz_subs_paynl_recurring_credentials', static fn () => array('service_id' => 'SL-1111-2222', 'username' => 'AT-1111-2222', 'password' => 'mock-only'));
add_filter('wsz_subs_tokenized_gateway_ids', static fn (array $ids): array => array_merge($ids, array('pay_gateway_custom')));
(new PayNLBlockCheckoutFixture(new WSZ_Subscription_Manager()))->init();
do_action('wp_enqueue_scripts');
echo json_encode($GLOBALS['scripts'], JSON_THROW_ON_ERROR);
