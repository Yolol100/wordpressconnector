<?php

declare(strict_types=1);

$path = __DIR__ . '/../plugin/wordpressconnector/includes/Admin/ElementorJsonImport.php';
$source = file_get_contents($path);
if (! is_string($source) || '' === $source) {
    fwrite(STDERR, "ElementorJsonImport.php is missing or unreadable.\n");
    exit(1);
}

$required = array(
    "add_filter('page_row_actions'",
    "add_filter('post_row_actions'",
    "add_action('admin_menu'",
    "admin_post_",
    "Import Elementor JSON",
    "elementor_library",
    "5 * MB_IN_BYTES",
    "new ElementorAdapter()",
    "replaceDocument",
    "'edit_mode' => 'builder'",
);

foreach ($required as $needle) {
    if (false === strpos($source, $needle)) {
        fwrite(STDERR, "Missing Elementor JSON import contract marker: {$needle}\n");
        exit(1);
    }
}

if (false !== strpos($source, "update_post_meta($postId, '_elementor_data'")) {
    fwrite(STDERR, "Elementor JSON import must not write _elementor_data directly.\n");
    exit(1);
}

$pluginPath = __DIR__ . '/../plugin/wordpressconnector/includes/Plugin.php';
$plugin = file_get_contents($pluginPath);
if (! is_string($plugin) || false === strpos($plugin, '(new ElementorJsonImport())->register();')) {
    fwrite(STDERR, "ElementorJsonImport is not registered by Plugin::boot().\n");
    exit(1);
}

echo "Elementor JSON import contract OK\n";
