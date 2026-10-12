<?php

declare(strict_types=1);

// Entirely isolated WordPress API model. No external site is modified.
class WP_Post
{
    public $ID;
    public $post_type = 'page';
    public $post_status = 'publish';
    public $post_password = '';
    public $post_content = '';
    public function __construct(int $id, string $text) { $this->ID = $id; $this->post_content = $text; }
}
$GLOBALS['posts'] = array(101 => new WP_Post(101, '<p>Tape Solutions</p><p>Tape Solutions</p>'));
$GLOBALS['can_edit'] = true;
function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
function get_post_type_object($type) { return (object) array('public' => in_array($type, array('page','post','product'), true)); }
function current_user_can($capability, ...$args) { return ! empty($GLOBALS['can_edit']); }
function wp_slash($value) { return $value; }
function is_wp_error($value) { return false; }
function wp_update_post($fields, $wp_error = false)
{
    $post = get_post((int) ($fields['ID'] ?? 0));
    if (!$post) throw new RuntimeException('Missing mock post.');
    $post->post_content = (string) ($fields['post_content'] ?? $post->post_content);
    return $post->ID;
}

$root = dirname(__DIR__);
foreach (array(
    'Support/Json.php','Support/Fingerprint.php','Security/Policy.php',
    'Runtime/Registry.php','Adapters/CoreAdapter.php',
) as $path) require_once $root . '/plugin/wordpressconnector/includes/' . $path;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

Policy::setPublicRepositoryContext(true);
$registry = new Registry();
(new Webactueel\WordPressConnector\Adapters\CoreAdapter())->register($registry);
$payload = array('id'=>101,'find'=>'Tape Solutions','replace'=>'ISOHULK');
$dry = $registry->execute('post.literal_replace', $payload, array('dry_run'=>true,'confirm'=>false));
if (($dry['match_count'] ?? null) !== 2
    || !preg_match('/^[a-f0-9]{64}$/D', (string) ($dry['before_fingerprint'] ?? ''))
    || $GLOBALS['posts'][101]->post_content !== '<p>Tape Solutions</p><p>Tape Solutions</p>') {
    throw new RuntimeException('Literal replacement preview was not accurate or mutated the page.');
}
$fail = static function (array $payload, array $context, string $why) use ($registry): void {
    try { $registry->execute('post.literal_replace', $payload, $context); }
    catch (RuntimeException $error) { return; }
    throw new RuntimeException('Unsafe literal replacement succeeded: ' . $why);
};
$bad = $payload; $bad['expected_matches'] = 1;
$fail($bad, array('dry_run'=>false,'expected_prewrite_fingerprint'=>$dry['before_fingerprint']), 'wrong match count');
$bad = $payload; $bad['expected_matches'] = 2;
$fail($bad, array('dry_run'=>false), 'no exact fingerprint');
$fail($bad, array('dry_run'=>false,'expected_prewrite_fingerprint'=>str_repeat('a',64)), 'stale fingerprint');
$bad['replace'] = '<script>alert(1)</script>';
$fail($bad, array('dry_run'=>true), 'HTML injection');
$bad = $payload; $bad['content'] = 'unsafe';
$fail($bad, array('dry_run'=>true), 'arbitrary source replacement');
$bad = $payload; $bad['find'] = 'NONEXISTENT';
$fail($bad, array('dry_run'=>true), 'no literal occurrences');
$GLOBALS['posts'][101]->post_status = 'draft';
$fail($payload, array('dry_run'=>true), 'nonpublished target');
$GLOBALS['posts'][101]->post_status = 'publish';
$GLOBALS['can_edit'] = false;
$fail($payload, array('dry_run'=>true), 'no edit capability');
$GLOBALS['can_edit'] = true;
$write = $registry->execute('post.literal_replace', array_merge($payload,array('expected_matches'=>2)),
    array('dry_run'=>false,'confirm'=>true,'expected_prewrite_fingerprint'=>$dry['before_fingerprint']));
if (true !== ($write['readback_verified'] ?? null)
    || ($write['_rollback']['action'] ?? '') !== 'post.update'
    || $GLOBALS['posts'][101]->post_content !== '<p>ISOHULK</p><p>ISOHULK</p>') {
    throw new RuntimeException('Literal replacement failed verification or did not preserve markup.');
}
$rollback = $write['_rollback']['payload'] ?? array();
if (($rollback['id'] ?? 0) !== 101 || ($rollback['content'] ?? '') !== '<p>Tape Solutions</p><p>Tape Solutions</p>') {
    throw new RuntimeException('Site-local rollback snapshot is missing.');
}
$fail($payload, array('dry_run'=>true), 'repeat replacement against absent text');

$tmp = sys_get_temp_dir() . '/wpconnector-literal-contract-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700);
register_shutdown_function(static function () use ($tmp) {
    foreach (glob($tmp.'/*') ?: array() as $path) @unlink($path);
    @rmdir($tmp);
});
$execute = static function (string $script, array $value, string $name) use ($tmp): array {
    $path = $tmp . '/' . $name . '.json';
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    $lines=array();$code=0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($path).' 2>&1', $lines, $code);
    return array($code, $path, implode("\n", $lines));
};
$validator = $root.'/scripts/validate-public-request.php';
$request = array(
    'version'=>1,'site_url'=>'https://example.test','request_id'=>'literal-page-public-test-0001',
    'action'=>'post.literal_replace','dry_run'=>true,'confirm'=>false,'payload'=>$payload,
);
list($status,$requestPath) = $execute($validator,$request,'good-dry-run');
if (0 !== $status) throw new RuntimeException('Safe literal preview request was rejected.');
$confirmed = $request;
$confirmed['request_id'] = 'literal-page-public-test-0002';
$confirmed['dry_run'] = false;
$confirmed['confirm'] = true;
$confirmed['expected_fingerprint'] = $dry['before_fingerprint'];
$confirmed['payload']['expected_matches'] = 2;
list($status,$confirmedPath) = $execute($validator,$confirmed,'good-write');
if (0 !== $status) throw new RuntimeException('Guarded literal update request was rejected.');
foreach (array(
    'without_fingerprint' => (function()use($confirmed){$x=$confirmed;unset($x['expected_fingerprint']);return $x;})(),
    'with_site_state_token' => array_merge($confirmed,array('expected_state_token'=>str_repeat('b',64))),
    'with_markup' => array_merge($request,array('payload'=>array('id'=>101,'find'=>'Tape Solutions','replace'=>'<b>bad</b>'))),
    'with_extra_payload' => array_merge($request,array('payload'=>array('id'=>101,'find'=>'Tape Solutions','replace'=>'ISOHULK','status'=>'draft'))),
) as $name => $rejected) {
    list($status) = $execute($validator,$rejected,'bad-'.$name);
    if (0 === $status) throw new RuntimeException('Public validation permitted '.$name);
}
$receiptResult = array(
    'version'=>1,'request_id'=>$confirmed['request_id'],'action'=>'post.literal_replace',
    'ok'=>true,'dry_run'=>false,'data'=>array(
        'post_id'=>101,'match_count'=>2,'before_fingerprint'=>$dry['before_fingerprint'],
        'before_content_sha256'=>$dry['before_content_sha256'],
        'after_content_sha256'=>$dry['after_content_sha256'],
        'readback_verified'=>true,'rollback_request_id'=>'original-content-private-only',
        'post_content'=>'DO NOT EXPORT RAW CONTENT',
    ),
);
$resultPath = $tmp.'/result.json';
file_put_contents($resultPath,json_encode($receiptResult,JSON_THROW_ON_ERROR));
$receiptPath = $tmp.'/receipt.json';
$out=array();$status=0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/build-public-receipt.php').' '.
    escapeshellarg($confirmedPath).' '.escapeshellarg($resultPath).' '.escapeshellarg($receiptPath).' 2>&1',$out,$status);
$receiptRaw = is_file($receiptPath) ? (string) file_get_contents($receiptPath) : '';
$receipt = $receiptRaw === '' ? array() : json_decode($receiptRaw,true,512,JSON_THROW_ON_ERROR);
if ($status !== 0 || ($receipt['match_count'] ?? null) !== 2 || ($receipt['readback_verified'] ?? null) !== true
    || ($receipt['before_fingerprint'] ?? '') !== $dry['before_fingerprint']
    || strpos($receiptRaw,'DO NOT EXPORT RAW CONTENT')!==false
    || strpos($receiptRaw,'original-content-private-only')!==false) {
    throw new RuntimeException('Public receipt leaked private content or lost verification evidence.');
}

echo "published page literal replacement, rollback and public privacy contracts OK\n";
