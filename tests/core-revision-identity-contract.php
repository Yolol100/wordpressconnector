<?php
/**
 * Revision restoration must not select another revision via a lossy ID cast.
 */
declare(strict_types=1);

// Isolated WordPress API fixture; no staging or production writes.
class WP_Post {
    public $ID;
    public $post_type;
    public $post_parent;
    public $post_status;
    public $post_title = 'Page';
    public $post_name = 'page';
    public $post_content;
    public $post_excerpt = '';
    public $post_author = 7;
    public $menu_order = 0;
    public $post_date = '2026-01-01 00:00:00';
    public $post_date_gmt = '2026-01-01 00:00:00';
    public $post_modified = '2026-01-01 00:00:00';
    public $post_modified_gmt = '2026-01-01 00:00:00';
    public $comment_status = 'open';
    public $ping_status = 'open';
    public $post_password = '';

    public function __construct(int $id, string $type, int $parent, string $content) {
        $this->ID = $id;
        $this->post_type = $type;
        $this->post_parent = $parent;
        $this->post_status = 'revision' === $type ? 'inherit' : 'publish';
        $this->post_content = $content;
    }
}

$GLOBALS['revision_posts'] = array(
    202 => new WP_Post(202, 'page', 0, 'Current content'),
    303 => new WP_Post(303, 'revision', 202, 'Older content'),
);
$GLOBALS['revision_lookups'] = 0;
$GLOBALS['revision_parent_reads'] = 0;
$GLOBALS['revision_restores'] = 0;
$GLOBALS['revision_rollback_writes'] = 0;
function wp_get_post_revision($id) {
    ++$GLOBALS['revision_lookups'];
    if (! is_int($id)) {
        throw new RuntimeException('Coerced revision ID reached WordPress.');
    }
    return 303 === $id ? $GLOBALS['revision_posts'][303] : false;
}
function get_post($id) {
    ++$GLOBALS['revision_parent_reads'];
    if (! is_int($id)) {
        throw new RuntimeException('Coerced post ID reached WordPress.');
    }
    return $GLOBALS['revision_posts'][$id] ?? null;
}
function get_post_thumbnail_id($id): int { return 0; }
function get_permalink($id): string { return 'https://example.test/?p=' . $id; }
function wp_restore_post_revision($revision_id) {
    ++$GLOBALS['revision_restores'];
    if (303 !== $revision_id) {
        throw new RuntimeException('Wrong revision restored.');
    }
    $GLOBALS['revision_posts'][202]->post_content = $GLOBALS['revision_posts'][303]->post_content;
    return 202;
}
function wp_slash($value) { return $value; }
function is_wp_error($value): bool { return false; }
function wp_update_post($fields, $wp_error = false) {
    ++$GLOBALS['revision_rollback_writes'];
    $post = get_post($fields['ID']);
    if (! $post || 202 !== $post->ID) {
        throw new RuntimeException('Wrong rollback target.');
    }
    $map = array(
        'post_title' => 'post_title',
        'post_content' => 'post_content',
        'post_excerpt' => 'post_excerpt',
        'post_status' => 'post_status',
        'post_name' => 'post_name',
        'post_author' => 'post_author',
        'post_parent' => 'post_parent',
        'menu_order' => 'menu_order',
        'comment_status' => 'comment_status',
        'ping_status' => 'ping_status',
    );
    foreach ($map as $field => $property) {
        if (array_key_exists($field, $fields)) {
            $post->$property = $fields[$field];
        }
    }
    return 202;
}

$root = dirname(__DIR__);
foreach (array(
    'Support/class-json.php', 'Support/class-fingerprint.php', 'Support/class-input.php',
    'Security/class-policy.php', 'Adapters/class-coreadapter.php',
) as $path) {
    require_once $root . '/plugin/wordpressconnector/includes/' . $path;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\CoreAdapter();

// Malformed revision IDs must not reach a lookup or a write.
$invalid = array(true, false, 303.5, -303, 0, '0', '303junk',
    '3.03e2', ' 303', "303\n", '99999999999999999999999999',
    array(303), null);
foreach ($invalid as $id) {
    foreach (array(true, false) as $dryRun) {
        $lookups = $GLOBALS['revision_lookups'];
        $parents = $GLOBALS['revision_parent_reads'];
        $restores = $GLOBALS['revision_restores'];
        try {
            $adapter->postRestoreRevision(
                array('revision_id' => $id), array('dry_run' => $dryRun)
            );
            throw new RuntimeException('Malformed revision ID was accepted.');
        } catch (RuntimeException $error) {
            if ('Revision not found.' !== $error->getMessage()) {
                throw $error;
            }
        }
        if ($lookups !== $GLOBALS['revision_lookups']
            || $parents !== $GLOBALS['revision_parent_reads']
            || $restores !== $GLOBALS['revision_restores']) {
            throw new RuntimeException('Rejected revision ID accessed WordPress APIs.');
        }
    }
}
foreach (array(303, '303', '000303') as $id) {
    $preview = $adapter->postRestoreRevision(
        array('revision_id' => $id), array('dry_run' => true)
    );
    if (($preview['before']['id'] ?? null) !== 202
        || ($preview['before']['content'] ?? null) !== 'Current content'
        || ($preview['revision']['id'] ?? null) !== 303
        || ($preview['revision']['content'] ?? null) !== 'Older content'
        || ! preg_match('/^[a-f0-9]{64}$/D', $preview['_current_fingerprint'] ?? '')
        || $GLOBALS['revision_restores'] !== 0) {
        throw new RuntimeException('Valid revision preview contract changed.');
    }
}
$restored = $adapter->postRestoreRevision(
    array('revision_id' => '000303'), array('dry_run' => false)
);
if ($GLOBALS['revision_restores'] !== 1
    || ($restored['after']['id'] ?? null) !== 202
    || ($restored['after']['content'] ?? null) !== 'Older content'
    || ($restored['_rollback']['action'] ?? null) !== 'post.update'
    || ($restored['_rollback']['payload']['id'] ?? null) !== 202
    || ($restored['_rollback']['payload']['content'] ?? null) !== 'Current content'
    || $GLOBALS['revision_rollback_writes'] !== 0) {
    throw new RuntimeException('Valid revision restore or rollback metadata changed.');
}
$rollback = $adapter->postUpdate(
    $restored['_rollback']['payload'], array('dry_run' => false)
);
if (($rollback['after']['content'] ?? null) !== 'Current content'
    || $GLOBALS['revision_rollback_writes'] !== 1
    || $GLOBALS['revision_restores'] !== 1) {
    throw new RuntimeException('Revision rollback failed to restore original content.');
}
echo "Core revision exact-ID preview, restore and rollback contract OK\n";
