<?php
declare(strict_types=1);

// WordPress upgrader lifecycle model: verify that an authenticated REST
// self-update never silently disables its own endpoint on the next request.
$GLOBALS['hooks'] = array();
$GLOBALS['connector_active'] = true;
$GLOBALS['force_external_deactivation'] = false;
$GLOBALS['force_activation_failure'] = false;
$GLOBALS['reactivation_attempts'] = 0;
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
    public function restore_temp_backup($backup) { return true; }
    public function run($arguments): array {
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
        $after = $GLOBALS['hooks']['upgrader_post_install'] ?? array();
        foreach ($after as $entry) {
            $entry['handler'](true, $arguments['hook_extra']);
        }
        return array('destination_name' => 'wordpressconnector');
    }
}
function get_filesystem_method() { return 'direct'; }
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
register_shutdown_function(static function() use($dir) {
    @unlink($dir.'/wordpressconnector/wordpressconnector.php');
    @rmdir($dir.'/wordpressconnector');
    @rmdir($dir);
});
require_once dirname(__DIR__).'/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php';
$adapter = new Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter();
add_filter('upgrader_pre_download', array($adapter, 'verifiedNativeDownload'), 10, 4);
$method=new ReflectionMethod($adapter,'installWithCoreBackup');
$method->setAccessible(true);
$run=static function()use($method,$adapter) {
    return $method->invoke($adapter, '/tmp/test-safe-canonical-package.zip');
};
$wasActive=$run();
$verifiedPath = new ReflectionProperty($adapter, 'verifiedSelfUpdatePackagePath');
$verifiedPath->setAccessible(true);
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
echo "REST self-update activation retention, reactivation and failure handling OK\n";
