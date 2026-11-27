<?php
/**
 * Post author and parent IDs must never select unrelated objects via lossy casts.
 */
declare(strict_types=1);

class WP_Post {
    public $ID = 202;
    public $post_type = 'page';
    public $post_status = 'publish';
    public $post_title = 'Original title';
    public $post_name = 'original-title';
    public $post_content = 'Original content';
    public $post_excerpt = '';
    public $post_author = 7;
    public $post_parent = 0;
    public $menu_order = -4;
    public $post_date = '2026-01-01 00:00:00';
    public $post_date_gmt = '2026-01-01 00:00:00';
    public $post_modified = '2026-01-01 00:00:00';
    public $post_modified_gmt = '2026-01-01 00:00:00';
    public $comment_status = 'open';
    public $ping_status = 'open';
    public $post_password = '';
}
$GLOBALS['posts'] = array(202 => new WP_Post());
$GLOBALS['post_creates'] = 0;
$GLOBALS['post_updates'] = 0;
$GLOBALS['post_insert_fields'] = null;
$GLOBALS['post_update_fields'] = null;
function post_type_exists($type): bool { return in_array($type, array('post', 'page'), true); }
function wp_slash($value) { return $value; }
class WP_Error { public function get_error_message(): string { return 'qa_private_provider_diagnostic_9c31'; } }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function get_post($id) {
    if (! is_int($id)) { throw new RuntimeException('Post lookup used a coerced ID.'); }
    return $GLOBALS['posts'][$id] ?? null;
}
function get_post_thumbnail_id($id): int { return 0; }
function get_permalink($id): string { return 'https://example.test/?p=' . $id; }
function wp_insert_post($fields, $wp_error = false) {
    ++$GLOBALS['post_creates'];
    if (isset($GLOBALS['post_provider_result'])) { return $GLOBALS['post_provider_result']; }
    $GLOBALS['post_insert_fields'] = $fields;
    $post = new WP_Post();
    $post->ID = 303;
    $post->post_type = $fields['post_type'] ?? 'post';
    $post->post_status = $fields['post_status'] ?? 'draft';
    if (array_key_exists('post_author', $fields)) { $post->post_author = $fields['post_author']; }
    if (array_key_exists('post_parent', $fields)) { $post->post_parent = $fields['post_parent']; }
    $GLOBALS['posts'][303] = $post;
    return 303;
}
function wp_update_post($fields, $wp_error = false) {
    ++$GLOBALS['post_updates'];
    if (isset($GLOBALS['post_provider_result'])) { return $GLOBALS['post_provider_result']; }
    $GLOBALS['post_update_fields'] = $fields;
    $post = get_post($fields['ID']);
    if (! $post) { throw new RuntimeException('Update target missing.'); }
    $map = array(
        'post_author' => 'post_author',
        'post_parent' => 'post_parent',
        'menu_order' => 'menu_order',
        'post_title' => 'post_title',
        'post_content' => 'post_content',
        'post_excerpt' => 'post_excerpt',
        'post_status' => 'post_status',
        'post_name' => 'post_name',
        'comment_status' => 'comment_status',
        'ping_status' => 'ping_status',
    );
    foreach ($map as $field => $property) {
        if (array_key_exists($field, $fields)) { $post->$property = $fields[$field]; }
    }
    return $post->ID;
}
$root = dirname(__DIR__);
foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Support/Input.php',
    'Security/Policy.php', 'Adapters/class-coreadapter.php') as $path) {
    require_once $root . '/plugin/wordpressconnector/includes/' . $path;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\CoreAdapter();

// All IDs must be rejected before either a create or update can write.
$badIDs = array(true, false, 102.5, -102, '-1', '', '102extra',
    '1.02e2', ' 102', "102\n", '999999999999999999999999', array(102));
foreach (array('author', 'parent') as $field) {
    foreach ($badIDs as $badID) {
        foreach (array(true, false) as $dryRun) {
            foreach (array('postCreate', 'postUpdate') as $method) {
                $createsBefore = $GLOBALS['post_creates'];
                $updatesBefore = $GLOBALS['post_updates'];
                $payload = array(
                    'id' => 202, 'type' => 'page', 'title' => 'Valid title',
                    $field => $badID,
                );
                try {
                    $adapter->$method($payload, array('dry_run' => $dryRun));
                    throw new RuntimeException('Malformed post field ID was accepted.');
                } catch (RuntimeException $error) {
                    if ($field . ' must be a non-negative integer.' !== $error->getMessage()) {
                        throw $error;
                    }
                }
                if ($GLOBALS['post_creates'] !== $createsBefore
                    || $GLOBALS['post_updates'] !== $updatesBefore) {
                    throw new RuntimeException('Invalid post field ID caused a write.');
                }
            }
        }
    }
}
// Optional/null fields must remain omitted; ordering remains signed integer.
$withoutIDs = $adapter->postCreate(
    array('type' => 'page', 'author' => null, 'parent' => null, 'menu_order' => -4),
    array('dry_run' => true)
);
if (isset($withoutIDs['would_create']['post_author'])
    || isset($withoutIDs['would_create']['post_parent'])
    || ($withoutIDs['would_create']['menu_order'] ?? null) !== -4) {
    throw new RuntimeException('Optional post fields or menu_order contract changed.');
}
foreach (array(0, '0', '000') as $rootID) {
    $preview = $adapter->postCreate(
        array('type' => 'page', 'author' => $rootID, 'parent' => $rootID),
        array('dry_run' => true)
    );
    if (($preview['would_create']['post_author'] ?? null) !== 0
        || ($preview['would_create']['post_parent'] ?? null) !== 0) {
        throw new RuntimeException('Exact root/zero IDs no longer accepted.');
    }
}
$createPayload = array('type' => 'page', 'title' => 'New page',
    'author' => '00055', 'parent' => '000202');
$preview = $adapter->postCreate($createPayload, array('dry_run' => true));
if (($preview['would_create']['post_author'] ?? null) !== 55
    || ($preview['would_create']['post_parent'] ?? null) !== 202
    || ($preview['would_create']['post_status'] ?? null) !== 'draft'
    || ! isset($preview['_current_fingerprint'])
    || $GLOBALS['post_creates'] !== 0) {
    throw new RuntimeException('Valid post create dry-run changed.');
}
$created = $adapter->postCreate($createPayload, array('dry_run' => false));
if (($created['post']['id'] ?? null) !== 303
    || ($created['post']['author'] ?? null) !== 55
    || ($created['post']['parent'] ?? null) !== 202
    || ($created['_rollback']['action'] ?? null) !== 'post.trash'
    || ($created['_rollback']['payload']['id'] ?? null) !== 303
    || ($GLOBALS['post_insert_fields']['post_author'] ?? null) !== 55
    || ($GLOBALS['post_insert_fields']['post_parent'] ?? null) !== 202
    || $GLOBALS['post_creates'] !== 1) {
    throw new RuntimeException('Valid post create and rollback changed.');
}
$updatePayload = array('id' => '000202', 'author' => '00044',
    'parent' => '000303', 'menu_order' => -4);
$preview = $adapter->postUpdate($updatePayload, array('dry_run' => true));
if (($preview['before']['author'] ?? null) !== 7
    || ($preview['before']['parent'] ?? null) !== 0
    || ($preview['after']['author'] ?? null) !== 44
    || ($preview['after']['parent'] ?? null) !== 303
    || ($preview['after']['menu_order'] ?? null) !== -4
    || ! isset($preview['_current_fingerprint'])
    || $GLOBALS['post_updates'] !== 0) {
    throw new RuntimeException('Valid post update dry-run changed.');
}
$updated = $adapter->postUpdate($updatePayload, array('dry_run' => false));
if (($updated['after']['author'] ?? null) !== 44
    || ($updated['after']['parent'] ?? null) !== 303
    || ($updated['_rollback']['action'] ?? null) !== 'post.update'
    || ($updated['_rollback']['payload']['author'] ?? null) !== 7
    || ($updated['_rollback']['payload']['parent'] ?? null) !== 0
    || ($GLOBALS['post_update_fields']['post_author'] ?? null) !== 44
    || ($GLOBALS['post_update_fields']['post_parent'] ?? null) !== 303
    || $GLOBALS['post_updates'] !== 1) {
    throw new RuntimeException('Valid post update and rollback contract changed.');
}
$restored = $adapter->postUpdate($updated['_rollback']['payload'], array('dry_run' => false));
if (($restored['after']['author'] ?? null) !== 7
    || ($restored['after']['parent'] ?? null) !== 0
    || $GLOBALS['post_updates'] !== 2) {
    throw new RuntimeException('Post update rollback no longer restores valid IDs.');
}
// Provider failure text can contain data from third-party hooks or a database.
$beforeState = serialize($GLOBALS['posts']);
$cases = array(array('postCreate', $createPayload, 'WordPress post creation failed.'), array('postUpdate', $updatePayload, 'WordPress post update failed.'));
foreach ($cases as $case) {
    list($method, $payload, $expected) = $case;
    $badResults = array(new WP_Error(), 0, -1, '202');
    if ('postUpdate' === $method) { $badResults[] = 303; }
    foreach ($badResults as $providerResult) {
        $GLOBALS['post_provider_result'] = $providerResult;
        $rejected = false;
        try { $adapter->$method($payload, array('dry_run' => false)); }
        catch (RuntimeException $error) { $rejected = $expected === $error->getMessage(); }
        catch (Throwable $error) { $rejected = false; }
        if (!$rejected || $beforeState !== serialize($GLOBALS['posts'])) {
            throw new LogicException('Invalid post provider result was not safely rejected: ' . $method);
        }
    }
}
unset($GLOBALS['post_provider_result']);
try {
    $adapter->postCreate(array('type' => 'qa_private_provider_diagnostic_9c31'), array('dry_run' => true));
    throw new LogicException('Unknown post type was accepted.');
} catch (RuntimeException $error) {
    if ('Unknown post type.' !== $error->getMessage()) { throw $error; }
}
echo "Core post author, parent and provider result contract OK\n";
