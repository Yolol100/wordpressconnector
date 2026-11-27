<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapterPath = $root . '/plugin/wordpressconnector/includes/Adapters/class-customcssadapter.php';
$pluginPath = $root . '/plugin/wordpressconnector/includes/class-plugin.php';
$bootstrapPath = $root . '/plugin/wordpressconnector/wordpressconnector.php';

foreach (array($adapterPath, $pluginPath, $bootstrapPath) as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$adapter = file_get_contents($adapterPath);
$plugin = file_get_contents($pluginPath);
$bootstrap = file_get_contents($bootstrapPath);

if ($adapter === false || $plugin === false || $bootstrap === false) {
    fwrite(STDERR, "Unable to read Additional CSS implementation files.\n");
    exit(1);
}

$normalizedAdapter = preg_replace('/\\s+/', '', $adapter);
if (! is_string($normalizedAdapter)) { throw new RuntimeException('Could not normalize Additional CSS implementation.'); }
$required = array(
    "register('custom_css.inspect'",
    "register('custom_css.update'",
    "register('custom_css.patch'",
    "'capability' => 'edit_css'",
    "'mutation' => true",
    "'privileged' => true",
    'wp_get_custom_css(',
    'wp_get_custom_css_post(',
    'wp_update_custom_css_post(',
    "'_current_fingerprint'",
    "'_rollback'",
    'MAX_CSS_BYTES',
    'MAX_PATCH_BYTES',
    'PATCH_PREFIX',
    'apply_patch(',
);

foreach ($required as $needle) {
    if (strpos($normalizedAdapter, preg_replace('/\\s+/', '', $needle)) === false) {
        fwrite(STDERR, "Missing Additional CSS contract marker: {$needle}\n");
        exit(1);
    }
}

if (strpos($normalizedAdapter, 'eval(') !== false || strpos($normalizedAdapter, 'file_put_contents(') !== false) {
    fwrite(STDERR, "Additional CSS adapter must use WordPress APIs, not execution or direct filesystem writes.\n");
    exit(1);
}

if (strpos($plugin, 'new CustomCssAdapter()') === false) {
    fwrite(STDERR, "CustomCssAdapter is not registered in class-plugin.php.\n");
    exit(1);
}

if (strpos($bootstrap, "includes/Adapters/class-customcssadapter.php") === false) {
    fwrite(STDERR, "CustomCssAdapter is not loaded by the plugin bootstrap.\n");
    exit(1);
}


// Runtime coverage of the actual adapter: a corrupt readback is not proof of
// restoration. A failed compensation must explicitly request manual recovery.
require_once $root . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once $root . '/plugin/wordpressconnector/includes/Support/class-fingerprint.php';
require_once $adapterPath;
class WP_Post {
    public $ID;
    public function __construct(int $id) { $this->ID=$id; }
}
class WP_Error {
    public function get_error_message(): string { return 'private provider diagnostic'; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function sanitize_text_field($value): string { return trim(strip_tags((string)$value)); }
function get_stylesheet(): string { return 'fixture-theme'; }
function wp_get_themes(): array { return array('fixture-theme'=>(object)array()); }
function wp_get_custom_css_post($stylesheet) {
    return $GLOBALS['css_fixture_post_id'] ? new WP_Post($GLOBALS['css_fixture_post_id']) : null;
}
function wp_get_custom_css($stylesheet): string { return $GLOBALS['css_fixture_content']; }
function clean_post_cache($id): void {}
function wp_update_custom_css_post($css,$args) {
    if ($args['stylesheet'] !== 'fixture-theme') throw new RuntimeException('Wrong CSS target.');
    $GLOBALS['css_fixture_calls']++;
    $mode=array_shift($GLOBALS['css_fixture_modes']) ?? 'success';
    if ($mode==='error') return new WP_Error();
    if ($mode==='false') return false;
    if ($mode==='throw') throw new RuntimeException('private provider failure');
    $GLOBALS['css_fixture_content']=$mode==='corrupt' ? 'body{color:wrong;}' : $css;
    $GLOBALS['css_fixture_post_id']=33;
    return new WP_Post($mode==='wrong_id' ? 44 : 33);
}
$adapter=new \Webactueel\WordPressConnector\Adapters\CustomCssAdapter();
$original='body{color:blue;}';
$cases=array(
    'update'=>array('css'=>'body{color:red;}'),
    'patch'=>array('patch_id'=>'fixture-test','operation'=>'upsert','css'=>'body{color:red;}'),
);
foreach ($cases as $method=>$payload) {
    foreach (array('error','false','throw','corrupt','wrong_id') as $restoreMode) {
        $GLOBALS['css_fixture_post_id']=33;
        $GLOBALS['css_fixture_content']=$original;
        $GLOBALS['css_fixture_calls']=0;
        $GLOBALS['css_fixture_modes']=array('corrupt',$restoreMode);
        try {
            $adapter->$method($payload,array('dry_run'=>false));
            throw new RuntimeException('Corrupt CSS readback accepted.');
        } catch (RuntimeException $error) {
            if ('Additional CSS readback verification failed; previous CSS restore could not be verified. Manual recovery is required.' !== $error->getMessage()) throw $error;
        }
        if ($GLOBALS['css_fixture_calls'] !== 2) throw new RuntimeException('CSS recovery must attempt exactly one restore.');
    }
    $GLOBALS['css_fixture_content']=$original;
    $GLOBALS['css_fixture_modes']=array('corrupt','success');
    $GLOBALS['css_fixture_calls']=0;
    try {
        $adapter->$method($payload,array('dry_run'=>false));
        throw new RuntimeException('Corrupt CSS readback accepted.');
    } catch (RuntimeException $error) {
        if ('Additional CSS readback verification failed; the previous CSS was restored.' !== $error->getMessage()) throw $error;
    }
    if ($GLOBALS['css_fixture_content']!==$original || $GLOBALS['css_fixture_calls']!==2) throw new RuntimeException('Verified restore differs from original.');
    $GLOBALS['css_fixture_modes']=array('wrong_id','success');
    $GLOBALS['css_fixture_calls']=0;
    try {
        $adapter->$method($payload,array('dry_run'=>false));
        throw new RuntimeException('Wrong CSS post identity accepted.');
    } catch (RuntimeException $error) {
        if ('Additional CSS readback verification failed; the previous CSS was restored.' !== $error->getMessage()) throw $error;
    }
    if ($GLOBALS['css_fixture_content']!==$original) throw new RuntimeException('Wrong identity recovery failed.');
    $GLOBALS['css_fixture_modes']=array('success');
    $GLOBALS['css_fixture_calls']=0;
    $preview=$adapter->$method($payload,array('dry_run'=>true));
    if ($GLOBALS['css_fixture_calls']!==0 || $preview['readback_verified']!==null) throw new RuntimeException('CSS preview mutated.');
    $success=$adapter->$method($payload,array('dry_run'=>false));
    if ($success['readback_verified']!==true || $success['_rollback']['payload']['css']!==$original) throw new RuntimeException('CSS success or rollback changed.');
    $adapter->update($success['_rollback']['payload'],array('dry_run'=>false));
    if ($GLOBALS['css_fixture_content']!==$original) throw new RuntimeException('CSS explicit rollback failed.');
}

echo "custom css contract OK\n";
