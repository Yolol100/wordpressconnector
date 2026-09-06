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
$elementorCapabilities = $read('plugin/wordpressconnector/includes/Adapters/ElementorCapabilitiesAdapter.php');
$elementorForms = $read('plugin/wordpressconnector/includes/Adapters/ElementorFormsAdapter.php');
$settings = $read('plugin/wordpressconnector/includes/Admin/Settings.php');
$catalog = $read('docs/ACTION-CATALOG.md');

foreach (array(
    'includes/Adapters/AbilitiesAdapter.php',
    'includes/Adapters/ElementorCapabilitiesAdapter.php',
    'includes/Adapters/ElementorFormsAdapter.php',
    'includes/Adapters/YoastAdapter.php',
) as $relative) {
    if (false === strpos($main, $relative)) {
        fwrite(STDERR, "Main plugin bootstrap is missing {$relative}.\n");
        exit(1);
    }
}

foreach (array('AbilitiesAdapter', 'ElementorCapabilitiesAdapter', 'ElementorFormsAdapter', 'YoastAdapter') as $class) {
    if (false === strpos($plugin, 'new ' . $class . '()')) {
        fwrite(STDERR, "Plugin registry is missing {$class}.\n");
        exit(1);
    }
}

if (preg_match("/update_post_meta\\([^;]*['_\"]_elementor_data['\"]/s", $elementor)) {
    fwrite(STDERR, "ElementorAdapter must not write _elementor_data directly.\n");
    exit(1);
}
if (preg_match("/update_post_meta\\([^;]*['_\"]_elementor_data['\"]/s", $elementorForms)) {
    fwrite(STDERR, "ElementorFormsAdapter must not write _elementor_data directly.\n");
    exit(1);
}

foreach (array('->save(', 'get_elements_data', 'get_db_document_settings', 'assertDocumentReadback') as $needle) {
    if (false === strpos($elementor, $needle)) {
        fwrite(STDERR, "Elementor document API/readback contract is missing: {$needle}.\n");
        exit(1);
    }
}

foreach (array(
    'elementor.capabilities',
    'elementor.inventory',
    'elementor.form_capabilities',
    'elementor.form_inspect',
    'elementor.form_upsert',
    'wordpress.abilities',
    'yoast.inspect',
    'yoast.update',
) as $action) {
    if (false === strpos($catalog, '`' . $action . '`')) {
        fwrite(STDERR, "Action catalog is missing {$action}.\n");
        exit(1);
    }
}

foreach (array("register('elementor.inventory'", 'missing_widgets', 'widgetSource', 'next_offset') as $needle) {
    if (false === strpos($elementorCapabilities, $needle)) {
        fwrite(STDERR, "Elementor inventory contract is missing: {$needle}.\n");
        exit(1);
    }
}

foreach (array(
    "register('elementor.form_capabilities'",
    "register('elementor.form_inspect'",
    "register('elementor.form_upsert'",
    'schema_fingerprint',
    'submit_action_choice_keys',
    'atomic_props_schema',
    'restoreSnapshot',
) as $needle) {
    if (false === strpos($elementorForms, $needle)) {
        fwrite(STDERR, "Elementor forms contract is missing: {$needle}.\n");
        exit(1);
    }
}

if (false === strpos($settings, 'WPCONNECTOR_SITE_URL')) {
    fwrite(STDERR, "Canonical GitHub Actions setup is missing from WordPress settings.\n");
    exit(1);
}

foreach (array('GitHub App Client ID', 'repo_owner', 'repo_name', 'repo_root') as $legacySetting) {
    if (false !== strpos($settings, $legacySetting)) {
        fwrite(STDERR, "Legacy direct-GitHub setting leaked into WordPress Connector: {$legacySetting}.\n");
        exit(1);
    }
}

echo "single-plugin contract OK\n";
