<?php

declare(strict_types=1);

// Regression: recent WordPress versions keep wpdb::$dbh protected.
// system.doctor must use the public readiness contract, even when the
// database handle cannot be accessed outside wpdb itself.
if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (! defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', __DIR__);
}

class wpdb
{
    protected $dbh = 'protected-test-handle';
    public $ready = true;
}

function wp_is_writable(string $path): bool { return true; }
function wp_get_upload_dir(): array { return array('basedir' => __DIR__); }
function wp_is_file_mod_allowed(string $context): bool { return $GLOBALS['wpconn_file_mod_allowed'] ?? true; }
function current_user_can(string $capability): bool { return true; }
function is_multisite(): bool { return false; }

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/SystemAdapter.php';

$wpdb = new wpdb();
$wp_version = '7.1-test';
$doctor = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();

$ready = $doctor->doctor();
if (true !== ($ready['database'] ?? null) || true !== ($ready['ok'] ?? null)
    || false !== ($ready['wp_cli'] ?? true)) {
    throw new RuntimeException('Doctor failed with a ready database and unavailable optional WP-CLI.');
}

// WordPress policy filters can deny file changes without DISALLOW_FILE_MODS.
$GLOBALS['wpconn_file_mod_allowed'] = false;
$denied = $doctor->doctor();
if (false !== ($denied['file_modifications_allowed'] ?? null)
    || true !== ($denied['file_modifications_disabled'] ?? null)
    || true !== ($denied['database'] ?? null)
    || true !== ($denied['ok'] ?? null)) {
    throw new RuntimeException('Doctor must honor WordPress file modification policy filters.');
}
$GLOBALS['wpconn_file_mod_allowed'] = true;
$permitted = $doctor->doctor();
if (true !== ($permitted['file_modifications_allowed'] ?? null)
    || false !== ($permitted['file_modifications_disabled'] ?? null)) {
    throw new RuntimeException('Doctor must report permitted file modifications consistently.');
}

$wpdb->ready = false;
$unready = $doctor->doctor();
if (false !== ($unready['database'] ?? null) || false !== ($unready['ok'] ?? null)) {
    throw new RuntimeException('Doctor must fail closed when the public database readiness flag is false.');
}

$wpdb = null;
$missing = $doctor->doctor();
if (false !== ($missing['database'] ?? null) || false !== ($missing['ok'] ?? null)) {
    throw new RuntimeException('Doctor must tolerate missing WordPress DB services.');
}

echo "system.doctor public database readiness and fail-closed regression OK\n";
