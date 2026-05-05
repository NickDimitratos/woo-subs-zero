<?php

defined('ABSPATH') || exit;

class WSZ_Start_Date_Manager
{
    private const OPTION_KEY = 'wsz_subs_options';

    public const FIELD_NAME = 'wsz_subscription_start_date';

    public const CHECKBOX_NAME = 'wsz_subscription_start_specific_date';

    public const CART_ITEM_KEY = 'wsz_subscription_start_date';

    public const ORDER_ITEM_META_KEY = '_wsz_requested_start_date';

    private const ITEM_DATA_LABEL = 'Subscription start date';

    public function init(): void
    {
        if (!$this->is_start_date_feature_enabled()) {
            return;
        }

        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_start_date_field'), 25);
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_start_date'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array($this, 'capture_start_date_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'render_cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'persist_order_item_start_date_meta'), 10, 4);
        add_action('wp_footer', array($this, 'render_ajax_start_date_bridge'), 40);
    }

    public function render_start_date_field(): void
    {
        global $product;

        if (!($product instanceof WC_Product) || !$this->is_subscription_product($product)) {
            return;
        }

        $min_date = $this->get_minimum_selectable_date();

        echo '<style id="wsz-subscription-start-date-layout">';
        echo '.single-product form.cart:has(.wsz-subscription-start-date-field){display:flex!important;flex-direction:column!important;align-items:flex-start!important;gap:12px!important;}';
        echo '.single-product form.cart:has(.wsz-subscription-start-date-field) .quantity,.single-product form.cart:has(.wsz-subscription-start-date-field) .qib-container,.single-product form.cart:has(.wsz-subscription-start-date-field) .qty-box{display:none!important;}';
        echo '.single-product form.cart .wsz-subscription-start-date-field{width:100%;max-width:100%;box-sizing:border-box;margin:0;}';
        echo '.single-product form.cart .wsz-subscription-start-date-field *{box-sizing:border-box;}';
        echo '.single-product form.cart .wsz-subscription-start-date-input input[type=date]{width:100%;max-width:240px;}';
        echo '</style>';
        echo '<div class="wsz-subscription-start-date-field">';
        echo '<label for="' . esc_attr(self::CHECKBOX_NAME) . '" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">';
        echo '<input type="checkbox" id="' . esc_attr(self::CHECKBOX_NAME) . '" name="' . esc_attr(self::CHECKBOX_NAME) . '" value="yes" />';
        echo '<span>' . esc_html__('Start specific date', 'woo-subzero') . '</span>';
        echo '</label>';
        echo '<div class="wsz-subscription-start-date-input" style="display:none;">';
        echo '<label for="' . esc_attr(self::FIELD_NAME) . '" style="display:block;margin-bottom:6px;">';
        echo esc_html__('Start subscription at', 'woo-subzero');
        echo '</label>';
        echo '<input type="date" id="' . esc_attr(self::FIELD_NAME) . '" name="' . esc_attr(self::FIELD_NAME) . '" min="' . esc_attr($min_date) . '" disabled="disabled" />';
        echo '</div>';
        echo '</div>';
    }

    public function validate_start_date($passed, $product_id, $quantity, $variation_id = 0, $variations = array()): bool
    {
        $passed = (bool) $passed;

        if (!$passed) {
            return false;
        }

        $product = $this->resolve_request_product((int) $product_id, (int) $variation_id);

        if (!($product instanceof WC_Product) || !$this->is_subscription_product($product)) {
            return $passed;
        }

        if (!$this->is_specific_start_date_requested()) {
            return true;
        }

        $raw_date = $this->get_request_start_date();
        $raw_date = is_string($raw_date) ? trim($raw_date) : '';

        if ('' === $raw_date) {
            wc_add_notice(__('Please choose a subscription start date.', 'woo-subzero'), 'error');
            return false;
        }

        $start_timestamp = $this->date_string_to_timestamp($raw_date);

        if ($start_timestamp <= 0) {
            wc_add_notice(__('Invalid subscription start date format.', 'woo-subzero'), 'error');
            return false;
        }

        $minimum_timestamp = $this->date_string_to_timestamp($this->get_minimum_selectable_date());

        if ($minimum_timestamp > 0 && $start_timestamp < $minimum_timestamp) {
            wc_add_notice(__('Subscription start date must be tomorrow or later.', 'woo-subzero'), 'error');
            return false;
        }

        return true;
    }

    public function capture_start_date_cart_item_data(array $cart_item_data, $product_id, $variation_id): array
    {
        $product = $this->resolve_request_product((int) $product_id, (int) $variation_id);

        if (!($product instanceof WC_Product) || !$this->is_subscription_product($product)) {
            return $cart_item_data;
        }

        if (!$this->is_specific_start_date_requested()) {
            return $cart_item_data;
        }

        $raw_date = $this->get_request_start_date();
        $raw_date = is_string($raw_date) ? trim($raw_date) : '';

        $normalized = $this->normalize_date_string($raw_date);

        if ('' === $normalized) {
            return $cart_item_data;
        }

        $cart_item_data[self::CART_ITEM_KEY] = $normalized;

        return $cart_item_data;
    }

    /**
     * @return mixed
     */
    private function get_request_start_date()
    {
        return $this->get_request_value(self::FIELD_NAME);
    }

    private function is_specific_start_date_requested(): bool
    {
        $value = $this->get_request_value(self::CHECKBOX_NAME);

        if (is_string($value) && $this->is_truthy_request_value($value)) {
            return true;
        }

        return false;
    }

    /**
     * @return mixed
     */
    private function get_request_value(string $key)
    {
        $direct_value = isset($_REQUEST[$key]) ? wp_unslash($_REQUEST[$key]) : '';

        if (is_string($direct_value) && '' !== trim($direct_value)) {
            return $direct_value;
        }

        if (isset($_REQUEST['cart_item_data']) && is_array($_REQUEST['cart_item_data'])) {
            $cart_item_data = wp_unslash($_REQUEST['cart_item_data']);
            $nested_value = $cart_item_data[$key] ?? '';

            if (is_string($nested_value) && '' !== trim($nested_value)) {
                return $nested_value;
            }
        }

        $form_value = $this->get_form_data_value($key);

        if (is_string($form_value) && '' !== trim($form_value)) {
            return $form_value;
        }

        if (isset($_COOKIE[$key])) {
            return sanitize_text_field(wp_unslash($_COOKIE[$key]));
        }

        return '';
    }

    private function get_form_data_value(string $key): string
    {
        if (!isset($_REQUEST['form_data'])) {
            return '';
        }

        $form_data = wp_unslash($_REQUEST['form_data']);

        if (is_string($form_data) && '' !== $form_data) {
            $parsed = array();
            parse_str($form_data, $parsed);

            return isset($parsed[$key]) ? (string) $parsed[$key] : '';
        }

        if (is_array($form_data)) {
            foreach ($form_data as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if ((string) ($entry['name'] ?? '') !== $key) {
                    continue;
                }

                return (string) ($entry['value'] ?? '');
            }
        }

        return '';
    }

    private function is_truthy_request_value(string $value): bool
    {
        return in_array(strtolower(trim($value)), array('1', 'on', 'true', 'yes'), true);
    }

    public function render_ajax_start_date_bridge(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        ?>
        <script>
        (function ($) {
            if (!$ || !document) {
                return;
            }

            var fieldName = '<?php echo esc_js(self::FIELD_NAME); ?>';
            var checkboxName = '<?php echo esc_js(self::CHECKBOX_NAME); ?>';
            var $checkbox = $('input[name="' + checkboxName + '"]').first();
            var $field = $('input[name="' + fieldName + '"]').first();
            var $wrapper = $('.wsz-subscription-start-date-input').first();

            function isSpecificDateEnabled() {
                return $checkbox.length ? $checkbox.is(':checked') : false;
            }

            function getCookie(name) {
                var cookieMatch = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
                return cookieMatch ? decodeURIComponent(cookieMatch[1]) : '';
            }

            function getFieldValue() {
                if (!isSpecificDateEnabled()) {
                    return '';
                }

                var value = $field.length ? String($field.val() || '') : '';

                if (value) {
                    return value;
                }

                return getCookie(checkboxName) === 'yes' ? getCookie(fieldName) : '';
            }

            function persistCookies(value) {
                var enabled = isSpecificDateEnabled();

                document.cookie = checkboxName + '=' + (enabled ? 'yes' : 'no') + '; path=/; max-age=86400; samesite=lax';

                if (enabled && value) {
                    document.cookie = fieldName + '=' + encodeURIComponent(value) + '; path=/; max-age=86400; samesite=lax';
                } else {
                    document.cookie = fieldName + '=; path=/; max-age=0; samesite=lax';
                }
            }

            function syncFieldVisibility() {
                var enabled = isSpecificDateEnabled();

                if ($field.length) {
                    $field.prop('disabled', !enabled);
                }

                if ($wrapper.length) {
                    $wrapper.toggle(enabled);
                }

                persistCookies($field.length ? String($field.val() || '') : '');
            }

            $checkbox.on('change', syncFieldVisibility);

            $(document).on('change', 'input[name="' + fieldName + '"]', function () {
                persistCookies(String($(this).val() || ''));
            });

            $(document.body).on('adding_to_cart', function (event, $button, data) {
                var value = getFieldValue();

                if (!value || !data || typeof data !== 'object') {
                    return;
                }

                if (!data[fieldName]) {
                    data[checkboxName] = 'yes';
                    data[fieldName] = value;
                }
            });

            $.ajaxPrefilter(function (options, originalOptions) {
                if (!options || typeof options.url !== 'string') {
                    return;
                }

                if (options.url.indexOf('add_to_cart') === -1 && options.url.indexOf('add-to-cart') === -1) {
                    return;
                }

                var value = getFieldValue();

                if (!value) {
                    return;
                }

                persistCookies(value);

                if (typeof options.data === 'string') {
                    if (options.data.indexOf(checkboxName + '=') === -1) {
                        options.data += (options.data ? '&' : '') + encodeURIComponent(checkboxName) + '=yes';
                    }

                    if (options.data.indexOf(fieldName + '=') === -1) {
                        options.data += (options.data ? '&' : '') + encodeURIComponent(fieldName) + '=' + encodeURIComponent(value);
                    }
                    return;
                }

                if (options.data && typeof options.data === 'object' && !Array.isArray(options.data)) {
                    if (!options.data[fieldName]) {
                        options.data[checkboxName] = 'yes';
                        options.data[fieldName] = value;
                    }
                }
            });

            $(document).on('click submit', 'form.cart', function () {
                var value = getFieldValue();
                persistCookies(value);
            });

            syncFieldVisibility();
        })(window.jQuery);
        </script>
        <?php
    }

    public function render_cart_item_data(array $item_data, array $cart_item): array
    {
        $start_date = isset($cart_item[self::CART_ITEM_KEY]) ? (string) $cart_item[self::CART_ITEM_KEY] : '';

        if ('' === $start_date) {
            return $item_data;
        }

        $item_data[] = array(
            'key' => __(self::ITEM_DATA_LABEL, 'woo-subzero'),
            'value' => esc_html($start_date),
        );

        return $item_data;
    }

    /**
     * @param mixed $item
     * @param mixed $order
     */
    public function persist_order_item_start_date_meta($item, string $cart_item_key, array $values, $order): void
    {
        if (!is_object($item) || !is_callable(array($item, 'update_meta_data'))) {
            return;
        }

        $start_date = isset($values[self::CART_ITEM_KEY]) ? (string) $values[self::CART_ITEM_KEY] : '';

        if ('' === $start_date) {
            return;
        }

        $item->update_meta_data(self::ORDER_ITEM_META_KEY, $start_date);
    }

    private function resolve_request_product(int $product_id, int $variation_id = 0): ?WC_Product
    {
        if (!function_exists('wc_get_product')) {
            return null;
        }

        $resolved_id = $variation_id > 0 ? $variation_id : $product_id;

        if ($resolved_id <= 0) {
            return null;
        }

        $product = wc_get_product($resolved_id);

        return $product instanceof WC_Product ? $product : null;
    }

    private function is_subscription_product(WC_Product $product): bool
    {
        $products_to_check = array($product);

        if (is_callable(array($product, 'get_parent_id')) && function_exists('wc_get_product')) {
            $parent_id = (int) $product->get_parent_id();

            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);

                if ($parent instanceof WC_Product) {
                    $products_to_check[] = $parent;
                }
            }
        }

        foreach ($products_to_check as $candidate) {
            if (in_array($candidate->get_type(), array('subscription', 'variable-subscription', 'wsz_subscription', 'wsz_variable_subscription'), true)) {
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

    private function normalize_date_string(string $date_value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_value)) {
            return '';
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_value, $timezone);

        if (!($date instanceof DateTimeImmutable) || $date->format('Y-m-d') !== $date_value) {
            return '';
        }

        return $date_value;
    }

    private function date_string_to_timestamp(string $date_value): int
    {
        $normalized = $this->normalize_date_string($date_value);

        if ('' === $normalized) {
            return 0;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized, $timezone);

        if (!($date instanceof DateTimeImmutable)) {
            return 0;
        }

        $timestamp = $date->setTime(0, 0, 0)->getTimestamp();

        return $timestamp > 0 ? $timestamp : 0;
    }

    private function get_minimum_selectable_date(): string
    {
        $current_timestamp = function_exists('current_time')
            ? (int) current_time('timestamp', true)
            : time();

        $tomorrow_timestamp = $current_timestamp + (24 * 60 * 60);

        return function_exists('wp_date')
            ? wp_date('Y-m-d', $tomorrow_timestamp)
            : gmdate('Y-m-d', $tomorrow_timestamp);
    }

    private function is_start_date_feature_enabled(): bool
    {
        if (!function_exists('get_option')) {
            return true;
        }

        $settings = (array) get_option(self::OPTION_KEY, array());
        $enabled = isset($settings['enable_start_date']) ? (string) $settings['enable_start_date'] : 'yes';

        return 'yes' === $enabled;
    }
}
