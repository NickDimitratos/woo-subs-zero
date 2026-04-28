# Woo Subs-Zero Operations Playbook

## Scope

This playbook covers queue operations, backlog recovery, idempotency validation, and high-volume performance operations for Woo Subs-Zero.

## Queue Runner Commands

Run queued renewal/retry jobs for the plugin group:

wp action-scheduler run --group=wsz-subscriptions --batch-size=200

Run in repeated intervals during incident recovery:

watch -n 10 'wp action-scheduler run --group=wsz-subscriptions --batch-size=200'

List oldest pending actions:

wp action-scheduler list --group=wsz-subscriptions --status=pending --per-page=50 --orderby=scheduled_date_gmt --order=ASC

## Throughput Tuning

Use plugin settings under WooCommerce > WSZ Subscriptions:

- Action Scheduler batch size
- Concurrent queue batches

Recommended starting values for 10k+ active subscriptions:

- Batch size: 200
- Concurrent batches: 2 to 4

Increase in small increments and monitor DB/CPU load.

## Backlog Recovery Procedure

1. Confirm root cause (gateway outage, webhook delay, DB pressure, etc.).
2. Verify idempotency guard paths are active (renewal schedule keys and webhook replay keys).
3. Increase queue runner throughput in plugin settings.
4. Run action-scheduler CLI in controlled batches.
5. Verify failed renewals are entering retry profile or manual fallback as expected.
6. Restore normal throughput after backlog is cleared.

## Idempotency Validation

Renewal idempotency:

- Renewal jobs use deterministic schedule keys.
- Duplicate schedule execution is skipped when a key is already processed.

Webhook idempotency:

- Exchange callbacks build a deterministic fingerprint and cache it.
- Replay callbacks return success without reprocessing order/subscription state.

Webhook authenticity:

- In public environments, configure `wsz_subs_verify_gateway_exchange` for signature/API verification.
- If custom verification is not configured, callbacks must include a valid WooCommerce `order_key`.

## Operational SLOs

Suggested service objectives:

- 99% of due renewals processed within 10 minutes of scheduled time.
- 99% of webhook callbacks acknowledged within 2 seconds.
- Retry job scheduling success >= 99.9% when retries are enabled.

## Incident Signals

Watch for:

- Rising pending action queue in wsz-subscriptions group.
- Repeated lock contention logs on renewal or retry execution.
- High retry exhaustion rate.
- Gateway unavailable fallback transitions to manual mode.

## Post-Incident Checklist

1. Confirm pending queue is back to baseline.
2. Confirm retry records are converging to complete or exhausted states.
3. Review subscriptions in on-hold status for manual intervention paths.
4. Export incident metrics and update tuning defaults if needed.
