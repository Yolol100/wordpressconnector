<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/MediaAdapter.php');
if (! is_string($source)) {
    throw new RuntimeException('MediaAdapter source missing.');
}

foreach (array(
    "current_user_can('upload_files')",
    "current_user_can('edit_post', \$id)",
    "current_user_can('edit_post', \$targetId)",
    "current_user_can('edit_theme_options')",
    "current_user_can('manage_options')",
    "current_user_can('edit_post', \$parentId)",
    "isset(\$payload['id']) && 0 === (int) \$payload['id']",
    "empty(\$context['rollback_mode'])",
    'delete_post_thumbnail($targetId)',
    'get_post_thumbnail_id($targetId)',
    'Featured-image assignment readback failed.',
    'Image rename aborted and original WordPress attachment state could not be verified.',
    'New image copies have been retained to prevent broken references',
    'wp_get_attachment_metadata($id, true)',
    "fopen(\$newPath, 'xb')",
) as $needle) {
    if (false === strpos($source, $needle)) {
        throw new RuntimeException('Media assignment/rename safety guard missing: ' . $needle);
    }
}
echo "Media assignment, guarded rollback and rename safety contract OK\n";
