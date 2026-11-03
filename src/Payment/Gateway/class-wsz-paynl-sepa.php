<?php

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-wsz-paynl-sepa-context.php';

/** Flexible mandates: WSZ owns the schedule, PAY.nl owns bank processing. */
final class WSZ_PayNL_SEPA
{
    public const RENEWAL_GATEWAY_ID = 'wsz_paynl_sepa';

    private const API = 'https://rest.pay.nl/v2/directdebits';
    private WSZ_Subscription_Manager $manager;

    public function __construct(WSZ_Subscription_Manager $manager)
    {
        $this->manager = $manager;
    }

    public function init(): void
    {
        require_once __DIR__ . '/class-wsz-paynl-sepa-order-gateway.php';
        if (class_exists('WSZ_PayNL_SEPA_Order_Gateway')) {
            add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
                $gateways[] = 'WSZ_PayNL_SEPA_Order_Gateway';
                return $gateways;
            });
        }
        add_filter('woocommerce_order_actions', array($this, 'order_actions'), 20, 2);
        add_action('woocommerce_order_action_wsz_paynl_sepa_reconcile', array($this, 'reconcile_order'));
        add_filter('woocommerce_order_needs_payment', array($this, 'filter_needs_payment'), 20, 2);
        add_action('woocommerce_payment_complete', array($this, 'sync_paid_parent'), 15);
        add_action('woocommerce_order_status_processing', array($this, 'sync_paid_parent'), 15);
        add_action('woocommerce_order_status_completed', array($this, 'sync_paid_parent'), 15);
        add_action('wsz_subs_subscription_activated', array($this, 'sync_activated_subscription'), 15);
        // Reconciliation remains available after the feature is disabled.
        add_action('woocommerce_api_wsz_paynl_sepa', array($this, 'receive_exchange'));
        add_action('wsz_subs_renewal_scheduled', array($this, 'schedule_preparation'), 10, 1);
        add_action('wsz_subs_paynl_sepa_poll', array($this, 'poll'), 10);
        add_action('woocommerce_subscription_payment_method_updated', array($this, 'payment_method_changed'), 20, 3);
        add_action('woocommerce_subscription_status_updated', array($this, 'subscription_status_changed'), 15, 3);
        add_action('wsz_subs_subscription_payments_completed', array($this, 'payments_completed'), 10, 1);
        add_action('wsz_subs_paynl_sepa_cancel', array($this, 'cancel_mandate'), 10);
        if (!class_exists('WSZ_Checkout_Handler')) {
            require_once dirname(__DIR__, 3) . '/includes/class-wsz-checkout-handler.php';
        }
        require_once __DIR__ . '/class-wsz-paynl-sepa-checkout.php';
        (new WSZ_PayNL_SEPA_Checkout($this->manager))->init();
    }

    public function order_actions(array $actions, $order): array
    {
        if ($order instanceof WC_Order && '' !== $order->get_meta('_wsz_paynl_sepa_state', true)) {
            $actions['wsz_paynl_sepa_reconcile'] = __('Refresh PAY.nl SEPA bank status', 'woo-subzero');
        }
        return $actions;
    }

    public function reconcile_order($order): void
    {
        // WooCommerce's order-action form enforces its nonce; also enforce the store capability.
        if (!($order instanceof WC_Order) || !current_user_can('manage_woocommerce')) {
            return;
        }
        if ('' === $order->get_meta('_wsz_paynl_sepa_debit_id', true)) {
            $order->add_order_note(__('The first PAY.nl IL debit reference has not arrived. Repair callback delivery and reconcile the existing provider request; no charge was resubmitted.', 'woo-subzero'));
            return;
        }
        if (!$this->handle_exchange(array('extra1' => (string) $order->get_id()))) {
            $order->add_order_note(__('PAY.nl bank status could not be verified. The existing collection remains unchanged; no charge was resubmitted.', 'woo-subzero'));
        }
    }

    public function filter_needs_payment(bool $needs_payment, $order): bool
    {
        return $order instanceof WC_Order && 'yes' === $order->get_meta('_wsz_async_payment_pending', true) ? false : $needs_payment;
    }

    public function sync_activated_subscription($subscription): void
    {
        if ($subscription instanceof WC_Order) {
            $this->sync_paid_parent((int) $subscription->get_meta('_wsz_parent_order_id', true));
        }
    }

    public function sync_paid_parent($order_id): void
    {
        $order = wc_get_order((int) $order_id);
        if (!($order instanceof WC_Order) || !$order->is_paid() || WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY !== $order->get_payment_method()
            || empty(WSZ_PayNL_SEPA_Context::read($order))) {
            return;
        }
        foreach ((array) $order->get_meta('_wsz_subscription_ids', true) as $id) {
            $sub = $this->manager->get_subscription((int) $id);
            if (!($sub instanceof WC_Order) || (int) $sub->get_meta('_wsz_parent_order_id', true) !== $order->get_id()
                || $sub->get_customer_id() !== $order->get_customer_id() || WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY !== $sub->get_payment_method()
                || in_array($sub->get_status(), array('cancelled', 'expired', 'pending-cancel'), true)
                || 'revoked' === $sub->get_meta('_wsz_paynl_sepa_mandate_state', true)) {
                continue;
            }
            $sub->update_meta_data(WSZ_PayNL_SEPA_Context::AUTH_META, $order->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META, true));
            if (WSZ_PayNL_SEPA_Context::valid_for_subscription($sub) && WSZ_PayNL_SEPA_Context::provider_available()) {
                if ('yes' !== $sub->get_meta('_wsz_paynl_sepa_setup_complete', true)) {
                    $this->manager->set_manual_renewal($sub, false);
                    $sub->update_meta_data('_wsz_paynl_sepa_setup_complete', 'yes');
                }
                if ('' === $sub->get_meta('_wsz_paynl_sepa_mandate_state', true)) {
                    $sub->update_meta_data('_wsz_paynl_sepa_mandate_state', 'authorized');
                }
            }
            $sub->save();
            if ($this->manager->has_completed_all_payments($sub)) {
                $this->payments_completed($sub);
            } else {
                $this->schedule_preparation($sub);
            }
        }
    }

    public function schedule_preparation($sub): void
    {
        if (!($sub instanceof WC_Order) || $this->manager->has_completed_all_payments($sub) || !WSZ_PayNL_SEPA_Context::valid_for_subscription($sub)
            || !WSZ_PayNL_SEPA_Context::provider_available() || $this->manager->is_manual_renewal($sub) || 'active' !== $sub->get_status()
            || !function_exists('as_schedule_single_action')) {
            return;
        }
        $due = $this->manager->get_next_payment_timestamp($sub);
        if ($due > 0) {
            $this->schedule_action('wsz_subs_paynl_sepa_prepare',
                array('subscription_id' => $sub->get_id(), 'schedule_key' => hash('sha256', $sub->get_id() . '|' . $due)),
                max(time() + 1, $due - WSZ_PayNL_SEPA_Context::authorized_notice_days($sub) * DAY_IN_SECONDS));
        }
    }

    /** Never use a card token, even though the original gateway remains iDEAL. */
    public function process_payment(WC_Order $sub, WC_Order $order): void
    {
        if ($this->manager->has_completed_all_payments($sub) || !WSZ_PayNL_SEPA_Context::valid_for_subscription($sub) || $this->manager->is_manual_renewal($sub)
            || 'active' !== $sub->get_status()
            || (int) $order->get_meta('_wsz_subscription_id', true) !== $sub->get_id()
            || $order->get_customer_id() !== $sub->get_customer_id()) {
            return;
        }
        if ('EUR' !== strtoupper($order->get_currency())) {
            $this->hold($sub, $order, __('SEPA Direct Debit supports EUR renewal orders only. No bank request was sent.', 'woo-subzero'));
            return;
        }
        if ($order->is_paid() || '' !== $order->get_meta('_wsz_paynl_sepa_state', true)) {
            return;
        }
        if (!$this->manager->acquire_lock('sepa', $sub->get_id(), 300)) {
            return;
        }
        try {
            // Another worker may have completed submission after this worker loaded its objects.
            $current_sub = $this->manager->get_subscription((int) $sub->get_id());
            $current_order = wc_get_order((int) $order->get_id());
            if (!($current_sub instanceof WC_Order) || !($current_order instanceof WC_Order)
                || 'active' !== $current_sub->get_status() || $this->manager->has_completed_all_payments($current_sub)
                || $this->manager->is_manual_renewal($current_sub) || !WSZ_PayNL_SEPA_Context::valid_for_subscription($current_sub)
                || (int) $current_order->get_meta('_wsz_subscription_id', true) !== $current_sub->get_id()
                || $current_order->get_customer_id() !== $current_sub->get_customer_id()
                || $current_order->get_currency() !== $order->get_currency()
                || (string) $current_order->get_total() !== (string) $order->get_total()) {
                return;
            }
            if ($current_order->is_paid() || '' !== $current_order->get_meta('_wsz_paynl_sepa_state', true)) {
                // Inform an enclosing stale renewal worker without modifying the already-persisted order.
                $order->update_meta_data('_wsz_async_payment_pending', 'yes');
                return;
            }
            if ((int) $current_sub->get_meta('_wsz_pending_renewal_order_id', true) > 0) {
                return;
            }
            if (!WSZ_PayNL_SEPA_Context::provider_available()) {
                $this->hold($sub, $order, __('PAY.nl SEPA is unavailable for this sales location. No bank request was sent; review the payment method.', 'woo-subzero'));
                return;
            }
            $sub = $current_sub;
            $credentials = $this->credentials($order, $sub);
            $auth = WSZ_PayNL_SEPA_Context::read($sub);
            if (!$this->valid_credentials($credentials) || ($auth['service_id'] ?? '') !== ($credentials['service_id'] ?? '')
                || (float) $order->get_total() < 0) {
                $this->hold($sub, $order, __('PAY.nl SEPA credentials or renewal amount are invalid.', 'woo-subzero'));
                return;
            }
            if (0.0 === (float) $order->get_total()) {
                $order->payment_complete();
                return;
            }
            $amount = (int) round((float) wc_format_decimal($order->get_total(), 2) * 100);
            if ($amount < 1) {
                $this->hold($sub, $order, __('PAY.nl SEPA requires a positive EUR amount.', 'woo-subzero'));
                return;
            }
            $mandate = (string) $sub->get_meta('_wsz_paynl_sepa_mandate_id', true);
            if ('' !== $mandate && 'active' !== $sub->get_meta('_wsz_paynl_sepa_mandate_state', true)) {
                $this->hold($sub, $order, __('The PAY.nl mandate has not completed its first successful debit.', 'woo-subzero'));
                return;
            }
            $process_date = gmdate('c', max($this->manager->get_next_payment_timestamp($sub), time() + WSZ_PayNL_SEPA_Context::authorized_notice_days($sub) * DAY_IN_SECONDS));
            if (!$this->send_notice($order, $sub, $auth, $process_date)) {
                $this->hold($sub, $order, __('The SEPA collection notice could not be sent. No bank charge was submitted.', 'woo-subzero'));
                return;
            }
            $payload = array(
                'description' => sprintf('Renewal order %d', $order->get_id()),
                'processDate' => $process_date,
                'amount' => array('value' => $amount, 'currency' => 'EUR'),
                'stats' => array('extra1' => (string) $order->get_id(), 'extra2' => (string) $sub->get_id(), 'object' => 'Woo Subs-Zero'),
            );
            $endpoint = self::API;
            if ('' === $mandate) {
                $payload += array('type' => 'FLEXIBLE', 'serviceId' => $credentials['service_id'],
                    'reference' => 'WSZ-R' . $order->get_id(), 'exchangeUrl' => add_query_arg(array('wsz_order_id' => $order->get_id()), WC()->api_request_url('wsz_paynl_sepa')),
                    'customer' => array('email' => $auth['email'], 'ipAddress' => $auth['ipAddress'],
                        'bankAccount' => array_intersect_key($auth, array_flip(array('iban', 'bic', 'owner')))));
                $endpoint .= '/mandates';
            } else {
                // Check the mandate in the merchant account before using it again.
                $verified = $this->request('GET', self::API . '/mandates/' . rawurlencode($mandate), array(), $credentials);
                if (!$this->matches_mandate($verified, $credentials, $auth, $mandate)) {
                    $this->hold($sub, $order, __('PAY.nl SEPA mandate verification failed. No new charge was submitted.', 'woo-subzero'));
                    return;
                }
                $payload['mandate'] = $mandate;
                $total = $this->manager->get_total_payments($sub);
                $payload['isLastOrder'] = $total > 0 && $this->manager->get_completed_payments($sub) + 1 >= $total;
            }
            // Persist intent BEFORE the network call. An uncertain response must never be retried blindly.
            $order->update_meta_data('_wsz_paynl_sepa_state', 'submitting');
            $order->update_meta_data('_wsz_paynl_sepa_service', $credentials['service_id']);
            $order->update_meta_data('_wsz_paynl_sepa_amount', $amount);
            $order->update_meta_data('_wsz_paynl_sepa_process_date', $process_date);
            $order->update_meta_data('_wsz_async_payment_pending', 'yes');
            $order->set_payment_method(self::RENEWAL_GATEWAY_ID);
            $order->set_payment_method_title(__('SEPA Direct Debit (PAY.nl)', 'woo-subzero'));
            $order->update_status('on-hold', __('SEPA collection submitted; awaiting verified bank confirmation.', 'woo-subzero'));
            $sub->update_meta_data('_wsz_pending_renewal_order_id', $order->get_id());
            $sub->save();
            $result = $this->request('POST', $endpoint, $payload, $credentials);
            if ('' === $mandate) {
                $mandate = (string) ($result['code'] ?? '');
                if (!$this->matches_mandate($result, $credentials, $auth, $mandate)
                    || 'WSZ-R' . $order->get_id() !== ($result['reference'] ?? '')) {
                    $this->review($order);
                    return;
                }
                $sub->update_meta_data('_wsz_paynl_sepa_mandate_id', $mandate);
                $sub->update_meta_data('_wsz_paynl_sepa_mandate_reference', 'WSZ-R' . $order->get_id());
                $sub->update_meta_data('_wsz_paynl_sepa_mandate_state', 'pending');
                $sub->save();
            } elseif (!$this->matches_debit($result, $order, $mandate)) {
                $this->review($order);
                return;
            }
            $order->update_meta_data('_wsz_paynl_sepa_mandate_id', $mandate);
            $order->update_meta_data('_wsz_paynl_sepa_state', 'pending');
            if (!empty($result['id']) && $this->valid_id((string) $result['id'], 'IL')) {
                $order->update_meta_data('_wsz_paynl_sepa_debit_id', $result['id']);
            }
            $order->save();
            $this->schedule_poll($order->get_id());
        } finally {
            $this->manager->release_lock('sepa', $sub->get_id());
        }
    }

    /** Untrusted exchange fields are hints only; status and identity come from authenticated GET. */
    public function handle_exchange(array $payload, int $order_id_hint = 0): bool
    {
        $extra = $payload['extra1'] ?? '';
        if (!is_scalar($extra)) {
            return false;
        }
        $payload_id = absint($extra);
        if ($order_id_hint > 0 && $payload_id > 0 && $order_id_hint !== $payload_id) {
            return false;
        }
        $id = $order_id_hint > 0 ? $order_id_hint : $payload_id;
        $order = $id > 0 ? wc_get_order($id) : null;
        if (!($order instanceof WC_Order) || '' === $order->get_meta('_wsz_paynl_sepa_state', true)) {
            return false;
        }
        $debit_id = (string) ($payload['referenceId'] ?? $order->get_meta('_wsz_paynl_sepa_debit_id', true));
        $mandate = (string) ($payload['mandateId'] ?? $order->get_meta('_wsz_paynl_sepa_mandate_id', true));
        if (!$this->valid_id($debit_id, 'IL') || !$this->valid_id($mandate, 'IO')) {
            return false;
        }
        $sub = $this->manager->get_subscription((int) $order->get_meta('_wsz_subscription_id', true));
        if (!($sub instanceof WC_Order)) {
            return false;
        }
        $locked_subscription_id = (int) $sub->get_id();
        if (!$this->manager->acquire_lock('sepa', $locked_subscription_id, 300)) {
            return false;
        }
        try {
            $order = wc_get_order($id);
            $sub = $this->manager->get_subscription((int) ($order instanceof WC_Order ? $order->get_meta('_wsz_subscription_id', true) : 0));
            if (!($order instanceof WC_Order) || !($sub instanceof WC_Order) || $locked_subscription_id !== (int) $sub->get_id()
                || '' === $order->get_meta('_wsz_paynl_sepa_state', true)
                || $order->get_customer_id() !== $sub->get_customer_id()) {
                return false;
            }
            $credentials = $this->credentials($order, $sub);
            $auth = WSZ_PayNL_SEPA_Context::read($sub);
            if (!$this->valid_credentials($credentials) || 'live' !== ($auth['environment'] ?? 'live')
                || ($auth['service_id'] ?? '') !== $credentials['service_id']
                || $order->get_meta('_wsz_paynl_sepa_service', true) !== $credentials['service_id']) {
                return false;
            }
            $result = $this->request('GET', self::API . '/' . rawurlencode($debit_id), array(), $credentials);
            if (!$this->matches_debit($result, $order, $mandate, true) || $debit_id !== ($result['id'] ?? '')
                || ('' !== $order->get_meta('_wsz_paynl_sepa_debit_id', true) && $debit_id !== $order->get_meta('_wsz_paynl_sepa_debit_id', true))
                || ('' !== $sub->get_meta('_wsz_paynl_sepa_mandate_id', true) && $mandate !== $sub->get_meta('_wsz_paynl_sepa_mandate_id', true))) {
                return false;
            }
            // The first bank debit may omit extra1. Verify the mandate's own original reference too.
            $debit_reference = (string) ($result['stats']['extra1'] ?? '');
            if ('' === $sub->get_meta('_wsz_paynl_sepa_mandate_id', true) || '' === $debit_reference) {
                $verified = $this->request('GET', self::API . '/mandates/' . rawurlencode($mandate), array(), $credentials);
                if (!$this->matches_mandate($verified, $credentials, $auth, $mandate)
                    || 'WSZ-R' . $order->get_id() !== ($verified['reference'] ?? '')) {
                    return false;
                }
                $sub->update_meta_data('_wsz_paynl_sepa_mandate_id', $mandate);
                $sub->update_meta_data('_wsz_paynl_sepa_mandate_reference', 'WSZ-R' . $order->get_id());
            }
            $order->update_meta_data('_wsz_paynl_sepa_debit_id', $debit_id);
            $order->update_meta_data('_wsz_paynl_sepa_mandate_id', $mandate);
            if (in_array($order->get_meta('_wsz_paynl_sepa_state', true), array('review', 'submitting'), true)) {
                $order->update_meta_data('_wsz_paynl_sepa_state', 'pending');
            }
            if ('authorized' === $sub->get_meta('_wsz_paynl_sepa_mandate_state', true)) {
                $sub->update_meta_data('_wsz_paynl_sepa_mandate_state', 'pending');
            }
            $order->save();
            $sub->save();
            $code = (int) ($result['status']['code'] ?? 0);
            if (in_array($result['declined'] ?? false, array(true, 1, '1', 'true'), true) || in_array($code, array(97, 103, 106, 127), true)
                || (94 === $code && in_array(strtolower((string) ($result['status']['phase'] ?? '')), array('prematurely rejected', 'rejected'), true))) {
                if (in_array($order->get_meta('_wsz_paynl_sepa_state', true), array('failed', 'reversed'), true)) {
                    return true; // An old bank failure must not overwrite a later recovery payment.
                }
                $was_paid = $order->is_paid();
                $order->update_meta_data('_wsz_paynl_sepa_state', $was_paid ? 'reversed' : 'failed');
                $order->update_meta_data('_wsz_async_payment_pending', 'no');
                $order->update_status('failed', __('PAY.nl verified a refused or reversed SEPA collection. Automatic retries are disabled; review the bank reason before recovery.', 'woo-subzero'));
                $sub->update_meta_data('_wsz_paynl_sepa_mandate_state', 'revoked');
                $sub->update_meta_data('_wsz_pending_renewal_order_id', 0);
                $this->manager->set_manual_renewal($sub, true);
                if ('active' === $sub->get_status()) {
                    $this->manager->transition_status($sub, 'on-hold', __('SEPA collection failed or was reversed.', 'woo-subzero'));
                }
                $sub->save();
                $this->schedule_revocation_if_needed($sub);
                return true;
            }
            if (100 === $code && in_array($order->get_meta('_wsz_paynl_sepa_state', true), array('reversed', 'failed'), true)) {
                // A recovery payment may already be in progress. Keep it blocked for operator review.
                if ('yes' !== $order->get_meta('_wsz_paynl_sepa_late_paid_review', true)) {
                    $order->update_meta_data('_wsz_paynl_sepa_late_paid_review', 'yes');
                    if (!$order->is_paid()) {
                        $order->update_meta_data('_wsz_async_payment_pending', 'yes');
                    }
                    $order->add_order_note(__('PAY.nl now reports this previously failed or reversed debit as collected. Review the bank transaction and any recovery payment before collecting again.', 'woo-subzero'));
                    $order->save();
                }
                return true;
            }
            if (100 === $code && !in_array($order->get_meta('_wsz_paynl_sepa_state', true), array('paid', 'reversed', 'failed'), true)) {
                if (!in_array($sub->get_status(), array('cancelled', 'expired', 'pending-cancel'), true)
                    && 'revoked' !== $sub->get_meta('_wsz_paynl_sepa_mandate_state', true)) {
                    $sub->update_meta_data('_wsz_paynl_sepa_mandate_state', 'active');
                }
                $sub->save();
                if (!$order->is_paid()) {
                    $order->payment_complete($debit_id);
                }
                $order->update_meta_data('_wsz_paynl_sepa_state', 'paid');
                $order->update_meta_data('_wsz_async_payment_pending', 'no');
                $order->save();
                do_action('wsz_subs_async_renewal_payment_complete', $sub, $order);
            } elseif (100 === $code && 'paid' === $order->get_meta('_wsz_paynl_sepa_state', true) && 'yes' !== $order->get_meta('_wsz_cycle_completed', true)) {
                // Retry accounting if a preceding callback stopped after recording bank settlement.
                do_action('wsz_subs_async_renewal_payment_complete', $sub, $order);
            } elseif (100 !== $code) {
                $order->save();
                $this->schedule_poll($order->get_id());
            }
            if (100 === $code && 'paid' === $order->get_meta('_wsz_paynl_sepa_state', true) && 'yes' !== $order->get_meta('_wsz_cycle_completed', true)) {
                $this->schedule_poll($order->get_id());
            }
            $this->schedule_revocation_if_needed($sub);
            return true;
        } finally {
            $this->manager->release_lock('sepa', $locked_subscription_id);
        }
    }

    public function poll($order_id): void
    {
        if (!$this->handle_exchange(array('extra1' => (string) $order_id))) {
            $order = wc_get_order((int) $order_id);
            if ($order instanceof WC_Order && 'yes' === $order->get_meta('_wsz_async_payment_pending', true)) {
                $this->schedule_poll($order->get_id());
            }
        }
    }

    public function receive_exchange(): void
    {
        $json = json_decode((string) file_get_contents('php://input'), true);
        $payload = is_array($json) ? $json : wp_unslash($_POST + $_GET);
        $hint = isset($_GET['wsz_order_id']) && is_scalar($_GET['wsz_order_id']) ? absint(wp_unslash($_GET['wsz_order_id'])) : 0;
        $ok = $this->handle_exchange(is_array($payload) ? $payload : array(), $hint);
        status_header($ok ? 200 : 503);
        header('Content-Type: text/plain; charset=utf-8');
        echo $ok ? 'TRUE|verified' : 'FALSE|not_verified';
        exit;
    }

    public function subscription_status_changed($sub, $to, $from): void
    {
        if (!($sub instanceof WC_Order) || !in_array($to, array('cancelled', 'expired', 'pending-cancel'), true)
            || ('' === $sub->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META, true) && '' === $sub->get_meta('_wsz_paynl_sepa_mandate_id', true))) {
            return;
        }
        $this->revoke_authority($sub);
    }

    public function payments_completed($sub): void
    {
        if ($sub instanceof WC_Order && $this->manager->has_completed_all_payments($sub)
            && ('' !== $sub->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META, true) || '' !== $sub->get_meta('_wsz_paynl_sepa_mandate_id', true))) {
            $this->revoke_authority($sub);
        }
    }

    private function revoke_authority(WC_Order $sub): void
    {
        $sub->update_meta_data('_wsz_paynl_sepa_mandate_state', 'revoked');
        $sub->save();
        if ('' === $sub->get_meta('_wsz_paynl_sepa_revoked_at', true)) {
            $this->cancel_mandate($sub->get_id());
        }
    }

    public function payment_method_changed($sub, $new = '', $old = ''): void
    {
        $sub = $sub instanceof WC_Order ? $sub : $this->manager->get_subscription((int) $sub);
        if ($sub instanceof WC_Order && WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY !== $sub->get_payment_method()) {
            $this->subscription_status_changed($sub, 'cancelled', $sub->get_status());
        }
    }

    public function cancel_mandate($subscription_id): void
    {
        $sub = $this->manager->get_subscription((int) $subscription_id);
        if (!($sub instanceof WC_Order) || !$this->valid_id((string) $sub->get_meta('_wsz_paynl_sepa_mandate_id', true), 'IO')) {
            return;
        }
        $id = $sub->get_meta('_wsz_paynl_sepa_mandate_id', true);
        $credentials = $this->credentials($sub, $sub);
        $auth = WSZ_PayNL_SEPA_Context::read($sub);
        $result = $this->valid_credentials($credentials) && 'live' === ($auth['environment'] ?? 'live') && ($auth['service_id'] ?? '') === $credentials['service_id']
            ? $this->request('DELETE', self::API . '/mandates/' . rawurlencode($id), array(), $credentials)
            : array();
        if (empty($result['_deleted'])) {
            $sub->add_order_note(__('PAY.nl mandate revocation is pending. Review the mandate and any bank collection already submitted in PAY.nl.', 'woo-subzero'));
            if (function_exists('as_schedule_single_action')) {
                $this->schedule_action('wsz_subs_paynl_sepa_cancel', array('subscription_id' => $sub->get_id()), time() + HOUR_IN_SECONDS);
            }
        } else {
            $sub->update_meta_data('_wsz_paynl_sepa_revoked_at', time());
            $sub->save();
        }
        if ((int) $sub->get_meta('_wsz_pending_renewal_order_id', true) > 0) {
            $sub->add_order_note(__('A SEPA debit was already submitted. Revoking a mandate does not confirm cancellation of a bank batch; review this collection in PAY.nl.', 'woo-subzero'));
        }
    }

    private function schedule_revocation_if_needed(WC_Order $sub): void
    {
        if ('revoked' === $sub->get_meta('_wsz_paynl_sepa_mandate_state', true)
            && '' === $sub->get_meta('_wsz_paynl_sepa_revoked_at', true) && function_exists('as_schedule_single_action')) {
            $this->schedule_action('wsz_subs_paynl_sepa_cancel', array('subscription_id' => $sub->get_id()), time() + 1);
        }
    }

    private function credentials(WC_Order $order, WC_Order $sub): array
    {
        // Credentials belong to the initial PAY.nl gateway, not the internal order-only gateway.
        return WSZ_PayNL_SEPA_Context::credentials($order, $sub);
    }

    private function valid_credentials(array $credentials): bool
    {
        return preg_match('/^SL-[A-Z0-9]{4}-[A-Z0-9]{4}$/iD', (string) ($credentials['service_id'] ?? ''))
            && !empty($credentials['username']) && !empty($credentials['password']);
    }

    private function request(string $method, string $url, array $payload, array $credentials): array
    {
        if (WSZ_PayNL_SEPA_Context::is_test_environment() || !$this->valid_credentials($credentials)) {
            return array();
        }
        $args = array('method' => $method, 'timeout' => 30, 'redirection' => 0,
            'headers' => array('Authorization' => 'Basic ' . base64_encode($credentials['username'] . ':' . $credentials['password']), 'Accept' => 'application/json', 'Content-Type' => 'application/json'));
        if ('POST' === $method) {
            $args['body'] = wp_json_encode($payload);
        }
        $response = wp_remote_request($url, $args);
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return array();
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ('DELETE' === $method && (in_array($code, array(200, 204), true))) {
            return array('_deleted' => true);
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        return $code >= 200 && $code < 300 && is_array($decoded) ? $decoded : array();
    }

    private function matches_mandate(array $data, array $credentials, array $auth, string $id): bool
    {
        return $this->valid_id($id, 'IO') && $id === ($data['code'] ?? '') && 'FLEXIBLE' === strtoupper((string) ($data['type'] ?? ''))
            && $credentials['service_id'] === ($data['service']['code'] ?? $data['serviceId'] ?? '')
            && empty($data['deletedAt']) && !empty($auth['iban'])
            && $auth['iban'] === WSZ_PayNL_SEPA_Context::normalize_iban((string) ($data['customer']['bankAccount']['iban'] ?? ''));
    }

    private function matches_debit(array $data, WC_Order $order, string $mandate, bool $allow_missing_reference = false): bool
    {
        $reference = $data['stats']['extra1'] ?? '';
        if (!is_scalar($reference) || (!$allow_missing_reference && '' === (string) $reference)) {
            return false;
        }
        return $this->valid_id((string) ($data['id'] ?? ''), 'IL') && $mandate === ($data['mandate']['code'] ?? '')
            && $order->get_meta('_wsz_paynl_sepa_service', true) === ($data['service']['code'] ?? '')
            && ((string) $order->get_id() === (string) $reference || ($allow_missing_reference && '' === (string) $reference))
            && (int) $order->get_meta('_wsz_paynl_sepa_amount', true) === ($data['amount']['value'] ?? null)
            && (int) round((float) $order->get_total() * 100) === ($data['amount']['value'] ?? null)
            && 'EUR' === ($data['amount']['currency'] ?? '');
    }

    private function valid_id(string $id, string $prefix): bool
    {
        return 1 === preg_match('/^' . $prefix . '-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/iD', $id);
    }

    private function hold(WC_Order $sub, WC_Order $order, string $note): void
    {
        $this->manager->set_manual_renewal($sub, true);
        $order->update_status('pending', $note);
    }

    private function review(WC_Order $order): void
    {
        $order->update_meta_data('_wsz_paynl_sepa_state', 'review');
        $order->add_order_note(__('PAY.nl SEPA submission outcome is uncertain. Do not resubmit or take manual payment until the existing request has been reconciled in PAY.nl.', 'woo-subzero'));
        $order->save();
        $this->schedule_poll($order->get_id());
    }

    private function schedule_poll(int $order_id): void
    {
        if (function_exists('as_schedule_single_action')) {
            $this->schedule_action('wsz_subs_paynl_sepa_poll', array('order_id' => $order_id), time() + 6 * HOUR_IN_SECONDS);
        }
    }

    private function schedule_action(string $hook, array $args, int $timestamp): void
    {
        if (function_exists('as_get_scheduled_actions')) {
            $pending = as_get_scheduled_actions(array('hook' => $hook, 'args' => $args, 'group' => WSZ_Subscription_Manager::ACTION_GROUP, 'status' => 'pending', 'per_page' => 1), 'ids');
            if (!empty($pending)) {
                return;
            }
        }
        // Pending-only query permits rescheduling from a currently running poll.
        // Exact arguments keep different subscriptions independent.
        as_schedule_single_action($timestamp, $hook, $args, WSZ_Subscription_Manager::ACTION_GROUP, false);
    }

    private function send_notice(WC_Order $order, WC_Order $sub, array $auth, string $process_date): bool
    {
        $settings = WSZ_PayNL_SEPA_Context::settings();
        $mandate_reference = $sub->get_meta('_wsz_paynl_sepa_mandate_reference', true) ?: 'WSZ-R' . $order->get_id();
        $message = sprintf(__('SEPA collection notice from %1$s (creditor ID %2$s)\nSubscription: %3$d\nAmount: EUR %4$s\nPAY.nl processing date: %5$s (bank debit follows processing)\nMandate reference: %6$s\nCollection reference: WSZ-R%7$d\nAccount ending: %8$s\nMandate and contact terms: %9$s', 'woo-subzero'),
            $settings['paynl_sepa_creditor_name'], $settings['paynl_sepa_creditor_id'], $sub->get_id(), wc_format_decimal($order->get_total(), 2),
            substr($process_date, 0, 10), $mandate_reference, $order->get_id(), substr($auth['iban'], -4), $settings['paynl_sepa_terms_url']);
        $message = str_replace('\\n', "\n", $message);
        $sent = apply_filters('wsz_subs_paynl_sepa_send_notice', null, $auth['email'], $message, $order, $sub);
        if (null === $sent) {
            $sent = wp_mail($auth['email'], __('Upcoming subscription SEPA collection', 'woo-subzero'), $message);
        }
        if ($sent) {
            $order->update_meta_data('_wsz_paynl_sepa_notice_sent_at', time());
            $order->save();
        }
        return (bool) $sent;
    }
}
