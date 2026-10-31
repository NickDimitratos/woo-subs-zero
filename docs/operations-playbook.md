# Woo Subs-Zero Operations Playbook

## Scope

This playbook covers queue operations, backlog recovery, idempotency validation, and high-volume performance operations for Woo Subs-Zero.

## Finite-term service expiry from 0.1.57

The last required payment ends billing, rather than immediately expiring prepaid service. The next-payment date is cleared, future collections are blocked, and expiry uses the stored end date. The PAY.nl SEPA mandate is revoked separately. This applies to normal payments, delayed bank confirmation and successful retries, using the existing subscription price, interval and term.

Already expired subscriptions are not automatically reactivated. Review affected records individually: confirm every required payment against orders/provider history, the original contracted end date, refunds/reversals, cancellation history and existing service access. A deliberately cancelled or genuinely ended term must stay ended. If a fully paid term was prematurely expired and its end date is still future, an operator needs a controlled recovery that restores only the remaining prepaid service, preserves that original end date, clears the next charge and obsolete collection/retry jobs, and schedules expiry. Do not replay payment requests, create a replacement paid signup, reauthorize a bank mandate or use the normal renewal flow to restore access. This release does not provide a bulk reactivation tool, and no live records are changed.

## PAY.nl SEPA from 0.1.56

The new SEPA feature is disabled by default and does not migrate old subscriptions. Read [PAY.nl SEPA setup and recovery](paynl-sepa.md) before enabling it. A successful iDEAL payment is not a mandate. Pending or uncertain bank collections must be reconciled before taking another payment; do not clear their pending markers to force a retry.

## PAY.nl iDEAL subscriptions created before 0.1.55

Version 0.1.54 treated every `pay_gateway_` method as a recurring card method. An initial iDEAL payment can be successful without providing a reusable card token or authorizing a bank mandate. A successful initial payment must not be collected again to repair a renewal flag.

Version 0.1.55 does not bulk-edit existing subscriptions. New PAY.nl subscriptions start in manual mode until valid recurring context is available. Unsupported methods stay manual. At dispatch, legacy unsupported PAY.nl subscriptions switch to manual mode, unpaid renewals remain pending, and automatic retry records are cancelled after fallback. Already paid renewals are preserved. These safeguards also apply when the store has manual renewals disabled: that setting blocks new unsupported subscription purchases, but cannot create an automatic collection authorization for existing purchases.

Run the read-only audit from the WordPress installation directory (adjust the plugin folder if renamed):

```sh
wp eval-file wp-content/plugins/woo-subs-zero/scripts/audit-paynl-subscriptions.php > paynl-audit.jsonl
```

Each JSON line includes only the subscription ID, status, gateway, manual-renewal flag, local context classification, and `automatic_needs_review`. The script pages through active and historical subscriptions; it does not contact PAY.nl or change subscription, order, token or mandate data. Treat the output as a point-in-time inventory. `stored_card_token_unverified` and `sepa_authorization_unverified` require review; they do not prove a payment context cannot be recovered. `custom_adapter_review` means the extension must be verified separately. Even `sepa_authorization_valid_locally` does not establish current provider mandate status. No customer names, IBANs, token values or credentials are included.

The installed PAY.nl gateway calls its Direct Debit method `pay_gateway_incasso` (option 137). It is not a card-token gateway and is not automatically enrolled in this iDEAL-to-SEPA flow. The plugin's internal bank renewal order method `wsz_paynl_sepa` is a different identifier.

Recovery should be reviewed by the store operator before jobs are resumed:

1. Identify subscriptions using `pay_gateway_ideal` or another unsupported PAY.nl method. Review the initial order, renewal orders, payment status, and provider transaction history. Preserve successful initial payments and check for any separately paid renewals before collecting an outstanding balance.
2. Use the subscription's **Switch to manual renewal** action for affected records. Review only those subscriptions' queued renewal/retry actions; cancel obsolete automatic retries through the normal queue controls. Do not run the entire billing queue as a diagnostic. The dispatch safeguard prevents a card charge if an old action still runs.
3. If manual collection is the chosen policy, enable **Enable manual renewals** deliberately and tell affected customers that every renewal requires payment. Reuse the appropriate unpaid renewal order/payment link after reviewing its amount and status; avoid creating duplicate renewal orders. Orders previously marked failed need operator review before being made payable. No automatic bank collection is established by an iDEAL payment.
4. If automatic collection is required, arrange supported card payment-context recovery through the store's payment-method update workflow. Verify the saved token belongs to the subscription customer and selected supported card gateway, contains PAY.nl's `recurring_id`, and is valid for the configured Sales Location. A late signup callback cannot overwrite a newer card or a changed gateway. Check **Automatically restore automatic renewals** for recovery of established subscriptions; initial pending card setup is completed independently of that recovery setting.
5. Confirm renewal mode and reusable payment context on each affected subscription before allowing future automatic jobs. Do not repair the issue by toggling Automatic alone, entering a fake token, using an initial transaction ID as a token, or relabelling an iDEAL method as a card method.

PAY.nl [card recurring payments](https://developer.pay.nl/docs/recurring-payments) require a saved card `recurring_id`; [bank direct debit](https://developer.pay.nl/docs/direct-debit-mandates) requires its own mandate and explicit consent. SEPA support was added in 0.1.56 for new eligible checkout purchases; it does not enroll existing subscriptions. Custom card IDs may use `wsz_subs_paynl_gateway_ids`; custom non-card adapters must explicitly register through `wsz_subs_tokenized_gateway_ids` and supply their own recurring-charge implementation and valid reusable context. Do not register iDEAL as a card gateway to bypass the safety checks.

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
