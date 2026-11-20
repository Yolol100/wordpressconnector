<?php
declare(strict_types=1);

// WordPress upgrader lifecycle model: verify that an authenticated REST
// self-update never silently disables its own endpoint on the next request.
$GLOBALS['hooks'] = array();
$GLOBALS['connector_active'] = true;
$GLOBALS['force_external_deactivation'] = false;
$GLOBALS['force_activation_failure'] = false;
$GLOBALS['reactivation_attempts'] = 0;
$GLOBALS['install_failure_mode'] = '';
$GLOBALS['connector_version'] = '1.20.10';
$GLOBALS['backup_available'] = true;
$GLOBALS['restore_attempts'] = 0;
class WP_Error { }
class Automatic_Upgrader_Skin { }
class Plugin_Upgrader
{
    public function __construct($skin) {}
    public function init(): void {}
    public function upgrade_strings(): void {}
    public function check_package($response, $payload) { return $response; }
    public function deactivate_plugin_before_upgrade($response, $payload) {
        $GLOBALS['connector_active'] = false;
        return $response;
    }
    public function active_before($response, $payload) { return $response; }
    public function active_after($response, $payload) { return $response; }
    public function restore_temp_backup($backup) {
        ++$GLOBALS['restore_attempts'];
        if (! $GLOBALS['backup_available']) return false;
        $GLOBALS['connector_version'] = '1.20.10';
        $GLOBALS['connector_active'] = true;
        return true;
    }
    public function run($arguments) {
        // Simulate Core calling upgrader_pre_download for the already verified
        // local ZIP used by connector.update.apply, not only installation hooks.
        foreach ($GLOBALS['hooks']['upgrader_pre_download'] ?? array() as $entry) {
            $reply = $entry['handler'](false, $arguments['package'], $this, $arguments['hook_extra']);
            if (false !== $reply) {
                throw new RuntimeException('Native download hook blocked verified REST self-update ZIP.');
            }
        }
        $before = $GLOBALS['hooks']['upgrader_pre_install'] ?? array();
        foreach ($before as $entry) {
            $entry['handler'](true, $arguments['hook_extra']);
        }
        if (! empty($GLOBALS['force_external_deactivation'])) {
            $GLOBALS['connector_active'] = false;
        }
        $failure = $GLOBALS['install_failure_mode'];
        if ('' !== $failure) {
            $GLOBALS['connector_active'] = false;
            $GLOBALS['connector_version'] = '1.20.12';
            if ('exception' === $failure) throw new RuntimeException('Simulated interruption after destination replacement.');
            if ('wp_error' === $failure) return new WP_Error();
            if ('unknown' === $failure) return null;
        }
        $after = $GLOBALS['hooks']['upgrader_post_install'] ?? array();
        foreach ($after as $entry) {
            $entry['handler'](true, $arguments['hook_extra']);
        }
        return array('destination_name' => 'wordpressconnector');
    }
}
function get_filesystem_method() { return 'direct'; }
function wp_clean_plugins_cache($clear = true) {}
function get_plugins(): array { return array('wordpressconnector/wordpressconnector.php' => array('Version' => $GLOBALS['connector_version'])); }
function trailingslashit($path): string { return rtrim($path, '/') . '/'; }
$GLOBALS['wp_filesystem'] = new class { public function is_dir($path): bool { return $GLOBALS['backup_available']; } };
function is_plugin_active($plugin) { return $plugin === 'wordpressconnector/wordpressconnector.php' && ! empty($GLOBALS['connector_active']); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function activate_plugin($plugin, $redirect = '', $network_wide = false, $silent = false) {
    ++$GLOBALS['reactivation_attempts'];
    if (! empty($GLOBALS['force_activation_failure'])) return new WP_Error();
    $GLOBALS['connector_active'] = true;
    return null;
}
function add_filter($hook, $handler, $priority = 10, $accepted_args = 1) {
    $GLOBALS['hooks'][$hook][] = array('handler' => $handler, 'priority' => $priority);
}
function remove_filter($hook, $handler, $priority = 10) {
    if (! isset($GLOBALS['hooks'][$hook])) return;
    foreach ($GLOBALS['hooks'][$hook] as $i=>$entry) {
        if ($entry['handler'] === $handler) unset($GLOBALS['hooks'][$hook][$i]);
    }
    $GLOBALS['hooks'][$hook] = array_values($GLOBALS['hooks'][$hook]);
}
$dir=sys_get_temp_dir().'/wpconn-updater-contract-'.bin2hex(random_bytes(5));
mkdir($dir.'/wordpressconnector',0700,true);
file_put_contents($dir.'/wordpressconnector/wordpressconnector.php',"<?php\n");
define('WP_PLUGIN_DIR',$dir);
define('WP_CONTENT_DIR',sys_get_temp_dir());
register_shutdown_function(static function() use($dir) {
    @unlink($dir.'/wordpressconnector/wordpressconnector.php');
    @rmdir($dir.'/wordpressconnector');
    @rmdir($dir);
});
require_once dirname(__DIR__).'/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php';
$adapter = new Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter();
add_filter('upgrader_pre_download', array($adapter, 'verifiedNativeDownload'), 10, 4);
$method=new ReflectionMethod($adapter,'install_with_core_backup');
$method->setAccessible(true);
$run=static function(?string $previous = null)use($method,$adapter) {
    return $method->invoke($adapter, '/tmp/test-safe-canonical-package.zip', null, null, $previous);
};
$wasActive=$run();
$verifiedPath = new ReflectionProperty($adapter, 'verified_self_update_package_path');
$verifiedPath->setAccessible(true);
if (property_exists($adapter, 'verifiedSelfUpdatePackagePath')) {
    throw new RuntimeException('REST self-update created an undeclared dynamic verification property.');
}
if (null !== $verifiedPath->getValue($adapter)) {
    throw new RuntimeException('REST self-update verification state leaked after Core upgrade.');
}
if (! is_plugin_active('wordpressconnector/wordpressconnector.php')
    || $GLOBALS['reactivation_attempts']!==0
    || !isset($wasActive['upgrader'])) {
    throw new RuntimeException('Self-update unexpectedly deactivated a working plugin.');
}
if (!empty($GLOBALS['hooks']['upgrader_pre_install'])) {
    foreach ($GLOBALS['hooks']['upgrader_pre_install'] as $entry) {
        if (is_array($entry['handler']) && $entry['handler'][1] === 'deactivate_plugin_before_upgrade') {
            throw new RuntimeException('The browser-only deactivation hook leaked into REST self-update.');
        }
    }
}
$GLOBALS['force_external_deactivation']=true;
$run();
if (! is_plugin_active('wordpressconnector/wordpressconnector.php')
    || $GLOBALS['reactivation_attempts']!==1) {
    throw new RuntimeException('Lost plugin activation was not detected and repaired.');
}
$GLOBALS['force_activation_failure']=true;
$denied=false;
try { $run(); } catch(RuntimeException $e) { $denied = str_contains($e->getMessage(),'reactivation failed'); }
if (!$denied) throw new RuntimeException('Updater claimed success after failed reactivation.');
$GLOBALS['force_activation_failure']=false;
$GLOBALS['force_external_deactivation']=false;
$GLOBALS['connector_active']=false;
$denied=false;
try { $run(); } catch(RuntimeException $e) { $denied = str_contains($e->getMessage(),'requires an active'); }
if (!$denied) throw new RuntimeException('Self-update accepted a previously inactive plugin.');
// Synchronous failures must never silently leave a partial update installed.
// Core's temporary backup is available in this controlled runtime fixture.
$GLOBALS['connector_active'] = true;
foreach (array('wp_error', 'exception', 'unknown') as $failureMode) {
    $GLOBALS['install_failure_mode'] = $failureMode;
    $GLOBALS['connector_version'] = '1.20.10';
    $GLOBALS['backup_available'] = true;
    $beforeRestore = $GLOBALS['restore_attempts'];
    $terminal = false;
    try { $run('1.20.10'); }
    catch (RuntimeException $error) {
        $terminal = str_contains($error->getMessage(), 'unknown outcome')
            || str_contains($error->getMessage(), 'prior installation was checked');
    }
    if (! $terminal || $GLOBALS['connector_version'] !== '1.20.10'
        || ! $GLOBALS['connector_active'] || $GLOBALS['restore_attempts'] !== $beforeRestore + 1) {
        throw new RuntimeException('Synchronous ' . $failureMode . ' left an inconsistent prior-version state.');
    }
}
$GLOBALS['install_failure_mode'] = 'unknown';
$GLOBALS['connector_version'] = '1.20.10';
$GLOBALS['connector_active'] = true;
$GLOBALS['backup_available'] = false;
$unrecoverable = false;
try { $run('1.20.10'); }
catch (RuntimeException $error) {
    $unrecoverable = str_contains($error->getMessage(), 'Restore externally before retrying');
}
if (! $unrecoverable) {
    throw new RuntimeException('Unknown outcome without backup must require external restore.');
}
$GLOBALS['install_failure_mode'] = '';
$GLOBALS['backup_available'] = true;
$GLOBALS['connector_version'] = '1.20.10';
$GLOBALS['connector_active'] = true;
echo "REST self-update activation retention, synchronous rollback and unknown-outcome rejection OK\n";
