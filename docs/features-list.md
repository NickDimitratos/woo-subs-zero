# Woo Subs-Zero Feature List

This file lists the current feature set implemented in the plugin.

## Feature Toggles (WooCommerce > WSZ Subscriptions > Features)

- `enable_manual_renewals`: Allow manual renewal payments when auto-charge is unavailable.
- 🟢 `enable_retries`: Retry failed automatic renewals through scheduled retry logic.
- 🟢 `enable_retry_emails_customer`: Send retry/failure notifications to customers.
- 🟢 `enable_retry_emails_admin`: Send retry/failure notifications to store admin.
- 🟢 `enable_start_date`: Allow customer-selected delayed subscription start date at checkout.
- 🟢 `enable_switching`: Allow plan switching between eligible subscriptions.
- 🟢 `enable_proration`: Enable proration logic on plan switching.
- 🟢 `prorate_recurring`: Prorate recurring amount during switch.
- 🟢 `prorate_signup_fee`: Prorate sign-up fee during switch.
- 🟢 `proration_subscription_length`: Prorate fixed-term length effects during switch.
- 🟢 `enable_synchronization`: Align renewals to synchronized day-of-month billing.
- 🟢 `enable_early_renewal`: Allow early renewal before next scheduled payment.
- 🟢 `enable_resubscribe`: Allow resubscribe from cancelled/expired subscriptions.
- 🟢 `allow_synced_early_renewal`: Allow early renewal for synchronized subscriptions.
- 🟢 `enable_sync_first_renewal_proration`: Prorate first synchronized renewal amount.
- 🟢 `enable_role_transitions`: Update user roles based on subscription status.

## Testing Toggles (WooCommerce > WSZ Subscriptions > Testing)

- 🟢 `enable_test_mode`: Accelerate billing cycles for QA (minute-based simulation).
- 🟢 `enable_test_deferred_start`: Accelerate delayed-start activation timing in test mode.
- 🟢 `enable_test_cycle_notifications`: Add subscription notes/hooks on each test cycle.

## Core Subscription Capabilities

- 🟢 Custom subscription order type and statuses on `shop_subscription`.
- 🟢 Subscription creation from checkout/order lifecycle.
- 🟢 Deterministic renewal scheduling with Action Scheduler.
- 🟢 Renewal processing pipeline and failed-payment retry queue.
- 🟢 Deferred activation workflow for future start dates.
- 🟢 Customer account actions (cancel, suspend/reactivate, pay renewal, change payment method, switch, early renew, resubscribe).
- 🟢 Finite-term subscription handling (length, end-date, expiration behavior).
- 🟢 Synchronization hooks and first-cycle proration handling.
- 🟢 Webhook handling endpoint (`woocommerce_api_wsz_gateway_webhook`).
- 🟢 Admin subscription diagnostics, renewal insights, and manual renewal toggle tools.
- 🟢 WSZ test payment gateway support for QA flows.
