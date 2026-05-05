# Tested Scenarios

## Subscription And Order Status

- New subscription starts as pending.
- Parent order pending keeps subscription pending.
- Parent order on-hold does not activate existing pending subscription.
- Parent order processing activates pending subscription.
- Parent order completed activates pending subscription.
- Parent order cancelled cancels active subscription.
- Parent order refunded cancels active subscription.
- Renewal order cancelled cancels linked subscription.
- Renewal order refunded cancels linked subscription.
- Already cancelled subscription is not cancelled again.
- Pending subscription is not expired by expiration cleanup.
- Deferred subscription waits when parent order is not paid.
- Deferred subscription activates when parent order is paid.
- Paid order with future start date schedules deferred activation.
- Paid order without future start date activates subscription now.
- On-hold order without future start date activates subscription now.
- Pending order without future start date keeps subscription pending.
- Paid status recovery activates existing linked subscriptions.
- On-hold status recovery does not activate existing linked subscriptions.

## Renewal Flow

- Active subscription schedules first renewal.
- First renewal is not scheduled twice.
- Renewal is skipped when subscription is not active.
- Renewal is skipped when subscription is cancelled.
- Renewal is skipped when next payment reaches the term end.
- Overdue renewal runs before the expiration boundary.
- Paid renewal keeps or returns subscription to active.
- Paid final renewal expires the subscription.
- Failed renewal puts subscription on-hold.
- Failed renewal queues a retry.
- Retry payment paid moves subscription back to active.
- Manual renewal keeps renewal order pending.
- Manual renewal puts subscription on-hold.
- Manual renewal does not start failed retry flow.
- Manual renewal payment restores automatic renewal when enabled.
- Scheduler failure does not save a fake schedule key.
- Due renewal schedules immediately.
- Blocked unique schedule retries as non-unique.

## Important Gap

- Pending manual renewal order paid does not currently have a direct test that moves subscription back to active.
- Current tested behavior only restores automatic renewal payment settings.

## Payment And Gateway Flow

- Manual renewal enabled makes order pending.
- Test card payment marks unpaid renewal order paid.
- Test card payment logs transaction details.
- Test card gateway payment returns success.
- Registered enabled gateway is available.
- Registered disabled gateway is unavailable.
- Unknown gateway payment context is ignored.
- Registered gateway payment context is accepted.
- Saved token can be used when gateway registry is missing.
- Missing gateway can switch subscription to manual renewal.
- Updated failed payment method refreshes token and gateway.
- Updated failed payment method can restore automatic renewal.
- Manual renewal payment complete can restore automatic renewal.

## Checkout And Order Creation

- Subscription cart forces account creation.
- Guest checkout without account is rejected for subscriptions.
- Non-subscription cart keeps normal account settings.
- Subscription product creates subscription from order.
- Variable subscription product is detected through parent product.
- Parent billing settings are used for variation items.
- Variation length is used when parent length is missing.
- Stale subscription IDs do not block recovery creation.
- Non-shop orders do not create subscriptions.
- Orders already linked to subscriptions do not create new subscriptions.
- Requested start date is read from order item meta.

## Retry Payments

- Retries stay off when retry settings are disabled.
- First failed renewal creates retry attempt 1.
- Retry attempt 1 is scheduled with Action Scheduler.
- Retry records save the attempt status and reason.
- Failed orders move back to pending when a retry is queued.
- Normal retry timing still uses normal retry rules.
- Test mode retry timing uses test cycle minutes.
- Test mode with 1 minute queues retry after 1 minute.
- Retry rules stop after the last allowed attempt.
- Exhausted retries keep the order failed.
- A paid retry marks the retry record complete.
- A paid retry reactivates the subscription.
- Ineligible retries are cancelled.
- Ineligible retries do not charge payment again.
- Retry queue events are logged.
- Retry process events are logged.
- Retry success events are logged.
- Retry failure events are logged.

## Plan Switching

- Upgrade proration calculates extra payment.
- Downgrade proration calculates customer credit.
- Proration can be disabled.
- Free switch window can waive the charge.
- Old cycle days can come from the subscription cycle.
- New monthly cycle days use the billing interval.
- New yearly cycle days use the billing interval.

## Early Renewal

- Early renewal can be disabled.
- Non-owners cannot renew early.
- Active subscriptions can renew early inside the allowed window.
- Subscriptions outside the early renewal window are blocked.
- Paid early renewal moves the next payment forward.
- Paid early renewal is processed only once.
- Paid early renewal can reactivate an on-hold subscription.

## Verification

- Full PHPUnit suite passes.
- PHP syntax lint passes.
