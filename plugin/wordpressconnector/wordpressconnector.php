<?php
/**
 * Plugin Name: WordPress Connector
 * Plugin URI: https://github.com/Yolol100/wordpressconnector
 * Description: Connect WordPress to the trusted Yolol100 GitHub workflow when an administrator activates this plugin. Manage WordPress, Elementor, WooCommerce and SEO via authenticated REST/MCP; signed releases appear in Plugins as native updates.
 * Version: 1.19.20
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Webactueel
 * License: GPL-2.0-or-later
 * Text Domain: wordpressconnector
 * Update URI: https://github.com/Yolol100/wordpressconnector
 */
declare(strict_types=1);
namespace Webactueel\WordPressConnector;
if(!defined('ABSPATH')){exit;}
define('WPCONNECTOR_VERSION','1.19.20');define('WPCONNECTOR_FILE',__FILE__);define('WPCONNECTOR_PATH',plugin_dir_path(__FILE__));
$files=array('includes/Support/Json.php','includes/Support/Fingerprint.php','includes/Support/Input.php','includes/Security/Policy.php','includes/Security/FilesystemPolicy.php','includes/Security/SitePairing.php','includes/Security/GitHubOidc.php','includes/Runtime/Request.php','includes/Runtime/Result.php','includes/Runtime/Diagnostics.php','includes/Runtime/SnapshotStore.php','includes/Runtime/ProcessedStore.php','includes/Runtime/Registry.php','includes/Adapters/DiscoveryAdapter.php','includes/Adapters/ConnectionReadinessAdapter.php','includes/Adapters/AbilitiesAdapter.php','includes/Adapters/CoreAdapter.php','includes/Adapters/GutenbergAdapter.php','includes/Adapters/SiteEditorAdapter.php','includes/Adapters/ElementorCapabilitiesAdapter.php','includes/Adapters/ElementorAdapter.php','includes/Adapters/ElementorFormsAdapter.php','includes/Adapters/WooCommerceAdapter.php','includes/Adapters/WooCommerceSettingsAdapter.php','includes/Adapters/WooCommerceOperationsAdapter.php','includes/Adapters/WooCommerceFulfillmentAdapter.php','includes/Adapters/AcfAdapter.php','includes/Adapters/PortfolioStatsAdapter.php','includes/Adapters/YoastAdapter.php','includes/Adapters/YoastSnippetAdapter.php','includes/Adapters/YoastTemplateSettingsAdapter.php','includes/Adapters/YoastFeatureSettingsAdapter.php','includes/Adapters/YoastNativeAbilitiesAdapter.php','includes/Adapters/MediaAdapter.php','includes/Adapters/AutoImageAttributesAdapter.php','includes/Adapters/GTranslateSettingsAdapter.php','includes/Adapters/OptimizerSettingsAdapter.php','includes/Adapters/PluginSettingsAdapter.php','includes/Adapters/CacheMaintenanceAdapter.php','includes/Adapters/CodeSnippetsAdapter.php','includes/Adapters/CustomCssAdapter.php','includes/Adapters/FilesystemAdapter.php','includes/Adapters/PluginPackageAdapter.php','includes/Adapters/ConnectorUpdateAdapter.php','includes/Adapters/SiteIdentityAdapter.php','includes/Adapters/SystemAdapter.php','includes/Runtime/Runner.php','includes/REST/AssetStore.php','includes/REST/Controller.php','includes/MCP/Controller.php','includes/Admin/Settings.php','includes/Admin/ElementorJsonExport.php','includes/Admin/ElementorJsonImport.php','includes/CLI/Command.php','includes/Plugin.php');foreach($files as $file){require_once WPCONNECTOR_PATH.$file;}Plugin::boot();
// Standard wp-admin activation uses WordPress Core's existing approval and
// immediately authorizes the pinned GitHub runtime for this exact website.
register_activation_hook(WPCONNECTOR_FILE, static function ($networkWide = false): void {
    \Webactueel\WordPressConnector\Security\SitePairing::approveOnActivation((bool) $networkWide);
});
