<?php

defined('ABSPATH') || exit;

/** Identifies bank renewal orders without exposing a second signup/payment option. */
if (class_exists('WC_Payment_Gateway')) {
    final class WSZ_PayNL_SEPA_Order_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = WSZ_PayNL_SEPA::RENEWAL_GATEWAY_ID;
            $this->title = __('SEPA Direct Debit (PAY.nl)', 'woo-subzero');
            $this->method_title = $this->title;
            $this->method_description = __('Used internally for SEPA subscription renewal orders. Signup uses iDEAL with explicit bank mandate consent.', 'woo-subzero');
            $this->enabled = 'no';
            $this->supports = array('products');
        }

        public function is_available()
        {
            return false;
        }
    }
}
