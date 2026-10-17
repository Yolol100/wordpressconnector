<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$root = sys_get_temp_dir() . '/wpconn-private-ops-' . bin2hex(random_bytes(6)) . '/';
mkdir($root . 'wp-content/themes/fixture/assets', 0700, true);
mkdir($root . 'wp-admin/includes', 0700, true);
file_put_contents($root . 'wp-admin/includes/file.php', "<?php\n");
define('ABSPATH', $root);

class WP_Filesystem_Base
{
    public function delete($path, $recursive = false, $type = false): bool {
        return $recursive ? false : unlink($path);
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
function is_multisite(): bool { return false; }
function is_plugin_active($file): bool { return $file === 'wordpressconnector/wordpressconnector.php'; }
function get_plugins(): array {
    return array(
        'wordpressconnector/wordpressconnector.php' => array('Name'=>'WordPress Connector','Version'=>'1.19.7'),
        'hello-dolly/hello.php'=>array('Name'=>'Hello Dolly','Version'=>'1.7.2'),
        'sample/sample.php'=>array('Name'=>'Sample','Version'=>'1.0.0'),
    );
}
function get_site_transient($key) {
    if ($key !== 'update_plugins') return false;
    return (object) array('last_checked'=>1760710000,
        'response'=>array('sample/sample.php'=>(object) array('new_version'=>'1.1.0')));
}
function plugins_api($action, $args = array()) {
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

foreach (array('Support/Fingerprint.php', 'Security/Policy.php',
    'Security/FilesystemPolicy.php', 'Adapters/FilesystemAdapter.php',
    'Adapters/SystemAdapter.php') as $file) {
    require_once $base . '/plugin/wordpressconnector/includes/' . $file;
}
use Webactueel\WordPressConnector\Adapters\FilesystemAdapter;
use Webactueel\WordPressConnector\Adapters\SystemAdapter;
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

$fs = new FilesystemAdapter();
$path = 'wp-content/themes/fixture/assets/new-block.html';
$content = '<div>Public demo HTML asset</div>';
$plan = $fs->createText(array('path'=>$path,'content'=>$content),array('dry_run'=>true));
if (file_exists($root . $path) || !isset($plan['_current_fingerprint'])) {
    throw new RuntimeException('Filesystem create dry-run changed the target.');
}
$created = $fs->createText(array('path'=>$path,'content'=>$content),array('dry_run'=>false));
if (!is_file($root . $path) || file_get_contents($root.$path)!==$content ||
    $created['_rollback']['action'] !== 'filesystem.delete_text') {
    throw new RuntimeException('File was not created and verified.');
}
$fail(static function()use($fs,$path,$content) {
    $fs->createText(array('path'=>$path,'content'=>$content),array('dry_run'=>false));
}, 'overwrite by create');
$deletePlan = $fs->deleteText(array('path'=>$path),array('dry_run'=>true));
if (!is_file($root.$path) || $deletePlan['before']['sha256'] !== hash('sha256',$content)) {
    throw new RuntimeException('Deletion plan missing original fingerprint.');
}
$fail(static function()use($fs,$path) {
    $fs->deleteText(array('path'=>$path,'expected_sha256'=>str_repeat('0',64)),array('dry_run'=>false));
},'delete with stale SHA');
$deleted = $fs->deleteText(array(
    'path'=>$path,'expected_sha256'=>hash('sha256',$content)),array('dry_run'=>false));
if (file_exists($root.$path) || $deleted['_rollback']['action']!=='filesystem.create_text' ||
    $deleted['_rollback']['payload']['content'] !== $content) {
    throw new RuntimeException('Deletion readback or rollback failed.');
}
$fs->createText($deleted['_rollback']['payload'],array('dry_run'=>false));
if (file_get_contents($root.$path)!==$content) throw new RuntimeException('Deletion rollback failed.');
$GLOBALS['wpconn_file_edits_allowed'] = false;
$fail(static function()use($fs,$path,$content) {
    $fs->deleteText(array('path'=>$path,'expected_sha256'=>hash('sha256',$content)),array('dry_run'=>false));
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
if (FilesystemPolicy::assertLifecycleText($path)!==$path) {
    throw new RuntimeException('Safe file lifecycle path rejected.');
}
unlink($root.$path);
unlink($root.'wp-admin/includes/file.php');
rmdir($root.'wp-content/themes/fixture/assets');
rmdir($root.'wp-content/themes/fixture');
rmdir($root.'wp-content/themes');
rmdir($root.'wp-content');
rmdir($root.'wp-admin/includes');
rmdir($root.'wp-admin');
rmdir($root);
echo "Private plugin directory, update status, and safe file lifecycle contracts OK\n";
