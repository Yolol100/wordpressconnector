<?php
/**
 * Isolated taxonomy assignment contract: validate exact IDs before reads/writes.
 */
declare(strict_types=1);

$GLOBALS['term_assign_reads'] = 0;
$GLOBALS['term_assign_writes'] = 0;
$GLOBALS['term_assign_last_write'] = null;

function sanitize_key($value): string { return strtolower((string) $value); }
function taxonomy_exists($taxonomy): bool { return 'category' === $taxonomy; }
function is_wp_error($value): bool { return false; }
function wp_get_object_terms($object_id, $taxonomy, $args): array {
    ++$GLOBALS['term_assign_reads'];
    if (101 !== $object_id || 'category' !== $taxonomy || ($args['fields'] ?? '') !== 'ids') {
        throw new RuntimeException('Taxonomy lookup used the wrong object or arguments.');
    }
    return array(12);
}
function wp_set_object_terms($object_id, $term_ids, $taxonomy, $append) {
    ++$GLOBALS['term_assign_writes'];
    $GLOBALS['term_assign_last_write'] = array($object_id, $term_ids, $taxonomy, $append);
    return $term_ids;
}
$root = dirname(__DIR__);
foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Support/Input.php',
    'Security/Policy.php', 'Adapters/CoreAdapter.php') as $path) {
    require_once $root . '/plugin/wordpressconnector/includes/' . $path;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\CoreAdapter();
$good = array('object_id' => '000101', 'taxonomy' => 'category', 'terms' => array(12, '013'), 'append' => false);
$preview = $adapter->termAssign($good, array('dry_run' => true));
if (($preview['before'] ?? null) !== array(12)
    || ($preview['after'] ?? null) !== array(12, 13)
    || $GLOBALS['term_assign_writes'] !== 0
    || ! isset($preview['_current_fingerprint'])) {
    throw new RuntimeException('Valid taxonomy preview changed its contract.');
}
$written = $adapter->termAssign($good, array('dry_run' => false));
if ($GLOBALS['term_assign_last_write'] !== array(101, array(12, 13), 'category', false)
    || $GLOBALS['term_assign_writes'] !== 1
    || ($written['_rollback']['action'] ?? '') !== 'term.assign'
    || ($written['_rollback']['payload']['terms'] ?? null) !== array(12)) {
    throw new RuntimeException('Valid taxonomy assignment or rollback changed.');
}
$assertRejected = static function (array $payload, string $message) use ($adapter): void {
    foreach (array(true, false) as $dry_run) {
        $read_count = $GLOBALS['term_assign_reads'];
        $write_count = $GLOBALS['term_assign_writes'];
        try {
            $adapter->termAssign($payload, array('dry_run' => $dry_run));
            throw new RuntimeException('Invalid taxonomy assignment accepted.');
        } catch (RuntimeException $error) {
            if ($message !== $error->getMessage()) {
                throw $error;
            }
        }
        if ($read_count !== $GLOBALS['term_assign_reads']
            || $write_count !== $GLOBALS['term_assign_writes']) {
            throw new RuntimeException('Rejected taxonomy assignment accessed or modified WordPress terms.');
        }
    }
};
foreach (array(true, false, 101.5, -101, 0, '101junk', ' 101', '1.01e2',
    '9999999999999999999999999999', array(101), null) as $invalid_id) {
    $bad = $good;
    $bad['object_id'] = $invalid_id;
    $assertRejected($bad, 'object_id and valid taxonomy are required.');
}
foreach (array(false, '12', null, 12) as $invalid_list) {
    $bad = $good;
    $bad['terms'] = $invalid_list;
    $assertRejected($bad, 'terms must be an array of positive term IDs.');
}
foreach (array(true, false, 12.5, -1, 0, '0', '12junk', ' 12',
    '99999999999999999999999', array(12), null) as $invalid_term) {
    $bad = $good;
    $bad['terms'] = array($invalid_term);
    $assertRejected($bad, 'terms must contain positive term IDs.');
}
// Empty lists remain an intentional way to preview or clear assignments.
$empty = array('object_id' => 101, 'taxonomy' => 'category', 'terms' => array());
$cleared = $adapter->termAssign($empty, array('dry_run' => true));
if (($cleared['after'] ?? null) !== array() || $GLOBALS['term_assign_writes'] !== 1) {
    throw new RuntimeException('Explicit empty taxonomy terms no longer preview a clear operation.');
}
// Preserve historical omitted-list preview semantics; a separate public
// contract change would be required before forbidding implicit empty lists.
$omitted = array('object_id' => 101, 'taxonomy' => 'category');
$legacy = $adapter->termAssign($omitted, array('dry_run' => true));
if (($legacy['after'] ?? null) !== array() || $GLOBALS['term_assign_writes'] !== 1) {
    throw new RuntimeException('Legacy omitted taxonomy terms changed.');
}
echo "Core taxonomy assignment strict-ID and rollback contract OK\n";