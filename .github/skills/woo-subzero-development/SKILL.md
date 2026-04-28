---
name: Woo Subs-Zero Development
description: This skill should be used when the user asks to "work on Woo Subs-Zero", "add a subscription feature", "fix a renewal bug", "debug checkout subscription creation", "update retry/switching/synchronization logic", "harden webhook/security logic", "prepare plugin for public release", "license the plugin properly", "update project agent or skill guidance", "add WooCommerce subscription tests", or "document install and usage for Woo Subs-Zero".
---

# Woo Subs-Zero Development

## Purpose
Provide a focused workflow for implementing and maintaining the Woo Subs-Zero WordPress plugin with safe, test-backed changes.

## Use Cases
Use this skill for:
- Feature implementation in subscription lifecycle, renewals, retries, switching, synchronization, checkout, webhooks, and customer actions.
- Bug fixing in WSZ-specific product types and subscription behavior.
- Security hardening for webhooks, payment-source validation, and customer action endpoints.
- Release-readiness updates including licensing, changelog, and security policy alignment.
- Maintenance of project-scoped AI workflow assets in `.github/agents/` and `.github/skills/`.
- Test additions and regression prevention.
- Documentation updates for install, operations, and QA flows.

## Project References
Read the project map for file-level routing and common commands:
- references/project-map.md

For release hygiene and public-repo safety checks:
- references/release-readiness.md

## Standard Workflow
1. Identify the affected behavior and locate the owning module before editing.
2. Prefer minimal, scoped patches that preserve existing public behavior.
3. Keep WooCommerce compatibility and fallback paths intact.
4. Keep security boundaries explicit for webhooks, payment dispatch, and endpoint permissions.
5. Add or update tests for any behavior change or bug fix.
6. Validate with syntax checks and PHPUnit.
7. Update docs and release metadata when user-facing behavior, security posture, or licensing changes.

## Validation Rules
Run both checks before finishing:
- php -l for changed PHP files.
- ./vendor/bin/phpunit for full regression coverage.

If a change is large, run targeted tests first, then full suite.

For release or licensing tasks, also validate metadata consistency in:
- wsz-woo-subscriptions.php
- composer.json
- LICENSE
- README.md
- CHANGELOG.md
- SECURITY.md

## High-Risk Areas
Apply extra caution in:
- includes/class-wsz-subscription-manager.php
- includes/class-wsz-renewal-engine.php
- includes/class-wsz-checkout-handler.php
- includes/class-wsz-product-type-manager.php
- includes/class-wsz-webhook-handler.php
- src/Payment/class-wsz-payment-handler.php

These files affect core billing timelines, trust boundaries, and admin UX.

## Change Quality Requirements
- Keep idempotency and scheduling safety intact.
- Never expand visibility or behavior for non-subscription products unless explicitly requested.
- Preserve deterministic status transitions.
- Prefer explicit metadata handling and avoid hidden side effects.
- Keep webhook authenticity verification fail-safe.
- Keep licensing metadata synchronized across plugin header, package metadata, and repository documents.

## Done Criteria
A task is complete when:
- Behavior matches the request.
- Tests cover the change or regression.
- Syntax checks and PHPUnit pass.
- Relevant docs are updated.
- Release metadata is consistent when licensing or public-readiness changes are included.
