<?php
/**
 * Plugin Name: WordPress Connector
 * Plugin URI: https://github.com/Yolol100/wordpressconnector
 * Description: Single controlled bridge for WordPress, Elementor, Gutenberg, WooCommerce, ACF, Yoast SEO, media, plugin settings, Additional CSS, bounded filesystem access and administration workflows over authenticated HTTPS REST or local WP-CLI.
 * Version: 1.12.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Webactueel
 * License: GPL-2.0-or-later
 * Text Domain: wordpressconnector
 * Update URI: false
 */
declare(strict_types=1);
namespace Webactueel\WordPressConnector;
if(!defined('ABSPATH')){exit;}
define('WPCONNECTOR_VERSION','1.12.2');define('WPCONNECTOR_FILE',__FILE__);define('WPCONNECTOR_PATH',plugin_dir_path(__FILE__));
$files=array('includes/Support/Json.php','includes/Support/Fingerprint.php','includes/Support/Input.php','includes/Security/Policy.php','includes/Security/FilesystemPolicy.php','includes/Runtime/Request.php','includes/Runtime/Result.php','includes/Runtime/SnapshotStore.php','includes/Runtime/ProcessedStore.php','includes/Runtime/Registry.php','includes/Adapters/DiscoveryAdapter.php','includes/Adapters/AbilitiesAdapter.php','includes/Adapters/CoreAdapter.php','includes/Adapters/GutenbergAdapter.php','includes/Adapters/ElementorCapabilitiesAdapter.php','includes/Adapters/ElementorAdapter.php','includes/Adapters/ElementorFormsAdapter.php','includes/Adapters/WooCommerceAdapter.php','includes/Adapters/AcfAdapter.php','includes/Adapters/YoastAdapter.php','includes/Adapters/MediaAdapter.php','includes/Adapters/AutoImageAttributesAdapter.php','includes/Adapters/PluginSettingsAdapter.php','includes/Adapters/CustomCssAdapter.php','includes/Adapters/FilesystemAdapter.php','includes/Adapters/PluginPackageAdapter.php','includes/Adapters/ConnectorUpdateAdapter.php','includes/Adapters/SystemAdapter.php','includes/Runtime/Runner.php','includes/REST/AssetStore.php','includes/REST/Controller.php','includes/Admin/Settings.php','includes/Admin/ElementorJsonExport.php','includes/Admin/ElementorJsonImport.php','includes/CLI/Command.php','includes/Plugin.php');foreach($files as $file){require_once WPCONNECTOR_PATH.$file;}Plugin::boot();
