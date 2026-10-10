<?php
declare(strict_types=1);
$GLOBALS['versions']=array('wp-asset-clean-up/wpacu.php'=>array('Version'=>'1.4.0.6'),'ewww-image-optimizer/ewww-image-optimizer.php'=>array('Version'=>'8.8.0'));
$GLOBALS['active']=true;
$GLOBALS['multisite']=false;
$GLOBALS['network_active']=false;
$GLOBALS['network_capable']=false;
$GLOBALS['network_options']=array();
$GLOBALS['options']=array('wpassetcleanup_settings'=>json_encode(array('minify_loaded_css'=>'1','minify_loaded_js'=>'1','custom_setting'=>'unchanged')),'ewww_image_optimizer_lazy_load'=>'','ewww_image_optimizer_ll_autoscale'=>'1','ewww_image_optimizer_ll_abovethefold'=>'0','ewww_image_optimizer_add_missing_dims'=>'','ewww_image_optimizer_maxmediawidth'=>'2560','ewww_image_optimizer_maxmediaheight'=>'2560','ewww_image_optimizer_metadata_remove'=>'1','ewww_image_optimizer_webp'=>'','ewww_image_optimizer_backup_files'=>'','ewww_image_optimizer_webp_conversion_method'=>'local');
$GLOBALS['rocket']=array('remove_unused_css'=>1,'lazyload'=>0,'image_dimensions'=>1);
function get_plugins(){return $GLOBALS['versions'];}
function is_plugin_active($file){return $GLOBALS['active'];}
function is_multisite(){return $GLOBALS['multisite'];}
function is_plugin_active_for_network($file){return $GLOBALS['network_active'];}
function get_site_option($name,$default=null){return $GLOBALS['network_options'][$name]??$default;}
function update_site_option($name,$value){$GLOBALS['network_options'][$name]=$value;return true;}
function get_option($name,$default=null){return $GLOBALS['options'][$name]??$default;}
function update_option($name,$value){$GLOBALS['options'][$name]=$value;return true;}
function delete_option($name){unset($GLOBALS['options'][$name]);return true;}
function wp_json_encode($value){return json_encode($value);}
function get_rocket_option($name,$default=null){return $GLOBALS['rocket'][$name]??$default;}
function ewww_image_optimizer_get_option($name,$default=null){$network=$GLOBALS['multisite']&&$GLOBALS['network_active']&&!get_site_option('ewww_image_optimizer_allow_multisite_override');return $network?get_site_option($name,$default):get_option($name,$default);}
function ewww_image_optimizer_set_option($name,$value){$network=$GLOBALS['multisite']&&$GLOBALS['network_active']&&!get_site_option('ewww_image_optimizer_allow_multisite_override');return $network?update_site_option($name,$value):update_option($name,$value);}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function current_user_can($cap){return $cap==='manage_options'||($cap==='manage_network_options'&&$GLOBALS['network_capable']);}
$root=dirname(__DIR__).'/plugin/wordpressconnector/includes/';
foreach(array('Support/Json.php','Support/Fingerprint.php','Security/Policy.php','Runtime/Registry.php','Runtime/Runner.php','Adapters/OptimizerSettingsAdapter.php') as $file) require_once $root.$file;
$r=new \Webactueel\WordPressConnector\Runtime\Registry();
(new \Webactueel\WordPressConnector\Adapters\OptimizerSettingsAdapter())->register($r);
$assertError=static function($run,$fragment){
 try{$run();}catch(RuntimeException $error){if(strpos($error->getMessage(),$fragment)!==false)return;throw $error;}
 throw new RuntimeException('Expected failure '.$fragment);
};
$orig=get_option('wpassetcleanup_settings');
$baseline=$r->execute('optimizer.settings.inspect',array('provider'=>'asset_cleanup'));
if($baseline['fields']['minify_loaded_css']!=='1'||$baseline['effective_state_verified']!==false)throw new RuntimeException('Asset settings must be labelled as stored-only');
$assertError(static function()use($r){$r->execute('optimizer.settings.update',array('provider'=>'asset_cleanup','fields'=>array('minify_loaded_css'=>'0')),array('dry_run'=>true));},'provider API');
if(get_option('wpassetcleanup_settings')!==$orig)throw new RuntimeException('Blocked Asset CleanUp request mutated data');
// Legacy rollback snapshots must not bypass the new Asset CleanUp write prohibition.
$rollback=array('provider'=>'asset_cleanup','field'=>'minify_loaded_css','exists'=>true,'value'=>'1','expected_after_fingerprint'=>str_repeat('a',64));
$assertError(static function()use($r,$rollback){$r->execute('optimizer.settings.restore',$rollback,array('rollback_mode'=>true));},'rollback is blocked');
if(get_option('wpassetcleanup_settings')!==$orig)throw new RuntimeException('Legacy rollback modified Asset CleanUp options');
$before=get_option('ewww_image_optimizer_lazy_load');
$p=array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_lazy_load'=>'1'));
$dry=$r->execute('optimizer.settings.update',$p,array('dry_run'=>true));
if(get_option('ewww_image_optimizer_lazy_load')!==$before)throw new RuntimeException('EWWW dry run changed value');
$p['expected_before_fingerprint']=$dry['_current_fingerprint'];
$done=$r->execute('optimizer.settings.update',$p,array('dry_run'=>false));
if(get_option('ewww_image_optimizer_lazy_load')!=='1')throw new RuntimeException('EWWW setting not written');
$r->execute('optimizer.settings.restore',$done['_rollback']['payload'],array('rollback_mode'=>true));
if(get_option('ewww_image_optimizer_lazy_load')!==$before)throw new RuntimeException('EWWW rollback failed');
$p=array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_lazy_load'=>false));
$dry=$r->execute('optimizer.settings.update',$p,array('dry_run'=>true));
$p['expected_before_fingerprint']=$dry['_current_fingerprint'];
$r->execute('optimizer.settings.update',$p,array('dry_run'=>false));
if(get_option('ewww_image_optimizer_lazy_load')!=='0')throw new RuntimeException('EWWW false must normalize to zero');
$GLOBALS['rocket']['lazyload']=1;
$assertError(static function()use($r){$r->execute('optimizer.settings.update',array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_lazy_load'=>'1')),array('dry_run'=>true));},'already active');
$GLOBALS['rocket']['lazyload']=0;
$assertError(static function()use($r){$r->execute('optimizer.settings.update',array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_add_missing_dims'=>'1')),array('dry_run'=>true));},'already owns');
$assertError(static function()use($r){$r->execute('optimizer.settings.update',array('provider'=>'ewww','fields'=>array('unlisted_option'=>'1')),array('dry_run'=>true));},'allowlist');
// A REST/MCP preview token must protect the actual write-time snapshot.
$guarded=array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_lazy_load'=>'1'));
$preview=$r->execute('optimizer.settings.update',$guarded,array('dry_run'=>true));
$beforeRace=get_option('ewww_image_optimizer_lazy_load');
$r->register('test.optimizer_state_race',static function(array $payload,array $context)use($r){
    $result=$r->execute('optimizer.settings.update',$payload,$context);
    if(!empty($context['dry_run']))$GLOBALS['options']['ewww_image_optimizer_ll_autoscale']='0';
    return $result;
});
$runnerClass=new ReflectionClass(\\Webactueel\\WordPressConnector\\Runtime\\Runner::class);
$runner=$runnerClass->newInstanceWithoutConstructor();
$runnerRegistry=$runnerClass->getProperty('registry');
$runnerRegistry->setAccessible(true);
$runnerRegistry->setValue($runner,$r);
$invokeGuard=$runnerClass->getMethod('executeWithStateGuards');
$invokeGuard->setAccessible(true);
$assertError(static function()use($invokeGuard,$runner,$guarded,$preview){
    $invokeGuard->invoke($runner,'test.optimizer_state_race',$guarded,array('dry_run'=>false),$preview['_current_fingerprint'],null);
},'changed since dry-run');
if(get_option('ewww_image_optimizer_lazy_load')!==$beforeRace)throw new RuntimeException('Stale write changed EWWW option');
$GLOBALS['options']['ewww_image_optimizer_ll_autoscale']='1';

// Network activation must require network capability only without per-site overrides.
$GLOBALS['multisite']=true;
$GLOBALS['network_active']=true;
$GLOBALS['network_options']=array('ewww_image_optimizer_allow_multisite_override'=>false);
foreach($GLOBALS['options'] as $key=>$value){
    if(strpos($key,'ewww_image_optimizer_')===0)$GLOBALS['network_options'][$key]=$value;
}
$netBefore=get_site_option('ewww_image_optimizer_lazy_load');
$netRequest=array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_lazy_load'=>'1'));
$netPreview=$r->execute('optimizer.settings.update',$netRequest,array('dry_run'=>true));
$netRequest['expected_before_fingerprint']=$netPreview['_current_fingerprint'];
$assertError(static function()use($r,$netRequest){$r->execute('optimizer.settings.update',$netRequest,array('dry_run'=>false));},'manage_network_options');
if(get_site_option('ewww_image_optimizer_lazy_load')!==$netBefore)throw new RuntimeException('Unauthorized network update changed settings');
$GLOBALS['network_capable']=true;
$netDone=$r->execute('optimizer.settings.update',$netRequest,array('dry_run'=>false));
if(get_site_option('ewww_image_optimizer_lazy_load')!=='1')throw new RuntimeException('EWWW network accessor write failed');
$r->execute('optimizer.settings.restore',$netDone['_rollback']['payload'],array('rollback_mode'=>true));
if(get_site_option('ewww_image_optimizer_lazy_load')!==$netBefore)throw new RuntimeException('EWWW network rollback failed');
$GLOBALS['network_options']['ewww_image_optimizer_allow_multisite_override']=true;
$GLOBALS['network_capable']=false;
$sitePreview=$r->execute('optimizer.settings.update',array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_lazy_load'=>'1')),array('dry_run'=>true));
$siteRequest=array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_lazy_load'=>'1'),'expected_before_fingerprint'=>$sitePreview['_current_fingerprint']);
$siteDone=$r->execute('optimizer.settings.update',$siteRequest,array('dry_run'=>false));
if(get_option('ewww_image_optimizer_lazy_load')!=='1'||get_site_option('ewww_image_optimizer_lazy_load')!==$netBefore)throw new RuntimeException('EWWW per-site override wrote network setting');
$r->execute('optimizer.settings.restore',$siteDone['_rollback']['payload'],array('rollback_mode'=>true));
$GLOBALS['multisite']=false;
$GLOBALS['network_active']=false;

$GLOBALS['versions']['ewww-image-optimizer/ewww-image-optimizer.php']['Version']='8.9.0';
$assertError(static function()use($r){$r->execute('optimizer.settings.inspect',array('provider'=>'ewww'));},'Unsupported');
echo "Optimizer contracts OK\n";
