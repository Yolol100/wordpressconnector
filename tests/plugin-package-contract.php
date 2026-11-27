<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/class-pluginpackageadapter.php');
$adapter .= (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/trait-pluginpackageadapteractions.php');
$assetStore = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/AssetStore.php');
$bootstrap = file_get_contents($root . '/plugin/wordpressconnector/wordpressconnector.php');
$plugin = file_get_contents($root . '/plugin/wordpressconnector/includes/class-plugin.php');
$docs = file_get_contents($root . '/docs/PLUGIN-PACKAGE-REST.md');

foreach (array('adapter' => $adapter, 'asset store' => $assetStore, 'bootstrap' => $bootstrap, 'plugin registry' => $plugin, 'documentation' => $docs) as $name => $source) {
    if (false === $source) {
        fwrite(STDERR, "Unable to read plugin package {$name}.\n");
        exit(1);
    }
}

$adapterRequired = array(
    "'plugin.install_package'",
    "activate_plugin(\$expected_plugin, '', \$network_wide, false)",
    "Plugin was installed but activation did not persist",
    "'system_update' => true",
    "Policy::assertLocalAssetPath",
    "plugin-packages/",
    "WPCONNECTOR_MAX_PLUGIN_PACKAGE_BYTES",
    "hash_file('sha256'",
    "hash_equals(\$expected_sha256",
    "expected_plugin",
    "ZipArchive",
    "MAX_UNCOMPRESSED_BYTES",
    "MAX_ENTRIES",
    "getExternalAttributesIndex",
    "0xA000",
    "duplicate entry paths",
    "1 !== substr_count(\$normalized, '/')",
    "overwrite_package",
    "plugin_info()",
    "current_user_can('install_plugins')",
    "current_user_can('update_plugins')",
    "current_user_can('activate_plugins')",
    "manage_network_plugins",
    "\$needs_activation = \$network_wide",
    "WordPress Connector cannot replace its own active runtime",
    "rollback_supported' => false",
);
$compactAdapter = preg_replace('/\\s+/', '', $adapter);
if (! is_string($compactAdapter)) {
    throw new RuntimeException('Could not normalize plugin-package source.');
}
foreach ($adapterRequired as $needle) {
    if (strpos($compactAdapter, preg_replace('/\\s+/', '', $needle)) === false) {
        fwrite(STDERR, "Missing plugin package contract fragment: {$needle}\n");
        exit(1);
    }
}

$assetRequired = array(
    "(?:plugin-packages|theme-packages)/",
    "array('zip' => 'application/zip')",
    "connector plugin/theme package",
);
$compactAssetStore = preg_replace('/\\s+/', '', $assetStore);
if (! is_string($compactAssetStore)) { throw new RuntimeException('Unable to normalize plugin-package asset store.'); }
foreach ($assetRequired as $needle) {
    if (strpos($compactAssetStore, preg_replace('/\\s+/', '', $needle)) === false) {
        fwrite(STDERR, "Missing plugin-package upload contract: {$needle}\n");
        exit(1);
    }
}

if (strpos($bootstrap, "includes/Adapters/class-pluginpackageadapter.php") === false) {
    fwrite(STDERR, "Plugin package adapter is missing from bootstrap.\n");
    exit(1);
}
if (strpos($plugin, 'new PluginPackageAdapter()') === false) {
    fwrite(STDERR, "Plugin package adapter is missing from registry.\n");
    exit(1);
}

foreach (array('eval(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen(', 'wp_remote_get(', 'wp_remote_request(') as $primitive) {
    if (strpos($compactAdapter, preg_replace('/\\s+/', '', $primitive)) !== false) {
        fwrite(STDERR, "Forbidden execution/fetch primitive in plugin package adapter: {$primitive}\n");
        exit(1);
    }
}

if (strpos($compactAdapter, preg_replace('/\\s+/', '', "false !== strpos(\$name, \"\\0\")")) === false || strpos($compactAdapter, preg_replace('/\\s+/', '', "preg_match('/[\\x01-\\x1F\\x7F]/', \$name)")) === false || strpos($compactAdapter, preg_replace('/\\s+/', '', "'.' === \$segment || '..' === \$segment")) === false) {
    fwrite(STDERR, "ZIP path traversal/control-character contract is incomplete.\n");
    exit(1);
}

foreach (array('dry-run consumes', 're-upload', 'expected_state_token') as $needle) {
    if (stripos($docs, $needle) === false) {
        fwrite(STDERR, "Plugin package documentation is missing lifecycle guidance: {$needle}\n");
        exit(1);
    }
}

// Theme ZIP support reuses the canonical installer. The public OIDC contract
// permits only new installs; overwrite and deletion require private approval.
$themeRequired = array(
    "'theme.install_package'",
    "registerThemePackage(\$registry)",
    "install_theme_package(array \$payload",
    "theme-packages/",
    "expected_stylesheet",
    "current_user_can('install_themes')",
    "current_user_can('update_themes')",
    "Refusing to replace or install over the active theme",
    "Custom theme roots cannot be overwritten",
    "Theme ZIP folder or duplicate path mismatch",
    "Theme ZIP must not contain symbolic links",
    "Theme ZIP lacks a valid root style.css theme header",
    "WPCONNECTOR_ALLOW_THEME_PACKAGE_REPLACE",
    "move_to_temp_backup_dir",
    "restore_temp_backup",
    "delete_temp_backup",
    "temporary_backup_cleanup_verified",
    "hash_equals(\$before_style_hash",
    "new \Theme_Upgrader(",
    "wp_clean_themes_cache()",
    "readback_verified' => true",
);
foreach ($themeRequired as $needle) {
    if (strpos($compactAdapter, preg_replace('/\\s+/', '', $needle)) === false) {
        fwrite(STDERR, "Missing guarded theme package contract: {$needle}\n");
        exit(1);
    }
}
if (strpos($assetStore, "(?:plugin-packages|theme-packages)") === false) {
    fwrite(STDERR, "Trusted asset store does not allow the guarded theme ZIP path.\n");
    exit(1);
}
if (strpos($docs, 'theme.install_package') === false) {
    fwrite(STDERR, "Missing guarded theme package documentation.\n");
    exit(1);
}
$publicPolicy = file_get_contents($root . '/plugin/wordpressconnector/includes/Security/Policy.php');
$publicRunner = file_get_contents($root . '/plugin/wordpressconnector/includes/Runtime/Runner.php');
$publicValidator = file_get_contents($root . '/scripts/validate-public-request.php');
foreach (array($publicPolicy, $publicRunner, $publicValidator) as $boundary) {
    if (! is_string($boundary) || strpos($boundary, "'theme.install_package'") === false) {
        fwrite(STDERR, "Missing bounded public theme ZIP contract.\n");
        exit(1);
    }
}
if (strpos($publicRunner, "false !== \$payload['overwrite']") === false ||
    strpos($publicValidator, "overwrite=false") === false) {
    fwrite(STDERR, "Public theme ZIP overwrite must remain denied.\n");
    exit(1);
}

// ZIP entry checks must use the supported PHP Countable API. Both guarded
// package adapters must retain the same archive size limits and read-only ZIP.
$updater = file_get_contents( $root . '/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php' );
if ( ! is_string( $updater ) || false !== strpos( $adapter, '->numFiles' )
    || false !== strpos( $updater, '->numFiles' )
    || false === strpos( $adapter, '$zip->count()' )
    || false === strpos( $updater, '$zip->count()' ) ) {
    throw new RuntimeException( 'ZIP member-count safety checks must use the stable ZipArchive count method.' );
}

// PHP 7.2+ defines count() with the same value as numFiles. Prove the
// compatibility on the actual extension when available in this test runtime.
if ( class_exists( 'ZipArchive' ) ) {
    $path = tempnam( sys_get_temp_dir(), 'wpc-zip-count-' );
    if ( false === $path ) {
        throw new RuntimeException( 'Cannot create the ZIP compatibility fixture.' );
    }
    $zip = new ZipArchive();
    $opened = false;
    try {
        if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            throw new RuntimeException( 'Cannot open the ZIP compatibility fixture.' );
        }
        $opened = true;
        if ( ! $zip->addFromString( 'plugin/main.php', '<?php // test' )
            || ! $zip->addFromString( 'plugin/readme.txt', 'readme' ) ) {
            throw new RuntimeException( 'Cannot populate the ZIP compatibility fixture.' );
        }
        if ( ! $zip->close() ) {
            throw new RuntimeException( 'Cannot finish the ZIP compatibility fixture.' );
        }
        $opened = false;
        if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
            throw new RuntimeException( 'Cannot read the ZIP compatibility fixture.' );
        }
        $opened = true;
        if ( 2 !== $zip->count() || $zip->count() !== $zip->numFiles ) {
            throw new RuntimeException( 'ZipArchive count changed the archive-size contract.' );
        }
    } finally {
        if ( $opened ) {
            $zip->close();
        }
        unlink( $path );
    }
}

echo "plugin package contract OK\n";
