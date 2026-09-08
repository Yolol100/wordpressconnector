<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapterPath = $root . '/plugin/wordpressconnector/includes/Adapters/CustomCssAdapter.php';
$pluginPath = $root . '/plugin/wordpressconnector/includes/Plugin.php';
$bootstrapPath = $root . '/plugin/wordpressconnector/wordpressconnector.php';

foreach (array($adapterPath, $pluginPath, $bootstrapPath) as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$adapter = file_get_contents($adapterPath);
$plugin = file_get_contents($pluginPath);
$bootstrap = file_get_contents($bootstrapPath);

if ($adapter === false || $plugin === false || $bootstrap === false) {
    fwrite(STDERR, "Unable to read Additional CSS implementation files.\n");
    exit(1);
}

$required = array(
    "register('custom_css.inspect'",
    "register('custom_css.update'",
    "'mutation' => true",
    "'privileged' => true",
    'wp_get_custom_css(',
    'wp_get_custom_css_post(',
    'wp_update_custom_css_post(',
    "'_current_fingerprint'",
    "'_rollback'",
    'MAX_CSS_BYTES',
);

foreach ($required as $needle) {
    if (strpos($adapter, $needle) === false) {
        fwrite(STDERR, "Missing Additional CSS contract marker: {$needle}\n");
        exit(1);
    }
}

if (strpos($adapter, 'eval(') !== false || strpos($adapter, 'file_put_contents(') !== false) {
    fwrite(STDERR, "Additional CSS adapter must use WordPress APIs, not execution or direct filesystem writes.\n");
    exit(1);
}

if (strpos($plugin, 'new CustomCssAdapter()') === false) {
    fwrite(STDERR, "CustomCssAdapter is not registered in Plugin.php.\n");
    exit(1);
}

if (strpos($bootstrap, "includes/Adapters/CustomCssAdapter.php") === false) {
    fwrite(STDERR, "CustomCssAdapter is not loaded by the plugin bootstrap.\n");
    exit(1);
}

echo "custom css contract OK\n";
