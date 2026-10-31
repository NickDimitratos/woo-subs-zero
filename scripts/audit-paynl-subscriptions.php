<?php
/**
 * Read-only JSONL inventory: wp eval-file wp-content/plugins/woo-subs-zero/scripts/audit-paynl-subscriptions.php
 * No customer names, bank details, tokens, credentials, provider requests or updates are emitted.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}
if (!class_exists('WSZ_Subscription_Manager') || !function_exists('wc_get_orders')) {
    WP_CLI::error('Activate WooCommerce and Woo Subs-Zero before running this audit.');
}
require_once dirname(__DIR__) . '/src/Payment/Gateway/class-wsz-paynl-gateway.php';
require_once dirname(__DIR__) . '/src/Payment/Gateway/class-wsz-paynl-sepa-context.php';
require_once dirname(__DIR__) . '/src/Payment/Gateway/class-wsz-paynl-audit.php';

// Avoid starting unrelated background jobs from this read-only WP-CLI invocation.
remove_action('shutdown', '_wp_cron');
add_filter('action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX);
$audit = new WSZ_PayNL_Audit(new WSZ_Subscription_Manager());
$page = 1;
do {
    $subscriptions = wc_get_orders(array(
        'type' => WSZ_Subscription_Manager::ORDER_TYPE,
        'status' => array_keys(wc_get_order_statuses()),
        'limit' => 100,
        'paged' => $page++,
        'return' => 'objects',
        'orderby' => 'id',
        'order' => 'ASC',
    ));
    foreach ($subscriptions as $subscription) {
        if (!($subscription instanceof WC_Order)) {
            continue;
        }
        $row = $audit->inspect($subscription);
        if (null !== $row) {
            WP_CLI::line(wp_json_encode($row));
        }
    }
} while (count($subscriptions) === 100);
