# Usage Guide

## Create Subscription-Capable Products

You can use:

- WSZ Subscription product type
- WSZ Variable Subscription product type
- Supported product/order-item subscription meta fields

Fixed-term example (4 monthly payments):

- Billing Interval: `1`
- Billing Period: `month`
- Subscription Length: `4`

Expected result: the subscription expires at term boundary with no extra renewal cycle.

## Customer-Selected Start Date

- Subscription products render a required `Start subscription at` date field on single product pages.
- The selected date is stored on checkout line items and copied to subscription meta.
- If the start date is in the future:
  - Checkout still charges immediately.
  - Subscription remains `pending`.
  - An activation action is scheduled for the selected start date.
  - First renewal is calculated one billing cycle after that start date.

## Renewal and Retry Behavior

- Renewals are queued by Action Scheduler per subscription profile.
- Renewal payment success marks the renewal paid and schedules the next cycle.
- Renewal failure transitions to failure handling and retry logic.
- Retry notifications can be enabled for customer and/or store owner.

## Webhook Authenticity Contract

For secure public deployment, callbacks should not rely on untrusted paid-state fields alone.

- Recommended: implement `wsz_subs_verify_gateway_exchange` for signature/API verification.
- Built-in fallback: if custom verification is absent, payload must include a valid WooCommerce `order_key`.

Example filter skeleton:

```php
add_filter('wsz_subs_verify_gateway_exchange', function ($verified, array $payload, WC_Order $order) {
    // Return true only after validating gateway signature/HMAC or API verification.
    // Return false for invalid payloads; return null to use built-in fallback behavior.
    return $verified;
}, 10, 3);
```

## Built-in QA Tools

WSZ Test Card gateway:

- Enables realistic QA payments without external credentials.
- Auto-approves checkout and scheduled renewal test payments.

Accelerated testing mode:

- Converts billing intervals to minute-based cycles for fast validation.
- Can also accelerate deferred start activation to minute-based delay (`Testing` settings).
- Useful for verifying finite-term completion and retry/queue behavior quickly.
