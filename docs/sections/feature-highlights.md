# Feature Highlights

- Custom subscription lifecycle on `shop_subscription` with clear state transitions.
- Finite-term subscriptions with boundary protection (prevents extra post-term renewals).
- Deterministic renewal scheduling through Action Scheduler.
- Retry engine for failed renewals with configurable retry notifications.
- Manual renewal fallback when auto-charge is unavailable.
- Customer account actions: cancel, suspend/reactivate, change payment method, pay failed renewal, renew early, resubscribe, switch plan.
- Plan switching and proration controls.
- Synchronized renewals and first-cycle synchronization proration options.
- Built-in WSZ Test Card gateway for QA.
- Accelerated testing mode (minute-based cycles for rapid QA validation).
- Webhook verification contract for safer public deployments.
- Admin diagnostics for queued renewals, transaction visibility, and recovery guidance.
