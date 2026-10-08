<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/wpconnector-upgrade-unit-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('WP_PLUGIN_DIR', $root . '/plugins');
define('WP_CONTENT_DIR', $root . '/content');
mkdir(WP_PLUGIN_DIR . '/wordpressconnector', 0700, true);
mkdir(WP_CONTENT_DIR, 0700, true);
file_put_contents(WP_PLUGIN_DIR . '/wordpressconnector/wordpressconnector.php', '<?php // original test version');

$GLOBALS['fake_fs_mode'] = 'direct';
$GLOBALS['fake_result'] = array('destination_name' => 'wordpressconnector');
$GLOBALS['fake_run_count'] = 0;
$GLOBALS['fake_version'] = '9.9.9';
$GLOBALS['fake_old_version'] = '1.17.6';
$GLOBALS['fake_backup_exists'] = true;
$GLOBALS['fake_restore_success'] = true;
$GLOBALS['fake_restored'] = false;
$GLOBALS['filters_added'] = array();
$GLOBALS['filters_removed'] = array();

class WP_Error {}
class Automatic_Upgrader_Skin {}
class Plugin_Upgrader
{
    public function __construct($skin) {}
    public function init(): void {}
    public function upgrade_strings(): void {}
    public function check_package() { return true; }
    public function deactivate_plugin_before_upgrade() { return true; }
    public function active_before() { return true; }
    public function active_after() { return true; }
    public function run(array $options) {
        ++$GLOBALS['fake_run_count'];
        $GLOBALS['fake_run_options'] = $options;
        return $GLOBALS['fake_result'];
    }
    public function restore_temp_backup(array $backups) {
        $GLOBALS['fake_restored'] = $backups;
        if (! $GLOBALS['fake_restore_success']) {
            return new WP_Error();
        }
        $GLOBALS['fake_version'] = $GLOBALS['fake_old_version'];
        return true;
    }
}
function get_filesystem_method(): string { return $GLOBALS['fake_fs_mode']; }
function add_filter($name, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['filters_added'][] = $name;
    return true;
}
function remove_filter($name, $callback, $priority = 10) {
    $GLOBALS['filters_removed'][] = $name;
    return true;
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function trailingslashit($path): string { return rtrim($path, '/') . '/'; }
function wp_clean_plugins_cache($force = true): void {}
function get_plugins(): array {
    return array('wordpressconnector/wordpressconnector.php' => array('Version' => $GLOBALS['fake_version']));
}

class FakeFilesystem
{
    public function is_dir($path): bool {
        return $GLOBALS['fake_backup_exists'] && false !== strpos($path, 'upgrade-temp-backup/plugins/wordpressconnector');
    }
}
$GLOBALS['wp_filesystem'] = new FakeFilesystem();

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php';
$updater = new \Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter();
$install = new ReflectionMethod($updater, 'installWithCoreBackup');
$install->setAccessible(true);
$restore = new ReflectionMethod($updater, 'restoreAfterReadbackFailure');
$restore->setAccessible(true);

$instance = $install->invoke($updater, '/tmp/already-verified-package.zip');
$args = $GLOBALS['fake_run_options'];
if (! is_array($instance)
    || $GLOBALS['fake_run_count'] !== 1
    || ($args['abort_if_destination_exists'] ?? true) !== false
    || empty($args['clear_destination'])
    || ($args['hook_extra']['temp_backup']['slug'] ?? null) !== 'wordpressconnector'
    || ($args['hook_extra']['temp_backup']['src'] ?? null) !== WP_PLUGIN_DIR
    || ($args['hook_extra']['temp_backup']['dir'] ?? null) !== 'plugins'
    || count($GLOBALS['filters_added']) !== 4
    || count($GLOBALS['filters_removed']) !== 4) {
    throw new RuntimeException('Safe updater did not request WordPress Core backup or clean up its hook scope.');
}
$restore->invoke($updater, $instance, '1.17.6');
if (! $GLOBALS['fake_restored'] || $GLOBALS['fake_version'] !== '1.17.6') {
    throw new RuntimeException('Readback mismatch did not restore the prior version.');
}

$expectFailure = static function (callable $callback, string $needle): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (false === strpos($error->getMessage(), $needle)) {
            throw new RuntimeException('Wrong updater failure: ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected updater protection failure: ' . $needle);
};
$GLOBALS['fake_fs_mode'] = 'ftpext';
$start = $GLOBALS['fake_run_count'];
$expectFailure(static function () use ($install, $updater): void {
    $install->invoke($updater, '/tmp/already-verified-package.zip');
}, 'direct filesystem mode');
if ($GLOBALS['fake_run_count'] !== $start) {
    throw new RuntimeException('Unsafe filesystem mode attempted a write.');
}
$GLOBALS['fake_fs_mode'] = 'direct';
$GLOBALS['fake_result'] = false;
$expectFailure(static function () use ($install, $updater): void {
    $install->invoke($updater, '/tmp/already-verified-package.zip');
}, 'verify WordPress Core backup recovery');

$GLOBALS['fake_result'] = array('destination_name' => 'wordpressconnector');
$GLOBALS['fake_backup_exists'] = false;
$GLOBALS['fake_restored'] = false;
$expectFailure(static function () use ($restore, $updater, $instance): void {
    $restore->invoke($updater, $instance, '1.17.6');
}, 'no recoverable temporary backup');
if ($GLOBALS['fake_restored']) {
    throw new RuntimeException('Missing backup must not trigger destructive restore.');
}
$GLOBALS['fake_backup_exists'] = true;
$GLOBALS['fake_restore_success'] = false;
$GLOBALS['fake_version'] = '9.9.9';
$expectFailure(static function () use ($restore, $updater, $instance): void {
    $restore->invoke($updater, $instance, '1.17.6');
}, 'automatic recovery was not verified');

// Remove the isolated fixture.
unlink(WP_PLUGIN_DIR . '/wordpressconnector/wordpressconnector.php');
rmdir(WP_PLUGIN_DIR . '/wordpressconnector');
rmdir(WP_PLUGIN_DIR);
rmdir(WP_CONTENT_DIR);
rmdir($root);
echo "Core temporary update backup, filesystem gating, hook lifecycle and restore faults OK\n";
