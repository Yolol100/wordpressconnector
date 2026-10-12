<?php
declare(strict_types=1);

// Sandbox options: verifies a bounded public-safe path without touching a site.
$GLOBALS['site_identity_options'] = array(
    'blogname'=>'Tape Solutions','blogdescription'=>'Oplossingen voor verpakkingen',
    'timezone_string'=>'UTC','WPLANG'=>'nl_NL',
    'siteurl'=>'https://example.test','admin_email'=>'never@example.test',
);
$GLOBALS['identity_caps'] = true;
$GLOBALS['identity_locales'] = array('nl_NL','en_GB');
function get_option($key, $fallback = false) { return $GLOBALS['site_identity_options'][$key] ?? $fallback; }
function update_option($key, $value) { $GLOBALS['site_identity_options'][$key] = $value; return true; }
function current_user_can($capability) { return $GLOBALS['identity_caps'] && 'manage_options' === $capability; }
function is_multisite() { return false; }
function get_available_languages() { return $GLOBALS['identity_locales']; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }

$root = dirname(__DIR__);
foreach (array('Support/Json.php','Support/Fingerprint.php','Security/Policy.php','Runtime/Registry.php','Adapters/SiteIdentityAdapter.php') as $p) {
    require_once $root.'/plugin/wordpressconnector/includes/'.$p;
}
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
Policy::setPublicRepositoryContext(true);
$registry = new Registry();
(new Webactueel\WordPressConnector\Adapters\SiteIdentityAdapter())->register($registry);
$inspect = $registry->descriptor('site.identity.inspect');
$update = $registry->descriptor('site.identity.update');
if (!$inspect['privileged'] || !$inspect['public_repository_safe'] || $inspect['capability'] !== 'manage_options'
    || !$update['privileged'] || !$update['mutation'] || !$update['public_repository_safe']) {
    throw new RuntimeException('Public-safe site identity descriptor did not retain manage_options.');
}
Policy::assertActionAllowed($inspect,true,false);
$fields = array('blogname'=>'ISOHULK','blogdescription'=>'Industrial Tapes and Packaging Solutions',
    'timezone_string'=>'Europe/Amsterdam','WPLANG'=>'en_GB');
$dry = $registry->execute('site.identity.update',array('fields'=>$fields),array('dry_run'=>true));
if (($dry['before']['blogname'] ?? '') !== 'Tape Solutions'
    || ($dry['after']['blogname'] ?? '') !== 'ISOHULK'
    || $GLOBALS['site_identity_options']['blogname'] !== 'Tape Solutions') {
    throw new RuntimeException('Site identity dry-run mutated the site or lacked expected values.');
}
$expectFail = static function (callable $f, string $why) {
    try { $f(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException('Unsafe site identity operation accepted: '.$why);
};
$expectFail(static function()use($registry) {
    $registry->execute('site.identity.update',array('fields'=>array('admin_email'=>'attacker@example.test')),array('dry_run'=>true));
},'generic sensitive key');
$expectFail(static function()use($registry) {
    $registry->execute('site.identity.update',array('fields'=>array('blogname'=>'<script>x</script>')),array('dry_run'=>true));
},'markup');
$expectFail(static function()use($registry,$fields) {
    $registry->execute('site.identity.update',array('fields'=>$fields),array('dry_run'=>false));
},'missing fingerprint');
$GLOBALS['identity_caps'] = false;
$expectFail(static function()use($registry) {
    Policy::assertActionAllowed($registry->descriptor('site.identity.update'),false,true);
},'no manage_options');
$GLOBALS['identity_caps'] = true;
$GLOBALS['identity_locales'] = array('nl_NL');
$expectFail(static function()use($registry) {
    $registry->execute('site.identity.update',array('fields'=>array('WPLANG'=>'en_GB')),array('dry_run'=>true));
},'language pack absent');
$GLOBALS['identity_locales'][]='en_GB';

$guard = Webactueel\WordPressConnector\Support\Fingerprint::make($dry['before']);
$result = $registry->execute('site.identity.update',array('fields'=>$fields),
    array('dry_run'=>false,'confirm'=>true,'expected_prewrite_fingerprint'=>$guard));
if (true !== ($result['readback_verified'] ?? null)
    || $GLOBALS['site_identity_options']['blogname'] !== 'ISOHULK'
    || $GLOBALS['site_identity_options']['timezone_string'] !== 'Europe/Amsterdam'
    || $GLOBALS['site_identity_options']['admin_email'] !== 'never@example.test') {
    throw new RuntimeException('Site identity update failed or modified an unrelated option.');
}
$rollback = $result['_rollback'] ?? array();
if (($rollback['action'] ?? '') !== 'site.identity.restore') throw new RuntimeException('Site identity rollback missing.');
$preview = $registry->execute('site.identity.restore',$rollback['payload'],array('dry_run'=>true,'rollback_mode'=>true));
if (empty($preview['_current_fingerprint']) || $GLOBALS['site_identity_options']['blogname'] !== 'ISOHULK') {
    throw new RuntimeException('Site identity rollback dry-run was ineffective or performed a mutation.');
}
$restore = $registry->execute('site.identity.restore',$rollback['payload'],array('dry_run'=>false,'rollback_mode'=>true));
if (empty($restore['restored']) || $GLOBALS['site_identity_options']['blogname'] !== 'Tape Solutions') {
    throw new RuntimeException('Site identity rollback did not restore original settings.');
}
$expectFail(static function()use($registry,$rollback) {
    $registry->execute('site.identity.restore',$rollback['payload'],array('dry_run'=>false,'rollback_mode'=>true));
},'replayed stale rollback');

$tmp = sys_get_temp_dir().'/wpconn-identity-'.bin2hex(random_bytes(5));
mkdir($tmp,0700);
register_shutdown_function(static function()use($tmp){foreach(glob($tmp.'/*')?:array() as $p)@unlink($p);@rmdir($tmp);});
$command = static function ($script, $object, $file) use($tmp): array {
    $path = $tmp.'/'.$file.'.json';
    file_put_contents($path,json_encode($object,JSON_THROW_ON_ERROR));
    $out=array();$code=0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($path).' 2>&1',$out,$code);
    return array($code,$path,implode("\n",$out));
};
$request = array('version'=>1,'site_url'=>'https://example.test','request_id'=>'site-identity-test-0001',
    'action'=>'site.identity.update','payload'=>array('fields'=>$fields),'dry_run'=>true,'confirm'=>false);
$validator=$root.'/scripts/validate-public-request.php';
list($code,$requestPath)=$command($validator,$request,'good-request');
if($code!==0)throw new RuntimeException('Valid public site identity dry-run request was blocked.');
$confirmed=$request;$confirmed['request_id']='site-identity-test-0002';
$confirmed['dry_run']=false;$confirmed['confirm']=true;
$confirmed['expected_fingerprint']=$guard;
list($code,$confirmedPath)=$command($validator,$confirmed,'good-confirmed');
if($code!==0)throw new RuntimeException('Valid confirmed site identity request was blocked.');
foreach(array(
    'secret' => array_merge($request,array('payload'=>array('fields'=>array('admin_email'=>'bad')))),
    'no_fingerprint' => (function()use($confirmed){$a=$confirmed;unset($a['expected_fingerprint']);return $a;})(),
    'locale_unknown' => array_merge($request,array('payload'=>array('fields'=>array('WPLANG'=>'de_DE')))),
    'state_token' => array_merge($request,array('expected_state_token'=>str_repeat('a',64))),
) as $name=>$bad) {
    list($code)=$command($validator,$bad,'bad-'.$name);
    if($code===0)throw new RuntimeException('Unsafe site identity request passed validator: '.$name);
}
$receiptResult = array('version'=>1,'ok'=>true,'request_id'=>$confirmed['request_id'],
    'action'=>'site.identity.update','dry_run'=>false,'data'=>array(
        'before'=>$dry['before'],'after'=>$result['after'],'readback_verified'=>true,
        'rollback_request_id'=>'site-local-rollback-only','irrelevant'=>'SECRET_MUST_NOT_LEAK',
    ));
$resultPath=$tmp.'/result.json';file_put_contents($resultPath,json_encode($receiptResult,JSON_THROW_ON_ERROR));
$receiptPath=$tmp.'/receipt.json';$lines=array();$code=0;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/build-public-receipt.php').' '.escapeshellarg($confirmedPath).' '.escapeshellarg($resultPath).' '.escapeshellarg($receiptPath).' 2>&1',$lines,$code);
$raw=is_file($receiptPath)?file_get_contents($receiptPath):'';
$rec=$raw===''?array():json_decode($raw,true,512,JSON_THROW_ON_ERROR);
if($code!==0 || ($rec['fields']['blogname']??'')!=='ISOHULK' || ($rec['readback_verified']??null)!==true
    || strpos($raw,'SECRET_MUST_NOT_LEAK')!==false || strpos($raw,'site-local-rollback-only')!==false) {
    throw new RuntimeException('Public identity receipt is incomplete or leaks private data.');
}
echo "public site identity authorization, dry-run, rollback and safe receipts OK\n";
