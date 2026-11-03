<?php
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require dirname(__DIR__, 2) . '/includes/class-wsz-renewal-engine.php';

$jobs = array();
$page_sizes = array();
function as_get_scheduled_actions($query = array(), $format = 'OBJECT') {
    global $jobs, $page_sizes;
    $page_sizes[] = $query['per_page'];
    $matches = array_values(array_filter($jobs, static fn ($a) => $a->hook === $query['hook']));
    return array_slice($matches, $query['offset'] ?? 0, $query['per_page'] > 0 ? $query['per_page'] : null);
}
function as_unschedule_action($hook, $args = array(), $group = '') {
    global $jobs;
    $jobs = array_filter($jobs, static fn ($a) => $a->hook !== $hook || $a->args !== $args);
}
function as_unschedule_all_actions($hook, $args = array(), $group = '') {
    as_unschedule_action($hook, $args, $group);
}
foreach (array('wsz_subs_process_renewal', 'wsz_subs_paynl_sepa_prepare') as $hook) {
    for ($i = 0; $i < 112; ++$i) {
        $jobs[] = new class($hook, array('subscription_id' => $i >= 110 ? 915 : 9999, 'schedule_key' => 'key-' . $i)) {
            public function __construct(public string $hook, public array $args) {}
            public function get_args() { return $this->args; }
        };
    }
}
$expiry = (object) array('hook' => 'wsz_subs_expire_subscription', 'args' => array('subscription_id' => 915));
$jobs[] = $expiry;
$sub = new class extends WC_Order { public function get_id() { return 915; } };
$engine = (new ReflectionClass(WSZ_Renewal_Engine::class))->newInstanceWithoutConstructor();
$engine->clear_scheduled_renewals($sub);
echo json_encode(array(
    'own_collections' => count(array_filter($jobs, static fn ($a) => $a !== $expiry && $a->args['subscription_id'] === 915)),
    'foreign_collections' => count(array_filter($jobs, static fn ($a) => $a->args['subscription_id'] === 9999)),
    'expiry_preserved' => in_array($expiry, $jobs, true),
    'bounded_pages' => !empty($page_sizes) && min($page_sizes) > 0 && max($page_sizes) <= 100,
));
