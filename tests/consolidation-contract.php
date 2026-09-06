<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    $content = file_get_contents($path);
    if (false === $content) {
        fwrite(STDERR, "Unable to read {$relative}.\n");
        exit(1);
    }
    return $content;
};

$main = $read('plugin/wordpressconnector/wordpressconnector.php');
$plugin = $read('plugin/wordpressconnector/includes/Plugin.php');
$elementor = $read('plugin/wordpressconnector/includes/Adapters/ElementorAdapter.php');
$settings = $read('plugin/wordpressconnector/includes/Admin/Settings.php');
$catalog = $read('docs/ACTION-CATALOG.md');
$readme = $read('plugin/wordpressconnector/readme.txt');

foreach (array(
    'includes/Adapters/AbilitiesAdapter.php',
    'includes/Adapters/ElementorCapabilitiesAdapter.php',
    'includes/Adapters/YoastAdapter.php',
) as $relative) {
    if (false === strpos($main, $relative)) {
        fwrite(STDERR, "Main plugin bootstrap is missing {$relative}.\n");
        exit(1);
    }
}

foreach (array('AbilitiesAdapter', 'ElementorCapabilitiesAdapter', 'YoastAdapter') as $class) {
    if (false === strpos($plugin, 'new ' . $class . '()')) {
        fwrite(STDERR, "Plugin registry is missing {$class}.\n");
        exit(1);
    }
}

if (preg_match("/update_post_meta\\([^;]*['_\"]_elementor_data['\"]/s", $elementor)) {
    fwrite(STDERR, "ElementorAdapter must not write _elementor_data directly.\n");
    exit(1);
}

foreach (array('->save(', 'get_elements_data', 'get_db_document_settings', 'assertDocumentReadback') as $needle) {
    if (false === strpos($elementor, $needle)) {
        fwrite(STDERR, "Elementor document API/readback contract is missing: {$needle}.\n");
        exit(1);
    }
}

foreach (array('elementor.capabilities', 'wordpress.abilities', 'yoast.inspect', 'yoast.update') as $action) {
    if (false === strpos($catalog, '`' . $action . '`')) {
        fwrite(STDERR, "Action catalog is missing {$action}.\n");
        exit(1);
    }
}

if (false === strpos($settings, 'WPCONNECTOR_SITE_URL') || false !== strpos($settings, 'GitHub App Client ID')) {
    fwrite(STDERR, "Unified settings contract is missing or legacy Client ID setup leaked into WordPress Connector.\n");
    exit(1);
}

if (false === strpos($main, 'Version: 1.2.0') || false === strpos($readme, 'Stable tag: 1.2.0')) {
    fwrite(STDERR, "Version metadata is not aligned at 1.2.0.\n");
    exit(1);
}

echo "single-plugin consolidation contract OK\n";
