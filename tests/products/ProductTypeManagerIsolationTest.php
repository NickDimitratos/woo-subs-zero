<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

if (!function_exists('absint')) {
    function absint($value)
    {
        return abs((int) $value);
    }
}

if (!function_exists('esc_js')) {
    function esc_js($text)
    {
        return (string) $text;
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen()
    {
        $screen = new stdClass();
        $screen->id = 'product';

        return $screen;
    }
}

if (!function_exists('woocommerce_simple_add_to_cart')) {
    function woocommerce_simple_add_to_cart()
    {
        $GLOBALS['km_test_simple_add_to_cart_calls'] = (int) ($GLOBALS['km_test_simple_add_to_cart_calls'] ?? 0) + 1;
    }
}

if (!function_exists('woocommerce_variable_add_to_cart')) {
    function woocommerce_variable_add_to_cart()
    {
        $GLOBALS['km_test_variable_add_to_cart_calls'] = (int) ($GLOBALS['km_test_variable_add_to_cart_calls'] ?? 0) + 1;
    }
}

if (!function_exists('wc_get_price_decimals')) {
    function wc_get_price_decimals()
    {
        return 2;
    }
}

if (!function_exists('wc_get_product')) {
    function wc_get_product($product_id)
    {
        $product_id = (int) $product_id;

        return $GLOBALS['km_test_wc_products'][$product_id] ?? null;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-product-type-manager.php';

final class ProductTypeManagerIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['km_test_wc_products'] = array();
        $GLOBALS['km_checkout_test_products'] = &$GLOBALS['km_test_wc_products'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['km_test_wc_products']);
        unset($GLOBALS['km_checkout_test_products']);

        parent::tearDown();
    }

    public function test_map_product_class_leaves_non_subscription_types_unchanged(): void
    {
        $manager = new WSZ_Product_Type_Manager();

        $this->assertSame('WC_Product_Simple', $manager->map_product_class('WC_Product_Simple', 'simple'));
        $this->assertSame('WC_Product_Variable', $manager->map_product_class('WC_Product_Variable', 'variable'));
        $this->assertSame('WC_Product_Grouped', $manager->map_product_class('WC_Product_Grouped', 'grouped'));
    }

    public function test_register_product_types_preserves_existing_types(): void
    {
        $manager = new WSZ_Product_Type_Manager();

        $existing = array(
            'simple' => 'Simple product',
            'variable' => 'Variable product',
        );

        $types = $manager->register_product_types($existing);

        $this->assertSame('Simple product', $types['simple']);
        $this->assertSame('Variable product', $types['variable']);
        $this->assertArrayHasKey(WSZ_Product_Type_Manager::SIMPLE_TYPE, $types);
        $this->assertArrayHasKey(WSZ_Product_Type_Manager::VARIABLE_TYPE, $types);
    }

    public function test_extend_product_data_tabs_only_appends_wsz_show_classes_to_show_if_tabs(): void
    {
        $manager = new WSZ_Product_Type_Manager();

        $tabs = array(
            'general' => array('class' => array('show_if_simple')),
            'inventory' => array('class' => array('show_if_variable')),
            'shipping' => array('class' => array('hide_if_virtual')),
            'attribute' => array('class' => array('show_if_variable')),
            'variations' => array('class' => array('show_if_variable')),
            'custom_tab' => array('class' => array('keep-me')),
        );

        $extended = $manager->extend_product_data_tabs($tabs);

        $this->assertContains('show_if_wsz_subscription', $extended['general']['class']);
        $this->assertContains('show_if_wsz_variable_subscription', $extended['general']['class']);
        $this->assertContains('show_if_wsz_subscription', $extended['inventory']['class']);
        $this->assertContains('show_if_wsz_variable_subscription', $extended['inventory']['class']);
        $this->assertContains('show_if_wsz_variable_subscription', $extended['attribute']['class']);
        $this->assertContains('show_if_wsz_variable_subscription', $extended['variations']['class']);
        $this->assertSame(array('hide_if_virtual'), $extended['shipping']['class']);
        $this->assertSame(array('keep-me'), $extended['custom_tab']['class']);
    }

    public function test_render_admin_visibility_script_toggles_only_wsz_custom_field_group(): void
    {
        $manager = new WSZ_Product_Type_Manager();

        ob_start();
        $manager->render_admin_visibility_script();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString("$('.show_if_' + coreType).addClass('show_if_' + kmType);", $output);
        $this->assertStringContainsString("$('.hide_if_' + coreType).addClass('hide_if_' + kmType);", $output);
        $this->assertStringContainsString("addVisibilityAliasClasses('simple', kmSimpleType);", $output);
        $this->assertStringContainsString("$('.km-subscription-fields').toggle(isKmType);", $output);
        $this->assertStringNotContainsString("$('.show_if_wsz_subscription, .show_if_wsz_variable_subscription').toggle(isKmType);", $output);
    }

    public function test_save_subscription_fields_ignores_non_wsz_product_types(): void
    {
        $_POST = array(
            '_wsz_subscription_period' => 'month',
            '_wsz_subscription_interval' => '1',
            '_wsz_subscription_length' => '4',
            '_wsz_signup_fee' => '9.99',
            '_wsz_sync_day' => '3',
        );

        $manager = new WSZ_Product_Type_Manager();
        $product = new ProductTypeManagerDummyProduct('simple');

        $manager->save_subscription_fields($product);

        $this->assertSame(array(), $product->get_updated_meta());
    }

    public function test_save_subscription_fields_writes_meta_for_wsz_subscription_type(): void
    {
        $_POST = array(
            '_wsz_subscription_period' => 'month',
            '_wsz_subscription_interval' => '1',
            '_wsz_subscription_length' => '4',
            '_wsz_signup_fee' => '9.99',
            '_wsz_sync_day' => '3',
        );

        $manager = new WSZ_Product_Type_Manager();
        $product = new ProductTypeManagerDummyProduct(WSZ_Product_Type_Manager::SIMPLE_TYPE);

        $manager->save_subscription_fields($product);

        $meta = $product->get_updated_meta();

        $this->assertSame('yes', $meta['_wsz_subscription_enabled']);
        $this->assertSame('month', $meta['_wsz_subscription_period']);
        $this->assertSame(1, $meta['_wsz_subscription_interval']);
        $this->assertSame(4, $meta['_wsz_subscription_length']);
        $this->assertSame('9.99', $meta['_wsz_signup_fee']);
        $this->assertSame(3, $meta['_wsz_sync_day']);
        $this->assertSame('yes', $meta['_virtual']);
        $this->assertSame('no', $meta['_downloadable']);
        $this->assertTrue($product->get_virtual_flag());
        $this->assertFalse($product->get_downloadable_flag());
    }

    public function test_filter_product_is_virtual_for_wsz_product_types(): void
    {
        $manager = new WSZ_Product_Type_Manager();

        $km_simple = new ProductTypeManagerDummyProduct(WSZ_Product_Type_Manager::SIMPLE_TYPE);
        $km_variable = new ProductTypeManagerDummyProduct(WSZ_Product_Type_Manager::VARIABLE_TYPE);

        $this->assertTrue($manager->filter_product_is_virtual(false, $km_simple));
        $this->assertTrue($manager->filter_product_is_virtual(false, $km_variable));
    }

    public function test_filter_product_is_virtual_preserves_non_wsz_types(): void
    {
        $manager = new WSZ_Product_Type_Manager();

        $simple = new ProductTypeManagerDummyProduct('simple');

        $this->assertFalse($manager->filter_product_is_virtual(false, $simple));
        $this->assertTrue($manager->filter_product_is_virtual(true, $simple));
    }

    public function test_render_wsz_simple_add_to_cart_uses_woocommerce_renderer(): void
    {
        $GLOBALS['km_test_simple_add_to_cart_calls'] = 0;

        $manager = new WSZ_Product_Type_Manager();
        $manager->render_wsz_simple_add_to_cart();

        $this->assertSame(1, (int) $GLOBALS['km_test_simple_add_to_cart_calls']);
    }

    public function test_render_wsz_variable_add_to_cart_uses_woocommerce_renderer(): void
    {
        $GLOBALS['km_test_variable_add_to_cart_calls'] = 0;

        $manager = new WSZ_Product_Type_Manager();
        $manager->render_wsz_variable_add_to_cart();

        $this->assertSame(1, (int) $GLOBALS['km_test_variable_add_to_cart_calls']);
    }

    public function test_apply_plan_total_installments_splits_fixed_term_price_without_double_division(): void
    {
        $manager = new WSZ_Product_Type_Manager();

        $parent = new ProductTypeManagerDummyProduct(
            WSZ_Product_Type_Manager::VARIABLE_TYPE,
            901,
            100.00,
            array(
                '_wsz_subscription_enabled' => 'yes',
                '_wsz_subscription_length' => 4,
            )
        );

        $variation = new ProductTypeManagerDummyProduct('variation', 902, 100.00, array(), 901);

        $GLOBALS['km_test_wc_products'][901] = $parent;

        $cart = new ProductTypeManagerDummyCart(
            array(
                'item_1' => array(
                    'data' => $variation,
                ),
            )
        );

        $manager->apply_plan_total_installments($cart);
        $this->assertSame(25.00, (float) $variation->get_price());
        $this->assertSame(100.00, (float) $cart->cart_contents['item_1']['wsz_plan_total_price']);

        $manager->apply_plan_total_installments($cart);
        $this->assertSame(25.00, (float) $variation->get_price());
    }
}

final class ProductTypeManagerDummyProduct extends WC_Product
{
    private $type;

    private $id;

    private $price;

    private $meta;

    private $parent_id;

    private $meta_updates = array();

    private $virtual_flag = null;

    private $downloadable_flag = null;

    public function __construct(
        string $type,
        int $id = 0,
        float $price = 0.0,
        array $meta = array(),
        int $parent_id = 0
    )
    {
        $this->type = $type;
        $this->id = $id;
        $this->price = $price;
        $this->meta = $meta;
        $this->parent_id = $parent_id;
    }

    public function get_type()
    {
        return $this->type;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_price()
    {
        return $this->price;
    }

    public function set_price($price)
    {
        $this->price = (float) $price;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }

    public function get_parent_id()
    {
        return $this->parent_id;
    }

    public function update_meta_data($key, $value, $meta_id = 0)
    {
        $this->meta_updates[(string) $key] = $value;
    }

    public function set_virtual($virtual)
    {
        $this->virtual_flag = (bool) $virtual;
    }

    public function set_downloadable($downloadable)
    {
        $this->downloadable_flag = (bool) $downloadable;
    }

    public function get_updated_meta(): array
    {
        return $this->meta_updates;
    }

    public function get_virtual_flag(): ?bool
    {
        return $this->virtual_flag;
    }

    public function get_downloadable_flag(): ?bool
    {
        return $this->downloadable_flag;
    }
}

final class ProductTypeManagerDummyCart
{
    public array $cart_contents;

    public function __construct(array $cart_contents)
    {
        $this->cart_contents = $cart_contents;
    }

    public function get_cart(): array
    {
        return $this->cart_contents;
    }
}
