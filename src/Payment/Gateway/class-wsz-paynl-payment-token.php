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
