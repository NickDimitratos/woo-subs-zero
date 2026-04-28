# WooCommerce Subscriptions Plugin – AI-Ready PRD

## Overview
Deterministic subscription system for WooCommerce with **gateway-agnostic architecture**. Pay.nl is the initial payment gateway, but the system supports any WooCommerce payment gateway that implements tokenization and subscription hooks.

## Core Requirements
- Recurring billing engine
- Subscription lifecycle management (deterministic state machine)
- **WooCommerce Payment Gateway abstraction** (gateway-agnostic)
- Payment methods/options managed in WooCommerce Payments settings (single source of truth)
- Pay.nl integration (initial + renewals) via WC gateway extension
- Action Scheduler for async jobs (per-subscription scheduling)
- Idempotent and concurrency-safe system
- Must support 10k+ subscriptions

## Architecture: Gateway-Agnostic Design

The subscription core is **gateway-agnostic**. It uses WooCommerce's built-in payment gateway system and Payment Token API. Each gateway implements subscription support independently.

### Flow
```
Subscription Core (gateway-agnostic)
    - Stores gateway_id + payment_token_id (via WC Payment Token API)
    - On renewal:
            1) fire woocommerce_scheduled_subscription_payment
            2) fire woocommerce_scheduled_subscription_payment_{gateway_id}
    - Gateway implementations:
            - Pay.nl gateway extension (our plugin)
            - Stripe (official plugin or custom)
            - Mollie (official plugin or custom)
            - Any WC gateway with tokenization support
```

### Gateway Contract
Each gateway that supports subscriptions must:
1. Declare subscription support in `$this->supports` (minimum: `'products'`, `'subscriptions'`, `'tokenization'`)
2. Add management support flags when available:
    - `'subscription_cancellation'`
    - `'subscription_suspension'`
    - `'subscription_reactivation'`
    - `'subscription_amount_changes'`
    - `'subscription_date_changes'`
    - `'subscription_payment_method_change'`
    - `'subscription_payment_method_change_customer'`
    - `'subscription_payment_method_change_admin'`
    - `'multiple_subscriptions'`
    - `'gateway_scheduled_payments'` (only if the gateway controls billing schedule)
3. Save a `WC_Payment_Token` after the first successful payment (token contains gateway recurring reference)
4. Hook into `woocommerce_scheduled_subscription_payment_{gateway_id}` to process renewals
5. Use Woo order status APIs for outcomes (`payment_complete()` on success, failed/on-hold flow on failure)

### Payment Method Ownership (WooCommerce)
- All payment method configuration is handled by WooCommerce (`WooCommerce > Settings > Payments`).
- This plugin must not create duplicate gateway credential fields or payment method toggles.
- Checkout payment options come from enabled WooCommerce gateways.
- Renewal processing must use the subscription's stored WooCommerce `payment_method` and `WC_Payment_Token`.

## Data Model

### Storage Model (Woo-native)
- Subscription records are stored as WooCommerce custom order type `shop_subscription`.
- Legacy storage: `shop_subscription` in WordPress posts tables.
- HPOS storage: `shop_subscription` rows in `wc_orders`.
- Runtime object is `WC_Subscription` (extends `WC_Order`).
- No separate custom subscription table is used for core subscription state.

### Core Subscription Properties
- `subscription_id` (Woo order ID for `shop_subscription`)
- `customer_id`
- `status` (enum: `pending` | `active` | `on-hold` | `pending-cancel` | `cancelled` | `expired`)
- `parent_order_id`
- `related_order_ids` (renewal, switch, resubscribe)
- schedule dates (`trial_end`, `next_payment`, `end`)
- `payment_method` (Woo gateway ID)
- `payment_token_id` (WC Payment Token reference, when automatic renewal is used)
- `requires_manual_renewal` (boolean)
- `order_key`
- `created_at`, `updated_at`

### Data and Performance Notes
- Use WooCommerce order/subscription data stores and query APIs for reads/writes.
- Use Action Scheduler for due payment execution and retry scheduling.
- For calculations, normalize amounts to integer cents inside plugin logic; convert at Woo order/subscription API boundaries.
- Do not directly mutate schedule meta keys for next payment; use `WC_Subscription::update_dates()` to keep scheduling deterministic.

### Status State Machine

Valid transitions:
```
pending → active       (initial payment succeeded)
pending → cancelled    (initial payment failed/cancelled)
active  → on-hold      (renewal failed, suspension, or waiting for manual renewal payment)
active  → pending-cancel (user/admin cancelled with prepaid term remaining)
pending-cancel → cancelled (end of prepaid term reached)
active  → expired      (end_date reached)
on-hold → active       (payment recovery or manual reactivation)
expired → [terminal]
cancelled → [terminal]
```

Invalid transitions should be rejected with an exception/log. Hooks fire on every transition:
- `woocommerce_subscription_pre_update_status` (before)
- `woocommerce_subscription_status_updated` (after, static)
- `woocommerce_subscription_status_{new_status}` (after)
- `woocommerce_subscription_status_{old}_to_{new}` (specific transition)

## Checkout Flow

1. Customer adds subscription product to cart
2. WooCommerce creates order (status: pending)
3. Plugin creates `shop_subscription` (`WC_Subscription`, status: pending) linked to the parent order
4. WooCommerce calls gateway's `process_payment()` → redirects to gateway (e.g. Pay.nl)
5. Customer completes payment
6. Gateway calls exchange webhook → plugin verifies payment via gateway API
7. Plugin updates order status → subscription status transitions to `active`
8. Gateway saves `WC_Payment_Token` for future automatic renewals

## Renewal Engine

### Scheduling Strategy: Per-Subscription Actions
Each subscription gets its own Action Scheduler job. No batch scanning needed.

```php
// When subscription becomes active, schedule its first renewal
as_schedule_single_action(
    $next_payment_timestamp,
    'wsz_subs_process_renewal',
    ['subscription_id' => $sub_id],
    'wsz-subscriptions',
    true  // unique — prevents duplicate jobs
);

// After successful renewal, schedule the next one
as_schedule_single_action(
    $new_next_payment_timestamp,
    'wsz_subs_process_renewal',
    ['subscription_id' => $sub_id],
    'wsz-subscriptions',
    true
);
```

### Renewal Processing
1. Action Scheduler fires `wsz_subs_process_renewal` hook
2. Plugin loads `WC_Subscription` and creates a WooCommerce renewal order
3. Plugin fires `woocommerce_scheduled_subscription_payment` with subscription ID/object for generic pre-processing
4. Plugin fires `woocommerce_scheduled_subscription_payment_{gateway_id}` with `$amount` and `$renewal_order`
5. Gateway-specific handler charges using the saved token/reference
6. On success: `$renewal_order->payment_complete()`, update subscription dates, schedule next renewal
7. On failure: `$renewal_order->update_status('failed')`, subscription moves to `on-hold`, apply retry rules (if enabled) or manual recovery flow

### Performance Notes
- Action Scheduler 3.0+ uses custom DB tables (not wp_posts) — scales to 10k+ subscriptions
- `$unique` flag on `as_schedule_single_action` ensures no duplicate renewal jobs
- WP-CLI for high-throughput: `wp action-scheduler run --group=wsz-subscriptions --batch-size=200`
- Batch size tunable via `action_scheduler_queue_runner_batch_size` filter
- Concurrent batches via `action_scheduler_queue_runner_concurrent_batches` filter

## Retry Logic
- Rule-based retry engine (not fixed intervals)
- Default profile mirrors Woo pattern: 5 retry rules over ~7 days
    - 12h, 12h, 24h, 48h, 72h
- Retry records tracked with statuses: `pending`, `processing`, `complete`, `failed`, `cancelled`
- Rule application sets both renewal order status and subscription status (normally `pending` + `on-hold` between attempts)
- Before retrying, validate:
    - renewal order still needs payment
    - retry status is still `pending`
    - order/subscription statuses still match the applied rule
- If rules are exhausted, renewal order is marked failed and customer follows manual recovery path
- Retry emails (customer/store owner) are controlled by retry rules
- Note: automatic retry may be disabled for some payment methods (for example, SEPA flows)

```php
$retry_rules = [
        ['interval' => 12 * HOUR_IN_SECONDS],
        ['interval' => 12 * HOUR_IN_SECONDS],
        ['interval' => 24 * HOUR_IN_SECONDS],
        ['interval' => 48 * HOUR_IN_SECONDS],
        ['interval' => 72 * HOUR_IN_SECONDS],
];

// Apply next rule only if order/subscription are still retry-eligible.
```

## Pay.nl Gateway Integration (Initial Gateway)

### SDK: `paynl/php-sdk` (V3 Order API) or `paynl/sdk` (v13 REST API)
- PHP 8.1–8.4 for V3 SDK, PHP 7.1+ for v13 REST SDK
- Configuration in WooCommerce Payments (`WooCommerce > Settings > Payments > Pay.nl`): tokenCode (AT-####-####), apiToken, serviceId (SL-####-####)

### Initial Payment
```php
// Transaction::start() — returns transactionId + redirectUrl
$result = Transaction::start([
    'amount'      => round($amount * 100),  // cents
    'returnUrl'   => $return_url,
    'exchangeUrl' => $exchange_url,
    'currency'    => 'EUR',
]);
$transaction_id = $result->getTransactionId();
$redirect_url   = $result->getRedirectUrl();
```

### Exchange Webhook (Pay.nl → our server)
- Pay.nl calls `exchangeUrl` with `order_id` parameter (GET, POST, JSON, or XML)
- Use `Transaction::getForExchange()` to parse all formats
- **Security:** No HMAC signature on webhooks. Instead, call `Transaction::status()` server-side to verify payment state.
- Always respond with `TRUE|` followed by optional status message

### Tokenization (for renewals)
Two recurring mechanisms:
1. **Credit Card Tokenization** — `Transaction::addRecurring()` then `Transaction::byRecurringId()`
   - Creates recurring profile from initial CC transaction
   - Renewals charged via `recurringId`
   - Only for Visa/MasterCard (requires Pay.nl activation)
2. **SEPA Direct Debit** — `DirectDebit::add()` with `intervalValue`/`intervalPeriod`
   - Mandate-based, works with iDEAL/Bank transfers
   - Stores `mandateId` for recurring charges

### Renewal Payment
```php
add_action('woocommerce_scheduled_subscription_payment_paynl', function($amount, $renewal_order) {
    $token = // retrieve WC_Payment_Token for this subscription
    $recurring_id = $token->get_token();

    $result = Transaction::byRecurringId([
        'recurringId' => $recurring_id,
        'amount'      => round($amount * 100),
        'currency'    => 'EUR',
    ]);

    if ($result->isPaid()) {
        $renewal_order->payment_complete();
    } else {
        $renewal_order->update_status('failed');
    }
}, 10, 2);
```

## Adding Future Gateways

To support a new gateway (e.g. Stripe, Mollie):
1. Create a gateway class extending `WC_Payment_Gateway` (or extend existing plugin's gateway)
2. Add subscription supports flags in `$this->supports` (`'subscriptions'`, `'tokenization'`, and management flags as supported)
3. Implement `process_payment()` for initial checkout
4. Save `WC_Payment_Token` with gateway's recurring ID after successful payment
5. Hook `woocommerce_scheduled_subscription_payment_{gateway_id}` for renewal processing
6. Optionally hook failing-payment-method update actions for recovery flows
7. Configure gateway options in WooCommerce Payments (not in plugin-specific duplicate settings)
8. No changes needed to the subscription core

## Security
- Webhook verification: server-side payment status check via gateway API (not HMAC signatures)
- No sensitive payment data stored (tokens stored via WC Payment Token API)
- Validate token ownership: `$token->get_user_id() === get_current_user_id()`
- Order key verification on webhook endpoints

## Plugin Structure
```
woo-subzero/
├── woo-subzero.php              // Main plugin file
├── wsz-subscriptions-prd.md               // This document
├── includes/
│   ├── class-wsz-subscription-manager.php // Lifecycle orchestration around WC_Subscription
│   ├── class-wsz-renewal-engine.php       // Action Scheduler integration
│   ├── class-wsz-retry-manager.php         // Rule-based failed payment retry engine
│   ├── class-wsz-checkout-handler.php     // Checkout flow integration
│   ├── class-wsz-webhook-handler.php      // Exchange webhook endpoint
│   └── admin/
│       ├── class-wsz-admin-subscriptions.php  // Admin list/detail views
│       └── class-wsz-admin-settings.php       // Subscription behavior settings (no gateway credential duplication)
├── src/Payment/
│   ├── class-wsz-payment-handler.php      // Gateway-agnostic renewal dispatcher
│   └── Gateway/
│       └── class-wsz-paynl-gateway.php    // Pay.nl subscription gateway extension
├── tests/
│   ├── lifecycle/
│   ├── renewals/
│   └── retries/
└── assets/
    ├── css/
    └── js/
```

## Final Note
System must behave as a deterministic state machine. Every subscription transition is explicit, logged, and hookable. The gateway layer is pluggable — adding a new payment provider requires only a gateway extension, not core changes.

## PRD v2 Patch Plan (Target: 8.5/10 Parity with WooCommerce Subscriptions)

### Target Outcome
- Align architecture and behavior with how WooCommerce Subscriptions actually works while keeping your gateway-agnostic implementation style.
- Keep deterministic state-machine guarantees, idempotency, and 10k+ subscription scalability.
- Reach practical parity on core merchant workflows, gateway integration contract, and failure handling.

### Phase 1: Core Model and Lifecycle Parity

#### 1. Data storage alignment
- Replace custom subscription table requirement with Woo custom order type model.
- Store subscriptions as `shop_subscription` and instantiate via `WC_Subscription`.
- Require compatibility for both legacy storage and HPOS (`wc_orders` with `shop_subscription` type).
- Keep all money handling in integer cents in your internal layer, but map to Woo order/subscription totals safely.

#### 2. Relationship model alignment
- Explicitly model related order types: parent, renewal, switch, and resubscribe orders.
- Track relationships via Woo order/subscription links (not custom FK-only design).

#### 3. Status model alignment
- Replace status enum with Woo-compatible statuses:
    - `pending`
    - `active`
    - `on-hold`
    - `pending-cancel`
    - `cancelled`
    - `expired`
- Remove `failed` as a terminal subscription status in core lifecycle.
- Model failed renewal as order/payment failure + retry flow, with subscription usually `on-hold` during recovery.

#### 4. Cancellation semantics
- Add prepaid-term behavior: customer/admin cancellation can move to `pending-cancel` first, then scheduled transition to `cancelled` at end-of-prepaid-term.

#### 5. Hook parity baseline
- Add static status hook requirement: `woocommerce_subscription_status_updated`.
- Keep dynamic status hooks and include hyphenated variants (for example, `pending-cancel`).

### Phase 2: Payments, Gateway Contract, and Retry Parity

#### 1. Gateway support contract expansion
- Extend gateway supports requirements beyond `subscriptions` and `tokenization` to include parity flags as applicable:
    - `subscription_cancellation`
    - `subscription_suspension`
    - `subscription_reactivation`
    - `subscription_amount_changes`
    - `subscription_date_changes`
    - `subscription_payment_method_change`
    - `subscription_payment_method_change_customer`
    - `subscription_payment_method_change_admin`
    - `multiple_subscriptions`
    - `gateway_scheduled_payments` (when gateway controls billing schedule)
- Keep gateway options (enable/disable, credentials, title, checkout behavior) in WooCommerce Payments as the only configuration source.

#### 2. Renewal flow parity
- Keep gateway-agnostic renewal dispatch.
- Require both hooks in lifecycle:
    - `woocommerce_scheduled_subscription_payment`
    - `woocommerce_scheduled_subscription_payment_{gateway_id}`
- Preserve renewal order creation before charge and order-status-driven subscription updates.

#### 3. Manual and automatic renewal support
- Add manual renewal mode as first-class behavior.
- Define behavior when gateway/plugin is deactivated: existing automatic subscriptions can fall back to manual renewal path.
- Add per-subscription manual/automatic toggle support in model and admin actions.

#### 4. Retry system parity
- Replace fixed retry array (1, 3, 7 days) with rule-based retry engine.
- Default retry rules should mirror Woo pattern (5 rules over ~7 days, configurable).
- Track retry entities/statuses (`pending`, `processing`, `complete`, `failed`, `cancelled`) and audit trail.
- Include retry-triggered order/subscription status checks before each attempt.
- Add optional customer/store-owner dunning emails driven by retry rules.

#### 5. Failed payment method recovery
- Add explicit hook handling for failing-payment-method updates (gateway-specific variants).
- Ensure token/meta updates occur on the original subscription context so future automatic renewals recover correctly.

### Phase 3: Merchant Features Required for Clone-Level Parity

#### 1. Switching and proration
- Add switching capability scope:
    - variation-to-variation switches
    - grouped subscription switches
    - quantity change as a switch operation
- Add configurable proration rules for:
    - recurring amount
    - signup fee
    - subscription length

#### 2. Synchronized renewals
- Add synchronized renewal capability and first-renewal proration options.
- Document next payment date behavior for synchronized vs non-synchronized subscriptions.

#### 3. Early renewal and resubscribe
- Add early renewal behavior (manual-style checkout or modal flow).
- Add resubscribe flow for cancelled/expired subscriptions.

#### 4. Customer account actions and roles
- Define customer-visible actions and constraints:
    - cancel
    - suspend/reactivate (if allowed)
    - change payment method
    - pay failed renewal
    - resubscribe
- Add role transition behavior tied to active/inactive subscription states.

#### 5. Settings parity surface
- Add settings scope to cover at least:
    - manual renewals and automatic payment controls
    - retry system enablement
    - switching controls
    - synchronization controls
    - customer suspension limits
- Do not duplicate payment gateway settings already owned by WooCommerce Payments.

### Phase 4: Operational Readiness and Acceptance Gates

#### 1. Compatibility
- HPOS compatibility required.
- Action Scheduler grouping, uniqueness, and concurrency controls required.

#### 2. Determinism and idempotency
- Idempotency keys for webhook and scheduled renewal handling.
- Concurrency protection for duplicate renewal triggers.

#### 3. Test matrix
- Required test suites:
    - lifecycle transition tests (including `pending-cancel`)
    - renewal success/failure/retry sequences
    - manual renewal checkout
    - payment-method-change-after-failure recovery
    - switch and proration calculations
    - synchronized renewal date calculations
    - webhook replay/idempotency tests

#### 4. Performance gate
- Validate 10k+ active subscriptions with queue-runner tuning and failure-retry load.
- Include WP-CLI operational playbook for backlog recovery.

### Implementation Checklist (Post-PRD Reconciliation)
- Implement Woo-native `shop_subscription` storage usage and HPOS compatibility.
- Implement Woo-compatible status transitions (`on-hold`, `pending-cancel`) and hook emissions.
- Implement expanded gateway supports contract and dual renewal-hook flow.
- Implement rule-based retry engine with default 5-rule profile and retry-state persistence.
- Implement manual renewal mode, switching/proration, synchronized renewals, early renewal, and resubscribe.
- Implement acceptance test matrix and operational SLO instrumentation.
