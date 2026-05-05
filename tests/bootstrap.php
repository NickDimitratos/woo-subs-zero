<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value)
    {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook_name)
    {
        return null;
    }
}

if (!function_exists('absint')) {
    function absint($value)
    {
        return abs((int) $value);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
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

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = false)
    {
        if ('timestamp' === $type) {
            return time();
        }

        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('WC')) {
    function WC()
    {
        return $GLOBALS['wsz_wc_test_container'] ?? null;
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id)
    {
        $order_id = (int) $order_id;

        if (isset($GLOBALS['wsz_subs_test_orders'][$order_id])) {
            return $GLOBALS['wsz_subs_test_orders'][$order_id];
        }

        if (isset($GLOBALS['wsz_test_orders'][$order_id])) {
            return $GLOBALS['wsz_test_orders'][$order_id];
        }

        if (isset($GLOBALS['wsz_test_card_orders'][$order_id])) {
            return $GLOBALS['wsz_test_card_orders'][$order_id];
        }

        return null;
    }
}

if (!function_exists('wc_get_orders')) {
    function wc_get_orders($args = array())
    {
        $orders = $GLOBALS['wsz_subs_test_order_queries'] ?? array();

        if (!empty($args['meta_key']) && isset($args['meta_value'])) {
            return $orders[(string) $args['meta_key'] . '|' . (string) $args['meta_value']] ?? array();
        }

        return array();
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array())
    {
        return array_merge((array) $defaults, (array) $args);
    }
}

if (!function_exists('wc_format_decimal')) {
    function wc_format_decimal($number, $dp = 2)
    {
        return number_format((float) $number, $dp, '.', '');
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order
    {
        public function update_status($status, $note = '', $manual = false)
        {
        }

        public function set_payment_method($gateway_id)
        {
        }

        public function set_payment_method_title($title)
        {
        }

        public function get_payment_method_title()
        {
            return '';
        }

        public function set_currency($currency)
        {
        }

        public function get_currency()
        {
            return '';
        }

        public function calculate_totals($and_taxes = true)
        {
        }

        public function add_item($item)
        {
        }

        public function get_items($types = array())
        {
            return array();
        }

        public function save()
        {
        }

        public function get_id()
        {
            return 0;
        }

        public function get_meta($key, $single = true)
        {
            return '';
        }

        public function get_meta_data()
        {
            return array();
        }

        public function update_meta_data($key, $value)
        {
        }

        public function get_status()
        {
            return 'pending';
        }

        public function get_customer_id()
        {
            return 0;
        }

        public function needs_payment()
        {
            return false;
        }

        public function get_checkout_payment_url($on_checkout = false)
        {
            return '';
        }

        public function is_paid()
        {
            return false;
        }

        public function payment_complete($transaction_id = '')
        {
        }

        public function add_order_note($note)
        {
        }

        public function has_status($status)
        {
            return false;
        }

        public function get_date_created()
        {
            return null;
        }

        public function get_total()
        {
            return 0.0;
        }

        public function set_total($total)
        {
        }

        public function get_payment_method()
        {
            return '';
        }

        public function get_order_key()
        {
            return '';
        }

        public function get_type()
        {
            return 'shop_order';
        }
    }
}

if (!class_exists('WC_Payment_Token')) {
    class WC_Payment_Token
    {
        private int $id = 0;

        private string $token = '';

        private string $gateway_id = '';

        private int $user_id = 0;

        private static int $next_id = 1000;

        public function get_id()
        {
            return $this->id;
        }

        public function get_token($context = 'view')
        {
            return $this->token;
        }

        public function set_token($token)
        {
            $this->token = (string) $token;
        }

        public function get_gateway_id($context = 'view')
        {
            return $this->gateway_id;
        }

        public function set_gateway_id($gateway_id)
        {
            $this->gateway_id = (string) $gateway_id;
        }

        public function get_user_id($context = 'view')
        {
            return $this->user_id;
        }

        public function set_user_id($user_id)
        {
            $this->user_id = (int) $user_id;
        }

        public function is_default()
        {
            return false;
        }

        public function save()
        {
            if ($this->id <= 0) {
                $this->id = self::$next_id++;
            }

            return $this->id;
        }
    }
}

if (!class_exists('WC_Payment_Tokens')) {
    class WC_Payment_Tokens
    {
        private static array $tokens = array();

        private static array $customer_tokens = array();

        public static function reset_test_tokens(): void
        {
            self::$tokens = array();
            self::$customer_tokens = array();
        }

        public static function set_test_tokens(array $tokens, array $customer_tokens = array()): void
        {
            self::$tokens = $tokens;
            self::$customer_tokens = $customer_tokens;
        }

        public static function get($token_id)
        {
            return self::$tokens[(int) $token_id] ?? null;
        }

        public static function get_customer_tokens($customer_id, $gateway_id = '')
        {
            return self::$customer_tokens[(int) $customer_id . '|' . (string) $gateway_id] ?? array();
        }
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product
    {
        public function get_price()
        {
            return 0.0;
        }

        public function get_id()
        {
            return 0;
        }

        public function get_meta($key, $single = true)
        {
            return '';
        }
    }
}

if (!class_exists('WP_User')) {
    class WP_User
    {
        public function has_cap($cap)
        {
            return false;
        }

        public function set_role($role)
        {
        }
    }
}
