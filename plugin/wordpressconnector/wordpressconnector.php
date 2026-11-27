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
	'includes/Support/class-json.php',
	'includes/Support/class-fingerprint.php',
	'includes/Support/class-input.php',
	'includes/Security/class-policy.php',
	'includes/Security/class-filesystempolicy.php',
	'includes/Security/class-sitepairing.php',
	'includes/Security/class-githuboidc.php',
	'includes/Security/class-nativeoauthserver.php',
	'includes/Security/class-oauthresourceserver.php',
	'includes/Security/class-releaseapproval.php',
	'includes/Runtime/class-request.php',
	'includes/Runtime/class-result.php',
	'includes/Runtime/class-diagnostics.php',
	'includes/Runtime/class-snapshotstore.php',
	'includes/Runtime/class-processedstore.php',
	'includes/Runtime/class-registry.php',
	'includes/Adapters/class-discoveryadapter.php',
	'includes/Adapters/class-connectionreadinessadapter.php',
	'includes/Adapters/class-abilitiesadapter.php',
	'includes/Adapters/class-coreadapter.php',
	'includes/Adapters/class-gutenbergadapter.php',
	'includes/Adapters/class-siteeditoradapter.php',
	'includes/Adapters/class-elementorcapabilitiesadapter.php',
	'includes/Adapters/class-elementoradapter.php',
	'includes/Adapters/class-elementorformsadapter.php',
	'includes/Adapters/class-woocommerceadapter.php',
	'includes/Adapters/class-woocommercesettingsadapter.php',
	'includes/Adapters/class-woocommerceoperationsadapter.php',
	'includes/Adapters/class-woocommercefulfillmentadapter.php',
	'includes/Adapters/class-acfadapter.php',
	'includes/Adapters/class-portfoliostatsadapter.php',
	'includes/Adapters/class-yoastadapter.php',
	'includes/Adapters/class-yoastsnippetadapter.php',
	'includes/Adapters/class-yoasttemplatesettingsadapter.php',
	'includes/Adapters/class-yoastfeaturesettingsadapter.php',
	'includes/Adapters/class-yoastnativeabilitiesadapter.php',
	'includes/Adapters/class-mediaadapter.php',
	'includes/Adapters/class-autoimageattributesadapter.php',
	'includes/Adapters/class-gtranslatesettingsadapter.php',
	'includes/Adapters/class-optimizersettingsadapter.php',
	'includes/Adapters/class-pluginsettingsadapter.php',
	'includes/Adapters/class-cachemaintenanceadapter.php',
	'includes/Adapters/class-codesnippetsadapter.php',
	'includes/Adapters/class-customcssadapter.php',
	'includes/Adapters/class-filesystemadapter.php',
	'includes/Adapters/class-pluginpackageadapter.php',
	'includes/Adapters/class-connectorupdateadapter.php',
	'includes/Adapters/class-siteidentityadapter.php',
	'includes/Adapters/class-systemadapter.php',
	'includes/Runtime/class-runner.php',
	'includes/REST/class-assetstore.php',
	'includes/REST/class-controller.php',
	'includes/MCP/class-controller.php',
	'includes/Admin/class-settings.php',
	'includes/Admin/class-elementorjsonexport.php',
	'includes/Admin/class-elementorjsonimport.php',
	'includes/CLI/class-command.php',
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
