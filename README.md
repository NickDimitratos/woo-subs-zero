# Woo Subs-Zero - Free Subscriptions for WooCommerce

![Woo Subs-Zero](docs/assets/readme-hero.png)

> [!IMPORTANT]
> **Open Source Beta**
> This plugin is open source and currently in beta.
> Use it in staging first, validate renewal flows with your gateway, and review logs/operations before production rollout.

## Table of Contents

- [Why Woo Subs-Zero](#why-woo-subs-zero)
- [Feature Highlights](docs/sections/feature-highlights.md)
- [Beta Status](#beta-status)
- [Requirements](docs/sections/requirements.md)
- [Installation](docs/sections/installation.md)
- [Quick Start](#quick-start)
- [Usage Guide](docs/sections/usage-guide.md)
- [Operations](#operations)
- [Testing and Quality Checks](#testing-and-quality-checks)
- [Technical Identifiers](#technical-identifiers)
- [Documentation Index](#documentation-index)
- [Agents and Skills](#agents-and-skills)
- [Open Source](docs/sections/open-source.md)
- [Security](#security)
- [License](#license)

## Why Woo Subs-Zero

- It's free.
- Works without requiring the WooCommerce Subscriptions extension.
- Uses deterministic scheduling and idempotency safeguards for renewal correctness.
- Keeps gateway credentials in WooCommerce Payments settings (no duplicate gateway credential screens).

## Feature Highlights

Includes lifecycle/state management, renewal scheduling, retries, switching, synchronization, customer actions, QA tooling, and webhook hardening.

Read more: [Feature Highlights](docs/sections/feature-highlights.md)

## Beta Status

Woo Subs-Zero is currently in beta.

- Features are functional but still evolving.
- Edge-case behavior can change between beta releases.
- Production stores should validate full checkout, renewal, retry, and webhook flows before launch.

## Requirements

Requires modern WordPress, WooCommerce, and PHP versions, with tokenized gateways recommended for full parity behavior.

Read more: [Requirements](docs/sections/requirements.md)

## Installation

Supports source installation and ZIP upload workflows, with optional recurring charge provider integration.

Read more: [Installation](docs/sections/installation.md)

## Quick Start

1. Configure payment gateways in WooCommerce > Settings > Payments.
2. Configure subscription behavior in WooCommerce > WSZ Subscriptions.
3. Create a WSZ subscription product (simple or variable).
4. Place a checkout order with a subscription item.
5. Verify activation and queued renewals in subscription admin screens.

## Payment Gateway Compatibility

Woo Subs-Zero can store WooCommerce payment context, but automatic renewals are safest only with gateways that provide a reusable token and a tested server-side recurring charge path.

| Gateway | Compatibility | Notes |
| --- | --- | --- |
| WSZ Test Card | Recommended for QA/staging | Built into this plugin. Use it to validate subscription lifecycle, renewal scheduling, retries, and transaction logging without charging real cards. |
| PAY.nl Credit/Debitcards (`pay_gateway_creditcardsgrouped`) | Recommended for production after PAY.nl setup | Requires PAY.nl Card-not-present credit cards, Tokenization, Create token on transaction, recurring/MIT permission for Card Payment authenticate token requests, API credentials (`AT-code:API token`), and the correct Sales Location ID. New subscriptions should be created after the correct PAY.nl Sales Location is configured so the stored `recurring_id` belongs to that Sales Location. |
| Manual/offline gateways | Supported as manual renewals | Safe for order/subscription tracking, but renewals require manual payment collection. They should not be treated as automatic card renewals. |
| Stripe (`stripe`) | Supported after Stripe setup | Enable Stripe tokens in WSZ Payment Gateways. Renewals use the saved Stripe customer and payment method to create confirmed off-session PaymentIntents with idempotency. |
| Mollie Credit Card (`mollie_wc_gateway_creditcard`) | Supported after Mollie recurring setup | Enable Mollie tokens in WSZ Payment Gateways. Renewals use the saved Mollie customer and mandate context to create customer payments with `sequenceType=recurring`. |
| PayPal Payments | Not certified for automatic WSZ renewals yet | PayPal automatic renewals require either PayPal Subscriptions or Vaulting/Reference Transactions. Those are gateway-specific flows. WSZ does not currently include a PayPal vault/reference-transaction adapter. |
| Adyen | Not certified for automatic WSZ renewals yet | Adyen supports tokenized subscription payments, but requires stored payment method IDs, shopper references, recurring processing model, credential roles, and webhooks. WSZ does not currently include an Adyen-specific adapter. |
| Other tokenized gateways | Requires custom validation | Gateways can be registered through the `wsz_subs_tokenized_gateway_ids` filter, but each gateway needs a tested recurring-charge implementation and webhook/transaction-ID behavior before production use. |

For production automatic card renewals, validate a full cycle in staging: initial checkout, token exchange, renewal charge, exchange webhook, WooCommerce renewal order transaction ID, and the subscription Card Transactions row.

## Usage Guide

Covers product setup, finite-term behavior, renewal/retry flow, webhook verification contract, and built-in QA tooling.

Read more: [Usage Guide](docs/sections/usage-guide.md)

## Operations

See the operations playbook for queue and incident workflows:

- [docs/operations-playbook.md](docs/operations-playbook.md)

Useful queue command:

- `wp action-scheduler run --group=wsz-subscriptions --batch-size=200`

## Testing and Quality Checks

Run PHPUnit:

- `./vendor/bin/phpunit -c phpunit.xml.dist`

Run syntax checks:

- `rg --files -g '*.php' | xargs -I{} php -l "{}"`

## Technical Identifiers

- Plugin bootstrap file: `wsz-woo-subscriptions.php`
- Text domain: `woo-subzero`
- Action Scheduler group: `wsz-subscriptions`

## Documentation Index

- [docs/README.md](docs/README.md): central documentation table of contents.
- [docs/operations-playbook.md](docs/operations-playbook.md): queue operations, backlog recovery, and incident runbook.
- [docs/sections/README.md](docs/sections/README.md): section-by-section documentation index.
- [CHANGELOG.md](CHANGELOG.md): release history.
- [SECURITY.md](SECURITY.md): security reporting and policy.

## Agents and Skills

This repository includes project-scoped agent and skill assets for implementation workflows, regression safety, and release-readiness checks.

Read more: [Agents and Skills](docs/sections/agents-and-skills.md)

## Open Source

Woo Subs-Zero is open source and welcomes contributions and issue reports.

Read more: [Open Source](docs/sections/open-source.md)

## Security

- Security policy and reporting process: [SECURITY.md](SECURITY.md)

## License

This repository is licensed under GPL-2.0-or-later.

See [LICENSE](LICENSE).
