<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$export = file_get_contents($root . '/plugin/wordpressconnector/includes/Admin/ElementorJsonExport.php');
$import = file_get_contents($root . '/plugin/wordpressconnector/includes/Admin/ElementorJsonImport.php');

if (! is_string($export) || ! is_string($import)) {
    fwrite(STDERR, "Unable to read Elementor JSON admin classes.\n");
    exit(1);
}

$exportChecks = array(
    "add_filter('page_row_actions'",
    "add_filter('post_row_actions'",
    'Export Elementor JSON',
    'Export Elementor JSON (ZIP)',
    'wpconnector_bulk_export_elementor_json',
    'bulk_actions-edit-page',
    'bulk_actions-edit-post',
    'bulk_actions-edit-elementor_library',
    'handle_bulk_actions-edit-page',
    'handle_bulk_actions-edit-post',
    'handle_bulk_actions-edit-elementor_library',
    "check_admin_referer('bulk-posts')",
    'MAX_BULK_ITEMS',
    'wordpressconnector/elementor-bulk-export',
    'manifest.json',
    'ZipArchive',
    'PclZip',
);

foreach ($exportChecks as $needle) {
    if (false === strpos($export, $needle)) {
        fwrite(STDERR, "Elementor JSON export admin contract is missing: {$needle}.\n");
        exit(1);
    }
}

$importChecks = array(
    "add_filter('page_row_actions'",
    "add_filter('post_row_actions'",
    'Import Elementor JSON',
    'wpconnector_import_elementor_json',
    'replaceDocument',
    'createDocument',
    'MAX_BYTES',
);

foreach ($importChecks as $needle) {
    if (false === strpos($import, $needle)) {
        fwrite(STDERR, "Elementor JSON import admin contract is missing: {$needle}.\n");
        exit(1);
    }
}

echo "elementor JSON admin contract OK\n";
