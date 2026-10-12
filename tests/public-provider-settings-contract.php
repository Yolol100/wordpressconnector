<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root.'/scripts/validate-public-request.php';
$receiptBuilder = $root.'/scripts/build-public-receipt.php';
$tmp = sys_get_temp_dir().'/wpconnector-safe-provider-'.bin2hex(random_bytes(5));
mkdir($tmp,0700);
register_shutdown_function(static function()use($tmp){foreach(glob($tmp.'/*')?:array() as $p)@unlink($p);@rmdir($tmp);});
$store = static function ($name,$data)use($tmp) {
    $path=$tmp.'/'.$name.'.json';
    file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    return $path;
};
$run = static function ($script,$args) {
    $out=array();$exit=0;$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
    foreach($args as $arg)$command.=' '.escapeshellarg($arg);
    exec($command.' 2>&1',$out,$exit);
    return array($exit,implode("\n",$out));
};
$base = array('version'=>1,'site_url'=>'https://example.test','request_id'=>'safe-provider-config-001',
    'action'=>'gtranslate.settings.inspect','payload'=>array(),'dry_run'=>true,'confirm'=>false);
foreach(array(
    'gtranslate.settings.inspect'=>array(),
    'yoast.site_representation.inspect'=>array(),
    'gtranslate.settings.update'=>array('fields'=>array('default_language'=>'en','incl_langs'=>array('nl','en'))),
    'yoast.site_representation.update'=>array('fields'=>array('company_name'=>'ISOHULK','org_description'=>'Industrial Tapes and Packaging Solutions')),
) as $action=>$payload) {
    $req=$base;$req['action']=$action;$req['payload']=$payload;
    $req['request_id']='provider-'.$action.'-safe-001';
    list($code,$message)=$run($validator,array($store('safe-'.str_replace('.','-',$action),$req)));
    if($code!==0)throw new RuntimeException('Valid public provider request failed: '.$action.' '.$message);
}
$badCases=array();
$bad=$base;$bad['action']='gtranslate.settings.update';
$bad['payload']=array('fields'=>array('custom_domains_data'=>'private'));
$badCases['gtranslate-arbitrary-option']=$bad;
$bad=$base;$bad['action']='yoast.site_representation.update';
$bad['payload']=array('fields'=>array('logo_attachment_id'=>123));
$badCases['yoast-logo-edit']=$bad;
$bad=$base;$bad['action']='yoast.site_representation.update';
$bad['payload']=array('fields'=>array('company_name'=>'<script>'));
$badCases['yoast-script']=$bad;
$bad=$base;$bad['action']='gtranslate.settings.restore';$bad['payload']=array();
$badCases['gtranslate-restore-direct']=$bad;
$bad=$base;$bad['action']='yoast.site_representation.restore';$bad['payload']=array();
$badCases['yoast-restore-direct']=$bad;
$bad=$base;$bad['action']='option.update';$bad['payload']=array('name'=>'blogname','value'=>'ISOHULK');
$badCases['arbitrary-option']=$bad;
$good=$base;$good['action']='gtranslate.settings.update';
$good['payload']=array('fields'=>array('default_language'=>'en'));
$good['dry_run']=false;$good['confirm']=true;
$good['expected_fingerprint']=str_repeat('a',64);
$bad=$good;$bad['payload']['expected_before_fingerprint']=str_repeat('b',64);
$badCases['gtranslate-fingerprint-mismatch']=$bad;
$bad=$good;
$badCases['gtranslate-fingerprint-missing']=$bad;
$good['payload']['expected_before_fingerprint']=str_repeat('a',64);
list($code,$message)=$run($validator,array($store('good-gtranslate-confirm',$good)));
if($code!==0)throw new RuntimeException('Valid GTranslate confirmed public change rejected: '.$message);
$goodYoast=$base;
$goodYoast['action']='yoast.site_representation.update';
$goodYoast['payload']=array('fields'=>array('company_name'=>'ISOHULK'));
$goodYoast['dry_run']=false;$goodYoast['confirm']=true;
$goodYoast['expected_fingerprint']=str_repeat('a',64);
list($code,$message)=$run($validator,array($store('good-yoast-confirm',$goodYoast)));
if($code!==0)throw new RuntimeException('Valid Yoast confirmed public change rejected: '.$message);
foreach($badCases as $name=>$bad) {
    $bad['request_id']='provider-bad-'.$name.'-001';
    list($code)=$run($validator,array($store('invalid-'.$name,$bad)));
    if($code===0)throw new RuntimeException('Public validator accepted unsafe provider operation: '.$name);
}
$yoastInspect=$base;
$yoastInspect['action']='yoast.site_representation.inspect';
$yoastInspect['request_id']='safe-provider-yoast-receipt-001';
$receiptInput=array('version'=>1,'ok'=>true,'request_id'=>$yoastInspect['request_id'],
    'action'=>$yoastInspect['action'],'dry_run'=>true,'data'=>array(
        'fields'=>array('company_or_person'=>'company','company_name'=>'ISOHULK',
            'org_description'=>'Industrial Tapes and Packaging Solutions',
            'company_logo_meta'=>array('private_token'=>'DO_NOT_EXPORT_SECRET'),
            'unrelated_login_data'=>'DO_NOT_EXPORT_SECRET'),
        'fingerprint'=>str_repeat('c',64),
    ));
$path=$store('yoast-result',$receiptInput);
$out=$tmp.'/yoast-receipt.json';
list($code)=$run($receiptBuilder,array($store('yoast-request',$yoastInspect),$path,$out));
$raw=is_file($out)?file_get_contents($out):'';
$receipt=$raw===''?array():json_decode($raw,true,512,JSON_THROW_ON_ERROR);
if($code!==0 || ($receipt['fields']['company_name']??'')!=='ISOHULK'
    || ($receipt['settings_fingerprint']??'')!==str_repeat('c',64)
    || strpos($raw,'DO_NOT_EXPORT_SECRET')!==false || strpos($raw,'company_logo_meta')!==false) {
    throw new RuntimeException('Public Yoast receipt leaked provider internals.');
}
$gtranslate=$base;$gtranslate['request_id']='safe-provider-gtranslate-receipt-001';
$gresult=array('version'=>1,'ok'=>true,'request_id'=>$gtranslate['request_id'],
    'action'=>$gtranslate['action'],'dry_run'=>true,'data'=>array(
        'fields'=>array('default_language'=>'en','incl_langs'=>array('nl','en'),
            'fincl_langs'=>array('nl','en'),'detect_browser_language'=>1,
            'secret_provider_field'=>'DO_NOT_EXPORT_SECRET'),
        'fingerprint'=>str_repeat('d',64),'version'=>'5.0.2'));
$out=$tmp.'/gtranslate-receipt.json';
list($code)=$run($receiptBuilder,array($store('gt-request',$gtranslate),$store('gt-result',$gresult),$out));
$raw=is_file($out)?file_get_contents($out):'';
$receipt=$raw===''?array():json_decode($raw,true,512,JSON_THROW_ON_ERROR);
if($code!==0 || ($receipt['fields']['default_language']??'')!=='en'
    || ($receipt['fields']['incl_langs']??array())!==array('nl','en')
    || ($receipt['provider_version']??'')!=='5.0.2'
    || strpos($raw,'DO_NOT_EXPORT_SECRET')!==false) {
    throw new RuntimeException('Public GTranslate receipt leaked provider internals or lost data.');
}
echo "authenticated public GTranslate and Yoast provider allowlists and receipts OK\n";
