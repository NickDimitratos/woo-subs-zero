# Changelog

All notable changes to this project are documented in this file.

## [0.1.4] - 2026-04-28

### Changed

- Updated README positioning with a top hero image and explicit free-subscriptions messaging.
- Added package export exclusions for the README hero image via `.distignore` and `.gitattributes` `export-ignore`.

## [0.1.3] - 2026-04-28

### Fixed

- Prevented duplicate bootstrap execution in the same request by adding an early bootstrap-loaded guard in the plugin entry file.
- Added defensive class guards for core bootstrap/subscription manager loading to prevent `Cannot declare class WSZ_Subscription_Manager` fatals.
- Removed root Composer classmap autoload for plugin source classes to avoid competing class-loading paths.

### Changed

- Removed repository-only README hero image asset from package content.

## [0.1.2] - 2026-04-27

### Added

- Added built-in `WSZ Test Card` WooCommerce gateway for realistic QA checkout and renewal card-flow simulation.

### Tests

- Added a realistic card-style failing payment method recovery test using WooCommerce `_payment_token_id` post-meta payload parsing.
- Added admin regression coverage for due/overdue active subscriptions auto-queueing a missing renewal action from the diagnostics metabox.
- Added scheduler-return regressions for admin/renewal paths to ensure `as_schedule_single_action()` failures (action ID `0`) do not report false success or persist schedule keys.
- Added regressions for scheduled renewal dispatch: WSZ Test Card renewals now bypass stale gateway-availability fallback, and manual-renewal renewal runs no longer enter failed/retry flow.
- Added regressions for fixed-plan completion boundaries: checkout now falls back to variation/item subscription length metadata, and renewal scheduling backfills missing end dates for finite plans.
- Added checkout/admin regressions ensuring renewal-linked orders do not create duplicate subscription entries and subscription posts expose a dedicated meta-keys profile panel.

### Fixed

- Fixed WSZ subscription product editor visibility so WooCommerce core price fields appear in Product Data > General for WSZ product types.
- Fixed frontend Add to Cart rendering for WSZ product types by wiring custom types to WooCommerce simple/variable add-to-cart handlers.
- Forced WSZ subscription product types to behave as virtual (non-shippable) products so shipping is not loaded for subscription-only purchases.
- Fixed variable-subscription checkout detection by resolving parent product type/meta from variation line items so subscriptions are created and visible in subscription order screens.
- Added status-fallback creation hooks so orders in `on-hold`, `processing`, or `completed` can recover missing subscription creation if checkout-time hooks were missed (including COD flows).
- Added `woocommerce_new_order` fallback with non-`shop_order` guard to improve subscription creation reliability for COD/manual paths while preventing recursive subscription creation.
- Normalized and cleaned stale `_wsz_subscription_ids` references during checkout/order fallback so invalid historical links no longer block subscription recreation and admin list visibility.
- Added fixed-term installment pricing: subscription product price is now treated as total plan value and split per cycle at cart/checkout for `_wsz_subscription_length` > 1.
- Registered standalone `wc-active` subscription status and dedicated `WSZ_Order_Subscription` order class to keep `shop_subscription` records visible and typed correctly without Woo Subscriptions plugin.
- Replaced subscription creation dependency on `wc_create_order()` (which creates `shop_order`) with direct `WSZ_Order_Subscription` object creation so new subscriptions are persisted as true `shop_subscription` records in standalone mode.
- Reloaded parent order snapshots in checkout creation flow before meta checks to prevent duplicate subscription creation across multiple Woo checkout/status hooks in the same request.
- Added subscription admin metabox with an "Upcoming Renewals" table (Action Scheduler pending actions, schedule keys, next payment summary, renewal count) for standalone debugging and test-mode visibility.
- Reloaded parent order snapshots before activation-from-parent status handling so newly linked subscriptions are reliably activated and scheduled after checkout payment completion.
- Expanded renewal diagnostics in the subscription metabox with explicit local/UTC current time, local/UTC next payment display, and actionable warnings for overdue/no-schedule scenarios (runner vs activation status).
- Fixed renewal scheduling edge case where due-now timestamps (`next_payment <= now`) could be skipped without queueing; such renewals are now enqueued immediately.
- Converted subscription list presentation to an informational view (subscription-focused columns like next renewal, queued renewals, renewal count, and latest test-card transaction) instead of order-style billing/total columns.
- Added a dedicated subscription metabox for `WSZ Test Card Transactions` so each minute-cycle renewal can be verified by timestamp, context, order ID, amount, status, and transaction ID.
- Added persistent WSZ Test Card transaction logging for both checkout and scheduled renewals with per-subscription retrieval.
- Added admin-side renewal auto-recovery: when a subscription is active and due/overdue but has no pending `wsz_subs_process_renewal` action, the `Upcoming Renewals` panel now queues an immediate recovery action.
- Fixed renewal recovery false-positive messaging by requiring a valid Action Scheduler action ID before reporting `Recovery: queued...`.
- Fixed schedule-key persistence to only save `_wsz_next_schedule_key` after Action Scheduler accepts the action.
- Fixed scheduled renewal dispatch for `wsz_test_card` so test-card subscriptions do not switch to manual renewal when gateway availability checks are stale.
- Fixed manual-renewal processing so manual due renewals move to pending/on-hold without being marked as failed or queued for automatic retries.
- Fixed infinite minute-cycle renewals for finite plans by backfilling missing subscription end timestamps before scheduling/processing renewal actions.
- Fixed checkout billing-profile resolution to recover interval/period/length/sync from variation/item metadata when parent variable-product metadata is incomplete.
- Fixed duplicate subscription creation from renewal-linked orders so one subscription record is retained with renewal history attached.
- Added a subscription-side `Subscription Meta Keys` panel to keep the profile focused on subscription metadata instead of order-style fields.
- Removed default WooCommerce order-style metaboxes from subscription edit screens to keep standalone subscription records focused on subscription data.
- Added explicit subscription status display in the subscription profile panel so status remains visible even when order-style boxes are hidden.
- Fixed finite-term boundary handling so fixed-length plans (for example 4 installments) cannot queue/process a 5th renewal payment at or past term end.
- Fixed admin overdue-recovery behavior to avoid re-queueing renewals once the finite term boundary is reached; expiration is processed/scheduled instead.
- Added a one-time admin cleanup action to remove stale pending renewal jobs for subscriptions already past finite term end and finalize their expiration safely.

### Docs

- Corrected project licensing metadata and documentation to GPL-2.0-or-later.
- Added explicit plugin header `License` and `License URI` fields.

## [0.1.1] - 2026-04-27

### Security

- Hardened webhook trust boundary: callbacks now require either explicit verification via filter or a valid WooCommerce order key.
- Adjusted webhook idempotency marking order so only authenticated callbacks can reserve replay keys.
- Added safer webhook payload parsing guards:
  - 1 MB raw payload cap.
  - XML parsing with non-network parser flags and controlled libxml error handling.
- Normalized request parsing in customer-facing action handlers using WordPress unslash + sanitize/cast patterns.

### Tests

- Added webhook order-key validation regression tests.
- Extended test bootstrap WC_Order stub with get_order_key support.

### Docs

- Documented secure webhook verification contract in README.
- Added webhook authenticity guidance in the operations playbook.
- Added repository security policy.
