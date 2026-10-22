<?php
declare(strict_types=1);

$root = dirname(__DIR__) . '/plugin/wordpressconnector';
$source = file_get_contents($root . '/includes/Admin/ElementorJsonImport.php');
if (! is_string($source) || '' === $source) {
    throw new RuntimeException('ElementorJsonImport.php is missing or unreadable.');
}
$normalized = preg_replace('/\s+/', '', $source);
if (! is_string($normalized)) {
    throw new RuntimeException('Elementor import normalization failed.');
}

$required = array(
    "add_filter('page_row_actions'",
    "add_filter('post_row_actions'",
    "add_action('admin_menu'",
    'admin_post_',
    'Import Elementor JSON',
    'elementor_library',
    'MAX_BYTES',
    'new ElementorAdapter()',
    'replaceDocument',
    'createDocument',
    'Create new draft from JSON',
    "'edit_mode'=>'builder'",
);
foreach ($required as $needle) {
    if (false === strpos($normalized, preg_replace('/\s+/', '', $needle))) {
        throw new RuntimeException('Missing Elementor JSON import contract marker: ' . $needle);
    }
}
if (false !== strpos($normalized, "update_post_meta(\$postId,'_elementor_data'")) {
    throw new RuntimeException('Elementor JSON import must not write _elementor_data directly.');
}

$plugin = file_get_contents($root . '/includes/Plugin.php');
if (! is_string($plugin)) {
    throw new RuntimeException('Plugin.php is missing or unreadable.');
}
$normalizedPlugin = preg_replace('/\s+/', '', $plugin);
if (! is_string($normalizedPlugin) ||
    false === strpos($normalizedPlugin, '(newElementorJsonImport())->register();')) {
    throw new RuntimeException('ElementorJsonImport is not registered by Plugin::boot().');
}
echo "Elementor JSON import contract OK\n";
