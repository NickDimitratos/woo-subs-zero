# Subscription Combinations (Ordered Scenario List)

This file lists the supported subscription behavior combinations in `woo-subs-zero` based on current implementation.

## 1. Inputs that create combinations

1. Start date selected by customer: `No` or `Yes`.
2. Start date timing when selected: `Today/Now` or `Future`.
3. Parent order status after checkout: `pending`, `on-hold`, `processing`, `completed`, `cancelled`, `refunded`.
4. Subscription length (total payments): `1`, `N>1` (example `4`), or `0` (open-ended).
5. Renewal payment result: `paid`, `failed`, `manual pending`, `cancelled/refunded`.
6. Subscription status changes triggered by customer/admin: `active`, `on-hold`, `cancelled`, `expired`.
7. Test mode behavior: minute cycles and deferred-start minute override.

## 2. Checkout creation combinations

1. No date + order `processing` + length `1`:
   - Subscription created.
   - Subscription activated immediately.
   - Initial payment is counted.
   - No further renewals should be charged.
   - Subscription expires at finite-term completion boundary.

2. No date + order `processing` + length `N>1` (example 4):
   - Subscription created.
   - Subscription activated immediately.
   - Initial payment is counted.
   - Remaining renewals run by schedule while status is `active`.
   - Expires after all required payments complete.

3. No date + order `completed` + length `1`:
   - Same behavior as `processing` length `1`.

4. No date + order `completed` + length `N>1`:
   - Same behavior as `processing` length `N>1`.

5. No date + order `on-hold`:
   - Subscription created in `pending`.
   - No immediate activation.
   - Becomes `active` only after qualifying paid status transition (`processing`/`completed`) or deferred activation flow.

6. No date + order `pending`:
   - Subscription created in `pending`.
   - No immediate activation.

7. Date selected (today/now) + order `processing` or `completed`:
   - Subscription created.
   - Treated as non-deferred when not in future.
   - Activation follows paid-status path.

8. Date selected (future) + order `processing` or `completed`:
   - Subscription created with requested start.
   - Subscription remains `pending`.
   - Deferred activation job is scheduled.
   - At activation time, subscription becomes `active` and renewal scheduling starts.

9. Date selected (future) + order `on-hold` or `pending`:
   - Subscription remains `pending`.
   - Deferred activation still controls activation timing.

10. Existing linked subscription IDs + order `processing`/`completed`:
    - Plugin does not create duplicate subscription.
    - Activation delegation is applied to existing linked subscription context.

11. Existing linked subscription IDs + order `on-hold`:
    - Plugin does not create duplicate subscription.
    - No paid-status activation trigger.

12. Existing linked subscription IDs + order `cancelled`/`refunded`:
    - Linked subscription is cancelled (if not already cancelled/expired).

## 3. Parent order status -> subscription status combinations

1. Parent order moves to `processing`:
   - Pending linked subscription can become `active` (if not blocked by deferred start).

2. Parent order moves to `completed`:
   - Pending linked subscription can become `active` (if not blocked by deferred start).

3. Parent order moves to `cancelled`:
   - Linked subscription transitions to `cancelled` (unless already cancelled/expired).

4. Parent order moves to `refunded`:
   - Linked subscription transitions to `cancelled` (unless already cancelled/expired).

5. Parent order moves to `on-hold`:
   - Does not auto-activate pending subscription.

## 4. Renewal execution combinations

1. Subscription status is not `active` (`pending`, `on-hold`, `cancelled`, `expired`):
   - Renewal processing exits.
   - No new charge should be executed.

2. Active finite plan, total payments `1`:
   - Initial payment counted.
   - Completion gate marks plan complete without additional charged renewals.
   - Ends as `expired` at completion boundary.

3. Active finite plan, total payments `4`:
   - Payment #1 counted at checkout activation.
   - Renewal creates/charges payment #2.
   - Renewal creates/charges payment #3.
   - Renewal creates/charges payment #4.
   - On final required payment completion, subscription transitions to `expired`.

4. Active open-ended plan (`length = 0`):
   - Renewal continues indefinitely while active.
   - No finite-length expiry completion rule applies.

5. Renewal payment succeeds:
   - Completed payment counter increments.
   - Next renewal scheduling continues unless final required payment reached.

6. Renewal payment fails:
   - Renewal order marked failed.
   - Subscription transitions `active` -> `on-hold`.
   - Retry flow may be queued.

7. Manual renewal mode:
   - Renewal order left `pending` for manual payment.
   - Subscription transitions `active` -> `on-hold` while awaiting payment.

8. Retry payment succeeds:
   - Completed payment counter increments.
   - If final required payment reached, subscription expires.
   - Otherwise next cycle is scheduled.

## 5. Cancellation communication combinations

1. Renewal order status changed to `cancelled`:
   - Linked subscription is cancelled.

2. Renewal order status changed to `refunded`:
   - Linked subscription is cancelled.

3. Subscription status changed to `cancelled`:
   - Pending renewal actions are unscheduled.
   - No further renewals should run.

4. Subscription status changed to `expired`:
   - Pending renewal actions are unscheduled.
   - No further renewals should run.

5. Renewal cancellation against already `cancelled` or `expired` subscription:
   - No extra transition is attempted.

## 6. Deferred-start + test mode combinations

1. Test mode ON + cycle minutes = `1`:
   - Renewal cadence uses 1-minute cycles.

2. Test mode ON + deferred-start minutes override enabled:
   - Future requested start can be normalized to configured test delay for activation scheduling.

3. Test mode OFF:
   - Normal billing period/interval calendar calculations are used.

## 7. Safety/idempotency combinations

1. Duplicate renewal schedule request with same key:
   - Deduplicated by schedule-key checks.

2. Unique scheduler conflict:
   - Fallback non-unique scheduling path is used to avoid dropped renewals.

3. Stale linked subscription IDs on order:
   - Normalization removes invalid IDs and allows recovery flow.

## 8. Practical scenario set (business-facing)

1. No date, 1 payment, paid order -> active -> no extra charge -> expired after completion.
2. No date, 4 payments, paid order -> active -> renewal every cycle until payment #4 -> expired.
3. Future date, 1 payment -> pending until start date -> active -> completion/expiry flow.
4. Future date, 4 payments -> pending until start date -> active -> renewal cycles -> expired at final payment.
5. Any plan + parent order cancelled/refunded -> subscription cancelled.
6. Any plan + renewal cancelled/refunded -> subscription cancelled.
7. Any plan + subscription cancelled -> all future renewals stop.

