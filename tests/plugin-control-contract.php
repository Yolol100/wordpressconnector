<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$mustContain = array(
    'plugin/wordpressconnector/wordpressconnector.php' => array('Requires at least: 6.4', 'includes/Adapters/class-autoimageattributesadapter.php', 'includes/Adapters/class-gtranslatesettingsadapter.php', 'includes/Adapters/class-optimizersettingsadapter.php', 'includes/Adapters/class-yoasttemplatesettingsadapter.php', 'includes/Adapters/class-woocommercesettingsadapter.php', 'includes/Adapters/class-woocommerceoperationsadapter.php', 'includes/Adapters/class-woocommercefulfillmentadapter.php', 'includes/Adapters/class-pluginsettingsadapter.php', 'includes/Adapters/CodeSnippetsAdapter.php', 'includes/Adapters/FilesystemAdapter.php', 'includes/Adapters/class-pluginpackageadapter.php', 'includes/Adapters/ConnectorUpdateAdapter.php'),
    'plugin/wordpressconnector/includes/class-plugin.php' => array('new AutoImageAttributesAdapter()', 'new GTranslateSettingsAdapter()', 'new OptimizerSettingsAdapter()', 'new YoastTemplateSettingsAdapter()', 'new WooCommerceSettingsAdapter()', 'new WooCommerceOperationsAdapter()', 'new WooCommerceFulfillmentAdapter()', 'new PluginSettingsAdapter()', 'new CodeSnippetsAdapter()', 'new FilesystemAdapter()', 'new PluginPackageAdapter()', 'new ConnectorUpdateAdapter()'),
    'plugin/wordpressconnector/includes/Adapters/class-pluginsettingsadapter.php' => array('plugin.settings.catalog', 'plugin.settings.inspect', 'plugin.settings.update', 'yoast.templates.inspect', 'yoast.templates.update', 'update_rocket_option', 'imagify/get-settings', 'imagify/update-settings', 'rsssl_get_option', 'rsssl_update_option', 'WPCONNECTOR_ALLOW_SENSITIVE', 'bounded_exact_patch', 'code_snippets.patch', 'shared_filesystem_interface', 'bounded_filesystem_bridge', 'private function read_joinchat', 'supported_setting_profiles', 'woocommerce/woocommerce.php', 'mollie-payments-for-woocommerce/mollie-payments-for-woocommerce.php', 'ewww-image-optimizer/ewww-image-optimizer.php', 'all-in-one-wp-security-and-firewall/wp-security.php', 'broken-link-checker-seo/aioseo-broken-link-checker.php', 'external_account_bound', 'native_risk_gated'),
    'plugin/wordpressconnector/includes/Adapters/class-optimizersettingsadapter.php' => array('optimizer.settings.inspect', 'optimizer.settings.update', 'optimizer.settings.restore', '_current_fingerprint', '_rollback'),
    'plugin/wordpressconnector/includes/Adapters/class-gtranslatesettingsadapter.php' => array('gtranslate.settings.inspect', 'gtranslate.settings.update', 'gtranslate.settings.restore', '_current_fingerprint', '_rollback'),
    'plugin/wordpressconnector/includes/Adapters/class-autoimageattributesadapter.php' => array('auto_image_attributes.inspect', 'auto_image_attributes.update', 'iaff_settings', '_current_fingerprint', '_rollback'),
    'plugin/wordpressconnector/includes/Adapters/class-woocommercefulfillmentadapter.php' => array('woocommerce.fulfillment.locations.inspect', 'woocommerce.fulfillment.locations.update', 'woocommerce.fulfillment.create', '_current_fingerprint', '_rollback'),
    'plugin/wordpressconnector/includes/Adapters/class-woocommerceoperationsadapter.php' => array('woocommerce.operations.list', 'woocommerce.operations.inspect', 'woocommerce.operations.update', 'woocommerce.operations.restore', '_current_fingerprint', '_rollback'),
    'plugin/wordpressconnector/includes/Adapters/class-woocommercesettingsadapter.php' => array('woocommerce.settings.catalog', 'woocommerce.settings.inspect', 'woocommerce.settings.section.inspect', 'woocommerce.settings.update', 'woocommerce.settings.restore', '_current_fingerprint', '_rollback'),
    'plugin/wordpressconnector/includes/Adapters/class-yoasttemplatesettingsadapter.php' => array('yoast.templates.inspect', 'yoast.templates.update', 'yoast.templates.restore', '_current_fingerprint', '_rollback'),
    'plugin/wordpressconnector/includes/Adapters/class-yoastadapter.php' => array('focus_keyphrase', 'robots_advanced', 'cornerstone', 'schema_page_type', 'schema_article_type', 'primary_category_term_id', 'opengraph_image', 'twitter_image', 'yoast.site_representation.inspect', 'yoast.site_representation.update', 'yoast.site_representation.restore', '_current_fingerprint', '_rollback'),
    'docs/PLUGIN-CONTROL.md' => array('Broken Link Checker', 'Wordfence Security', 'WP Mail SMTP', 'Code Snippets', 'WP File Manager', 'WP File Manager and controlled filesystem access'),
);
foreach ($mustContain as $relative => $needles) {
    $source = file_get_contents($root . '/' . $relative);
    if ($source === false) { fwrite(STDERR, "Unable to read {$relative}.\n"); exit(1); }
    $implementationPaths = array(
        'plugin/wordpressconnector/includes/Adapters/class-woocommercesettingsadapter.php' => 'plugin/wordpressconnector/includes/Adapters/trait-woocommercesettingsadapteractions.php',
        'plugin/wordpressconnector/includes/Adapters/class-woocommercefulfillmentadapter.php' => 'plugin/wordpressconnector/includes/Adapters/trait-woocommercefulfillmentadapteractions.php',
        'plugin/wordpressconnector/includes/Adapters/class-yoastadapter.php' => 'plugin/wordpressconnector/includes/Adapters/trait-yoastadapteractions.php',
    );
    if (isset($implementationPaths[$relative])) {
        $implementation = file_get_contents($root . '/' . $implementationPaths[$relative]);
        if ($implementation === false) { throw new RuntimeException('Missing canonical implementation: ' . $relative); }
        $source .= $implementation;
    }
    foreach ($needles as $needle) { if (strpos($source, $needle) === false) { fwrite(STDERR, "Missing plugin-control contract fragment in {$relative}: {$needle}\n"); exit(1); } }
}
$bootstrap = file_get_contents($root . '/plugin/wordpressconnector/wordpressconnector.php');
$readme = file_get_contents($root . '/plugin/wordpressconnector/readme.txt');
if (! is_string($bootstrap) || ! is_string($readme) || ! preg_match('/^[ \t*#\/]*Version:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)/mi', $bootstrap, $version) || ! preg_match('/^Stable tag:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)/mi', $readme, $stable) || $version[1] !== $stable[1]) {
    fwrite(STDERR, "Plugin release metadata is not aligned.\n");
    exit(1);
}
$settingsAdapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/class-pluginsettingsadapter.php');
$autoImageAdapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/class-autoimageattributesadapter.php');
if ($settingsAdapter === false || $autoImageAdapter === false) { fwrite(STDERR, "Unable to read plugin settings adapters.\n"); exit(1); }
$normalizedSettingsAdapter = preg_replace('/\\s+/', '', $settingsAdapter);
if (! is_string($normalizedSettingsAdapter)) { throw new RuntimeException('Could not normalize plugin settings source.'); }
foreach (array('eval(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen(') as $forbidden) { if (strpos($settingsAdapter, $forbidden) !== false || strpos($autoImageAdapter, $forbidden) !== false) { fwrite(STDERR, "Forbidden execution primitive in plugin settings bridge: {$forbidden}\n"); exit(1); } }
if (strpos($normalizedSettingsAdapter, "unset(\$settings['api_key']") === false) { fwrite(STDERR, "Imagify API-key redaction contract missing.\n"); exit(1); }
if (strpos($normalizedSettingsAdapter, "'arbitrary_filesystem'=>false") === false) { fwrite(STDERR, "Unrestricted filesystem boundary is missing from plugin catalog.\n"); exit(1); }
echo "plugin control contract OK\n";
