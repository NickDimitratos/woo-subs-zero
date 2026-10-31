<?php

defined('ABSPATH') || exit;

/** SEPA authorization is separate from a reusable card token and a provider mandate. */
final class WSZ_PayNL_SEPA_Context
{
    public const AUTH_META = '_wsz_paynl_sepa_authorization';
    public const DRAFT_META = '_wsz_paynl_sepa_checkout_draft';
    public const INITIAL_GATEWAY = 'pay_gateway_ideal';
    public const DIRECT_DEBIT_OPTION_ID = 137;

    public static function settings(): array
    {
        return (array) get_option('wsz_subs_options', array());
    }

    public static function is_test_environment(): bool
    {
        $settings = self::settings();
        return 'yes' === ($settings['enable_test_mode'] ?? 'no')
            || in_array(get_option('paynl_test_mode', 'no'), array('yes', '1', 1, true), true)
            || (is_callable(array('PPMFWC_Helper_Data', 'isTestMode')) && PPMFWC_Helper_Data::isTestMode());
    }

    public static function enabled(): bool
    {
        $settings = self::settings();
        // PAY.nl's hosted-checkout test switch does not document a sandbox for mandate APIs.
        return 'yes' === ($settings['enable_paynl_sepa'] ?? 'no')
            && !self::is_test_environment()
            && function_exists('sodium_crypto_secretbox') && function_exists('wp_salt')
            && '' !== trim((string) ($settings['paynl_sepa_creditor_name'] ?? ''))
            && '' !== trim((string) ($settings['paynl_sepa_creditor_id'] ?? ''))
            && '' !== self::consent_text()
            && in_array(strtolower((string) parse_url($settings['paynl_sepa_terms_url'] ?? '', PHP_URL_SCHEME)), array('https', 'http'), true)
            && (bool) filter_var($settings['paynl_sepa_terms_url'] ?? '', FILTER_VALIDATE_URL);
    }

    /** Resolve the same merchant context at checkout, for the subscription, and for each renewal. */
    public static function credentials(WC_Order $order, WC_Order $subscription): array
    {
        $credentials = apply_filters('wsz_subs_paynl_sepa_credentials',
            (new WSZ_PayNL_Gateway_Integration())->get_recurring_credentials($subscription, $subscription), $order, $subscription);
        return is_array($credentials) ? $credentials : array();
    }

    public static function has_credentials(WC_Order $order): bool
    {
        $credentials = self::credentials($order, $order);
        return 1 === preg_match('/^SL-[A-Z0-9]{4}-[A-Z0-9]{4}$/iD', (string) ($credentials['service_id'] ?? ''))
            && !empty($credentials['username']) && !empty($credentials['password']);
    }

    public static function provider_available(): bool
    {
        // The native PAY.nl plugin caches the sales location's available payment options.
        // Custom gateways without that helper retain their existing explicit opt-in.
        $available = !is_callable(array('PPMFWC_Helper_Data', 'isOptionAvailable'))
            || (bool) PPMFWC_Helper_Data::isOptionAvailable(self::DIRECT_DEBIT_OPTION_ID);
        return (bool) apply_filters('wsz_subs_paynl_sepa_provider_available', $available);
    }

    public static function valid_iban(string $iban): bool
    {
        $iban = self::normalize_iban($iban);
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            foreach (str_split(ctype_alpha($char) ? (string) (ord($char) - 55) : $char) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }
        return 1 === $remainder;
    }

    public static function normalize_iban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban));
    }

    public static function consent_text(): string
    {
        $settings = self::settings();
        $text = sprintf(
            __('I authorise %1$s (creditor ID %2$s) to collect my subscription renewals from this bank account by SEPA Direct Debit, and my bank to debit those collections. I pay the initial order with iDEAL. Renewal amounts and frequency follow the subscription shown at checkout. I will receive a collection notice at least %3$d days before processing. I can request a refund from my bank within eight weeks of a debit. Mandate and cancellation terms: %4$s', 'woo-subzero'),
            (string) ($settings['paynl_sepa_creditor_name'] ?? ''),
            (string) ($settings['paynl_sepa_creditor_id'] ?? ''),
            self::notice_days(),
            (string) ($settings['paynl_sepa_terms_url'] ?? '')
        );
        $text = apply_filters('wsz_subs_paynl_sepa_consent_text', $text);
        return is_string($text) ? trim($text) : '';
    }

    public static function notice_days(): int
    {
        return min(60, max(1, (int) (self::settings()['paynl_sepa_notice_days'] ?? 14)));
    }

    /** Called only after WooCommerce's authenticated checkout validation. */
    public static function capture(WC_Order $order, array $input): void
    {
        $iban = self::normalize_iban((string) ($input['iban'] ?? ''));
        $bic = strtoupper(trim((string) ($input['bic'] ?? '')));
        $owner = sanitize_text_field((string) ($input['owner'] ?? ''));
        if (!self::enabled() || !self::has_credentials($order) || self::INITIAL_GATEWAY !== $order->get_payment_method()
            || 'EUR' !== strtoupper($order->get_currency()) || (int) $order->get_customer_id() <= 0
            || !self::valid_iban($iban) || !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', $bic)
            || '' === $owner || !filter_var($order->get_billing_email(), FILTER_VALIDATE_EMAIL)
            || !filter_var($order->get_customer_ip_address(), FILTER_VALIDATE_IP) || !in_array($input['consent'] ?? false, array(true, 1, '1'), true)) {
            throw new InvalidArgumentException(__('To use iDEAL with automatic SEPA renewals, provide a valid IBAN, BIC, account holder and explicit mandate consent. EUR and a customer account are required.', 'woo-subzero'));
        }
        // Retrying the same checkout must not rewrite consent evidence after payment.
        if ($order->is_paid() && '' !== (string) $order->get_meta(self::AUTH_META, true)) {
            return;
        }
        $authorization = array(
            'notice_days' => self::notice_days(),
            'creditor_id' => (string) self::settings()['paynl_sepa_creditor_id'],
            'service_id' => self::credentials($order, $order)['service_id'],
            'checkout_total' => wc_format_decimal($order->get_total(), 2), 'currency' => $order->get_currency(),
            'version' => 1, 'environment' => 'live', 'customer_id' => (int) $order->get_customer_id(),
            'iban' => $iban, 'bic' => $bic, 'owner' => $owner,
            'email' => (string) $order->get_billing_email(),
            'ipAddress' => (string) $order->get_customer_ip_address(),
            'consented_at' => time(), 'consent_text' => self::consent_text(),
            'reference' => bin2hex(random_bytes(16)),
        );
        $order->update_meta_data(self::AUTH_META, self::encrypt($authorization));
    }

    /** Partial checkout fields are encrypted but never treated as an accepted mandate. */
    public static function save_draft(WC_Order $order, array $input): void
    {
        if (!function_exists('sodium_crypto_secretbox') || !function_exists('wp_salt')) {
            return;
        }
        $draft = self::read_draft($order);
        foreach (array('owner', 'iban', 'bic', 'consent') as $name) {
            if (!array_key_exists($name, $input) || !is_scalar($input[$name])) {
                continue;
            }
            if ('consent' === $name) {
                $draft[$name] = in_array($input[$name], array(true, 1, '1'), true) ? '1' : '0';
            } elseif ('iban' === $name) {
                $draft[$name] = self::normalize_iban((string) $input[$name]);
            } elseif ('bic' === $name) {
                $draft[$name] = strtoupper(trim((string) $input[$name]));
            } else {
                $draft[$name] = sanitize_text_field((string) $input[$name]);
            }
        }
        if (!empty($draft)) {
            $order->update_meta_data(self::DRAFT_META, self::encrypt($draft));
        }
    }

    public static function read_draft(WC_Order $order): array
    {
        return self::decrypt((string) $order->get_meta(self::DRAFT_META, true));
    }

    public static function read(WC_Order $order): array
    {
        return self::decrypt((string) $order->get_meta(self::AUTH_META, true));
    }

    private static function encrypt(array $data): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox(wp_json_encode($data), $nonce, self::key()));
    }

    private static function decrypt(string $encoded): array
    {
        if (!function_exists('sodium_crypto_secretbox_open') || !function_exists('wp_salt')) {
            return array();
        }
        $raw = base64_decode($encoded, true);
        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return array();
        }
        $json = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        $data = false !== $json ? json_decode($json, true) : null;
        return is_array($data) ? $data : array();
    }

    public static function valid_for_subscription(WC_Order $subscription): bool
    {
        if (!self::enabled() || !self::has_credentials($subscription) || self::INITIAL_GATEWAY !== $subscription->get_payment_method()
            || 'EUR' !== strtoupper($subscription->get_currency())
            || 'revoked' === $subscription->get_meta('_wsz_paynl_sepa_mandate_state', true)) {
            return false;
        }
        $parent_id = (int) $subscription->get_meta('_wsz_parent_order_id', true);
        $parent = $parent_id > 0 ? wc_get_order($parent_id) : null;
        if (!($parent instanceof WC_Order) || !$parent->is_paid() || self::INITIAL_GATEWAY !== $parent->get_payment_method()
            || (int) $subscription->get_customer_id() <= 0 || $subscription->get_customer_id() !== $parent->get_customer_id()
            || (int) $subscription->get_id() <= 0
            || !in_array((int) $subscription->get_id(), array_map('absint', (array) $parent->get_meta('_wsz_subscription_ids', true)), true)
            || !hash_equals((string) $parent->get_meta(self::AUTH_META, true), (string) $subscription->get_meta(self::AUTH_META, true))) {
            return false;
        }
        $auth = self::read($subscription);
        $credentials = self::credentials($subscription, $subscription);
        return ($auth['service_id'] ?? '') === ($credentials['service_id'] ?? '')
            && ($auth['creditor_id'] ?? '') === (self::settings()['paynl_sepa_creditor_id'] ?? '')
            && 1 === ($auth['version'] ?? 0) && 'live' === ($auth['environment'] ?? 'live') && !empty($auth['consented_at']) && !empty($auth['consent_text'])
            && (int) ($auth['customer_id'] ?? 0) === (int) $subscription->get_customer_id()
            && self::valid_iban((string) ($auth['iban'] ?? '')) && !empty($auth['owner']) && !empty($auth['bic']);
    }

    public static function authorized_notice_days(WC_Order $subscription): int
    {
        return max(self::notice_days(), (int) (self::read($subscription)['notice_days'] ?? 14));
    }

    private static function key(): string
    {
        return hash('sha256', 'wsz-paynl-sepa:' . wp_salt('auth'), true);
    }
}
