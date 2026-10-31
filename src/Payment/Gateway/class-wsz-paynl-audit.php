<?php

defined('ABSPATH') || exit;

/** Read-only local context assessment; it does not verify the provider's current state. */
final class WSZ_PayNL_Audit
{
    private WSZ_Subscription_Manager $manager;

    public function __construct(WSZ_Subscription_Manager $manager)
    {
        $this->manager = $manager;
    }

    public function inspect(WC_Order $subscription): ?array
    {
        if (WSZ_Subscription_Manager::ORDER_TYPE !== $subscription->get_type()) {
            return null;
        }
        $gateway = sanitize_key((string) $subscription->get_payment_method());
        if (!WSZ_PayNL_Gateway_Integration::is_paynl_gateway_id($gateway)) {
            return null;
        }

        $manual = $this->manager->is_manual_renewal($subscription);
        if (WSZ_PayNL_SEPA_Context::INITIAL_GATEWAY === $gateway
            && '' !== (string) $subscription->get_meta(WSZ_PayNL_SEPA_Context::AUTH_META, true)) {
            if (!WSZ_PayNL_SEPA_Context::valid_for_subscription($subscription)) {
                $context = 'sepa_authorization_unverified';
            } else {
                $context = WSZ_PayNL_SEPA_Context::provider_available()
                    ? 'sepa_authorization_valid_locally' : 'sepa_provider_unavailable';
            }
        } elseif (WSZ_PayNL_Gateway_Integration::is_supported_gateway_id($gateway)
            && !WSZ_PayNL_Gateway_Integration::are_card_tokens_enabled()) {
            $context = WSZ_PayNL_Gateway_Integration::is_manual_only_gateway_id($gateway)
                ? 'card_adapter_disabled' : 'custom_adapter_review';
        } elseif (WSZ_PayNL_Gateway_Integration::is_supported_gateway_id($gateway)) {
            $context = WSZ_PayNL_Gateway_Integration::has_valid_recurring_payment_token(
                $subscription,
                $this->manager->get_payment_token_id($subscription)
            ) ? 'stored_card_token_valid' : 'stored_card_token_unverified';
        } elseif (WSZ_PayNL_Gateway_Integration::is_manual_only_gateway_id($gateway)) {
            $context = 'manual_only';
        } else {
            $context = 'custom_adapter_review';
        }

        return array(
            'subscription_id' => (int) $subscription->get_id(),
            'status' => sanitize_key((string) $subscription->get_status()),
            'gateway' => $gateway,
            'manual_renewal' => $manual,
            'local_context' => $context,
            'automatic_needs_review' => !$manual && !in_array($context, array('stored_card_token_valid', 'sepa_authorization_valid_locally'), true),
        );
    }
}
