<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$mustContain = array(
    'plugin/wordpressconnector/wordpressconnector.php' => array('Version: 1.11.0', 'Requires at least: 6.4', 'includes/Adapters/AutoImageAttributesAdapter.php', 'includes/Adapters/PluginSettingsAdapter.php', 'includes/Adapters/FilesystemAdapter.php', 'includes/Adapters/PluginPackageAdapter.php'),
    'plugin/wordpressconnector/includes/Plugin.php' => array('new AutoImageAttributesAdapter()', 'new PluginSettingsAdapter()', 'new FilesystemAdapter()', 'new PluginPackageAdapter()'),
    'plugin/wordpressconnector/includes/Adapters/PluginSettingsAdapter.php' => array('plugin.settings.catalog', 'plugin.settings.inspect', 'plugin.settings.update', 'update_rocket_option', 'imagify/get-settings', 'imagify/update-settings', 'rsssl_get_option', 'rsssl_update_option', 'WPCONNECTOR_ALLOW_SENSITIVE', 'blocked_arbitrary_code', 'shared_filesystem_interface', 'bounded_filesystem_bridge'),
    'plugin/wordpressconnector/includes/Adapters/AutoImageAttributesAdapter.php' => array('auto_image_attributes.inspect', 'auto_image_attributes.update', 'iaff_settings', '_current_fingerprint', '_rollback'),
    'plugin/wordpressconnector/includes/Adapters/YoastAdapter.php' => array('focus_keyphrase', 'robots_advanced', 'cornerstone', 'schema_page_type', 'schema_article_type', 'primary_category_term_id', 'opengraph_image', 'twitter_image', '_current_fingerprint', '_rollback'),
    'docs/PLUGIN-CONTROL.md' => array('Broken Link Checker', 'Wordfence Security', 'WP Mail SMTP', 'Code Snippets', 'WP File Manager', 'WP File Manager and controlled filesystem access'),
);
foreach ($mustContain as $relative => $needles) {
    $source = file_get_contents($root . '/' . $relative);
    if ($source === false) { fwrite(STDERR, "Unable to read {$relative}.\n"); exit(1); }
    foreach ($needles as $needle) { if (strpos($source, $needle) === false) { fwrite(STDERR, "Missing plugin-control contract fragment in {$relative}: {$needle}\n"); exit(1); } }
}
$settingsAdapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/PluginSettingsAdapter.php');
$autoImageAdapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/AutoImageAttributesAdapter.php');
if ($settingsAdapter === false || $autoImageAdapter === false) { fwrite(STDERR, "Unable to read plugin settings adapters.\n"); exit(1); }
foreach (array('eval(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen(') as $forbidden) { if (strpos($settingsAdapter, $forbidden) !== false || strpos($autoImageAdapter, $forbidden) !== false) { fwrite(STDERR, "Forbidden execution primitive in plugin settings bridge: {$forbidden}\n"); exit(1); } }
if (strpos($settingsAdapter, "unset(\$settings['api_key']") === false) { fwrite(STDERR, "Imagify API-key redaction contract missing.\n"); exit(1); }
if (strpos($settingsAdapter, "'arbitrary_filesystem' => false") === false) { fwrite(STDERR, "Unrestricted filesystem boundary is missing from plugin catalog.\n"); exit(1); }
echo "plugin control contract OK\n";
