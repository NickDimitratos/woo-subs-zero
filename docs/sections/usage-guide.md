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

Expected result: after the fourth payment, billing stops and the next-payment date is cleared. The subscription stays active until its stored four-month end date, then expires without an extra renewal cycle.

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
- When manual renewals are enabled, a customer can open **My account → Subscriptions** to see subscription status and pay an outstanding renewal. The renewal order also appears in the regular Orders list.
- When a manual renewal becomes payable, the customer receives an email with the amount and the order payment link. A failed mail attempt is noted on the order; mail delivery must be configured and monitored by the store. Existing pending renewals do not automatically receive a backdated email.
- **WooCommerce → Unpaid Renewals** lists linked pending, failed and on-hold renewal orders. A store manager can send the payment email individually for an eligible unpaid manual renewal. Orders with automatic collection, a pending bank collection, an invalid customer link or other ineligible status require review. Sending the email records an order note and does not automatically schedule a backdated reminder. Older email history may be incomplete.
- Configure **WSZ subscription renewal payment** and **WSZ renewal payment reminder** independently in **WooCommerce → Settings → Emails**. Each has enable/disable, subject, heading, main message, additional content and HTML/plain-text options. The main message supports `{customer_name}`, `{subscription_id}`, and `{amount}`; the payment button stays linked to the renewal order.
- The reminder is enabled by default and scheduled once, 15 days after the renewal order is created. Change the interval (1–365 days) in the reminder settings. Already-scheduled reminders retain their date; older pending orders are not automatically enrolled. The scheduled action checks the current order and subscription before sending and is cancelled when the order is paid or cancelled. Delivery depends on Action Scheduler and the configured mail transport.
- A failed reminder send is recorded on the renewal order and retried up to three times, one hour apart. Each attempt checks eligibility again, and payment or order cancellation clears pending retries. If all attempts fail or a retry cannot be scheduled, the renewal order records the failure; monitor mail delivery and scheduled actions.
- For finite plans, new manual renewal notices display the payment position and the number remaining after that payment when the information is available. The reference number is optional and is read from `_wsz_contract_reference` on the subscription or its line items. Integrations can supply a reference through the `wsz_subs_renewal_contract_reference` filter, which receives the default reference, the subscription and the renewal order. No particular contract system is required.
- Built-in email text follows the site locale when a translation is available. Dutch translations are bundled; the English source is the fallback. Custom subject, heading and body text entered in settings remain as entered and are not automatically translated.
- The HTML design is bundled with Woo Subs-Zero and does not depend on Gravity Forms.
- Once a manual renewal order is confirmed paid, its subscription cycle is completed and the next renewal is scheduled. No automatic bank authorization is created by a manual payment.

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
