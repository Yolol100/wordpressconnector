<?php
/**
 * Exact term IDs are required for reads, updates and deletion.
 */
declare(strict_types=1);

class WP_Term {
    public int $term_id = 101;
    public string $taxonomy = 'category';
    public string $name = 'Original term';
    public string $slug = 'original-term';
    public string $description = 'Original description';
    public int $parent = 0;
    public int $count = 2;
}
$GLOBALS['term_fixture'] = new WP_Term();
$GLOBALS['term_reads'] = 0;
$GLOBALS['term_updates'] = 0;
$GLOBALS['term_deletes'] = 0;
function sanitize_key($v): string { return strtolower((string) $v); }
function sanitize_text_field($v): string { return (string) $v; }
function sanitize_title($v): string { return (string) $v; }
function sanitize_textarea_field($v): string { return (string) $v; }
function get_taxonomy($taxonomy) { return (object) array('public' => 'category' === $taxonomy); }
function get_term($id, $taxonomy = '') {
    ++$GLOBALS['term_reads'];
    if (! is_int($id)) { throw new RuntimeException('Term lookup received a coerced ID.'); }
    return 101 === $id && ('category' === $taxonomy || '' === $taxonomy)
        ? $GLOBALS['term_fixture'] : null;
}
function is_wp_error($value): bool { return false; }
function wp_update_term($id, $taxonomy, $args) {
    ++$GLOBALS['term_updates'];
    if ($id !== 101 || $taxonomy !== 'category') {
        throw new RuntimeException('Taxonomy update target changed.');
    }
    if (isset($args['name'])) {
        $GLOBALS['term_fixture']->name = $args['name'];
    }
    return array('term_id' => $id, 'term_taxonomy_id' => 55);
}
function wp_delete_term($id, $taxonomy) {
    ++$GLOBALS['term_deletes'];
    return 101 === $id && 'category' === $taxonomy;
}
$root = dirname(__DIR__);
foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Support/Input.php',
    'Security/Policy.php', 'Adapters/CoreAdapter.php') as $path) {
    require_once $root . '/plugin/wordpressconnector/includes/' . $path;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\CoreAdapter();
$bad_ids = array(true, false, 101.5, -101, 0, '0', '101wrong', '1.01e2',
    ' 101', "101\n", '9999999999999999999999999999', array(101), null);
foreach ($bad_ids as $bad_id) {
    foreach (array('termGet', 'termUpdate', 'termDelete') as $action) {
        foreach (array(true, false) as $dry_run) {
            $reads = $GLOBALS['term_reads'];
            $updates = $GLOBALS['term_updates'];
            $deletes = $GLOBALS['term_deletes'];
            $payload = array('id' => $bad_id, 'taxonomy' => 'category', 'name' => 'Unsafe');
            try {
                if ('termGet' === $action) {
                    $adapter->$action($payload);
                } else {
                    $adapter->$action($payload, array('dry_run' => $dry_run));
                }
                throw new RuntimeException('Malformed term ID accepted.');
            } catch (RuntimeException $error) {
                if ($error->getMessage() !== 'Term not found.') {
                    throw $error;
                }
            }
            if ($GLOBALS['term_reads'] !== $reads
                || $GLOBALS['term_updates'] !== $updates
                || $GLOBALS['term_deletes'] !== $deletes) {
                throw new RuntimeException('Rejected term ID reached a WordPress API.');
            }
        }
    }
}
$valid = array('id' => '000101', 'taxonomy' => 'category', 'name' => 'Updated term');
$read = $adapter->termGet($valid);
if (($read['term']['id'] ?? 0) !== 101 || ($read['term']['name'] ?? '') !== 'Original term'
    || ! preg_match('/^[a-f0-9]{64}$/D', $read['fingerprint'] ?? '')) {
    throw new RuntimeException('Valid term read or fingerprint changed.');
}
$dry = $adapter->termUpdate($valid, array('dry_run' => true));
if (($dry['before']['name'] ?? '') !== 'Original term' || $GLOBALS['term_updates'] !== 0) {
    throw new RuntimeException('Term update preview changed data.');
}
$write = $adapter->termUpdate($valid, array('dry_run' => false));
if ($GLOBALS['term_updates'] !== 1
    || ($write['after']['name'] ?? '') !== 'Updated term'
    || ($write['_rollback']['action'] ?? '') !== 'term.update'
    || ($write['_rollback']['payload']['name'] ?? '') !== 'Original term') {
    throw new RuntimeException('Valid term update or rollback changed.');
}
$dryDelete = $adapter->termDelete($valid, array('dry_run' => true));
if (($dryDelete['before']['name'] ?? '') !== 'Updated term' || $GLOBALS['term_deletes'] !== 0) {
    throw new RuntimeException('Term delete dry-run called WordPress delete.');
}
$deleted = $adapter->termDelete($valid, array('dry_run' => false));
if ($GLOBALS['term_deletes'] !== 1
    || ($deleted['_rollback']['action'] ?? '') !== 'term.create'
    || ($deleted['_rollback']['payload']['name'] ?? '') !== 'Updated term') {
    throw new RuntimeException('Term delete rollback contract changed.');
}
echo "Core term exact-ID read, update and delete contract OK\n";