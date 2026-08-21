<?php
/**
 * Plugin Name: WordPress Connector
 * Plugin URI: https://github.com/Yolol100/wordpressconnector
 * Description: GitHub-controlled, WP-CLI-only bridge for auditable WordPress content and administration workflows.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Webactueel
 * License: GPL-2.0-or-later
 * Text Domain: wordpressconnector
 * Update URI: false
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector;

if (! defined('ABSPATH')) {
    exit;
}

define('WPCONNECTOR_VERSION', '1.0.0');
define('WPCONNECTOR_FILE', __FILE__);
define('WPCONNECTOR_PATH', plugin_dir_path(__FILE__));

$files = array(
    'includes/Support/Json.php',
    'includes/Support/Fingerprint.php',
    'includes/Security/Policy.php',
    'includes/Runtime/Request.php',
    'includes/Runtime/Result.php',
    'includes/Runtime/SnapshotStore.php',
    'includes/Runtime/ProcessedStore.php',
    'includes/Runtime/Registry.php',
    'includes/Adapters/DiscoveryAdapter.php',
    'includes/Adapters/CoreAdapter.php',
    'includes/Adapters/GutenbergAdapter.php',
    'includes/Adapters/ElementorAdapter.php',
    'includes/Adapters/WooCommerceAdapter.php',
    'includes/Adapters/AcfAdapter.php',
    'includes/Adapters/MediaAdapter.php',
    'includes/Adapters/SystemAdapter.php',
    'includes/Runtime/Runner.php',
    'includes/CLI/Command.php',
    'includes/Plugin.php',
);

foreach ($files as $file) {
    require_once WPCONNECTOR_PATH . $file;
}

Plugin::boot();
