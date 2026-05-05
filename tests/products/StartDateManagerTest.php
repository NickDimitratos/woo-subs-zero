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

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('esc_js')) {
    function esc_js($text)
    {
        return (string) $text;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        return trim((string) $value);
    }
}

if (!function_exists('is_product')) {
    function is_product()
    {
        return true;
    }
}

if (!function_exists('wc_add_notice')) {
    function wc_add_notice($message, $type = 'success')
    {
        $GLOBALS['wsz_start_date_test_notices'][] = array(
            'message' => (string) $message,
            'type' => (string) $type,
        );
    }
}

if (!function_exists('wc_get_product')) {
    function wc_get_product($product_id)
    {
        $product_id = (int) $product_id;

        return $GLOBALS['wsz_start_date_test_products'][$product_id]
            ?? $GLOBALS['wsz_test_wc_products'][$product_id]
            ?? null;
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-wsz-start-date-manager.php';

final class StartDateManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_REQUEST = array();
        $_COOKIE = array();
        $GLOBALS['wsz_start_date_test_notices'] = array();
        $GLOBALS['wsz_start_date_test_products'] = array();
        $GLOBALS['wsz_test_wc_products'] = &$GLOBALS['wsz_start_date_test_products'];
        $GLOBALS['wsz_checkout_test_products'] = &$GLOBALS['wsz_start_date_test_products'];
    }

    protected function tearDown(): void
    {
        $_REQUEST = array();
        $_COOKIE = array();
        unset($GLOBALS['product']);
        unset($GLOBALS['wsz_start_date_test_notices']);
        unset($GLOBALS['wsz_start_date_test_products']);
        unset($GLOBALS['wsz_test_wc_products']);
        unset($GLOBALS['wsz_checkout_test_products']);

        parent::tearDown();
    }

    public function test_render_start_date_field_uses_checkbox_and_tomorrow_minimum(): void
    {
        $product = new StartDateManagerDummyProduct('wsz_subscription');
        $GLOBALS['product'] = $product;

        $manager = new WSZ_Start_Date_Manager();

        ob_start();
        $manager->render_start_date_field();
        $output = (string) ob_get_clean();

        $tomorrow = gmdate('Y-m-d', time() + (24 * 60 * 60));

        $this->assertStringContainsString('name="wsz_subscription_start_specific_date"', $output);
        $this->assertStringContainsString('Start specific date', $output);
        $this->assertStringContainsString('.single-product form.cart .wsz-subscription-start-date-field{flex:0 0 100%;width:100%;', $output);
        $this->assertStringContainsString('.wsz-subscription-start-date-field~.quantity', $output);
        $this->assertStringContainsString('class="wsz-subscription-start-date-input" style="display:none;"', $output);
        $this->assertStringContainsString('name="wsz_subscription_start_date"', $output);
        $this->assertStringContainsString('min="' . $tomorrow . '"', $output);
        $this->assertStringContainsString('disabled="disabled"', $output);
    }

    public function test_unchecked_specific_date_ignores_submitted_date(): void
    {
        $product = new StartDateManagerDummyProduct('wsz_subscription');
        $this->registerProduct(11, $product);
        $_REQUEST = array(
            'wsz_subscription_start_date' => gmdate('Y-m-d', time() - (24 * 60 * 60)),
        );

        $manager = new WSZ_Start_Date_Manager();

        $this->assertTrue($manager->validate_start_date(true, 11, 1));
        $this->assertSame(array(), $GLOBALS['wsz_start_date_test_notices']);
        $this->assertSame(array(), $manager->capture_start_date_cart_item_data(array(), 11, 0));
    }

    public function test_checked_specific_date_requires_date(): void
    {
        $product = new StartDateManagerDummyProduct('wsz_subscription');
        $this->registerProduct(12, $product);
        $_REQUEST = array(
            'wsz_subscription_start_specific_date' => 'yes',
        );

        $manager = new WSZ_Start_Date_Manager();

        $this->assertFalse($manager->validate_start_date(true, 12, 1));
        $this->assertSame('error', $GLOBALS['wsz_start_date_test_notices'][0]['type']);
        $this->assertSame('Please choose a subscription start date.', $GLOBALS['wsz_start_date_test_notices'][0]['message']);
    }

    public function test_checked_specific_date_rejects_today_and_accepts_tomorrow(): void
    {
        $product = new StartDateManagerDummyProduct('wsz_subscription');
        $this->registerProduct(13, $product);
        $manager = new WSZ_Start_Date_Manager();

        $_REQUEST = array(
            'wsz_subscription_start_specific_date' => 'yes',
            'wsz_subscription_start_date' => gmdate('Y-m-d'),
        );

        $this->assertFalse($manager->validate_start_date(true, 13, 1));

        $_REQUEST = array(
            'wsz_subscription_start_specific_date' => 'yes',
            'wsz_subscription_start_date' => gmdate('Y-m-d', time() + (24 * 60 * 60)),
        );

        $this->assertTrue($manager->validate_start_date(true, 13, 1));
    }

    public function test_checked_specific_date_is_captured_in_cart_item_data(): void
    {
        $product = new StartDateManagerDummyProduct('wsz_subscription');
        $this->registerProduct(14, $product);
        $tomorrow = gmdate('Y-m-d', time() + (24 * 60 * 60));
        $_REQUEST = array(
            'wsz_subscription_start_specific_date' => 'yes',
            'wsz_subscription_start_date' => $tomorrow,
        );

        $manager = new WSZ_Start_Date_Manager();

        $this->assertSame(
            array('wsz_subscription_start_date' => $tomorrow),
            $manager->capture_start_date_cart_item_data(array(), 14, 0)
        );
    }

    private function registerProduct(int $product_id, WC_Product $product): void
    {
        $GLOBALS['wsz_start_date_test_products'][$product_id] = $product;
        $GLOBALS['wsz_test_wc_products'][$product_id] = $product;
        $GLOBALS['wsz_checkout_test_products'][$product_id] = $product;
    }
}

final class StartDateManagerDummyProduct extends WC_Product
{
    private string $type;

    private array $meta;

    public function __construct(string $type, array $meta = array())
    {
        $this->type = $type;
        $this->meta = $meta;
    }

    public function get_type()
    {
        return $this->type;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }
}
