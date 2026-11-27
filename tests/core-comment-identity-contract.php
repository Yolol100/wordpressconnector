<?php
/**
 * Comment reads and mutations must never coerce ambiguous IDs.
 */
declare(strict_types=1);

class WP_Comment {
    public int $comment_ID = 101;
    public int $comment_post_ID = 202;
    public string $comment_author = 'Original author';
    public string $comment_author_email = 'author@example.test';
    public string $comment_author_url = '';
    public string $comment_author_IP = '127.0.0.1';
    public string $comment_date = '2026-01-01 00:00:00';
    public string $comment_content = 'Original comment';
    public string $comment_approved = '1';
    public string $comment_type = '';
    public int $comment_parent = 0;
    public int $user_id = 0;
}
$GLOBALS['comment_fixture'] = new WP_Comment();
$GLOBALS['comment_lookups'] = 0;
$GLOBALS['comment_writes'] = 0;
$GLOBALS['comment_trashes'] = 0;
$GLOBALS['comment_list_calls'] = 0;
$GLOBALS['comment_list_args'] = null;
function get_comment($id) {
    ++$GLOBALS['comment_lookups'];
    if (! is_int($id)) { throw new RuntimeException('Comment lookup received a coerced ID.'); }
    return 101 === $id ? $GLOBALS['comment_fixture'] : null;
}
function sanitize_key($value): string { return strtolower((string) $value); }
function get_comments($args): array {
    ++$GLOBALS['comment_list_calls'];
    if (! is_int($args['post_id']) || ! in_array($args['post_id'], array(0, 202), true)) {
        throw new RuntimeException('Comment list used a coerced post ID.');
    }
    $GLOBALS['comment_list_args'] = $args;
    return array($GLOBALS['comment_fixture']);
}
function wp_slash($fields) { return $fields; }
class WP_Error { public function get_error_message(): string { return 'private provider diagnostic'; } }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_update_comment($fields, $return_error = false) {
    ++$GLOBALS['comment_writes'];
    if (array_key_exists('comment_provider_result',$GLOBALS)) { return $GLOBALS['comment_provider_result']; }
    $GLOBALS['comment_fixture']->comment_content = (string) $fields['comment_content'];
    return 1;
}
function wp_trash_comment($id): bool {
    ++$GLOBALS['comment_trashes'];
    return 101 === $id;
}
$root = dirname(__DIR__);
foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Support/Input.php',
    'Security/Policy.php', 'Adapters/class-coreadapter.php') as $path) {
    require_once $root . '/plugin/wordpressconnector/includes/' . $path;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\CoreAdapter();
$bad_ids = array(true, false, 101.5, -101, 0, '0', '101extra', '1.01e2',
    "101\n", ' 101', '9999999999999999999999', array(101), null);
foreach ($bad_ids as $bad_id) {
    foreach (array('commentGet', 'commentUpdate', 'commentTrash') as $method) {
        foreach (array(true, false) as $dry_run) {
            $reads = $GLOBALS['comment_lookups'];
            $writes = $GLOBALS['comment_writes'];
            $trashes = $GLOBALS['comment_trashes'];
            $payload = array('id' => $bad_id, 'content' => 'Unsafe edit');
            try {
                if ('commentGet' === $method) {
                    $adapter->$method($payload);
                } else {
                    $adapter->$method($payload, array('dry_run' => $dry_run));
                }
                throw new RuntimeException('Malformed comment ID accepted.');
            } catch (RuntimeException $error) {
                if ('Comment not found.' !== $error->getMessage()) {
                    throw $error;
                }
            }
            if ($reads !== $GLOBALS['comment_lookups']
                || $writes !== $GLOBALS['comment_writes']
                || $trashes !== $GLOBALS['comment_trashes']) {
                throw new RuntimeException('Rejected comment ID caused a lookup or mutation.');
            }
        }
    }
}
$valid = array('id' => '000101', 'content' => 'Updated comment');
$read = $adapter->commentGet($valid);
if (101 !== ($read['comment']['id'] ?? null) || 'Original comment' !== ($read['comment']['content'] ?? null)) {
    throw new RuntimeException('Valid comment read contract changed.');
}
$preview = $adapter->commentUpdate($valid, array('dry_run' => true));
if (($preview['before']['content'] ?? null) !== 'Original comment'
    || $GLOBALS['comment_writes'] !== 0) {
    throw new RuntimeException('Comment update dry-run mutated content.');
}
$updated = $adapter->commentUpdate($valid, array('dry_run' => false));
if ($GLOBALS['comment_writes'] !== 1
    || $GLOBALS['comment_fixture']->comment_content !== 'Updated comment'
    || ($updated['_rollback']['payload']['content'] ?? null) !== 'Original comment') {
    throw new RuntimeException('Valid comment update/rollback contract changed.');
}
$preview_trash = $adapter->commentTrash($valid, array('dry_run' => true));
if ($GLOBALS['comment_trashes'] !== 0
    || ($preview_trash['before']['content'] ?? null) !== 'Updated comment') {
    throw new RuntimeException('Comment trash preview performed a write.');
}
$trashed = $adapter->commentTrash($valid, array('dry_run' => false));
if ($GLOBALS['comment_trashes'] !== 1
    || 101 !== ($trashed['trashed'] ?? null)
    || false !== ($trashed['rollback_supported'] ?? null)) {
    throw new RuntimeException('Valid comment trash contract changed.');
}
// Invalid optional post filters must be rejected before querying private comments.
$invalidPostFilters = array(true, false, 202.5, -202, '-1', '',
    '202junk', '2.02e2', ' 202', "202\n",
    '99999999999999999999999999', array(202));
foreach ($invalidPostFilters as $postID) {
    $before = $GLOBALS['comment_list_calls'];
    try {
        $adapter->commentList(array('post_id' => $postID));
        throw new RuntimeException('Invalid comment post filter accepted.');
    } catch (RuntimeException $error) {
        if ('post_id must be a non-negative integer.' !== $error->getMessage()) {
            throw $error;
        }
    }
    if ($GLOBALS['comment_list_calls'] !== $before) {
        throw new RuntimeException('Invalid comment post filter reached WordPress.');
    }
}
foreach (array(202, '202', '000202') as $postID) {
    $listed = $adapter->commentList(array(
        'post_id' => $postID, 'status' => 'APPROVE', 'per_page' => 3, 'offset' => 4,
    ));
    if (($GLOBALS['comment_list_args']['post_id'] ?? null) !== 202
        || ($GLOBALS['comment_list_args']['status'] ?? null) !== 'approve'
        || ($GLOBALS['comment_list_args']['number'] ?? null) !== 3
        || ($GLOBALS['comment_list_args']['offset'] ?? null) !== 4
        || ($listed['comments'][0]['id'] ?? null) !== 101
        || ($listed['comments'][0]['post_id'] ?? null) !== 202) {
        throw new RuntimeException('Valid filtered comment list contract changed.');
    }
}
// Omitting the post filter or providing zero still queries all comments.
foreach (array(array(), array('post_id' => null), array('post_id' => 0),
    array('post_id' => '0'), array('post_id' => '000')) as $payload) {
    $listed = $adapter->commentList($payload);
    if (($GLOBALS['comment_list_args']['post_id'] ?? null) !== 0
        || ($GLOBALS['comment_list_args']['number'] ?? null) !== 50
        || ($GLOBALS['comment_list_args']['offset'] ?? null) !== 0
        || ($GLOBALS['comment_list_args']['status'] ?? null) !== 'all'
        || ($listed['comments'][0]['id'] ?? null) !== 101) {
        throw new RuntimeException('Comment list default/all-post behavior changed.');
    }
}
foreach (array(new WP_Error(), false, null, -1, '1') as $failure) {
    $GLOBALS['comment_provider_result'] = $failure;
    $before = $GLOBALS['comment_fixture']->comment_content;
    try {
        $adapter->commentUpdate(array('id'=>101, 'content'=>'Unsafe'), array('dry_run'=>false));
        throw new RuntimeException('Rejected comment provider result accepted.');
    } catch (RuntimeException $error) {
        if ('WordPress comment update failed.' !== $error->getMessage()) throw $error;
    }
    if ($GLOBALS['comment_fixture']->comment_content !== $before) throw new RuntimeException('Rejected comment changed.');
}
$GLOBALS['comment_provider_result'] = 0;
$unchanged = $adapter->commentUpdate(array('id'=>101,'content'=>$before),array('dry_run'=>false));
if (($unchanged['comment']['content'] ?? null) !== $before) throw new RuntimeException('Valid no-op comment update rejected.');
unset($GLOBALS['comment_provider_result']);
echo "Core comment exact-ID read, update and trash contract OK\n";