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
        $args = func_get_args();
        $filters = $GLOBALS['wsz_test_filters'][(string) $hook_name] ?? array();

        if (empty($filters) || !is_array($filters)) {
            return $value;
        }

        ksort($filters);

        foreach ($filters as $callbacks) {
            foreach ($callbacks as $filter) {
                $callback = $filter['callback'] ?? null;

                if (!is_callable($callback)) {
                    continue;
                }

                $accepted_args = max(1, (int) ($filter['accepted_args'] ?? 1));
                $callback_args = array_slice($args, 1, $accepted_args);
                $value = call_user_func_array($callback, $callback_args);
                $args[1] = $value;
            }
        }

        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['wsz_test_filters'][(string) $hook_name][(int) $priority][] = array(
            'callback' => $callback,
            'accepted_args' => (int) $accepted_args,
        );

        return true;
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

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array())
    {
        $request = array(
            'url' => (string) $url,
            'args' => $args,
        );

        $GLOBALS['wsz_test_http_requests'][] = $request;

        if (false !== strpos((string) $url, 'api.stripe.com')) {
            $GLOBALS['wsz_stripe_test_http_requests'][] = $request;

            return $GLOBALS['wsz_stripe_test_http_response'] ?? array(
                'response' => array('code' => 200),
                'body' => '{"id":"pi_wsz_test","status":"succeeded"}',
            );
        }

        if (false !== strpos((string) $url, 'payment.pay.nl')) {
            $GLOBALS['wsz_paynl_test_http_requests'][] = $request;

            return $GLOBALS['wsz_paynl_test_http_response'] ?? array(
                'response' => array('code' => 200),
                'body' => '{"state":"paid","transactionId":"PAY-RENEWAL-1"}',
            );
        }

        if (false !== strpos((string) $url, 'api.mollie.com')) {
            $GLOBALS['wsz_mollie_test_http_requests'][] = $request;

            return $GLOBALS['wsz_mollie_test_http_response'] ?? array(
                'response' => array('code' => 201),
                'body' => '{"id":"tr_wsz_test","status":"paid","mandateId":"mdt_wsz_test"}',
            );
        }

        return $GLOBALS['wsz_test_http_response'] ?? array(
            'response' => array('code' => 200),
            'body' => '{}',
        );
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return (int) ($response['response']['code'] ?? 0);
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return (string) ($response['body'] ?? '');
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
        private array $payment_tokens = array();

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

        public function get_transaction_id()
        {
            return '';
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

        public function add_payment_token($token)
        {
            if (!($token instanceof WC_Payment_Token)) {
                return false;
            }

            $token_id = (int) $token->get_id();

            if ($token_id <= 0) {
                return false;
            }

            $this->payment_tokens[$token_id] = $token;

            return $token_id;
        }

        public function get_payment_tokens()
        {
            return array_values($this->payment_tokens);
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

            if (class_exists('WC_Payment_Tokens') && is_callable(array('WC_Payment_Tokens', 'register_test_token'))) {
                WC_Payment_Tokens::register_test_token($this);
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

        public static function register_test_token(WC_Payment_Token $token): void
        {
            $token_id = (int) $token->get_id();

            if ($token_id <= 0) {
                return;
            }

            self::$tokens[$token_id] = $token;

            $customer_id = (int) $token->get_user_id();
            $gateway_id = (string) $token->get_gateway_id();

            if ($customer_id <= 0 || '' === $gateway_id) {
                return;
            }

            self::$customer_tokens[$customer_id . '|' . $gateway_id][$token_id] = $token;
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
