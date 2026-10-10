<?php
declare(strict_types=1);

$GLOBALS['fake_gtranslate_options']=array('GTranslate'=>array(
    'default_language'=>'nl',
    'incl_langs'=>array('nl','en','de'),
    'fincl_langs'=>array('nl','en','de'),
    'detect_browser_language'=>1,
    'widget_look'=>'float',
    'flag_style'=>'2d',
    'custom_domains_data'=>'SECRET_PROVIDER_CUSTOM_DOMAIN_SETTINGS',
    'pro_version'=>0,
));
$GLOBALS['fake_gtranslate_version']='5.0.1';
$GLOBALS['fake_gtranslate_active']=true;
$GLOBALS['fake_gtranslate_caps']=array();
function get_plugins():array{return array('gtranslate/gtranslate.php'=>array('Version'=>$GLOBALS['fake_gtranslate_version']));}
function is_plugin_active($path):bool{return $path==='gtranslate/gtranslate.php' && $GLOBALS['fake_gtranslate_active'];}
function get_option($name,$default=null){return $GLOBALS['fake_gtranslate_options'][$name]??$default;}
function update_option($name,$value){$GLOBALS['fake_gtranslate_options'][$name]=$value;return true;}
function current_user_can($cap):bool{return in_array($cap,$GLOBALS['fake_gtranslate_caps'],true);}
function sanitize_text_field($value):string{return trim(strip_tags((string)$value));}
$root=dirname(__DIR__).'/plugin/wordpressconnector/includes/';
foreach(array('Support/Json.php','Support/Fingerprint.php','Security/Policy.php',
    'Runtime/Registry.php','Adapters/GTranslateSettingsAdapter.php') as $file)require_once $root.$file;
$reg=new \Webactueel\WordPressConnector\Runtime\Registry();
(new \Webactueel\WordPressConnector\Adapters\GTranslateSettingsAdapter())->register($reg);
$descriptor=$reg->descriptor('gtranslate.settings.update');
if(empty($descriptor['mutation'])||empty($descriptor['privileged'])
    ||$descriptor['capability']!=='manage_options')throw new RuntimeException('GTranslate ACL descriptor invalid.');
$policy='Webactueel\\WordPressConnector\\Security\\Policy';
$policy::setPublicRepositoryContext(false);
$throws=static function(callable $callback,string $fragment):void {
    try{$callback();}catch(RuntimeException $e) {
        if(strpos($e->getMessage(),$fragment)!==false)return;
        throw new RuntimeException('Incorrect failure: '.$e->getMessage());
    }
    throw new RuntimeException('Expected rejection containing '.$fragment);
};
$throws(static function()use($policy,$descriptor){
    $policy::assertActionAllowed($descriptor,false,true);
},'lacks the required WordPress capability');
$GLOBALS['fake_gtranslate_caps'][]='manage_options';
$policy::assertActionAllowed($descriptor,false,true);
$original=get_option('GTranslate');
$baseline=$reg->execute('gtranslate.settings.inspect',array());
if($baseline['version']!=='5.0.1'
    ||$baseline['fields']['default_language']!=='nl'
    ||strpos(json_encode($baseline),'SECRET_PROVIDER')!==false)
    throw new RuntimeException('GTranslate site inventory or secret isolation failed.');

$patch=array('fields'=>array(
    'default_language'=>'nl',
    'incl_langs'=>array('nl','en'),
    'fincl_langs'=>array('nl','en'),
    'detect_browser_language'=>0,
));
$before=$reg->execute('gtranslate.settings.update',$patch,array('dry_run'=>true));
if($before['after']['fincl_langs']!==array('nl','en')
    ||$before['after']['detect_browser_language']!==0
    ||get_option('GTranslate')!==$original)throw new RuntimeException('Preview touched live options.');
$throws(static function()use($reg,$patch){
    $reg->execute('gtranslate.settings.update',$patch,array('dry_run'=>false));
},'changed since the preflight read');
$throws(static function()use($reg,$patch){
    $bad=$patch;
    $bad['expected_before_fingerprint']=str_repeat('a',64);
    $reg->execute('gtranslate.settings.update',$bad,array('dry_run'=>false));
},'changed since the preflight read');
$patch['expected_before_fingerprint']=$before['_current_fingerprint'];
$apply=$reg->execute('gtranslate.settings.update',$patch,array('dry_run'=>false));
$applied=get_option('GTranslate');
if($applied['incl_langs']!==array('nl','en')
    ||$applied['fincl_langs']!==array('nl','en')
    ||$applied['detect_browser_language']!==0
    ||$applied['custom_domains_data']!=='SECRET_PROVIDER_CUSTOM_DOMAIN_SETTINGS'
    ||$applied['widget_look']!=='float')
    throw new RuntimeException('GTranslate write damaged unrelated provider options.');
$updated=$reg->execute('gtranslate.settings.inspect',array());
if($updated['fields']['incl_langs']!==array('nl','en')
    ||$updated['fields']['detect_browser_language']!==0)
    throw new RuntimeException('GTranslate write/readback failed.');
$throws(static function()use($reg,$apply){
    $reg->execute('gtranslate.settings.restore',$apply['_rollback']['payload']);
},'rollback-only');
$restored=$reg->execute('gtranslate.settings.restore',$apply['_rollback']['payload'],array('rollback_mode'=>true));
if(!$restored['restored']||get_option('GTranslate')!==$original)
    throw new RuntimeException('GTranslate rollback did not recover all original options.');
$throws(static function()use($reg,$apply){
    $reg->execute('gtranslate.settings.restore',$apply['_rollback']['payload'],array('rollback_mode'=>true));
},'changed since the preflight read');
$cases=array(
    array('fields'=>array('api_key'=>'secret'), 'error'=>'allowlist'),
    array('fields'=>array('default_language'=>'de'), 'error'=>'Dutch or English'),
    array('fields'=>array('fincl_langs'=>array('nl','fr')), 'error'=>'exactly Dutch and English'),
    array('fields'=>array('incl_langs'=>array('nl','en','fr')), 'error'=>'exactly Dutch and English'),
    array('fields'=>array('detect_browser_language'=>'on'), 'error'=>'0 or 1'),
);
foreach($cases as $case){
    $throws(static function()use($reg,$case){
        $reg->execute('gtranslate.settings.update',array('fields'=>$case['fields']),array('dry_run'=>true));
    },$case['error']);
}
$GLOBALS['fake_gtranslate_version']='5.1.0';
$throws(static function()use($reg){
    $reg->execute('gtranslate.settings.inspect',array());
},'supported 5.0.x');
$GLOBALS['fake_gtranslate_version']='5.0.1';
$GLOBALS['fake_gtranslate_active']=false;
$throws(static function()use($reg){
    $reg->execute('gtranslate.settings.inspect',array());
},'active supported');
echo "GTranslate provider version, ACL, secret isolation, dry-run, NL/EN, exact fingerprint, write, rollback and negatives OK\n";
