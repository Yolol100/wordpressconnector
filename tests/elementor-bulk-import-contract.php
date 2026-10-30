<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$import = file_get_contents($root . '/plugin/wordpressconnector/includes/Admin/ElementorJsonImport.php');
$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/ElementorAdapter.php');
if (! is_string($import) || ! is_string($adapter)) {
    throw new RuntimeException('Elementor source missing.');
}
foreach (array(
    'wpconnector_elementor_bulk_preview',
    'wpconnector_elementor_bulk_confirm',
    'check_admin_referer(self::BULK_PREVIEW)',
    'check_admin_referer(self::BULK_CONFIRM)',
    "current_user_can('manage_options')",
    'is_uploaded_file($tmp)',
    'self::BULK_LIMIT',
    'self::BULK_BYTES',
    'get_posts(array(',
    "'name'=>\$slug",
    "'posts_per_page'=>3",
    "'suppress_filters'=>true",
    'conflict: duplicate slug',
    'conflict: repeated target',
    'delete_transient($key)',
    'hash_equals($row[',
    "'status']='ready'",
    'replaceDocument($payload',
) as $required) {
    if (false === strpos($import, $required)) {
        throw new RuntimeException('Bulk import safety marker missing: ' . $required);
    }
}
if (false === strpos($adapter, "get_post_meta($postId, '_elementor_data', true)")) {
    throw new RuntimeException('Elementor snapshot must read persisted data.');
}
if (false !== strpos($adapter, "get_elements_data() : array()")) {
    throw new RuntimeException('Elementor snapshot must not use cached in-memory elements.');
}
if (false === strpos($adapter, 'assertDocumentReadback($restored,')) {
    throw new RuntimeException('Rollback readback must remain enforced.');
}
echo "Elementor bulk import/readback contract OK\n";
+"slug",
    "'posts_per_page'=>3",
    "'suppress_filters'=>true",
    'conflict: duplicate slug',
    'conflict: repeated target',
    'delete_transient($key)',
    'hash_equals($row[',
    "'status'=>'ready'",
    'replaceDocument($payload',
) as $required) {
    if (false === strpos($import, $required)) {
        throw new RuntimeException('Bulk import safety marker missing: ' . $required);
    }
}
if (false === strpos($adapter, "get_post_meta($postId, '_elementor_data', true)")) {
    throw new RuntimeException('Elementor snapshot must read persisted data.');
}
if (false !== strpos($adapter, "get_elements_data() : array()")) {
    throw new RuntimeException('Elementor snapshot must not use cached in-memory elements.');
}
if (false === strpos($adapter, 'assertDocumentReadback($restored,')) {
    throw new RuntimeException('Rollback readback must remain enforced.');
}
echo "Elementor bulk import/readback contract OK\n";
