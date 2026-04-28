# Release Readiness Reference

## Purpose
Use this checklist when preparing Woo Subs-Zero for public release or distribution.

## Security Checklist
- Verify webhook authenticity paths in includes/class-wsz-webhook-handler.php.
- Verify payment gateway validation remains WooCommerce-source-of-truth in src/Payment/class-wsz-payment-handler.php.
- Verify customer/admin endpoint handlers enforce nonce + ownership/capability checks.
- Verify idempotency behavior for renewals, retries, and webhook processing.
- Confirm no high-risk runtime helpers are introduced (eval, shell exec, unsafe deserialize, raw SQL without prepare).

## Release Metadata Checklist
- Confirm plugin header metadata in wsz-woo-subscriptions.php:
  - Version
  - License
  - License URI
- Confirm package metadata in composer.json:
  - license
- Confirm repository docs are synchronized:
  - LICENSE
  - README.md license section
  - CHANGELOG.md release entry
  - SECURITY.md policy

## Validation Checklist
- Run php -l on changed PHP files.
- Run full PHPUnit suite:
  - ./vendor/bin/phpunit -c phpunit.xml.dist
- Run targeted metadata consistency search:
  - rg -n "License:|License URI:|\"license\":|GPL-2.0-or-later|Version:" wsz-woo-subscriptions.php composer.json README.md CHANGELOG.md

## Documentation Checklist
- Update README usage notes when behavior changes.
- Update docs/operations-playbook.md when operational or security behavior changes.
- Record notable runtime/security changes in CHANGELOG.md.

## AI Workflow Asset Checklist
- Refresh .github/agents/wsz-subs-implementation.agent.md when responsibilities or validation flow changes.
- Refresh .github/skills/woo-subzero-development/SKILL.md when new triggers or workflows are introduced.
- Refresh references/project-map.md when module ownership changes.
