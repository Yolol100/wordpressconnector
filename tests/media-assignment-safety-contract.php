<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/MediaAdapter.php');
if (! is_string($source)) {
    throw new RuntimeException('MediaAdapter source missing.');
}
$compactSource = preg_replace('/\\s+/', '', $source);
if (! is_string($compactSource)) { throw new RuntimeException('Could not normalize media source.'); }


foreach (array(
    "current_user_can('upload_files')",
    "current_user_can('edit_post', \$id)",
    "current_user_can('edit_post', \$target_id)",
    "current_user_can('edit_theme_options')",
    "current_user_can('manage_options')",
    "current_user_can('edit_post', \$parent_id)",
    "isset(\$payload['id']) && 0 === (int) \$payload['id']",
    "empty(\$context['rollback_mode'])",
    'delete_post_thumbnail($target_id)',
    'get_post_thumbnail_id($target_id)',
    'Featured-image assignment readback failed.',
    'Image rename aborted and original WordPress attachment state could not be verified.',
    'New image copies have been retained to prevent broken references',
    'wp_get_attachment_metadata($id, true)',
    "fopen(\$new_path, 'xb')",
) as $needle) {
    if (false === strpos($compactSource, preg_replace('/\\s+/', '', $needle))) {
        throw new RuntimeException('Media assignment/rename safety guard missing: ' . $needle);
    }
}
// Exercise the real attachment resolver: invalid identifiers must be rejected
// before get_post(), not silently cast into an unrelated media attachment.
class WP_Post {
    public int $ID;
    public string $post_type;
    public function __construct(int $id, string $postType = 'attachment') {
        $this->ID = $id;
        $this->post_type = $postType;
    }
}
$GLOBALS['media_test_lookups'] = array();
function get_post($id) {
    $GLOBALS['media_test_lookups'][] = $id;
    return 101 === $id ? new WP_Post(101) : (102 === $id ? new WP_Post(102, 'page') : null);
}
require_once $root . '/plugin/wordpressconnector/includes/Support/Input.php';
require_once $root . '/plugin/wordpressconnector/includes/Adapters/MediaAdapter.php';
$adapter = new \Webactueel\WordPressConnector\Adapters\MediaAdapter();
$resolve = new ReflectionMethod($adapter, 'attachment_id');
$resolve->setAccessible(true);
foreach (array(101, '101', '000101') as $validId) {
    if (101 !== $resolve->invoke($adapter, array('id' => $validId))) {
        throw new RuntimeException('Valid attachment ID no longer resolves.');
    }
}
foreach (array(true, false, 101.5, -101, 0, '0', '101junk', '1.01e2',
    ' 101', "101\n", '99999999999999999999999999', array(101), null) as $invalidId) {
    $before = count($GLOBALS['media_test_lookups']);
    try {
        $resolve->invoke($adapter, array('id' => $invalidId));
        throw new RuntimeException('Invalid media attachment ID was accepted.');
    } catch (RuntimeException $error) {
        if ('Attachment not found.' !== $error->getMessage()) {
            throw $error;
        }
    }
    if (count($GLOBALS['media_test_lookups']) !== $before) {
        throw new RuntimeException('Malformed attachment ID reached get_post().');
    }
}
try {
    $resolve->invoke($adapter, array('id' => 102));
    throw new RuntimeException('Non-attachment WordPress post was accepted as media.');
} catch (RuntimeException $error) {
    if ('Attachment not found.' !== $error->getMessage()) {
        throw $error;
    }
}

// The real media.assign handler must reject malformed featured-image and
// product-gallery target IDs before fetching or changing another post.
function current_user_can($capability, ...$args): bool { return true; }
function sanitize_key($value): string { return is_string($value) ? strtolower($value) : ''; }
function get_post_thumbnail_id($id): int { return 0; }
require_once $root . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once $root . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
$badTargets = array(true, 102.5, '102junk', '1.02e2', ' 102', -102,
    0, '0', array(102), '9999999999999999999999999', null);
foreach (array('featured_image', 'product_gallery') as $targetType) {
    $expectedError = 'featured_image' === $targetType
        ? 'You cannot edit the featured-image target.'
        : 'You cannot edit the product gallery target.';
    foreach ($badTargets as $badTarget) {
        $before = count($GLOBALS['media_test_lookups']);
        try {
            $adapter->mediaAssign(array(
                'id' => 101, 'target_type' => $targetType, 'target_id' => $badTarget,
            ), array('dry_run' => true));
            throw new RuntimeException('Malformed media assignment target was accepted.');
        } catch (RuntimeException $error) {
            if ($expectedError !== $error->getMessage()) { throw $error; }
        }
        if (count($GLOBALS['media_test_lookups']) !== $before + 1) {
            throw new RuntimeException('Malformed media target ID reached get_post().');
        }
    }
}
// Preserve canonical numeric strings and the connector-owned rollback-only
// ID=0 for clearing an existing featured image, without any write.
$preview = $adapter->mediaAssign(array(
    'id' => 101, 'target_type' => 'featured_image', 'target_id' => '000102',
), array('dry_run' => true));
if (($preview['before'] ?? null) !== 0 || ($preview['after'] ?? null) !== 101) {
    throw new RuntimeException('Valid media target ID compatibility regressed.');
}
$rollbackPreview = $adapter->mediaAssign(array(
    'id' => 0, 'target_type' => 'featured_image', 'target_id' => 102,
), array('dry_run' => true, 'rollback_mode' => true));
if (($rollbackPreview['before'] ?? null) !== 0 || ($rollbackPreview['after'] ?? null) !== 0) {
    throw new RuntimeException('Featured-image rollback zero-ID contract regressed.');
}

echo "Media assignment, guarded rollback and rename safety contract OK\n";
