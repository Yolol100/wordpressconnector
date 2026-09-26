<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/ElementorJsonAdapter.php');
$plugin = file_get_contents($root . '/plugin/wordpressconnector/includes/Plugin.php');
$bootstrap = file_get_contents($root . '/plugin/wordpressconnector/wordpressconnector.php');
$export = file_get_contents($root . '/plugin/wordpressconnector/includes/Admin/ElementorJsonExport.php');
$import = file_get_contents($root . '/plugin/wordpressconnector/includes/Admin/ElementorJsonImport.php');

foreach (array(
    'adapter' => $adapter,
    'plugin' => $plugin,
    'bootstrap' => $bootstrap,
    'export' => $export,
    'import' => $import,
) as $label => $source) {
    if (! is_string($source) || '' === $source) {
        fwrite(STDERR, $label . " source is missing.\n");
        exit(1);
    }
}

$requiredAdapter = array(
    "'elementor.json_export'",
    "'elementor.json_import'",
    "'privileged' => true",
    "'sensitive' => true",
    "'mutation' => true",
    'new ElementorJsonExport()',
    'new ElementorJsonImport()',
    "payload['document']",
);
foreach ($requiredAdapter as $needle) {
    if (false === strpos($adapter, $needle)) {
        fwrite(STDERR, "Missing Elementor JSON adapter contract marker: {$needle}\n");
        exit(1);
    }
}

if (false === strpos($plugin, 'new ElementorJsonAdapter()')) {
    fwrite(STDERR, "ElementorJsonAdapter is not registered by Plugin::boot().\n");
    exit(1);
}
if (false === strpos($bootstrap, "includes/Adapters/ElementorJsonAdapter.php")) {
    fwrite(STDERR, "ElementorJsonAdapter bootstrap include is missing.\n");
    exit(1);
}
if (false === strpos($export, 'public function exportDocument(')) {
    fwrite(STDERR, "Shared Elementor export service is missing.\n");
    exit(1);
}
if (false === strpos($import, 'public function importDocument(')) {
    fwrite(STDERR, "Shared Elementor import service is missing.\n");
    exit(1);
}
if (false !== strpos($adapter, "update_post_meta(") || false !== strpos($adapter, "'_elementor_data'")) {
    fwrite(STDERR, "Connector JSON adapter must not write Elementor meta directly.\n");
    exit(1);
}

echo "private Elementor JSON action contract OK\n";
