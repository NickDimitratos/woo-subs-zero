<?php

defined('ABSPATH') || exit;

class WSZ_Start_Date_Manager
{
    private const OPTION_KEY = 'wsz_subs_options';

    public const FIELD_NAME = 'wsz_subscription_start_date';

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

        $min_date = function_exists('wp_date')
            ? wp_date('Y-m-d')
            : gmdate('Y-m-d');

        echo '<div class="wsz-subscription-start-date-field" style="margin:12px 0;">';
        echo '<label for="' . esc_attr(self::FIELD_NAME) . '" style="display:block;margin-bottom:6px;">';
        echo esc_html__('Start subscription at', 'woo-subzero');
        echo '</label>';
        // Keep this optional: empty means "start now".
        echo '<input type="date" id="' . esc_attr(self::FIELD_NAME) . '" name="' . esc_attr(self::FIELD_NAME) . '" min="' . esc_attr($min_date) . '" />';
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

        $raw_date = $this->get_request_start_date();
        $raw_date = is_string($raw_date) ? trim($raw_date) : '';

        if ('' === $raw_date) {
            return true;
        }

        $start_timestamp = $this->date_string_to_timestamp($raw_date);

        if ($start_timestamp <= 0) {
            wc_add_notice(__('Invalid subscription start date format.', 'woo-subzero'), 'error');
            return false;
        }

        $today_timestamp = $this->date_string_to_timestamp(
            function_exists('wp_date')
                ? wp_date('Y-m-d')
                : gmdate('Y-m-d')
        );

        if ($today_timestamp > 0 && $start_timestamp < $today_timestamp) {
            wc_add_notice(__('Subscription start date cannot be in the past.', 'woo-subzero'), 'error');
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
        $direct_value = isset($_REQUEST[self::FIELD_NAME]) ? wp_unslash($_REQUEST[self::FIELD_NAME]) : '';

        if (is_string($direct_value) && '' !== trim($direct_value)) {
            return $direct_value;
        }

        if (isset($_REQUEST['cart_item_data']) && is_array($_REQUEST['cart_item_data'])) {
            $cart_item_data = wp_unslash($_REQUEST['cart_item_data']);
            $nested_value = $cart_item_data[self::FIELD_NAME] ?? '';

            if (is_string($nested_value) && '' !== trim($nested_value)) {
                return $nested_value;
            }
        }

        if (isset($_REQUEST['form_data'])) {
            $form_data = wp_unslash($_REQUEST['form_data']);

            if (is_string($form_data) && '' !== $form_data) {
                $parsed = array();
                parse_str($form_data, $parsed);

                $value = isset($parsed[self::FIELD_NAME]) ? (string) $parsed[self::FIELD_NAME] : '';

                if ('' !== trim($value)) {
                    return $value;
                }
            } elseif (is_array($form_data)) {
                foreach ($form_data as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    if ((string) ($entry['name'] ?? '') !== self::FIELD_NAME) {
                        continue;
                    }

                    $value = (string) ($entry['value'] ?? '');

                    if ('' !== trim($value)) {
                        return $value;
                    }
                }
            }
        }

        if (isset($_COOKIE[self::FIELD_NAME])) {
            return sanitize_text_field(wp_unslash($_COOKIE[self::FIELD_NAME]));
        }

        return '';
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

            function getFieldValue() {
                var $field = $('input[name="' + fieldName + '"]').first();
                var value = $field.length ? String($field.val() || '') : '';

                if (value) {
                    return value;
                }

                var cookieMatch = document.cookie.match(new RegExp('(?:^|; )' + fieldName + '=([^;]*)'));
                return cookieMatch ? decodeURIComponent(cookieMatch[1]) : '';
            }

            function persistCookie(value) {
                if (!value) {
                    return;
                }

                document.cookie = fieldName + '=' + encodeURIComponent(value) + '; path=/; max-age=86400; samesite=lax';
            }

            $(document).on('change', 'input[name="' + fieldName + '"]', function () {
                persistCookie(String($(this).val() || ''));
            });

            $(document.body).on('adding_to_cart', function (event, $button, data) {
                var value = getFieldValue();

                if (!value || !data || typeof data !== 'object') {
                    return;
                }

                if (!data[fieldName]) {
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

                persistCookie(value);

                if (typeof options.data === 'string') {
                    if (options.data.indexOf(fieldName + '=') === -1) {
                        options.data += (options.data ? '&' : '') + encodeURIComponent(fieldName) + '=' + encodeURIComponent(value);
                    }
                    return;
                }

                if (options.data && typeof options.data === 'object' && !Array.isArray(options.data)) {
                    if (!options.data[fieldName]) {
                        options.data[fieldName] = value;
                    }
                }
            });

            $(document).on('click submit', 'form.cart', function () {
                var value = getFieldValue();
                persistCookie(value);
            });
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
