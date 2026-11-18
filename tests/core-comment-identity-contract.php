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
function get_comment($id) {
    ++$GLOBALS['comment_lookups'];
    if (! is_int($id)) { throw new RuntimeException('Comment lookup received a coerced ID.'); }
    return 101 === $id ? $GLOBALS['comment_fixture'] : null;
}
function wp_slash($fields) { return $fields; }
function is_wp_error($value): bool { return false; }
function wp_update_comment($fields, $return_error = false) {
    ++$GLOBALS['comment_writes'];
    $GLOBALS['comment_fixture']->comment_content = (string) $fields['comment_content'];
    return true;
}
function wp_trash_comment($id): bool {
    ++$GLOBALS['comment_trashes'];
    return 101 === $id;
}
$root = dirname(__DIR__);
foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Support/Input.php',
    'Security/Policy.php', 'Adapters/CoreAdapter.php') as $path) {
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
echo "Core comment exact-ID read, update and trash contract OK\n";