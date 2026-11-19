<?php
/**
 * Classic-menu IDs must be exact before any lookup or mutation.
 */
declare(strict_types=1);

$GLOBALS['menu_lookups'] = array();
$GLOBALS['menu_updates'] = 0;
$GLOBALS['menu_deletes'] = 0;
$GLOBALS['menu_item_reads'] = 0;
$GLOBALS['menu_item_meta_reads'] = 0;
$GLOBALS['menu_item_writes'] = 0;
$GLOBALS['menu_item_last_write'] = null;
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
function get_post($id) {
    ++$GLOBALS['menu_item_reads'];
    if (! is_int($id) || 202 !== $id) {
        throw new RuntimeException('Incorrect menu item lookup target.');
    }
    return (object) array('ID' => 202, 'post_type' => 'nav_menu_item');
}
function get_post_meta($id): array {
    ++$GLOBALS['menu_item_meta_reads'];
    if (202 !== $id) {
        throw new RuntimeException('Incorrect menu item metadata target.');
    }
    return array('_menu_item_type' => array('post_type'));
}
function wp_update_nav_menu_item($menu_id, $item_id, $args) {
    ++$GLOBALS['menu_item_writes'];
    if (101 !== $menu_id || (0 !== $item_id && 202 !== $item_id)) {
        throw new RuntimeException('Menu item target changed.');
    }
    $GLOBALS['menu_item_last_write'] = array($menu_id, $item_id, $args);
    return 0 === $item_id ? 303 : 202;
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
// menu.item_upsert must reject ambiguous IDs before WordPress is accessed.
$bad_ids = array(true, false, 202.5, -202, '202junk', '2.02e2',
    ' 202', "202\n", '99999999999999999999999999', array(202));
$expectInvalidItem = static function (array $payload, string $errorMessage) use ($adapter): void {
    foreach (array(true, false) as $dryRun) {
        $lookups = count($GLOBALS['menu_lookups']);
        $itemReads = $GLOBALS['menu_item_reads'];
        $metaReads = $GLOBALS['menu_item_meta_reads'];
        $writes = $GLOBALS['menu_item_writes'];
        try {
            $adapter->menuItemUpsert($payload, array('dry_run' => $dryRun));
            throw new RuntimeException('Invalid menu item payload was accepted.');
        } catch (RuntimeException $error) {
            if ($errorMessage !== $error->getMessage()) {
                throw $error;
            }
        }
        if (count($GLOBALS['menu_lookups']) !== $lookups
            || $GLOBALS['menu_item_reads'] !== $itemReads
            || $GLOBALS['menu_item_meta_reads'] !== $metaReads
            || $GLOBALS['menu_item_writes'] !== $writes) {
            throw new RuntimeException('Invalid menu item payload accessed a WordPress API.');
        }
    }
};
foreach (array_merge($bad_ids, array(0, '0', null)) as $badMenuId) {
    $expectInvalidItem(
        array('menu_id' => $badMenuId, 'item_id' => 0),
        'Menu not found.'
    );
}
foreach ($bad_ids as $badId) {
    $expectInvalidItem(
        array('menu_id' => 101, 'item_id' => $badId),
        'item_id must be a non-negative integer.'
    );
    foreach (array('object_id', 'parent', 'position') as $field) {
        $expectInvalidItem(
            array('menu_id' => 101, 'item_id' => 0, $field => $badId),
            'object_id, parent and position must be non-negative integers.'
        );
    }
}
// A zero item ID creates an item; zero-related fields and valid zero-padded
// IDs must remain available for legacy clients.
$emptyPreview = $adapter->menuItemUpsert(
    array('menu_id' => '000101', 'item_id' => '000', 'title' => 'New link',
        'object_id' => '000', 'parent' => 0, 'position' => '000'),
    array('dry_run' => true)
);
if (($emptyPreview['would_upsert']['menu_id'] ?? null) !== 101
    || ($emptyPreview['would_upsert']['item_id'] ?? null) !== 0
    || ($emptyPreview['would_upsert']['args']['menu-item-object-id'] ?? null) !== 0
    || ($emptyPreview['would_upsert']['args']['menu-item-parent-id'] ?? null) !== 0
    || ($emptyPreview['would_upsert']['args']['menu-item-position'] ?? null) !== 0
    || $GLOBALS['menu_item_writes'] !== 0) {
    throw new RuntimeException('Valid zero/default menu item preview changed.');
}
$created = $adapter->menuItemUpsert(
    array('menu_id' => 101, 'title' => 'New link', 'object_id' => 0,
        'parent' => '0', 'position' => 0),
    array('dry_run' => false)
);
if (($created['item_id'] ?? null) !== 303
    || ($created['rollback_supported'] ?? null) !== false
    || ($GLOBALS['menu_item_last_write'][0] ?? null) !== 101
    || ($GLOBALS['menu_item_last_write'][1] ?? null) !== 0
    || $GLOBALS['menu_item_writes'] !== 1) {
    throw new RuntimeException('New menu item creation or rollback changed.');
}
$updatedPreview = $adapter->menuItemUpsert(
    array('menu_id' => 101, 'item_id' => '000202', 'title' => 'Edited',
        'object_id' => '00055', 'parent' => '000202', 'position' => '003'),
    array('dry_run' => true)
);
if (($updatedPreview['would_upsert']['item_id'] ?? null) !== 202
    || ($updatedPreview['would_upsert']['args']['menu-item-object-id'] ?? null) !== 55
    || ($updatedPreview['would_upsert']['args']['menu-item-parent-id'] ?? null) !== 202
    || ($updatedPreview['would_upsert']['args']['menu-item-position'] ?? null) !== 3
    || ! isset($updatedPreview['_current_fingerprint'])
    || $GLOBALS['menu_item_writes'] !== 1) {
    throw new RuntimeException('Existing menu item preview changed.');
}
$updatedItem = $adapter->menuItemUpsert(
    array('menu_id' => 101, 'item_id' => 202, 'title' => 'Edited',
        'object_id' => 55, 'parent' => 0, 'position' => 3),
    array('dry_run' => false)
);
if (($updatedItem['item_id'] ?? null) !== 202
    || ($updatedItem['rollback_supported'] ?? null) !== false
    || ($GLOBALS['menu_item_last_write'][1] ?? null) !== 202
    || ($GLOBALS['menu_item_last_write'][2]['menu-item-object-id'] ?? null) !== 55
    || $GLOBALS['menu_item_writes'] !== 2) {
    throw new RuntimeException('Existing menu item update or rollback changed.');
}
echo "Classic-menu exact ID and rollback contract OK\n";
