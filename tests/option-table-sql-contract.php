<?php

declare(strict_types=1);

// Exercise real lifecycle code with a WordPress-style wpdb SQL prepare stub.
// Identifiers must be supplied as %i, not interpolated in SQL strings.
final class WpcOptionTableSqlStub
{
    public $options = 'wp9_options';
    public $lastLike = '';
    public $prepareCalls = 0;
    public $forceNullResults = false;

    public function esc_like($value)
    {
        return addcslashes((string) $value, '_%\\');
    }

    public function prepare($query, ...$args)
    {
        if (false === strpos($query, 'FROM %i WHERE option_name LIKE %s')
            || count($args) !== 2 || $args[0] !== $this->options) {
            throw new RuntimeException('Option table identifier was not prepared with %i.');
        }
        ++$this->prepareCalls;
        $this->lastLike = $args[1];
        return str_replace('%i', '`' . $this->options . '`', $query);
    }

    public function get_results($query)
    {
        if ($this->forceNullResults) {
            return null;
        }
        $kind = false !== strpos($this->lastLike, 'snapshot') ? 'snapshot' : 'processed';
        return array(
            (object) array('option_name' => 'wpconnector_' . $kind . '_expired', 'option_value' => serialize(array('created_at' => time() - 3600))),
            (object) array('option_name' => 'wpconnector_' . $kind . '_fresh', 'option_value' => serialize(array('created_at' => time()))),
            (object) array('option_name' => 'wpconnector_' . $kind . '_invalid', 'option_value' => serialize(array('no_created_at' => true))),
        );
    }

    public function get_col($query)
    {
        $prefix = str_replace(array('\\_', '\\%', '%'), array('_', '%', ''), $this->lastLike);
        return array($prefix . 'expired', $prefix . 'fresh', 'unrelated_site_option');
    }
}

function maybe_unserialize($value)
{
    return unserialize((string) $value, array('allowed_classes' => false));
}

$GLOBALS['wpc_deleted_options'] = array();
$GLOBALS['wpc_deleted_transients'] = array();

function delete_option($name)
{
    $GLOBALS['wpc_deleted_options'][] = $name;
    return true;
}

function delete_transient($name)
{
    $GLOBALS['wpc_deleted_transients'][] = $name;
    return true;
}

function is_multisite()
{
    return false;
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/SnapshotStore.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/ProcessedStore.php';

$wpdb = new WpcOptionTableSqlStub();
$snapshot = new \Webactueel\WordPressConnector\Runtime\SnapshotStore();
$processed = new \Webactueel\WordPressConnector\Runtime\ProcessedStore();

if (1 !== $snapshot->cleanup(120) || 1 !== $processed->cleanup(120)) {
    throw new RuntimeException('Cleanup must remove one expired record from each store.');
}

if ($GLOBALS['wpc_deleted_options'] !== array('wpconnector_snapshot_expired', 'wpconnector_processed_expired')
    || $wpdb->prepareCalls !== 2) {
    throw new RuntimeException('Cleanup deleted fresh/invalid rows or skipped prepared table identifiers.');
}

define('WP_UNINSTALL_PLUGIN', 'wordpressconnector/wordpressconnector.php');
require dirname(__DIR__) . '/plugin/wordpressconnector/uninstall.php';

if (6 !== $wpdb->prepareCalls || $GLOBALS['wpc_deleted_transients'] !== array('wpconnector_github_oidc_jwks_v1')
    || in_array('unrelated_site_option', $GLOBALS['wpc_deleted_options'], true)) {
    throw new RuntimeException('Uninstall must prepare all option-table identifiers and preserve unrelated options.');
}

$wpdb->forceNullResults = true;
$beforeUnavailable = count($GLOBALS['wpc_deleted_options']);
set_error_handler(static function (int $severity, string $message): void {
    throw new ErrorException($message, 0, $severity);
});
try {
    if (0 !== $snapshot->cleanup(120) || 0 !== $processed->cleanup(120)
        || count($GLOBALS['wpc_deleted_options']) !== $beforeUnavailable) {
        throw new RuntimeException('Unavailable WordPress DB must prevent cleanup without warnings or deletion.');
    }
} finally {
    restore_error_handler();
}

echo "WordPress options SQL identifier preparation, expiration, and unavailable DB handling OK\n";
