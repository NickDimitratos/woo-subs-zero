<?php

defined('ABSPATH') || exit;

if (class_exists('WC_Payment_Gateway') && !class_exists('WSZ_Test_Card_Gateway')) {
    class WSZ_Test_Card_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = WSZ_Test_Card_Gateway_Integration::GATEWAY_ID;
            $this->method_title = __('WSZ Test Card', 'woo-subzero');
            $this->method_description = __('Built-in test card gateway for QA and development. Do not use in production.', 'woo-subzero');
            $this->has_fields = false;

            $this->supports = WSZ_Test_Card_Gateway_Integration::required_supports_flags();

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled', 'no');
            $this->title = $this->get_option('title', __('Test Card (WSZ)', 'woo-subzero'));
            $this->description = $this->get_option('description', __('Simulates successful card payments for checkout and renewals.', 'woo-subzero'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields(): void
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woo-subzero'),
                    'label' => __('Enable WSZ Test Card gateway', 'woo-subzero'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => __('Title', 'woo-subzero'),
                    'type' => 'text',
                    'default' => __('Test Card (WSZ)', 'woo-subzero'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'woo-subzero'),
                    'type' => 'textarea',
                    'default' => __('Use this gateway to simulate card payments in development or QA environments.', 'woo-subzero'),
                ),
            );
        }

        /**
         * @param mixed $order_id
         * @return array<string,string>
         */
        public function process_payment($order_id): array
        {
            if (!function_exists('wc_get_order')) {
                return array('result' => 'failure');
            }

            $order = wc_get_order((int) $order_id);

            if (!($order instanceof WC_Order)) {
                return array('result' => 'failure');
            }

            $transaction_id = 'wsz_test_card_' . uniqid('', true);

            if (!$order->is_paid()) {
                $order->payment_complete($transaction_id);
            }

            $order->add_order_note(
                __('WSZ Test Card payment approved (test gateway).', 'woo-subzero')
            );

            WSZ_Test_Card_Gateway_Integration::record_transaction(
                $order,
                'checkout',
                $transaction_id,
                (float) $order->get_total()
            );

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
    }
}
