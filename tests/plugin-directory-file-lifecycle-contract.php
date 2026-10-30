<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$root = sys_get_temp_dir() . '/wpconn-private-ops-' . bin2hex(random_bytes(6)) . '/';
mkdir($root . 'wp-content/themes/fixture/assets', 0700, true);
mkdir($root . 'wp-admin/includes', 0700, true);
file_put_contents($root . 'wp-admin/includes/file.php', "<?php\n");
file_put_contents($root . 'wp-admin/includes/plugin.php', "<?php\n");
file_put_contents($root . 'wp-admin/includes/plugin-install.php', "<?php\n");
file_put_contents($root . 'wp-admin/includes/update.php', "<?php\n");
file_put_contents($root . 'wp-admin/includes/class-wp-upgrader.php', "<?php\n");
define('ABSPATH', $root);

class WP_Filesystem_Base
{
    public array $writeBehaviors = array();
    public int $writeCalls = 0;
    public function delete($path, $recursive = false, $type = false): bool {
        return $recursive ? false : unlink($path);
    }
    public function put_contents($path, $content, $mode = false): bool {
        ++$this->writeCalls;
        $behavior = array_shift($this->writeBehaviors);
        if ('partial_false' === $behavior || 'partial_throw' === $behavior) {
            file_put_contents($path, substr($content, 0, max(1, (int) (strlen($content) / 2))));
            if ('partial_throw' === $behavior) throw new RuntimeException('Injected write failure.');
            return false;
        }
        if ('corrupt_true' === $behavior) {
            file_put_contents($path, 'corrupted fixture bytes');
            return true;
        }
        if ('false' === $behavior) return false;
        if ('throw' === $behavior) throw new RuntimeException('Injected filesystem failure.');
        if ('complete_false' === $behavior) {
            file_put_contents($path, $content);
            return false;
        }
        return false !== file_put_contents($path, $content);
    }
}
$GLOBALS['wp_filesystem'] = new WP_Filesystem_Base();
$GLOBALS['wpconn_file_edits_allowed'] = true;
$GLOBALS['wpconn_file_permissions'] = true;
function get_filesystem_method($args = array(), $context = ''): string { return 'direct'; }
function WP_Filesystem($credentials = false, $context = ''): bool { return true; }
function current_user_can($cap): bool { return $GLOBALS['wpconn_file_permissions']; }
function wp_is_file_mod_allowed($context): bool { return $GLOBALS['wpconn_file_edits_allowed']; }
function sanitize_text_field($s): string { return trim(strip_tags((string) $s)); }
function sanitize_key($s): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function is_multisite(): bool { return false; }
$GLOBALS['wpconn_plugins'] = array(
    'wordpressconnector/wordpressconnector.php' => array('Name'=>'WordPress Connector','Version'=>'1.19.8'),
    'hello-dolly/hello.php'=>array('Name'=>'Hello Dolly','Version'=>'1.7.2'),
    'sample/sample.php'=>array('Name'=>'Sample','Version'=>'1.0.0'),
);
$GLOBALS['wpconn_active'] = array('wordpressconnector/wordpressconnector.php' => true);
$GLOBALS['wpconn_activation_hooks'] = array();
$GLOBALS['wpconn_plugin_download_link'] = 'https://downloads.wordpress.org/plugin/new-plugin.2.4.zip';
function is_plugin_active($file): bool { return ! empty($GLOBALS['wpconn_active'][$file]); }
function is_plugin_active_for_network($file): bool { return false; }
function get_plugins(): array { return $GLOBALS['wpconn_plugins']; }
function wp_clean_plugins_cache($clear = false): void {}
function activate_plugin($file, $redirect = '', $network = false, $silent = false) {
    if (! $silent) $GLOBALS['wpconn_activation_hooks'][] = $file;
    $GLOBALS['wpconn_active'][$file] = true;
    return null;
}
function deactivate_plugins($file, $silent = false, $network = false): void {
    unset($GLOBALS['wpconn_active'][$file]);
}
function delete_plugins($files) {
    foreach ($files as $file) unset($GLOBALS['wpconn_plugins'][$file]);
    return true;
}
$GLOBALS['wpconn_offer_version'] = '1.1.0';
$GLOBALS['wpconn_offer_after_refresh'] = null;
$GLOBALS['wpconn_plugin_upgrade_calls'] = 0;
function wp_update_plugins(): void {
    if (null !== $GLOBALS['wpconn_offer_after_refresh']) {
        $GLOBALS['wpconn_offer_version'] = $GLOBALS['wpconn_offer_after_refresh'];
        $GLOBALS['wpconn_offer_after_refresh'] = null;
    }
}
class Automatic_Upgrader_Skin {}
class Plugin_Upgrader {
    public function __construct($skin) {}
    public function install($url): bool {
        $GLOBALS['wpconn_plugins']['new-plugin/new-plugin.php']=array('Name'=>'New Plugin','Version'=>'2.4');
        return true;
    }
    public function upgrade($file): bool {
        ++$GLOBALS['wpconn_plugin_upgrade_calls'];
        $GLOBALS['wpconn_plugins'][$file]['Version']='2.0.0';
        return true;
    }
}
function get_site_transient($key) {
    if ($key !== 'update_plugins') return false;
    return (object) array('last_checked'=>1760710000,
        'response'=>array('sample/sample.php'=>(object) array('new_version'=>$GLOBALS['wpconn_offer_version'])));
}
function plugins_api($action, $args = array()) {
    if ($action === 'plugin_information') return (object) array('slug'=>'new-plugin', 'download_link'=>$GLOBALS['wpconn_plugin_download_link']);
    if ($action !== 'query_plugins') return new WP_Error();
    return (object) array(
        'info'=>array('results'=>3),
        'plugins'=>array(
            (object) array('slug'=>'sample','name'=>'Sample','version'=>'1.1.0',
                'short_description'=>'Simple plugin',
                'requires'=>'6.4','requires_php'=>'7.4','tested'=>'7.1.3','active_installs'=>600),
            (object) array('slug'=>'malicious/../../','name'=>'Reject','version'=>'1.0'),
            (object) array('slug'=>'new-plugin','name'=>'New Plugin','version'=>'2.4',
                'short_description'=>'New supported plugin'),
        ),
    );
}
class WP_Error { public function get_error_message(): string { return 'Test failure'; } }
function is_wp_error($value): bool { return $value instanceof WP_Error; }

foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Support/Input.php', 'Runtime/Diagnostics.php', 'Security/Policy.php',
    'Security/FilesystemPolicy.php', 'Adapters/FilesystemAdapter.php',
    'Adapters/SystemAdapter.php') as $file) {
    require_once $base . '/plugin/wordpressconnector/includes/' . $file;
}
use Webactueel\WordPressConnector\Adapters\FilesystemAdapter;
use Webactueel\WordPressConnector\Adapters\SystemAdapter;
use Webactueel\WordPressConnector\Runtime\Diagnostics;
use Webactueel\WordPressConnector\Security\FilesystemPolicy;
$fail = static function(callable $fn, string $label): void {
    try { $fn(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Unsafe request accepted: ' . $label);
};
$system = new SystemAdapter();
$list = $system->pluginList();
$items = array();
foreach ($list['plugins'] as $p) $items[$p['file']] = $p;
if (count($items)!==3 || $items['sample/sample.php']['update_available'] !== true ||
    $items['sample/sample.php']['available_version'] !== '1.1.0' ||
    $items['hello-dolly/hello.php']['can_delete'] !== true ||
    $items['wordpressconnector/wordpressconnector.php']['can_delete'] !== false) {
    throw new RuntimeException('Private installed-plugin status failed.');
}
$results = $system->pluginDirectorySearch(array('search'=>'sample', 'page'=>1));
if (count($results['plugins']) !== 2 || !$results['plugins'][0]['already_installed'] ||
    $results['plugins'][1]['already_installed'] ||
    $results['plugins'][1]['directory_url'] !== 'https://wordpress.org/plugins/new-plugin/' ||
    $results['results_total'] !== 3) {
    throw new RuntimeException('WordPress.org plugin directory search returned invalid data.');
}
$fail(static function()use($system){$system->pluginDirectorySearch(array('search'=>'x'));},'short search');
$fail(static function()use($system){$system->pluginDirectorySearch(array('search'=>'theme','token'=>'test'));},'unknown query');
$fail(static function()use($system){$system->pluginDirectorySearch(array('search'=>'theme','per_page'=>1000));},'unbounded query');
$fail(static function()use($system){$system->pluginDirectorySearch(array('search'=>'theme','page'=>0));},'invalid page');

$activate = $system->pluginActivate(array('file'=>'hello-dolly/hello.php'),array('dry_run'=>false));
if (!in_array('hello-dolly/hello.php', $GLOBALS['wpconn_activation_hooks'], true)) {
    throw new RuntimeException('Normal plugin activation skipped WordPress activation hooks.');
}
if (!is_plugin_active('hello-dolly/hello.php') || empty($activate['readback_verified'])) {
    throw new RuntimeException('Plugin activation lacked readback proof.');
}
$deactivate = $system->pluginDeactivate(array('file'=>'hello-dolly/hello.php'),array('dry_run'=>false));
if (is_plugin_active('hello-dolly/hello.php') || empty($deactivate['readback_verified'])) {
    throw new RuntimeException('Plugin deactivation lacked readback proof.');
}
$fail(static function() use($system) {
    $system->pluginDeactivate(array('file'=>'wordpressconnector/wordpressconnector.php'),array('dry_run'=>false));
},'remote connector self-deactivation');
$fail(static function() use($system) {
    $system->pluginUpdate(array('file'=>'wordpressconnector/wordpressconnector.php'),array('dry_run'=>false));
},'unverified connector self-upgrade');
$fail(static function() use($system) {
    $system->pluginDelete(array('file'=>'wordpressconnector/wordpressconnector.php'),array('dry_run'=>false));
},'connector self-deletion');
$fail(static function() use($system) {
    $system->pluginInstall(array('slug'=>'../new-plugin'),array('dry_run'=>true));
},'sanitized slug substitution');
foreach (array(
    'https://attacker.example/plugin/new-plugin.zip',
    'http://downloads.wordpress.org/plugin/new-plugin.zip',
    'https://downloads.wordpress.org/plugin/unrelated.zip',
    'https://downloads.wordpress.org/plugin/new-plugin.zip?redirect=attacker',
    'https://downloads.wordpress.org@attacker.example/plugin/new-plugin.zip',
    'https://downloads.wordpress.org/plugin/../new-plugin.zip',
) as $untrusted) {
    $GLOBALS['wpconn_plugin_download_link'] = $untrusted;
    $fail(static function() use($system) {
        $system->pluginInstall(array('slug'=>'new-plugin'),array('dry_run'=>true));
    },'plugin source from filter: ' . $untrusted);
}
$GLOBALS['wpconn_plugin_download_link'] = 'https://downloads.wordpress.org/plugin/new-plugin.2.4.zip';
// Both plugin and theme directory packages must match the requested slug,
 // not merely share a plausible domain.
$origin = new ReflectionMethod(SystemAdapter::class, 'officialDirectoryPackageUrl');
$origin->setAccessible(true);
$officialTheme = 'https://downloads.wordpress.org/theme/qa-theme.1.2.3.zip';
if ($origin->invoke($system, $officialTheme, 'qa-theme', 'theme') !== $officialTheme) {
    throw new RuntimeException('Canonical WordPress theme package was rejected.');
}
$fail(static function() use ($origin, $system) {
    $origin->invoke($system, 'https://downloads.wordpress.org/plugin/qa-theme.zip', 'qa-theme', 'theme');
}, 'WordPress theme package path changed to plugin');
$fail(static function() use ($origin, $system) {
    $origin->invoke($system, 'https://downloads.wordpress.org/theme/another-theme.zip', 'qa-theme', 'theme');
}, 'Theme package identity substitution');
$preview = $system->pluginInstall(array('slug'=>'new-plugin'),array('dry_run'=>true));
if (($preview['verified_package_origin'] ?? '') !== 'downloads.wordpress.org') {
    throw new RuntimeException('Official plugin source not validated in dry-run.');
}
$installed = $system->pluginInstall(array('slug'=>'new-plugin'),array('dry_run'=>false));
if (empty($installed['readback_verified']) ||
    !isset(get_plugins()['new-plugin/new-plugin.php'])) {
    throw new RuntimeException('WordPress.org plugin install was not verified.');
}
$preview = $system->pluginUpdate(array(
    'file'=>'sample/sample.php', 'expected_target_version'=>'1.1.0',
), array('dry_run'=>true));
if (($preview['available_version'] ?? null) !== '1.1.0'
    || ($preview['from_version'] ?? null) !== '1.0.0') {
    throw new RuntimeException('Pinned plugin preview did not disclose available and installed versions.');
}
// A pinned native updater cannot be used for a downgrade or no-op release.
foreach (array('1.0.0', '0.9.0') as $oldTarget) {
    try {
        $system->pluginUpdate(array('file'=>'sample/sample.php',
            'expected_target_version'=>$oldTarget), array('dry_run'=>true));
        throw new RuntimeException('Pinned downgrade/no-op update was accepted.');
    } catch (RuntimeException $error) {
        if (strpos($error->getMessage(), 'must be newer') === false) {
            throw $error;
        }
    }
}
$beforeCalls = $GLOBALS['wpconn_plugin_upgrade_calls'];
$fail(static function() use ($system) {
    $system->pluginUpdate(array('file'=>'sample/sample.php', 'expected_target_version'=>'2.0.0'),array('dry_run'=>true));
}, 'plugin dry-run mismatched target version');
$fail(static function() use ($system) {
    $system->pluginUpdate(array('file'=>'sample/sample.php', 'expected_target_version'=>'bad/version'),array('dry_run'=>true));
}, 'plugin invalid target version');
$fail(static function() use ($system) {
    $system->pluginUpdate(array('file'=>'sample/sample.php', 'unexpected'=>'extra'),array('dry_run'=>true));
}, 'plugin unexpected update argument');
$GLOBALS['wpconn_offer_after_refresh'] = '2.1.0';
$fail(static function() use ($system) {
    $system->pluginUpdate(array('file'=>'sample/sample.php','expected_target_version'=>'1.1.0'),array('dry_run'=>false));
}, 'plugin offer changed after preview');
if ($beforeCalls !== $GLOBALS['wpconn_plugin_upgrade_calls']
    || get_plugins()['sample/sample.php']['Version'] !== '1.0.0') {
    throw new RuntimeException('Plugin with changed offer was upgraded despite the pinned target.');
}
$GLOBALS['wpconn_offer_version'] = '2.0.0';
$updated = $system->pluginUpdate(array('file'=>'sample/sample.php','expected_target_version'=>'2.0.0'),array('dry_run'=>false));
if ($updated['version'] !== '2.0.0' || empty($updated['readback_verified'])) {
    throw new RuntimeException('Pinned plugin update was not verified.');
}
$GLOBALS['wpconn_plugins']['sample/sample.php']['Version']='1.0.0';
$GLOBALS['wpconn_offer_version'] = '1.1.0';
$fail(static function() use ($system) {
    $system->pluginUpdate(array('file'=>'sample/sample.php','expected_target_version'=>'1.1.0'),array('dry_run'=>false));
}, 'plugin install version mismatch after native updater');
if (get_plugins()['sample/sample.php']['Version'] !== '2.0.0') {
    throw new RuntimeException('Unexpected plugin update result was not observable for reconciliation.');
}
$deletedPlugin = $system->pluginDelete(array('file'=>'hello-dolly/hello.php'),array('dry_run'=>false));
if (isset(get_plugins()['hello-dolly/hello.php']) || empty($deletedPlugin['readback_verified'])) {
    throw new RuntimeException('Plugin deletion did not verify target absence.');
}

$fs = new FilesystemAdapter();
$path = 'wp-content/themes/fixture/assets/new-block.html';
$content = '<div>Public demo HTML asset</div>';
$plan = $fs->createText(array('path'=>$path,'content'=>$content),array('dry_run'=>true));
if (file_exists($root . $path) || !isset($plan['_current_fingerprint'])) {
    throw new RuntimeException('Filesystem create dry-run changed the target.');
}
if (empty($plan['requires_active_content_acknowledgement'])) {
    throw new RuntimeException('HTML preview failed to warn about active browser content.');
}
$fail(static function() use($fs,$path,$content) {
    $fs->createText(array('path'=>$path,'content'=>$content),array('dry_run'=>false));
}, 'HTML asset creation without active content acknowledgement');
$created = $fs->createText(array('path'=>$path,'content'=>$content,'acknowledge_active_content'=>true),array('dry_run'=>false));
if (!is_file($root . $path) || file_get_contents($root.$path)!==$content ||
    $created['_rollback']['action'] !== 'filesystem.delete_text') {
    throw new RuntimeException('File was not created and verified.');
}
$fail(static function()use($fs,$path,$content) {
    $fs->createText(array('path'=>$path,'content'=>$content,'acknowledge_active_content'=>true),array('dry_run'=>false));
}, 'overwrite by create');
$replacement = '<div>Updated QA content, rollback required</div>';
$writePlan = $fs->writeText(array('path'=>$path,'content'=>$replacement),array('dry_run'=>true));
if (empty($writePlan['requires_active_content_acknowledgement']) ||
    file_get_contents($root.$path) !== $content) {
    throw new RuntimeException('Browser-active replacement preview mutated the target or hid its risk.');
}
$fail(static function()use($fs,$path,$replacement,$content) {
    $fs->writeText(array('path'=>$path,'content'=>$replacement,
        'expected_sha256'=>hash('sha256',$content)),array('dry_run'=>false));
}, 'HTML replacement without active-content acknowledgement');
$fail(static function()use($fs,$path,$replacement) {
    $fs->writeText(array('path'=>$path,'content'=>$replacement,
        'expected_sha256'=>str_repeat('0',64),'acknowledge_active_content'=>true),array('dry_run'=>false));
}, 'HTML replacement with stale fingerprint');
$fail(static function()use($fs,$path) {
    $fs->writeText(array('path'=>$path,'content'=>'{"api_key":"real-production-credential"}'),
        array('dry_run'=>true));
}, 'embedded secret in replacement content');
$GLOBALS['wpconn_file_edits_allowed'] = false;
$fail(static function()use($fs,$path,$replacement,$content) {
    $fs->writeText(array('path'=>$path,'content'=>$replacement,
        'expected_sha256'=>hash('sha256',$content),'acknowledge_active_content'=>true),
        array('dry_run'=>false));
}, 'WordPress file modification policy for replacement');
$GLOBALS['wpconn_file_edits_allowed'] = true;
$written = $fs->writeText(array('path'=>$path,'content'=>$replacement,
    'expected_sha256'=>hash('sha256',$content),'acknowledge_active_content'=>true),
    array('dry_run'=>false));
if (file_get_contents($root.$path) !== $replacement ||
    ($written['after']['sha256'] ?? '') !== hash('sha256',$replacement) ||
    ($written['_rollback']['payload']['acknowledge_active_content'] ?? null) !== true) {
    throw new RuntimeException('Confirmed active-content replacement did not persist or preserve rollback acknowledgement.');
}
$restoredWrite = $fs->writeText($written['_rollback']['payload'],array('dry_run'=>false));
if (file_get_contents($root.$path) !== $content ||
    ($restoredWrite['after']['sha256'] ?? '') !== hash('sha256',$content)) {
    throw new RuntimeException('Browser-active replacement rollback did not restore original bytes.');
}
// A failing filesystem write may already have truncated or changed the file.
// Recover once, verify actual bytes, and keep the requested operation failed.
$writeCases = array(
    array('partial_false', null, true),
    array('corrupt_true', null, true),
    array('partial_throw', null, true),
    array('partial_false', 'complete_false', true),
    array('partial_false', 'false', false),
    array('partial_false', 'corrupt_true', false),
    array('partial_false', 'throw', false),
);
$expectedDiagnostics = array(
    'partial_false' => 'filesystem_unavailable',
    'corrupt_true' => 'persistence_unverified',
    'partial_throw' => 'unknown_failure',
);
foreach ($writeCases as $writeCase) {
    list($initialFailure, $recoveryBehavior, $recoveryExpected) = $writeCase;
    file_put_contents($root.$path, $content);
    $GLOBALS['wp_filesystem']->writeBehaviors = array($initialFailure, $recoveryBehavior);
    $GLOBALS['wp_filesystem']->writeCalls = 0;
    $failureMessage = '';
    try {
        $fs->writeText(array('path'=>$path, 'content'=>$replacement,
            'expected_sha256'=>hash('sha256',$content), 'acknowledge_active_content'=>true),
            array('dry_run'=>false));
    } catch (RuntimeException $error) {
        $failureMessage = $error->getMessage();
    }
    $restored = file_get_contents($root.$path) === $content;
    if ('' === $failureMessage || $restored !== $recoveryExpected ||
        $GLOBALS['wp_filesystem']->writeCalls !== 2) {
        throw new RuntimeException('Failed replacement did not attempt and verify one recovery: ' . $initialFailure . '/' . (string) $recoveryBehavior);
    }
    $expectedMessage = $recoveryExpected ? 'original file restored and verified' : 'manual recovery required';
    if (strpos($failureMessage, $expectedMessage) === false) {
        throw new RuntimeException('Failed replacement reported an incorrect recovery outcome: ' . $initialFailure . '/' . (string) $recoveryBehavior);
    }
    $diagnostic = Diagnostics::classify($failureMessage, true);
    if ($diagnostic['code'] !== $expectedDiagnostics[$initialFailure] || ! $diagnostic['reconcile_before_retry']) {
        throw new RuntimeException('Filesystem recovery hid the original failure diagnostic: ' . $initialFailure);
    }
}
$GLOBALS['wp_filesystem']->writeBehaviors = array();
file_put_contents($root.$path, $content);
if (FilesystemPolicy::affectsBrowserExecution('wp-content/plugins/sample/main.php')) {
    throw new RuntimeException('PHP must retain its separate parser/capability safety path.');
}

$deletePlan = $fs->deleteText(array('path'=>$path),array('dry_run'=>true));
if (empty($deletePlan['requires_active_content_acknowledgement'])) {
    throw new RuntimeException('HTML deletion preview did not declare browser-active asset.');
}
$fail(static function() use($fs,$path,$content) {
    $fs->deleteText(array('path'=>$path,'expected_sha256'=>hash('sha256',$content)),array('dry_run'=>false));
},'HTML deletion without active-content acknowledgement');
if (!is_file($root.$path) || $deletePlan['before']['sha256'] !== hash('sha256',$content)) {
    throw new RuntimeException('Deletion plan missing original fingerprint.');
}
$fail(static function()use($fs,$path) {
    $fs->deleteText(array('path'=>$path,'expected_sha256'=>str_repeat('0',64),'acknowledge_active_content'=>true),array('dry_run'=>false));
},'delete with stale SHA');
$deleted = $fs->deleteText(array(
    'path'=>$path,'expected_sha256'=>hash('sha256',$content),'acknowledge_active_content'=>true),array('dry_run'=>false));
if (file_exists($root.$path) || $deleted['_rollback']['action']!=='filesystem.create_text' ||
    $deleted['_rollback']['payload']['content'] !== $content) {
    throw new RuntimeException('Deletion readback or rollback failed.');
}
if (($deleted['_rollback']['payload']['acknowledge_active_content'] ?? null) !== true) {
    throw new RuntimeException('Browser-active restore does not preserve approved acknowledgement.');
}
$fs->createText($deleted['_rollback']['payload'],array('dry_run'=>false));
if (file_get_contents($root.$path)!==$content) throw new RuntimeException('Deletion rollback failed.');
$GLOBALS['wpconn_file_edits_allowed'] = false;
$fail(static function()use($fs,$path,$content) {
    $fs->deleteText(array('path'=>$path,'expected_sha256'=>hash('sha256',$content),'acknowledge_active_content'=>true),array('dry_run'=>false));
},'server file modification policy');
$GLOBALS['wpconn_file_edits_allowed'] = true;
$GLOBALS['wpconn_file_permissions'] = false;
$fail(static function()use($fs,$path) {
    $fs->deleteText(array('path'=>$path),array('dry_run'=>true));
},'WordPress edit capability');
$GLOBALS['wpconn_file_permissions'] = true;
foreach (array(
    'wp-config.php',
    'wp-admin/index.html',
    'wp-content/plugins/wordpressconnector/assets/new.js',
    'wp-content/themes/fixture/functions.php',
    'wp-content/themes/fixture/style.css',
    'wp-content/themes/fixture/theme.json',
    'wp-content/themes/fixture/.htaccess',
    'wp-content/uploads/test.html',
    'wp-content/themes/fixture/assets/../evil.html',
) as $unsafe) {
    $fail(static function() use($unsafe) {
        FilesystemPolicy::assertLifecycleText($unsafe);
    },$unsafe);
}
if (!FilesystemPolicy::affectsBrowserExecution($path) ||
    !FilesystemPolicy::affectsBrowserExecution('wp-content/themes/fixture/assets/app.js') ||
    !FilesystemPolicy::affectsBrowserExecution('wp-content/themes/fixture/assets/styles.css') ||
    FilesystemPolicy::affectsBrowserExecution('wp-content/themes/fixture/assets/notes.txt')) {
    throw new RuntimeException('Active browser code classification is incorrect.');
}
if (FilesystemPolicy::assertLifecycleText($path)!==$path) {
    throw new RuntimeException('Safe file lifecycle path rejected.');
}
unlink($root.$path);
unlink($root.'wp-admin/includes/file.php');
unlink($root.'wp-admin/includes/plugin.php');
unlink($root.'wp-admin/includes/plugin-install.php');
unlink($root.'wp-admin/includes/update.php');
unlink($root.'wp-admin/includes/class-wp-upgrader.php');
rmdir($root.'wp-content/themes/fixture/assets');
rmdir($root.'wp-content/themes/fixture');
rmdir($root.'wp-content/themes');
rmdir($root.'wp-content');
rmdir($root.'wp-admin/includes');
rmdir($root.'wp-admin');
rmdir($root);
echo "Private plugin directory, update status, and safe file lifecycle contracts OK\n";
