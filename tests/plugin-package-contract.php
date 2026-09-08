<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/PluginPackageAdapter.php');
$assetStore = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/AssetStore.php');
$bootstrap = file_get_contents($root . '/plugin/wordpressconnector/wordpressconnector.php');
$plugin = file_get_contents($root . '/plugin/wordpressconnector/includes/Plugin.php');

foreach (array('adapter' => $adapter, 'asset store' => $assetStore, 'bootstrap' => $bootstrap, 'plugin registry' => $plugin) as $name => $source) {
    if (false === $source) {
        fwrite(STDERR, "Unable to read plugin package {$name}.\n");
        exit(1);
    }
}

$adapterRequired = array(
    "'plugin.install_package'",
    "'system_update' => true",
    "Policy::assertLocalAssetPath",
    "WPCONNECTOR_MAX_PLUGIN_PACKAGE_BYTES",
    "hash_file('sha256'",
    "hash_equals(\$expectedSha256",
    "expected_plugin",
    "ZipArchive",
    "MAX_UNCOMPRESSED_BYTES",
    "MAX_ENTRIES",
    "getExternalAttributesIndex",
    "0xA000",
    "overwrite_package",
    "plugin_info()",
    "current_user_can('install_plugins')",
    "current_user_can('update_plugins')",
    "current_user_can('activate_plugins')",
    "manage_network_plugins",
    "WordPress Connector cannot replace its own active runtime",
    "rollback_supported' => false",
);
foreach ($adapterRequired as $needle) {
    if (strpos($adapter, $needle) === false) {
        fwrite(STDERR, "Missing plugin package contract fragment: {$needle}\n");
        exit(1);
    }
}

$assetRequired = array(
    "plugin-packages/",
    "array('zip' => 'application/zip')",
    "connector plugin package",
);
foreach ($assetRequired as $needle) {
    if (strpos($assetStore, $needle) === false) {
        fwrite(STDERR, "Missing plugin-package upload contract: {$needle}\n");
        exit(1);
    }
}

if (strpos($bootstrap, "includes/Adapters/PluginPackageAdapter.php") === false) {
    fwrite(STDERR, "Plugin package adapter is missing from bootstrap.\n");
    exit(1);
}
if (strpos($plugin, 'new PluginPackageAdapter()') === false) {
    fwrite(STDERR, "Plugin package adapter is missing from registry.\n");
    exit(1);
}

foreach (array('eval(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen(') as $primitive) {
    if (strpos($adapter, $primitive) !== false) {
        fwrite(STDERR, "Forbidden execution primitive in plugin package adapter: {$primitive}\n");
        exit(1);
    }
}

if (strpos($adapter, "false !== strpos(\$name, \"\\0\")") === false || strpos($adapter, "'.' === \$segment || '..' === \$segment") === false) {
    fwrite(STDERR, "ZIP path traversal contract is incomplete.\n");
    exit(1);
}

echo "plugin package contract OK\n";
