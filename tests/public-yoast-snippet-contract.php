<?php
declare(strict_types=1);

// Isolated public SEO metadata unit model. Never touches a live WordPress site.
class WP_Post {
    public $ID;
    public $post_type = 'page';
    public $post_status = 'publish';
    public $post_password = '';
    public function __construct(int $id) {$this->ID = $id;}
}
$GLOBALS['snippet_posts'] = array(13 => new WP_Post(13), 14 => new WP_Post(14));
$GLOBALS['snippet_meta'] = array();
$GLOBALS['snippet_edit'] = true;
function get_post($id) { return $GLOBALS['snippet_posts'][$id] ?? null; }
function get_post_type_object($type) { return (object) array('public' => in_array($type, array('page','post','product'), true)); }
function current_user_can($cap, ...$arguments) { return !empty($GLOBALS['snippet_edit']); }
function get_post_meta($id, $key, $single=true) { return $GLOBALS['snippet_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['snippet_meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['snippet_meta'][$id][$key]); return true; }
function clean_post_cache($id) {}
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
define('WPSEO_VERSION', '28.7');

$base = dirname(__DIR__);
foreach (array('Support/Fingerprint.php', 'Security/Policy.php', 'Runtime/Registry.php',
    'Adapters/YoastSnippetAdapter.php', 'Runtime/Runner.php') as $file) {
    require_once $base . '/plugin/wordpressconnector/includes/' . $file;
}
use Webactueel\WordPressConnector\Adapters\YoastSnippetAdapter;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Security\Policy;
Policy::setPublicRepositoryContext(true);
$registry = new Registry();
(new YoastSnippetAdapter())->register($registry);
$fail = static function(callable $cb): void {
    try { $cb(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException('Public snippet security test unexpectedly passed.');
};
$read = $registry->execute('yoast.snippet.inspect', array('id'=>13));
if ($read['fields'] !== array('title'=>null,'description'=>null)
    || !preg_match('/^[a-f0-9]{64}$/D', $read['fingerprint'])) {
    throw new RuntimeException('Unexpected public snippet inspect response.');
}
$patch = array('id'=>13,'fields'=>array(
    'title'=>'Adhesive Tapes & Packaging Materials | ISOHULK',
    'description'=>'Discover industrial adhesive tapes and packaging materials at ISOHULK.',
));
$preview = $registry->execute('yoast.snippet.update', $patch, array('dry_run'=>true));
if ($preview['_current_fingerprint'] !== $read['fingerprint'] || $GLOBALS['snippet_meta']) {
    throw new RuntimeException('Snippet dry run mutated stored metadata.');
}
foreach (array(
    array('id'=>13,'fields'=>array('robots_noindex'=>'1')),
    array('id'=>13,'fields'=>array('canonical'=>'https://site.test')),
    array('id'=>13,'fields'=>array('focus_keyphrase'=>'Tape Solutions')),
    array('id'=>13,'fields'=>array('title'=>'<script>evil</script>')),
    array('id'=>13,'fields'=>array('title'=>'%%title%% %%sep%% ISOHULK')),
    array('id'=>13,'fields'=>array('description'=>str_repeat('x',321))),
    array('id'=>13,'fields'=>array('title'=>null)),
    array('id'=>13,'fields'=>array()),
) as $invalid) {
    $fail(static function() use($registry,$invalid) { $registry->execute('yoast.snippet.update', $invalid, array('dry_run'=>true)); });
}
$GLOBALS['snippet_posts'][13]->post_status='draft';
$fail(static function() use($registry,$patch) { $registry->execute('yoast.snippet.update', $patch, array('dry_run'=>true)); });
$GLOBALS['snippet_posts'][13]->post_status='publish';
$GLOBALS['snippet_edit']=false;
$fail(static function() use($registry,$patch) { $registry->execute('yoast.snippet.update', $patch, array('dry_run'=>true)); });
$GLOBALS['snippet_edit']=true;
$write = $registry->execute('yoast.snippet.update', $patch, array('dry_run'=>false,'confirm'=>true));
if ($write['after']['fields'] !== $patch['fields'] || $write['_rollback']['action'] !== 'yoast.snippet.restore') {
    throw new RuntimeException('Snippet write/readback failed.');
}
$restore = $write['_rollback']['payload'];
$fail(static function() use($registry,$restore) { $registry->execute('yoast.snippet.restore', $restore, array('dry_run'=>false)); });
$GLOBALS['snippet_meta'][13]['_yoast_wpseo_title']='External concurrent edit';
$fail(static function() use($registry,$restore) { $registry->execute('yoast.snippet.restore', $restore, array('dry_run'=>false,'rollback_mode'=>true)); });
$GLOBALS['snippet_meta'][13]['_yoast_wpseo_title']=$patch['fields']['title'];
$dryRestore = $registry->execute('yoast.snippet.restore', $restore, array('dry_run'=>true,'rollback_mode'=>true));
if (!isset($dryRestore['_current_fingerprint'])) throw new RuntimeException('Missing guarded rollback fingerprint.');
$registry->execute('yoast.snippet.restore', $restore, array('dry_run'=>false,'rollback_mode'=>true));
if ($registry->execute('yoast.snippet.inspect',array('id'=>13))['fields'] !== $read['fields']) {
    throw new RuntimeException('Snippet rollback did not delete previous empty overrides.');
}

// Exercise real runtime public descriptor, not just adapter validation.
$reflection = new ReflectionClass(Runner::class);
$runner = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('publicDescriptor');
$method->setAccessible(true);
$desc = $registry->descriptor('yoast.snippet.update');
$method->invoke($runner,'yoast.snippet.update',$patch,$desc,true,null);
$fail(static function() use($method,$runner,$patch,$desc) {
    $method->invoke($runner,'yoast.snippet.update',$patch,$desc,false,null);
});
$method->invoke($runner,'yoast.snippet.update',$patch,$desc,false,str_repeat('a',64));

// Exercise real GitHub public validator; no broad Yoast fields are permitted.
$validator = $base . '/scripts/validate-public-request.php';
$req = array('version'=>1,'site_url'=>'https://isohulk.example.test',
    'request_id'=>'public-yoast-snippet-check-0001',
    'action'=>'yoast.snippet.update','payload'=>$patch,'dry_run'=>true,'confirm'=>false);
$tmp = tempnam(sys_get_temp_dir(),'yoast-snippet-');
$validate = static function(array $request) use($tmp,$validator): int {
    file_put_contents($tmp,json_encode($request,JSON_THROW_ON_ERROR));
    $out = array(); $exit = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($validator).' '.escapeshellarg($tmp).' 2>&1',$out,$exit);
    return $exit;
};
if ($validate($req) !== 0) throw new RuntimeException('Valid public snippet request was rejected.');
$req['dry_run']=false; $req['confirm']=true;
if ($validate($req) === 0) throw new RuntimeException('Confirmed snippet request was accepted without fingerprint.');
$req['expected_fingerprint']=str_repeat('b',64);
if ($validate($req) !== 0) throw new RuntimeException('Fingerprint-guarded public snippet write rejected.');
$req['payload']['fields']=array('robots_noindex'=>'1');
if ($validate($req) === 0) throw new RuntimeException('Public snippet accepted a Yoast robots change.');
@unlink($tmp);
echo "Guarded public per-post Yoast snippet contract OK\n";
