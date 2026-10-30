<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$import = file_get_contents($root . '/plugin/wordpressconnector/includes/Admin/ElementorJsonImport.php');
$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/ElementorAdapter.php');
if (! is_string($import) || ! is_string($adapter)) {
    throw new RuntimeException('Elementor bulk import sources are unavailable.');
}

$requiredImport = array(
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
);
$compactImport = preg_replace('/\\s+/', '', $import);
foreach ($requiredImport as $needle) {
    if (false === strpos((string) $compactImport, (string) preg_replace('/\\s+/', '', $needle))) {
        throw new RuntimeException('Elementor bulk import safety contract missing: ' . $needle);
    }
}
foreach (array(
    "get_post_meta(\$postId, '_elementor_data', true)",
    'assertDocumentReadback($after, $data, $pageSettings)',
    'assertDocumentReadback($restored,',
) as $needle) {
    if (false === strpos($adapter, $needle)) {
        throw new RuntimeException('Elementor persisted readback contract missing: ' . $needle);
    }
}
if (false !== strpos($adapter, "get_elements_data() : array()")) {
    throw new RuntimeException('Elementor persisted readback must not use an in-memory editor tree.');
}
echo "Elementor bulk import/readback contract OK\n";
