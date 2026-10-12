<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/wpc-plugin-catalog-contract-' . getmypid();
if (! is_dir($root . '/wp-admin/includes') && ! mkdir($root . '/wp-admin/includes', 0700, true)) {
    throw new RuntimeException('Could not set up WordPress plugin catalog fixture.');
}
file_put_contents($root . '/wp-admin/includes/plugin.php', "<?php\n");
define('ABSPATH', $root . '/');
function get_plugins(): array
{
    return array(
        'code-snippets/code-snippets.php' => array('Name' => 'Code Snippets', 'Version' => '3.10.2'),
        'unknown-plugin/unknown.php' => array('Name' => 'Unknown Plugin', 'Version' => '9.9.9'),
    );
}
function is_plugin_active($name): bool { return true; }
function sanitize_key($name): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $name)); }

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/PluginSettingsAdapter.php';

$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
foreach (array('code_snippets.patch', 'code_snippets.catalog_only.inspect') as $action) {
    $registry->register($action, static function (): array { return array(); });
}
$adapter = new \Webactueel\WordPressConnector\Adapters\PluginSettingsAdapter();
$result = $adapter->catalog(array(), array('registry' => $registry));
$plugins = array();
foreach ($result['plugins'] as $item) { $plugins[$item['id']] = $item; }

$code = $plugins['code_snippets'] ?? array();
if (($code['registered_action_status'] ?? null) !== 'partial' ||
    ! in_array('code_snippets.catalog_only.ensure', $code['missing_registered_actions'] ?? array(), true) ||
    ! in_array('code_snippets.catalog_only.undo', $code['missing_registered_actions'] ?? array(), true)) {
    throw new RuntimeException('Missing plugin adapters were incorrectly declared ready.');
}
$unknown = $plugins['unknown-plugin'] ?? array();
if (($unknown['mode'] ?? null) !== 'unmodeled' ||
    ($unknown['registered_action_status'] ?? null) !== 'not_modeled') {
    throw new RuntimeException('Unmodeled plugin was incorrectly advertised as supported.');
}
$registry->register('code_snippets.catalog_only.ensure', static function (): array { return array(); });
$registry->register('code_snippets.catalog_only.undo', static function (): array { return array(); });
$result = $adapter->catalog(array(), array('registry' => $registry));
foreach ($result['plugins'] as $item) {
    if ('code_snippets' === $item['id'] &&
        ($item['registered_action_status'] !== 'complete' || ! empty($item['missing_registered_actions']))) {
        throw new RuntimeException('Registered built-in Code Snippets capabilities were not recognized.');
    }
}
$legacy = $adapter->catalog(array(), array());
foreach ($legacy['plugins'] as $item) {
    if (array_key_exists('registered_action_status', $item)) {
        throw new RuntimeException('Missing registry context must not fabricate compatibility status.');
    }
}
@unlink($root . '/wp-admin/includes/plugin.php');
@rmdir($root . '/wp-admin/includes');
@rmdir($root . '/wp-admin');
@rmdir($root);

echo "Plugin adapter capability contract OK\n";
