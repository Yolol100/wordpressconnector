<?php
/**
 * Classic-menu IDs must be exact before any lookup or mutation.
 */
declare(strict_types=1);

$GLOBALS['menu_lookups'] = array();
$GLOBALS['menu_updates'] = 0;
$GLOBALS['menu_deletes'] = 0;
$GLOBALS['menu_fixture'] = (object) array('term_id' => 101, 'name' => 'Primary', 'slug' => 'primary');
function wp_get_nav_menu_object($id) {
    $GLOBALS['menu_lookups'][] = $id;
    if (101 === $id || 'Primary' === $id) {
        return $GLOBALS['menu_fixture'];
    }
    return false;
}
function wp_get_nav_menu_items($id): array {
    if (101 !== $id) {
        throw new RuntimeException('Menu items requested for a different target.');
    }
    return array();
}
function sanitize_text_field($value): string { return (string) $value; }
function is_wp_error($value): bool { return false; }
function wp_update_nav_menu_object($id, $args) {
    ++$GLOBALS['menu_updates'];
    if (101 !== $id) {
        throw new RuntimeException('Incorrect menu update target.');
    }
    $GLOBALS['menu_fixture']->name = $args['menu-name'];
    return $id;
}
function wp_delete_nav_menu($id): bool {
    ++$GLOBALS['menu_deletes'];
    if (101 !== $id) {
        throw new RuntimeException('Incorrect menu deletion target.');
    }
    return true;
}

$root = dirname(__DIR__);
foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Support/Input.php',
    'Security/Policy.php', 'Adapters/CoreAdapter.php') as $path) {
    require_once $root . '/plugin/wordpressconnector/includes/' . $path;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\CoreAdapter();
$invalid = array(true, false, 101.5, -101, 0, '0', '101junk', '1.01e2',
    ' 101', "101\n", '9999999999999999999999999', array(101));
foreach ($invalid as $id) {
    foreach (array('menuGet', 'menuUpdate', 'menuDelete') as $method) {
        foreach (array(true, false) as $dry_run) {
            $lookups = count($GLOBALS['menu_lookups']);
            $updates = $GLOBALS['menu_updates'];
            $deletes = $GLOBALS['menu_deletes'];
            try {
                if ('menuGet' === $method) {
                    $adapter->menuGet(array('id' => $id));
                } else {
                    $adapter->$method(array('id' => $id, 'name' => 'Bad'), array('dry_run' => $dry_run));
                }
                throw new RuntimeException('Malformed menu ID was accepted.');
            } catch (RuntimeException $error) {
                if ('Menu not found.' !== $error->getMessage()) {
                    throw $error;
                }
            }
            if (count($GLOBALS['menu_lookups']) !== $lookups
                || $GLOBALS['menu_updates'] !== $updates
                || $GLOBALS['menu_deletes'] !== $deletes) {
                throw new RuntimeException('Invalid menu ID reached a WordPress API.');
            }
        }
    }
}
foreach (array(101, '101', '000101') as $id) {
    $snapshot = $adapter->menuGet(array('id' => $id));
    if (($snapshot['menu']['term_id'] ?? null) !== 101) {
        throw new RuntimeException('Valid menu ID lookup regressed.');
    }
}
$by_name = $adapter->menuGet(array('name' => 'Primary'));
if (($by_name['menu']['name'] ?? null) !== 'Primary') {
    throw new RuntimeException('Legacy menu name lookup regressed.');
}
$valid = array('id' => '000101', 'name' => 'Renamed');
$preview = $adapter->menuUpdate($valid, array('dry_run' => true));
if (($preview['after']['id'] ?? null) !== 101
    || $GLOBALS['menu_updates'] !== 0) {
    throw new RuntimeException('Menu update preview changed.');
}
$updated = $adapter->menuUpdate($valid, array('dry_run' => false));
if (($updated['menu_id'] ?? null) !== 101
    || ($updated['_rollback']['payload']['name'] ?? null) !== 'Primary'
    || $GLOBALS['menu_updates'] !== 1) {
    throw new RuntimeException('Valid menu update or rollback changed.');
}
$before_delete = $adapter->menuDelete(array('id' => '000101'), array('dry_run' => true));
if (($before_delete['before']['menu']['term_id'] ?? null) !== 101
    || $GLOBALS['menu_deletes'] !== 0) {
    throw new RuntimeException('Menu delete dry-run changed.');
}
$deleted = $adapter->menuDelete(array('id' => 101), array('dry_run' => false));
if (($deleted['deleted'] ?? null) !== 101
    || ($deleted['rollback_supported'] ?? null) !== false
    || $GLOBALS['menu_deletes'] !== 1) {
    throw new RuntimeException('Valid menu deletion behavior changed.');
}
echo "Classic-menu exact ID and rollback contract OK\n";
