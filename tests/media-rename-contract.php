<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$source = file_get_contents($root.'/plugin/wordpressconnector/includes/Adapters/MediaAdapter.php');
if (! is_string($source)) { throw new RuntimeException('MediaAdapter missing.'); }
foreach (array(
    "'media.rename'",
    "'capability' => 'manage_options'",
    "current_user_can('upload_files')",
    "wp_attachment_is_image(",
    "wp_get_upload_dir()",
    "realpath(",
    "is_link(",
    "file_exists(",
    "wp_get_attachment_metadata(",
    "wp_update_attachment_metadata(",
    "update_attached_file(",
    "old_urls_preserved",
    "rollback_supported' => false",
    "Image rename readback verification failed",
    "Images with a separate original_image require manual migration",
) as $marker) {
    if (false === strpos($source, $marker)) {
        throw new RuntimeException('Missing safe image rename marker: '.$marker);
    }
}
if (false !== strpos($source, 'rename($sourceReal')) {
    throw new RuntimeException('Old image paths must not be moved or deleted.');
}
echo "Media rename contract OK\n";
