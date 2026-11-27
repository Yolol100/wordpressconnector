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
$GLOBALS['menu_location_reads'] = 0;
$GLOBALS['menu_location_writes'] = 0;
$GLOBALS['menu_location_data'] = array('primary' => 101);
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
function sanitize_key($value): string { return strtolower((string) $value); }
function get_registered_nav_menus(): array {
    ++$GLOBALS['menu_location_reads'];
    return array('primary' => 'Primary navigation');
}
function get_nav_menu_locations(): array {
    ++$GLOBALS['menu_location_reads'];
    return $GLOBALS['menu_location_data'];
}
function set_theme_mod($name, $value): bool {
    if ('nav_menu_locations' !== $name) {
        throw new RuntimeException('Unexpected theme mod target.');
    }
    ++$GLOBALS['menu_location_writes'];
    $GLOBALS['menu_location_data'] = $value;
    return true;
}
class WP_Error {
    public function get_error_message(): string { return 'private provider detail'; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_create_nav_menu($name) {
    return $GLOBALS['menu_provider_create'] ?? 404;
}
function wp_update_nav_menu_object($id, $args) {
    ++$GLOBALS['menu_updates'];
    if (101 !== $id) {
        throw new RuntimeException('Incorrect menu update target.');
    }
    if (array_key_exists('menu_provider_update', $GLOBALS)) {
        return $GLOBALS['menu_provider_update'];
    }
    $GLOBALS['menu_fixture']->name = $args['menu-name'];
    return $id;
}
function wp_delete_nav_menu($id) {
    ++$GLOBALS['menu_deletes'];
    if (101 !== $id) {
        throw new RuntimeException('Incorrect menu deletion target.');
    }
    if (array_key_exists('menu_provider_delete', $GLOBALS)) {
        return $GLOBALS['menu_provider_delete'];
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
    if (array_key_exists('menu_provider_item', $GLOBALS)) {
        return $GLOBALS['menu_provider_item'];
    }
    $GLOBALS['menu_item_last_write'] = array($menu_id, $item_id, $args);
    return 0 === $item_id ? 303 : 202;
}

$root = dirname(__DIR__);
foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Support/Input.php',
    'Security/Policy.php', 'Adapters/class-coreadapter.php') as $path) {
    require_once $root . '/plugin/wordpressconnector/includes/' . $path;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\CoreAdapter();
$aliases = array(
    'postList' => 'post_list',
    'postGet' => 'post_get',
    'postCreate' => 'post_create',
    'postUpdate' => 'post_update',
    'postLiteralReplace' => 'post_literal_replace',
    'postTrash' => 'post_trash',
    'postRestore' => 'post_restore',
    'postRevisions' => 'post_revisions',
    'postRestoreRevision' => 'post_restore_revision',
    'metaGet' => 'meta_get',
    'metaUpdate' => 'meta_update',
    'metaDelete' => 'meta_delete',
    'termList' => 'term_list',
    'termGet' => 'term_get',
    'termCreate' => 'term_create',
    'termUpdate' => 'term_update',
    'termDelete' => 'term_delete',
    'termAssign' => 'term_assign',
    'menuList' => 'menu_list',
    'menuGet' => 'menu_get',
    'menuCreate' => 'menu_create',
    'menuUpdate' => 'menu_update',
    'menuDelete' => 'menu_delete',
    'menuItemUpsert' => 'menu_item_upsert',
    'menuLocationSet' => 'menu_location_set',
    'optionGet' => 'option_get',
    'optionUpdate' => 'option_update',
    'optionDelete' => 'option_delete',
    'networkOptionGet' => 'network_option_get',
    'networkOptionUpdate' => 'network_option_update',
    'networkOptionDelete' => 'network_option_delete',
    'themeModGet' => 'theme_mod_get',
    'themeModUpdate' => 'theme_mod_update',
    'themeModRemove' => 'theme_mod_remove',
    'commentList' => 'comment_list',
    'commentGet' => 'comment_get',
    'commentUpdate' => 'comment_update',
    'commentTrash' => 'comment_trash',
);
$class = new ReflectionClass($adapter);
$traitAliases = $class->getTraitAliases();
if (count($traitAliases) !== count($aliases)) throw new RuntimeException('Legacy Core action aliases are incomplete.');
foreach ($aliases as $legacy => $canonical) {
    if (!method_exists($adapter, $legacy) || !is_callable(array($adapter, $legacy)) ||
        !method_exists($adapter, $canonical) || !is_callable(array($adapter, $canonical)) ||
        ($traitAliases[$legacy] ?? '') !== 'Webactueel\\WordPressConnector\\Adapters\\CoreAdapterActions::'.$canonical) {
        throw new RuntimeException('Core public method compatibility failed: '.$legacy);
    }
    $old = new ReflectionMethod($adapter, $legacy);
    $new = new ReflectionMethod($adapter, $canonical);
    if ($old->getName() !== $legacy || $old->getDeclaringClass()->getName() !== $class->getName() ||
        !$old->isPublic() || $old->isStatic() !== $new->isStatic() ||
        (string)$old->getReturnType() !== (string)$new->getReturnType() ||
        count($old->getParameters()) !== count($new->getParameters())) {
        throw new RuntimeException('Core method reflection contract changed: '.$legacy);
    }
    foreach ($old->getParameters() as $index => $parameter) {
        $other = $new->getParameters()[$index];
        if ($parameter->getName() !== $other->getName() ||
            (string)$parameter->getType() !== (string)$other->getType() ||
            $parameter->isOptional() !== $other->isOptional() ||
            $parameter->isPassedByReference() !== $other->isPassedByReference() ||
            $parameter->isDefaultValueAvailable() !== $other->isDefaultValueAvailable() ||
            ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() !== $other->getDefaultValue())) {
            throw new RuntimeException('Core named argument contract changed: '.$legacy);
        }
    }
}
require_once $root . '/plugin/wordpressconnector/includes/Runtime/class-registry.php';
$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter->register($registry);
$registered = array();
foreach ($registry->catalog() as $action => $metadata) {
    $handler = $registry->descriptor($action)['handler'];
    if ($handler[0] !== $adapter || !in_array($handler[1], $aliases, true)) {
        throw new RuntimeException('Core action did not register its canonical implementation: '.$action);
    }
    $registered[] = $handler[1];
}
if (count($registered) !== count($aliases) || count(array_unique($registered)) !== count($aliases)) {
    throw new RuntimeException('Core action registration count changed.');
}

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
// menu.location_set must fail closed for malformed menu IDs, including on a write.
$badLocationIds = array(true, false, 101.5, -101, '-1', '101junk',
    '1.01e2', ' 101', "101\n", '99999999999999999999999999', array(101));
foreach ($badLocationIds as $badLocationId) {
    foreach (array(true, false) as $dryRun) {
        $reads = $GLOBALS['menu_location_reads'];
        $writes = $GLOBALS['menu_location_writes'];
        try {
            $adapter->menuLocationSet(
                array('location' => 'primary', 'menu_id' => $badLocationId),
                array('dry_run' => $dryRun)
            );
            throw new RuntimeException('Invalid menu location ID was accepted.');
        } catch (RuntimeException $error) {
            if ('menu_id must be a non-negative integer.' !== $error->getMessage()) {
                throw $error;
            }
        }
        if ($reads !== $GLOBALS['menu_location_reads']
            || $writes !== $GLOBALS['menu_location_writes']) {
            throw new RuntimeException('Invalid menu location ID accessed a WordPress API.');
        }
    }
}
$locationPreview = $adapter->menuLocationSet(
    array('location' => 'primary', 'menu_id' => '000101'),
    array('dry_run' => true)
);
if (($locationPreview['before'] ?? null) !== 101
    || ($locationPreview['after'] ?? null) !== 101
    || ! isset($locationPreview['_current_fingerprint'])
    || $GLOBALS['menu_location_writes'] !== 0) {
    throw new RuntimeException('Valid menu location preview changed.');
}
$locationWrite = $adapter->menuLocationSet(
    array('location' => 'primary', 'menu_id' => 101),
    array('dry_run' => false)
);
if (($locationWrite['menu_id'] ?? null) !== 101
    || ($locationWrite['_rollback']['payload']['menu_id'] ?? null) !== 101
    || $GLOBALS['menu_location_writes'] !== 1) {
    throw new RuntimeException('Valid menu location assignment or rollback changed.');
}
$clearPreview = $adapter->menuLocationSet(
    array('location' => 'primary', 'menu_id' => '000'),
    array('dry_run' => true)
);
if (($clearPreview['after'] ?? null) !== 0
    || $GLOBALS['menu_location_writes'] !== 1) {
    throw new RuntimeException('Empty menu location preview changed.');
}
$cleared = $adapter->menuLocationSet(
    array('location' => 'primary', 'menu_id' => 0),
    array('dry_run' => false)
);
if (($cleared['menu_id'] ?? null) !== 0
    || ($cleared['_rollback']['payload']['menu_id'] ?? null) !== 101
    || ($GLOBALS['menu_location_data']['primary'] ?? null) !== 0
    || $GLOBALS['menu_location_writes'] !== 2) {
    throw new RuntimeException('Clearing a menu location or rollback changed.');
}
$omitted = $adapter->menuLocationSet(
    array('location' => 'primary', 'menu_id' => null),
    array('dry_run' => true)
);
if (($omitted['after'] ?? null) !== 0
    || $GLOBALS['menu_location_writes'] !== 2) {
    throw new RuntimeException('Optional menu ID default changed.');
}
try {
    $adapter->menuLocationSet(
        array('location' => 'unknown', 'menu_id' => 101),
        array('dry_run' => false)
    );
    throw new RuntimeException('Unknown location accepted.');
} catch (RuntimeException $error) {
    if ('Unknown menu location.' !== $error->getMessage()) {
        throw $error;
    }
}
if ($GLOBALS['menu_location_writes'] !== 2) {
    throw new RuntimeException('Unknown menu location changed theme state.');
}
$providerCases = array(
    array('menuCreate', array('name' => 'New'), 'create', 'WordPress menu creation failed.', array(new WP_Error(), false, 0, -1, '404')),
    array('menuUpdate', array('id' => 101, 'name' => 'New'), 'update', 'WordPress menu update failed.', array(new WP_Error(), false, 0, 999, '101')),
    array('menuDelete', array('id' => 101), 'delete', 'WordPress menu deletion failed.', array(new WP_Error(), false, null, 1)),
    array('menuItemUpsert', array('menu_id' => 101, 'item_id' => 202), 'item', 'WordPress menu item update failed.', array(new WP_Error(), false, 0, 999, '202')),
    array('menuItemUpsert', array('menu_id' => 101), 'item', 'WordPress menu item update failed.', array(new WP_Error(), false, 0, -1, '303')),
);
foreach ($providerCases as list($method, $payload, $key, $expected, $results)) {
    foreach ($results as $result) {
        $GLOBALS['menu_provider_' . $key] = $result;
        try {
            $adapter->$method($payload, array('dry_run' => false));
            throw new RuntimeException('Invalid provider result accepted: ' . $method);
        } catch (RuntimeException $error) {
            if ($expected !== $error->getMessage()) {
                throw $error;
            }
        } finally {
            unset($GLOBALS['menu_provider_' . $key]);
        }
    }
}
if (404 !== ($adapter->menuCreate(array('name' => 'New'), array('dry_run' => false))['menu_id'] ?? null)) {
    throw new RuntimeException('Valid menu creation changed.');
}
echo "Classic-menu exact ID and rollback contract OK\n";
