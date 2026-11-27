<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$import = file_get_contents($root . '/plugin/wordpressconnector/includes/Admin/class-elementorjsonimport.php');
$implementation = file_get_contents($root . '/plugin/wordpressconnector/includes/Admin/trait-elementorjsonimportmethods.php');
if ($implementation === false || !is_string($import)) { throw new RuntimeException('Missing canonical implementation: Admin/trait-elementorjsonimportmethods.php'); }
$import .= $implementation;
$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/class-elementoradapter.php');
$adapter .= (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/trait-elementoradapteractions.php');
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
    'hash_equals(',
    "row['fingerprint']",
    "'status']='ready'",
    'replaceDocument($payload',
);
$compactImport = preg_replace('/\\s+/', '', $import);
$compactAdapter = preg_replace('/\\s+/', '', $adapter);
if (! is_string($compactImport) || ! is_string($compactAdapter)) {
    throw new RuntimeException('Elementor contract source normalization failed.');
}
foreach ($requiredImport as $needle) {
    if (false === strpos((string) $compactImport, (string) preg_replace('/\\s+/', '', $needle))) {
        throw new RuntimeException('Elementor bulk import safety contract missing: ' . $needle);
    }
}
foreach (array(
    "get_post_meta(\$post_id, '_elementor_data', true)",
    'assert_document_readback($after, $saved_elements, $page_settings)',
    'assert_document_readback($restored,',
    "add_action('elementor/editor/after_save'",
    "remove_action('elementor/editor/after_save'",
) as $needle) {
    if (false === strpos($compactAdapter, preg_replace('/\\s+/', '', $needle))) {
        throw new RuntimeException('Elementor persisted readback contract missing: ' . $needle);
    }
}
if (false !== strpos($compactAdapter, "get_elements_data():array()")) {
    throw new RuntimeException('Elementor persisted readback must not use an in-memory editor tree.');
}
echo "Elementor bulk import/readback contract OK\n";
