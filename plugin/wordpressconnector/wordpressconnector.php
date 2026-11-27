<?php
/**
 * Plugin Name: WordPress Connector
 * Plugin URI: https://github.com/Yolol100/wordpressconnector
 * Description: Connect WordPress to the trusted Yolol100 GitHub workflow when an administrator activates this plugin. Manage WordPress, Elementor, WooCommerce and SEO via authenticated REST/MCP; signed releases appear in Plugins as native updates.
 * Version: 1.20.18
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Webactueel
 * License: GPL-2.0-or-later
 * Text Domain: wordpressconnector
 * Update URI: https://github.com/Yolol100/wordpressconnector
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'WPCONNECTOR_VERSION', '1.20.18' );
define( 'WPCONNECTOR_FILE', __FILE__ );
define( 'WPCONNECTOR_PATH', plugin_dir_path( __FILE__ ) );
$wpconnector_files = array(
	'includes/Support/Json.php',
	'includes/Support/class-fingerprint.php',
	'includes/Support/Input.php',
	'includes/Security/Policy.php',
	'includes/Security/class-filesystempolicy.php',
	'includes/Security/SitePairing.php',
	'includes/Security/GitHubOidc.php',
	'includes/Security/class-nativeoauthserver.php',
	'includes/Security/OAuthResourceServer.php',
	'includes/Security/ReleaseApproval.php',
	'includes/Runtime/class-request.php',
	'includes/Runtime/class-result.php',
	'includes/Runtime/class-diagnostics.php',
	'includes/Runtime/SnapshotStore.php',
	'includes/Runtime/ProcessedStore.php',
	'includes/Runtime/class-registry.php',
	'includes/Adapters/DiscoveryAdapter.php',
	'includes/Adapters/class-connectionreadinessadapter.php',
	'includes/Adapters/AbilitiesAdapter.php',
	'includes/Adapters/class-coreadapter.php',
	'includes/Adapters/class-gutenbergadapter.php',
	'includes/Adapters/class-siteeditoradapter.php',
	'includes/Adapters/ElementorCapabilitiesAdapter.php',
	'includes/Adapters/ElementorAdapter.php',
	'includes/Adapters/ElementorFormsAdapter.php',
	'includes/Adapters/WooCommerceAdapter.php',
	'includes/Adapters/WooCommerceSettingsAdapter.php',
	'includes/Adapters/class-woocommerceoperationsadapter.php',
	'includes/Adapters/WooCommerceFulfillmentAdapter.php',
	'includes/Adapters/AcfAdapter.php',
	'includes/Adapters/PortfolioStatsAdapter.php',
	'includes/Adapters/YoastAdapter.php',
	'includes/Adapters/class-yoastsnippetadapter.php',
	'includes/Adapters/class-yoasttemplatesettingsadapter.php',
	'includes/Adapters/class-yoastfeaturesettingsadapter.php',
	'includes/Adapters/class-yoastnativeabilitiesadapter.php',
	'includes/Adapters/MediaAdapter.php',
	'includes/Adapters/class-autoimageattributesadapter.php',
	'includes/Adapters/class-gtranslatesettingsadapter.php',
	'includes/Adapters/class-optimizersettingsadapter.php',
	'includes/Adapters/class-pluginsettingsadapter.php',
	'includes/Adapters/CacheMaintenanceAdapter.php',
	'includes/Adapters/CodeSnippetsAdapter.php',
	'includes/Adapters/class-customcssadapter.php',
	'includes/Adapters/FilesystemAdapter.php',
	'includes/Adapters/class-pluginpackageadapter.php',
	'includes/Adapters/ConnectorUpdateAdapter.php',
	'includes/Adapters/class-siteidentityadapter.php',
	'includes/Adapters/SystemAdapter.php',
	'includes/Runtime/Runner.php',
	'includes/REST/AssetStore.php',
	'includes/REST/Controller.php',
	'includes/MCP/Controller.php',
	'includes/Admin/class-settings.php',
	'includes/Admin/ElementorJsonExport.php',
	'includes/Admin/ElementorJsonImport.php',
	'includes/CLI/Command.php',
	'includes/class-plugin.php',
);
foreach ( $wpconnector_files as $wpconnector_file ) {
	require_once WPCONNECTOR_PATH . $wpconnector_file;
}
Plugin::boot();
register_activation_hook(
	WPCONNECTOR_FILE,
	static function ( $network_wide = false ): void {
		\Webactueel\WordPressConnector\Security\SitePairing::approveOnActivation( (bool) $network_wide );
	}
);
