<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return (string) $url;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        return trim((string) $value);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array())
    {
        return array_merge((array) $defaults, (array) $args);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = false)
    {
        return 1700000000;
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null)
    {
        return gmdate($format, (int) $timestamp);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability)
    {
        return true;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '')
    {
        $separator = false === strpos((string) $url, '?') ? '?' : '&';

        return (string) $url . $separator . http_build_query((array) $args);
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true)
    {
        $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';

        if ($echo) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true)
    {
        $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';

        if ($echo) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null)
    {
        echo '<button type="submit" class="' . esc_attr((string) $type) . '">' . esc_html((string) ($text ?? 'Save Changes')) . '</button>';
    }
}

if (!function_exists('settings_fields')) {
    function settings_fields($option_group)
    {
    }
}

if (!function_exists('register_setting')) {
    function register_setting($option_group, $option_name, $args = array())
    {
        $GLOBALS['wp_registered_settings'][(string) $option_name] = (array) $args;
    }
}

if (!function_exists('add_settings_section')) {
    function add_settings_section($id, $title, $callback, $page, $args = array())
    {
        $GLOBALS['wp_settings_sections'][(string) $page][(string) $id] = array(
            'id' => (string) $id,
            'title' => $title,
            'callback' => $callback,
        ) + (array) $args;
    }
}

if (!function_exists('add_settings_field')) {
    function add_settings_field($id, $title, $callback, $page, $section = 'default', $args = array())
    {
        $GLOBALS['wp_settings_fields'][(string) $page][(string) $section][(string) $id] = array(
            'id' => (string) $id,
            'title' => $title,
            'callback' => $callback,
            'args' => (array) $args,
        );
    }
}

if (!function_exists('do_settings_fields')) {
    function do_settings_fields($page, $section)
    {
        $fields = $GLOBALS['wp_settings_fields'][(string) $page][(string) $section] ?? array();

        foreach ($fields as $field) {
            echo '<tr><th scope="row">' . esc_html((string) $field['title']) . '</th><td>';
            if (is_callable($field['callback'])) {
                call_user_func($field['callback'], (array) ($field['args'] ?? array()));
            }
            echo '</td></tr>';
        }
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true)
    {
        $field = '<input type="hidden" name="' . esc_attr((string) $name) . '" value="nonce-' . esc_attr((string) $action) . '" />';

        if ($display) {
            echo $field;
        }

        return $field;
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action = -1, $query_arg = '_wpnonce')
    {
        return true;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress')
    {
        $GLOBALS['wsz_admin_settings_redirect'] = (string) $location;

        return true;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '')
    {
        throw new RuntimeException((string) $message);
    }
}

if (!function_exists('get_option')) {
    function get_option($option_name, $default = false)
    {
        if ('wsz_subs_options' === $option_name && isset($GLOBALS['wsz_subs_test_options'])) {
            return $GLOBALS['wsz_subs_test_options'];
        }

        if ('wsz_subs_test_card_transactions' === $option_name && isset($GLOBALS['wsz_subs_test_card_transactions'])) {
            return $GLOBALS['wsz_subs_test_card_transactions'];
        }

        if ('wsz_subs_paynl_card_transactions' === $option_name && isset($GLOBALS['wsz_subs_paynl_card_transactions'])) {
            return $GLOBALS['wsz_subs_paynl_card_transactions'];
        }

        if (isset($GLOBALS['wsz_admin_test_options']) && is_array($GLOBALS['wsz_admin_test_options']) && array_key_exists($option_name, $GLOBALS['wsz_admin_test_options'])) {
            return $GLOBALS['wsz_admin_test_options'][$option_name];
        }

        return $GLOBALS['wsz_admin_settings_options'][$option_name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option_name, $value, $autoload = null)
    {
        if ('wsz_subs_test_card_transactions' === $option_name) {
            $GLOBALS['wsz_subs_test_card_transactions'] = is_array($value) ? $value : array();
        }

        if ('wsz_subs_paynl_card_transactions' === $option_name) {
            $GLOBALS['wsz_subs_paynl_card_transactions'] = is_array($value) ? $value : array();
        }

        if (isset($GLOBALS['wsz_admin_test_options']) && is_array($GLOBALS['wsz_admin_test_options'])) {
            $GLOBALS['wsz_admin_test_options'][$option_name] = $value;
        }

        $GLOBALS['wsz_admin_settings_options'][$option_name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option_name)
    {
        unset($GLOBALS['wsz_admin_settings_options'][$option_name]);

        return true;
    }
}

require_once dirname(__DIR__, 2) . '/includes/admin/class-wsz-admin-settings.php';

final class AdminSettingsLogsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = array();
        $_POST = array();
        $GLOBALS['wsz_admin_settings_options'] = array();
        $GLOBALS['wp_settings_sections'] = array();
        $GLOBALS['wp_settings_fields'] = array();
        $GLOBALS['wp_registered_settings'] = array();
        unset($GLOBALS['wsz_admin_settings_redirect']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wsz_admin_settings_options']);
        unset($GLOBALS['wp_settings_sections']);
        unset($GLOBALS['wp_settings_fields']);
        unset($GLOBALS['wp_registered_settings']);
        unset($GLOBALS['wsz_subs_test_options']);
        unset($GLOBALS['wsz_admin_settings_redirect']);
        $_GET = array();
        $_POST = array();

        parent::tearDown();
    }

    public function test_logs_tab_renders_diagnostic_entries(): void
    {
        WSZ_Admin_Settings::log_diagnostic(
            'error',
            'Recurring charge failed for test order.',
            array(
                'source' => 'woo-subzero',
                'order_id' => 55,
                'secret' => array('nested' => 'safe'),
            )
        );

        $_GET = array(
            'page' => 'wsz-subs-settings',
            'tab' => 'logs',
            'level' => 'error',
        );

        $settings = new WSZ_Admin_Settings();

        ob_start();
        $settings->render_settings_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('Error Logs', $output);
        $this->assertStringContainsString('Recurring charge failed for test order.', $output);
        $this->assertStringContainsString('woo-subzero', $output);
        $this->assertStringContainsString('order_id', $output);
        $this->assertStringContainsString('nested', $output);
    }

    public function test_logs_tab_filters_below_minimum_level(): void
    {
        WSZ_Admin_Settings::log_diagnostic('info', 'Informational entry.', array('source' => 'woo-subzero'));
        WSZ_Admin_Settings::log_diagnostic('error', 'Error entry.', array('source' => 'woo-subzero'));

        $_GET = array(
            'page' => 'wsz-subs-settings',
            'tab' => 'logs',
            'level' => 'warning',
        );

        $settings = new WSZ_Admin_Settings();

        ob_start();
        $settings->render_settings_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('Error entry.', $output);
        $this->assertStringNotContainsString('Informational entry.', $output);
    }

    public function test_clear_diagnostic_logs_deletes_option(): void
    {
        WSZ_Admin_Settings::log_diagnostic('error', 'Error entry.', array('source' => 'woo-subzero'));

        $settings = new WSZ_Admin_Settings();
        $settings->clear_diagnostic_logs();

        $this->assertArrayNotHasKey('wsz_subs_diagnostic_logs', $GLOBALS['wsz_admin_settings_options']);
    }

    public function test_paynl_tokens_setting_defaults_to_disabled_and_sanitizes_yes_no(): void
    {
        $settings = new WSZ_Admin_Settings();

        $defaulted = $settings->sanitize_settings(array());
        $enabled = $settings->sanitize_settings(array('enable_paynl_tokens' => 'yes'));
        $invalid = $settings->sanitize_settings(array('enable_paynl_tokens' => 'unexpected'));

        $this->assertSame('no', $defaulted['enable_paynl_tokens'] ?? null);
        $this->assertSame('yes', $enabled['enable_paynl_tokens'] ?? null);
        $this->assertSame('no', $invalid['enable_paynl_tokens'] ?? null);
    }

    public function test_partial_payment_gateways_save_preserves_testing_settings(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_test_mode' => 'yes',
            'enable_test_deferred_start' => 'yes',
            'enable_test_cycle_notifications' => 'yes',
            'test_cycle_minutes' => 7,
            'enable_paynl_tokens' => 'no',
        );

        $settings = new WSZ_Admin_Settings();
        $sanitized = $settings->sanitize_settings(array('enable_paynl_tokens' => 'yes'));

        $this->assertSame('yes', $sanitized['enable_paynl_tokens'] ?? null);
        $this->assertSame('yes', $sanitized['enable_test_mode'] ?? null);
        $this->assertSame('yes', $sanitized['enable_test_deferred_start'] ?? null);
        $this->assertSame('yes', $sanitized['enable_test_cycle_notifications'] ?? null);
        $this->assertSame(7, $sanitized['test_cycle_minutes'] ?? null);
    }

    public function test_partial_testing_save_preserves_paynl_token_setting(): void
    {
        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_test_mode' => 'yes',
            'enable_test_deferred_start' => 'yes',
            'enable_test_cycle_notifications' => 'yes',
            'enable_paynl_tokens' => 'yes',
        );

        $settings = new WSZ_Admin_Settings();
        $sanitized = $settings->sanitize_settings(
            array(
                'enable_test_mode' => 'no',
                'enable_test_deferred_start' => 'no',
                'enable_test_cycle_notifications' => 'no',
                'test_cycle_minutes' => 3,
            )
        );

        $this->assertSame('yes', $sanitized['enable_paynl_tokens'] ?? null);
        $this->assertSame('no', $sanitized['enable_test_mode'] ?? null);
        $this->assertSame('no', $sanitized['enable_test_deferred_start'] ?? null);
        $this->assertSame('no', $sanitized['enable_test_cycle_notifications'] ?? null);
        $this->assertSame(3, $sanitized['test_cycle_minutes'] ?? null);
    }

    public function test_payment_gateways_tab_renders_paynl_token_toggle(): void
    {
        $_GET = array(
            'page' => 'wsz-subs-settings',
            'tab' => 'payment-gateways',
        );

        $GLOBALS['wsz_subs_test_options'] = array(
            'enable_paynl_tokens' => 'yes',
        );

        $settings = new WSZ_Admin_Settings();
        $settings->register_settings();

        ob_start();
        $settings->render_settings_page();
        $output = ob_get_clean();

        unset($GLOBALS['wsz_subs_test_options']);

        $this->assertStringContainsString('Payment Gateways', $output);
        $this->assertStringContainsString('Enable PAY.nl tokens', $output);
        $this->assertStringContainsString('enable_paynl_tokens', $output);
        $this->assertStringContainsString('type="hidden" name="wsz_subs_options[enable_paynl_tokens]" value="no"', $output);
        $this->assertStringContainsString('checked="checked"', $output);
    }
}
