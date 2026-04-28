# Woo Subs-Zero

<p align="center">
  <img src="./wsz-subs-zerο.png" alt="Woo Subs-Zero hero image" width="100%" />
</p>

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
