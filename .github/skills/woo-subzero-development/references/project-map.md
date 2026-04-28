# Project Map

## Release Baseline
- Current plugin version: 0.1.2
- Project license baseline: GPL-2.0-or-later

## Core Runtime
- wsz-woo-subscriptions.php: Plugin bootstrap, activation defaults, service wiring.
- includes/class-woo-subzero.php: Manager orchestration and lifecycle init.
- includes/class-wsz-subscription-manager.php: Order type, states, date math, relationship/meta helpers.
- includes/class-wsz-renewal-engine.php: Renewal scheduling and payment cycle progression.
- includes/class-wsz-retry-manager.php: Retry policy and retry queue behavior.
- includes/class-wsz-checkout-handler.php: Subscription creation from checkout orders.
- includes/class-wsz-product-type-manager.php: WSZ product types and admin product field UX.

## Supporting Modules
- includes/class-wsz-switching-manager.php: Switching and proration logic.
- includes/class-wsz-synchronization-manager.php: Sync-date alignment and synchronized behavior.
- includes/class-wsz-early-renewal-manager.php: Early renewal and resubscribe flows.
- includes/class-wsz-customer-actions-manager.php: Customer action endpoints and permissions.
- includes/class-wsz-webhook-handler.php: Webhook ingestion and idempotency.
- src/Payment/class-wsz-payment-handler.php: Gateway dispatch and payment context.

## Security-Critical Paths
- includes/class-wsz-webhook-handler.php: Webhook authenticity checks, idempotency keys, payload parsing safeguards.
- src/Payment/class-wsz-payment-handler.php: Gateway source-of-truth validation against WooCommerce gateways.
- includes/class-wsz-customer-actions-manager.php: Customer endpoint authorization and nonce enforcement.

## Admin
- includes/admin/class-wsz-admin-settings.php: Plugin settings UI and sanitization.
- includes/admin/class-wsz-admin-subscriptions.php: Subscription list actions/columns.

## Release and Governance Docs
- README.md: Install, usage, and security integration notes.
- CHANGELOG.md: Release history and hardening entries.
- SECURITY.md: Vulnerability disclosure policy.
- LICENSE: Repository licensing terms.
- composer.json: SPDX package license metadata.

## Tests
- tests/lifecycle: State transitions and date math.
- tests/renewals: Renewal scheduling and edge handling.
- tests/products: WSZ product-type isolation and admin behavior.
- tests/payments, tests/retries, tests/switching, tests/synchronization, tests/webhooks.

## Common Commands
- Syntax check (single file): php -l path/to/file.php
- Full tests: ./vendor/bin/phpunit
- Targeted tests: ./vendor/bin/phpunit tests/path/SpecificTest.php
- Release metadata consistency check: rg -n "License:|License URI:|\"license\":|GPL-2.0-or-later|Version:" wsz-woo-subscriptions.php composer.json README.md CHANGELOG.md

## QA Notes
- Accelerated test mode is configured in WooCommerce > WSZ Subscriptions > Testing.
- Product tab visibility regressions are usually in class-wsz-product-type-manager.php.
- Webhook callbacks should use `wsz_subs_verify_paynl_exchange`; fallback flow requires matching `order_key`.
