<?php

defined('ABSPATH') || exit;

/** Classic and Store API checkout collect fresh consent; no existing iDEAL customer is opted in. */
final class WSZ_PayNL_SEPA_Checkout
{
    private WSZ_Checkout_Handler $checkout;
    private const PREFIX = 'woo-subs-zero/sepa-';

    public function __construct(WSZ_Subscription_Manager $manager)
    {
        $this->checkout = new WSZ_Checkout_Handler($manager);
    }

    public function init(): void
    {
        add_filter('woocommerce_checkout_fields', array($this, 'classic_fields'));
        add_filter('woocommerce_form_field_args', array($this, 'mark_classic_fields_required'), 10, 2);
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_classic'), 15, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_classic'), 15, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_store_api'), 15, 2);
        add_action('woocommerce_init', array($this, 'register_block_fields'));
        foreach (array('owner', 'iban', 'bic', 'consent') as $name) {
            add_filter('woocommerce_get_default_value_for_' . self::PREFIX . $name,
                function ($default, $group, $object) use ($name) {
                    if ('other' !== $group || !($object instanceof WC_Order) || $object->is_paid()
                        || WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY !== $object->get_payment_method() || !self::available(WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY, true)) {
                        return $default;
                    }
                    $draft = WSZ_PayNL_SEPA_Context::read_draft($object);
                    return $draft[$name] ?? $default;
                }, 10, 3);
        }
        add_action('wp_enqueue_scripts', array($this, 'enqueue_classic_visibility'), 30);
        add_filter('wsz_subs_automatic_renewal_gateway_ids', array($this, 'register_automatic_gateway'));
    }

    public static function available(string $gateway, bool $blocks = false): bool
    {
        if (WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY !== $gateway || !WSZ_PayNL_SEPA_Context::enabled()
            || !WSZ_PayNL_SEPA_Context::provider_available() || 'EUR' !== strtoupper(get_woocommerce_currency())) {
            return false;
        }
        if ($blocks && (!defined('WC_VERSION') || version_compare(WC_VERSION, '9.9', '<')
            || !function_exists('woocommerce_register_additional_checkout_field'))) {
            return false;
        }
        $context = new WC_Order();
        $context->set_payment_method(WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY);
        return WSZ_PayNL_SEPA_Context::has_credentials($context);
    }

    public function register_automatic_gateway(array $ids): array
    {
        $ids = array_merge($ids, (array) apply_filters('wsz_subs_tokenized_gateway_ids', array()));
        if (self::available(WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY)) {
            $ids[] = WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY;
        }
        return array_values(array_unique($ids));
    }

    private function definitions(): array
    {
        return array(
            'owner' => array('type' => 'text', 'label' => __('SEPA account holder (for iDEAL subscriptions)', 'woo-subzero')),
            'iban' => array('type' => 'text', 'label' => __('SEPA IBAN for automatic subscription renewals', 'woo-subzero')),
            'bic' => array('type' => 'text', 'label' => __('SEPA BIC / SWIFT', 'woo-subzero')),
            'consent' => array('type' => 'checkbox', 'label' => WSZ_PayNL_SEPA_Context::consent_text()),
        );
    }

    public function classic_fields(array $fields): array
    {
        if (!$this->checkout->cart_contains_subscription_items() || !self::available(WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY)) {
            return $fields;
        }
        foreach ($this->definitions() as $name => $definition) {
            $fields['order']['wsz_sepa_' . $name] = $definition + array('required' => false, 'class' => array('form-row-wide', 'wsz-sepa-field'), 'priority' => 130);
        }
        return $fields;
    }

    /** Mark visible SEPA fields as required without requiring them for card payments. */
    public function mark_classic_fields_required(array $args, string $key): array
    {
        if (in_array($key, array('wsz_sepa_owner', 'wsz_sepa_iban', 'wsz_sepa_bic', 'wsz_sepa_consent'), true)
            && in_array('wsz-sepa-field', (array) ($args['class'] ?? array()), true)) {
            $args['required'] = true;
        }
        return $args;
    }

    public function validate_classic(array $posted, $errors): void
    {
        if (!$this->checkout->cart_contains_subscription_items() || WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY !== ($posted['payment_method'] ?? '')
            || !self::available(WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY)) {
            return;
        }
        $input = $this->classic_input($posted);
        if (!WSZ_PayNL_SEPA_Context::valid_iban((string) $input['iban']) || '' === trim((string) $input['owner'])
            || !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', strtoupper(trim((string) $input['bic'])))
            || !in_array($input['consent'], array(true, 1, '1'), true)) {
            $errors->add('wsz_sepa_consent_required', __('iDEAL subscriptions require a valid IBAN, BIC, account holder and your explicit consent for automatic SEPA renewals.', 'woo-subzero'));
        }
    }

    public function save_classic($order, array $posted): void
    {
        if ($order instanceof WC_Order && $this->checkout->cart_contains_subscription_items()
            && self::available((string) $order->get_payment_method())) {
            WSZ_PayNL_SEPA_Context::capture($order, $this->classic_input($posted));
        }
    }

    private function classic_input(array $posted): array
    {
        $input = array();
        foreach (array_keys($this->definitions()) as $name) {
            $input[$name] = $posted['wsz_sepa_' . $name] ?? '';
        }
        return $input;
    }

    public function register_block_fields(): void
    {
        if (!self::available(WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY, true)) {
            return;
        }
        // Cart extension makes fields invisible for ordinary products, including ordinary iDEAL orders.
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data(array(
                'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
                'namespace' => 'woo-subs-zero',
                'data_callback' => fn () => array('has_subscription' => $this->checkout->cart_contains_subscription_items()),
                'schema_callback' => static fn () => array('has_subscription' => array('type' => 'boolean', 'readonly' => true)),
                'schema_type' => ARRAY_A,
            ));
        }
        $condition = array('type' => 'object', 'required' => array('checkout', 'cart'), 'properties' => array(
            'checkout' => array('required' => array('payment_method'), 'properties' => array('payment_method' => array('const' => WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY))),
            'cart' => array('required' => array('extensions'), 'properties' => array('extensions' => array(
                'required' => array('woo-subs-zero'), 'properties' => array('woo-subs-zero' => array(
                    'required' => array('has_subscription'), 'properties' => array('has_subscription' => array('const' => true)),
                )),
            ))),
        ));
        foreach ($this->definitions() as $name => $definition) {
            woocommerce_register_additional_checkout_field($definition + array(
                'id' => self::PREFIX . $name, 'location' => 'order',
                'optionalLabel' => $definition['label'],
                'required' => $condition,
                'hidden' => array('not' => $condition),
                'sanitize_callback' => static fn ($value) => is_string($value) ? sanitize_text_field($value) : $value,
            ));
        }
    }

    public function save_store_api($order, $request): void
    {
        if (!($order instanceof WC_Order)) {
            return;
        }
        // Woo stores additional fields on draft PUTs as well as the final POST.
        foreach (array('owner', 'iban', 'bic', 'consent') as $name) {
            $order->delete_meta_data('_wc_other/' . self::PREFIX . $name);
        }
        $data = is_array($request) || $request instanceof ArrayAccess ? $request : array();
        $gateway = (string) ($data['payment_method'] ?? $order->get_payment_method());
        $contains_subscription = $this->checkout->cart_contains_subscription_items() || $this->checkout->contains_subscription_items($order);
        $is_post = !is_object($request) || !is_callable(array($request, 'get_method')) || 'POST' === $request->get_method();
        if (!$contains_subscription || WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY !== $gateway || !WSZ_PayNL_SEPA_Context::enabled()) {
            $order->delete_meta_data(WSZ_PayNL_SEPA_Context::DRAFT_META);
            return;
        }
        if (!self::available($gateway, true)) {
            if ($is_post) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('wsz_sepa_checkout_unavailable', __('This checkout cannot collect SEPA mandate consent. Choose another supported method.', 'woo-subzero'), 400);
            }
            $order->delete_meta_data(WSZ_PayNL_SEPA_Context::DRAFT_META);
            return;
        }
        $fields = (array) ($data['additional_fields'] ?? array());
        $input = array();
        foreach (array('owner', 'iban', 'bic', 'consent') as $name) {
            $key = self::PREFIX . $name;
            if (array_key_exists($key, $fields)) {
                $input[$name] = $fields[$key];
            }
        }
        if (!$is_post) {
            WSZ_PayNL_SEPA_Context::save_draft($order, $input);
            return; // A draft, including a checked box, is not an accepted mandate.
        }
        try {
            WSZ_PayNL_SEPA_Context::capture($order, $input);
            $order->delete_meta_data(WSZ_PayNL_SEPA_Context::DRAFT_META);
        } catch (InvalidArgumentException $error) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('wsz_sepa_consent_required', $error->getMessage(), 400);
        }
    }

    public function enqueue_classic_visibility(): void
    {
        if (!$this->checkout->cart_contains_subscription_items() || !self::available(WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY)) {
            return;
        }
        wp_add_inline_script('wc-checkout', "jQuery(function($){function wszSepaFields(){var show=$('input[name=payment_method]:checked').val()==='pay_gateway_ideal';$('.wsz-sepa-field').toggle(show).find('input').prop('disabled',!show).prop('required',show);}$(document.body).on('updated_checkout payment_method_selected',wszSepaFields);wszSepaFields();});", 'after');
    }
}
