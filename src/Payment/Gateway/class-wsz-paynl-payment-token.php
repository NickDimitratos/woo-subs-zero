<?php

defined('ABSPATH') || exit;

if (class_exists('WC_Payment_Token') && !class_exists('WC_Payment_Token_PayNL')) {
    class WC_Payment_Token_PayNL extends WC_Payment_Token
    {
        protected $type = 'PayNL';

        public function get_display_name($deprecated = '')
        {
            return __('PAY.nl recurring payment token', 'woo-subzero');
        }
    }
}

if (function_exists('add_filter')) {
    add_filter(
        'woocommerce_payment_token_class',
        static function (string $class, string $type): string {
            return 'PayNL' === $type && class_exists('WC_Payment_Token_PayNL')
                ? 'WC_Payment_Token_PayNL'
                : $class;
        },
        10,
        2
    );
}
