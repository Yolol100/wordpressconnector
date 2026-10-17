<?php

declare(strict_types=1);

// Exercise the real plugin.list adapter without exporting private plugin inventories.
$fixture = sys_get_temp_dir() . '/wpconnector-pluginlist-' . getmypid() . '-' . bin2hex(random_bytes(4));
if (! mkdir($fixture . '/wp-admin/includes', 0700, true)
    || false === file_put_contents($fixture . '/wp-admin/includes/plugin.php', "<?php\n")) {
    throw new RuntimeException('Could not build isolated WordPress plugin fixture.');
}
define('ABSPATH', $fixture . '/');
register_shutdown_function(static function () use ($fixture): void {
    @unlink($fixture . '/wp-admin/includes/plugin.php');
    @rmdir($fixture . '/wp-admin/includes');
    @rmdir($fixture . '/wp-admin');
    @rmdir($fixture);
});

$GLOBALS['wpconnector_test_multisite'] = true;
$GLOBALS['wpconnector_test_network_admin'] = false;

function get_plugins(): array
{
    return array(
        'network-inactive/main.php' => array('Name' => 'Inactive network-only', 'Version' => '1.0.0'),
        'network-active/main.php' => array('Name' => 'Active network-only', 'Version' => '1.0.1'),
        'regular/main.php' => array('Name' => 'Regular plugin', 'Version' => '1.0.2'),
        'wordpressconnector/wordpressconnector.php' => array('Name' => 'WordPress Connector', 'Version' => '1.19.9'),
    );
}
function is_multisite(): bool { return $GLOBALS['wpconnector_test_multisite']; }
function is_network_only_plugin(string $file): bool
{
    return in_array($file, array('network-inactive/main.php', 'network-active/main.php'), true);
}
function is_plugin_active(string $file): bool
{
    return in_array($file, array('network-active/main.php', 'regular/main.php', 'wordpressconnector/wordpressconnector.php'), true);
}
function is_plugin_active_for_network(string $file): bool { return 'network-active/main.php' === $file; }
function current_user_can(string $capability): bool
{
    return 'manage_network_plugins' === $capability ? $GLOBALS['wpconnector_test_network_admin'] : true;
}
function get_site_transient(string $name) { return false; }
function wp_is_file_mod_allowed(string $context): bool { return true; }
function sanitize_text_field(string $value): string { return $value; }

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/SystemAdapter.php';
$adapter = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();

$siteItems = $adapter->pluginList()['plugins'];
$siteFiles = array_column($siteItems, 'file');
if ($siteFiles !== array('network-active/main.php', 'regular/main.php', 'wordpressconnector/wordpressconnector.php')) {
    throw new RuntimeException('Site administrator may not see inactive network-only plugins.');
}
if (! $siteItems[0]['active'] || ! $siteItems[0]['network_active'] || $siteItems[0]['can_deactivate']) {
    throw new RuntimeException('Network-active plugin status must remain correct.');
}

$GLOBALS['wpconnector_test_network_admin'] = true;
$networkFiles = array_column($adapter->pluginList()['plugins'], 'file');
if ($networkFiles !== array('network-inactive/main.php', 'network-active/main.php', 'regular/main.php', 'wordpressconnector/wordpressconnector.php')) {
    throw new RuntimeException('Network administrator must see all installed plugins.');
}

$GLOBALS['wpconnector_test_multisite'] = false;
$GLOBALS['wpconnector_test_network_admin'] = false;
$singleFiles = array_column($adapter->pluginList()['plugins'], 'file');
if ($singleFiles !== $networkFiles) {
    throw new RuntimeException('Single-site plugin visibility must remain unchanged.');
}
echo "plugin.list multisite visibility and network permission contract OK\n";
