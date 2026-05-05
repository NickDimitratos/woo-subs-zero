<?php

defined('ABSPATH') || exit;

class WSZ_Product_Type_Manager
{
    public const SIMPLE_TYPE = 'wsz_subscription';

    public const VARIABLE_TYPE = 'wsz_variable_subscription';

    public function init(): void
    {
        add_filter('product_type_selector', array($this, 'register_product_types'));
        add_filter('woocommerce_product_class', array($this, 'map_product_class'), 10, 2);
        add_filter('woocommerce_product_data_tabs', array($this, 'extend_product_data_tabs'));
        add_filter('woocommerce_is_virtual', array($this, 'filter_product_is_virtual'), 20, 2);
        add_filter('woocommerce_is_sold_individually', array($this, 'force_subscription_products_sold_individually'), 20, 2);
        add_action('woocommerce_before_calculate_totals', array($this, 'apply_plan_total_installments'), 30);

        // Reuse WooCommerce add-to-cart renderers for WSZ custom product types on single product pages.
        add_action('woocommerce_' . self::SIMPLE_TYPE . '_add_to_cart', array($this, 'render_wsz_simple_add_to_cart'));
        add_action('woocommerce_' . self::VARIABLE_TYPE . '_add_to_cart', array($this, 'render_wsz_variable_add_to_cart'));

        add_action('woocommerce_product_options_general_product_data', array($this, 'render_subscription_fields'));
        add_action('woocommerce_admin_process_product_object', array($this, 'save_subscription_fields'));

        add_action('admin_footer', array($this, 'render_admin_visibility_script'));
    }

    /**
     * @param mixed $product
     */
    public function filter_product_is_virtual(bool $is_virtual, $product): bool
    {
        if (!($product instanceof WC_Product)) {
            return $is_virtual;
        }

        if (in_array($product->get_type(), array(self::SIMPLE_TYPE, self::VARIABLE_TYPE), true)) {
            return true;
        }

        return $is_virtual;
    }

    /**
     * Subscription products represent one recurring agreement, so quantity controls should not be shown.
     *
     * @param mixed $product
     */
    public function force_subscription_products_sold_individually(bool $sold_individually, $product): bool
    {
        if (!($product instanceof WC_Product)) {
            return $sold_individually;
        }

        return $this->is_quantityless_subscription_product($product) ? true : $sold_individually;
    }

    public function render_wsz_simple_add_to_cart(): void
    {
        if (function_exists('woocommerce_simple_add_to_cart')) {
            woocommerce_simple_add_to_cart();
        }
    }

    public function render_wsz_variable_add_to_cart(): void
    {
        if (function_exists('woocommerce_variable_add_to_cart')) {
            woocommerce_variable_add_to_cart();
        }
    }

    /**
     * Treat product price as full plan value for fixed-length subscriptions and split into per-cycle installments.
     *
     * @param mixed $cart
     */
    public function apply_plan_total_installments($cart): void
    {
        if (!is_object($cart) || !is_callable(array($cart, 'get_cart'))) {
            return;
        }

        if (function_exists('is_admin') && is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $cart_items = $cart->get_cart();

        if (!is_array($cart_items) || empty($cart_items)) {
            return;
        }

        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product = $cart_item['data'] ?? null;

            if (!($product instanceof WC_Product)) {
                continue;
            }

            $billing_product = $this->resolve_billing_source_product($product);

            if (!($billing_product instanceof WC_Product)) {
                $billing_product = $product;
            }

            if (!$this->is_wsz_subscription_product($product) && !$this->is_wsz_subscription_product($billing_product)) {
                continue;
            }

            $subscription_length = $this->get_subscription_length($billing_product ?? $product);

            if ($subscription_length <= 1) {
                continue;
            }

            $stored_plan_price = isset($cart_item['wsz_plan_total_price'])
                ? (float) $cart_item['wsz_plan_total_price']
                : 0.0;

            if ($stored_plan_price <= 0) {
                $stored_plan_price = (float) $product->get_price();

                if ($stored_plan_price <= 0) {
                    continue;
                }

                if (isset($cart->cart_contents[$cart_item_key]) && is_array($cart->cart_contents[$cart_item_key])) {
                    $cart->cart_contents[$cart_item_key]['wsz_plan_total_price'] = $stored_plan_price;
                }
            }

            $price_decimals = function_exists('wc_get_price_decimals')
                ? max(0, (int) wc_get_price_decimals())
                : 2;

            $installment_price = round($stored_plan_price / $subscription_length, $price_decimals);

            if (is_callable(array($product, 'set_price'))) {
                $product->set_price((string) $installment_price);
            }
        }
    }

    public function register_product_types(array $types): array
    {
        $types[self::SIMPLE_TYPE] = __('WSZ Subscription', 'woo-subzero');
        $types[self::VARIABLE_TYPE] = __('WSZ Variable Subscription', 'woo-subzero');

        return $types;
    }

    public function map_product_class(string $classname, string $product_type): string
    {
        if (self::SIMPLE_TYPE === $product_type) {
            return class_exists('WSZ_Product_Subscription') ? 'WSZ_Product_Subscription' : 'WC_Product_Simple';
        }

        if (self::VARIABLE_TYPE === $product_type) {
            return class_exists('WSZ_Product_Variable_Subscription') ? 'WSZ_Product_Variable_Subscription' : 'WC_Product_Variable';
        }

        return $classname;
    }

    public function extend_product_data_tabs(array $tabs): array
    {
        $shared_tabs = array('general', 'inventory', 'shipping', 'advanced');

        foreach ($shared_tabs as $tab_key) {
            if (!isset($tabs[$tab_key])) {
                continue;
            }

            $tabs[$tab_key] = $this->append_show_if_classes(
                $tabs[$tab_key],
                array(
                    'show_if_' . self::SIMPLE_TYPE,
                    'show_if_' . self::VARIABLE_TYPE,
                )
            );
        }

        if (isset($tabs['variations'])) {
            $tabs['variations'] = $this->append_show_if_classes(
                $tabs['variations'],
                array('show_if_' . self::VARIABLE_TYPE)
            );
        }

        if (isset($tabs['attribute'])) {
            $tabs['attribute'] = $this->append_show_if_classes(
                $tabs['attribute'],
                array('show_if_' . self::VARIABLE_TYPE)
            );
        }

        return $tabs;
    }

    private function append_show_if_classes(array $tab, array $new_classes): array
    {
        $existing_classes = isset($tab['class']) && is_array($tab['class']) ? $tab['class'] : array();

        $has_show_if = false;

        foreach ($existing_classes as $class_name) {
            if (0 === strpos((string) $class_name, 'show_if_')) {
                $has_show_if = true;
                break;
            }
        }

        if (!$has_show_if) {
            return $tab;
        }

        foreach ($new_classes as $new_class) {
            if (!in_array($new_class, $existing_classes, true)) {
                $existing_classes[] = $new_class;
            }
        }

        $tab['class'] = $existing_classes;

        return $tab;
    }

    private function is_wsz_subscription_product(WC_Product $product): bool
    {
        if (in_array($product->get_type(), array(self::SIMPLE_TYPE, self::VARIABLE_TYPE), true)) {
            return true;
        }

        return 'yes' === $product->get_meta('_wsz_subscription_enabled', true);
    }

    private function is_quantityless_subscription_product(WC_Product $product): bool
    {
        $products_to_check = array($product);

        $parent_product = $this->resolve_billing_source_product($product);

        if ($parent_product instanceof WC_Product && $parent_product !== $product) {
            $products_to_check[] = $parent_product;
        }

        foreach ($products_to_check as $candidate) {
            if (in_array($candidate->get_type(), array('subscription', 'variable-subscription', self::SIMPLE_TYPE, self::VARIABLE_TYPE), true)) {
                return true;
            }

            if (function_exists('wcs_is_subscription_product') && wcs_is_subscription_product($candidate)) {
                return true;
            }

            if ('yes' === $candidate->get_meta('_wsz_subscription_enabled', true)) {
                return true;
            }
        }

        return false;
    }

    private function resolve_billing_source_product(WC_Product $product): ?WC_Product
    {
        if (!method_exists($product, 'get_parent_id')) {
            return $product;
        }

        $parent_id = (int) $product->get_parent_id();

        if ($parent_id <= 0 || !function_exists('wc_get_product')) {
            return $product;
        }

        $parent_product = wc_get_product($parent_id);

        return $parent_product instanceof WC_Product ? $parent_product : $product;
    }

    private function get_subscription_length(WC_Product $product): int
    {
        $length = (int) $product->get_meta('_wsz_subscription_length', true);

        if ($length <= 0) {
            $length = (int) $product->get_meta('_subscription_length', true);
        }

        return max(0, $length);
    }

    public function render_subscription_fields(): void
    {
        echo '<div class="options_group wsz-subscription-fields show_if_' . esc_attr(self::SIMPLE_TYPE) . ' show_if_' . esc_attr(self::VARIABLE_TYPE) . '">';

        woocommerce_wp_text_input(
            array(
                'id' => '_wsz_subscription_interval',
                'label' => __('Billing Interval', 'woo-subzero'),
                'description' => __('Number of periods between renewals.', 'woo-subzero'),
                'desc_tip' => true,
                'type' => 'number',
                'custom_attributes' => array(
                    'min' => '1',
                    'step' => '1',
                ),
                'value' => get_post_meta(get_the_ID(), '_wsz_subscription_interval', true) ?: '1',
            )
        );

        woocommerce_wp_select(
            array(
                'id' => '_wsz_subscription_period',
                'label' => __('Billing Period', 'woo-subzero'),
                'description' => __('How often this subscription renews.', 'woo-subzero'),
                'desc_tip' => true,
                'options' => array(
                    'day' => __('Day', 'woo-subzero'),
                    'week' => __('Week', 'woo-subzero'),
                    'month' => __('Month', 'woo-subzero'),
                    'year' => __('Year', 'woo-subzero'),
                ),
                'value' => get_post_meta(get_the_ID(), '_wsz_subscription_period', true) ?: 'month',
            )
        );

        woocommerce_wp_text_input(
            array(
                'id' => '_wsz_subscription_length',
                'label' => __('Subscription Length', 'woo-subzero'),
                'description' => __('Number of billing periods before expiration. Use 0 for no fixed end.', 'woo-subzero'),
                'desc_tip' => true,
                'type' => 'number',
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '1',
                ),
                'value' => get_post_meta(get_the_ID(), '_wsz_subscription_length', true) ?: '0',
            )
        );

        woocommerce_wp_text_input(
            array(
                'id' => '_wsz_signup_fee',
                'label' => __('Sign-up Fee', 'woo-subzero'),
                'description' => __('Optional one-time fee charged on initial checkout.', 'woo-subzero'),
                'desc_tip' => true,
                'data_type' => 'price',
                'value' => get_post_meta(get_the_ID(), '_wsz_signup_fee', true) ?: '0',
            )
        );

        woocommerce_wp_text_input(
            array(
                'id' => '_wsz_sync_day',
                'label' => __('Synchronized Renewal Day', 'woo-subzero'),
                'description' => __('Optional day of month for synchronized renewals (1-28).', 'woo-subzero'),
                'desc_tip' => true,
                'type' => 'number',
                'custom_attributes' => array(
                    'min' => '1',
                    'max' => '28',
                    'step' => '1',
                ),
                'value' => get_post_meta(get_the_ID(), '_wsz_sync_day', true),
            )
        );

        echo '</div>';
    }

    public function save_subscription_fields(WC_Product $product): void
    {
        $product_type = $product->get_type();

        if (!in_array($product_type, array(self::SIMPLE_TYPE, self::VARIABLE_TYPE), true)) {
            return;
        }

        $period = isset($_POST['_wsz_subscription_period'])
            ? sanitize_key((string) wp_unslash($_POST['_wsz_subscription_period']))
            : 'month';

        if (!in_array($period, array('day', 'week', 'month', 'year'), true)) {
            $period = 'month';
        }

        $interval = isset($_POST['_wsz_subscription_interval'])
            ? max(1, absint(wp_unslash($_POST['_wsz_subscription_interval'])))
            : 1;

        $length = isset($_POST['_wsz_subscription_length'])
            ? max(0, absint(wp_unslash($_POST['_wsz_subscription_length'])))
            : 0;

        $sync_day = isset($_POST['_wsz_sync_day'])
            ? min(28, max(1, absint(wp_unslash($_POST['_wsz_sync_day']))))
            : 0;

        $signup_fee = isset($_POST['_wsz_signup_fee'])
            ? wc_format_decimal(wp_unslash($_POST['_wsz_signup_fee']))
            : '0';

        $product->update_meta_data('_wsz_subscription_enabled', 'yes');
        $product->update_meta_data('_wsz_subscription_period', $period);
        $product->update_meta_data('_wsz_subscription_interval', $interval);
        $product->update_meta_data('_wsz_subscription_length', $length);
        $product->update_meta_data('_wsz_signup_fee', $signup_fee);
        $product->update_meta_data('_wsz_sync_day', $sync_day);

        if (is_callable(array($product, 'set_virtual'))) {
            $product->set_virtual(true);
        }

        if (is_callable(array($product, 'set_downloadable'))) {
            $product->set_downloadable(false);
        }

        $product->update_meta_data('_virtual', 'yes');
        $product->update_meta_data('_downloadable', 'no');

        // Mirror WCS-style keys for broader compatibility with existing conventions.
        $product->update_meta_data('_subscription_period', $period);
        $product->update_meta_data('_subscription_period_interval', $interval);
        $product->update_meta_data('_subscription_length', $length);
        $product->update_meta_data('_subscription_sign_up_fee', $signup_fee);
        $product->update_meta_data('_subscription_payment_sync_date', $sync_day);
    }

    public function render_admin_visibility_script(): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();

        if (!$screen || 'product' !== $screen->id) {
            return;
        }

        ?>
        <script>
        jQuery(function($) {
            const wszSimpleType = '<?php echo esc_js(self::SIMPLE_TYPE); ?>';
            const wszVariableType = '<?php echo esc_js(self::VARIABLE_TYPE); ?>';
            const wszTypes = [wszSimpleType, wszVariableType];

            function addVisibilityAliasClasses(coreType, wszType) {
                $('.show_if_' + coreType).addClass('show_if_' + wszType);
                $('.hide_if_' + coreType).addClass('hide_if_' + wszType);
            }

            // Reuse Woo core field visibility rules so WSZ types inherit price and data-field behavior.
            addVisibilityAliasClasses('simple', wszSimpleType);
            addVisibilityAliasClasses('variable', wszVariableType);

            function toggleWszSubscriptionFields() {
                const currentType = $('#product-type').val();
                const isWszType = wszTypes.includes(currentType);

                $('.wsz-subscription-fields').toggle(isWszType);
            }

            $('#product-type').on('change', toggleWszSubscriptionFields);
            // Trigger Woo's existing product-type handlers after aliasing classes.
            $('#product-type').trigger('change');
        });
        </script>
        <?php
    }
}

if (class_exists('WC_Product_Simple') && !class_exists('WSZ_Product_Subscription')) {
    class WSZ_Product_Subscription extends WC_Product_Simple
    {
        public function get_type(): string
        {
            return WSZ_Product_Type_Manager::SIMPLE_TYPE;
        }
    }
}

if (class_exists('WC_Product_Variable') && !class_exists('WSZ_Product_Variable_Subscription')) {
    class WSZ_Product_Variable_Subscription extends WC_Product_Variable
    {
        public function get_type(): string
        {
            return WSZ_Product_Type_Manager::VARIABLE_TYPE;
        }
    }
}
