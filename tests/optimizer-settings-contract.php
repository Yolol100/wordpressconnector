<?php
declare(strict_types=1);
$GLOBALS['versions']=array('wp-asset-clean-up/wpacu.php'=>array('Version'=>'1.4.0.6'),'ewww-image-optimizer/ewww-image-optimizer.php'=>array('Version'=>'8.8.0'));
$GLOBALS['active']=true;
$GLOBALS['options']=array('wpassetcleanup_settings'=>json_encode(array('minify_loaded_css'=>'1','minify_loaded_js'=>'1','custom_setting'=>'unchanged')),'ewww_image_optimizer_lazy_load'=>'','ewww_image_optimizer_ll_autoscale'=>'1','ewww_image_optimizer_ll_abovethefold'=>'0','ewww_image_optimizer_add_missing_dims'=>'','ewww_image_optimizer_maxmediawidth'=>'2560','ewww_image_optimizer_maxmediaheight'=>'2560','ewww_image_optimizer_metadata_remove'=>'1','ewww_image_optimizer_webp'=>'','ewww_image_optimizer_backup_files'=>'','ewww_image_optimizer_webp_conversion_method'=>'local');
$GLOBALS['rocket']=array('remove_unused_css'=>1,'lazyload'=>0,'image_dimensions'=>1);
function get_plugins(){return $GLOBALS['versions'];}
function is_plugin_active($file){return $GLOBALS['active'];}
function get_option($name,$default=null){return $GLOBALS['options'][$name]??$default;}
function update_option($name,$value){$GLOBALS['options'][$name]=$value;return true;}
function delete_option($name){unset($GLOBALS['options'][$name]);return true;}
function wp_json_encode($value){return json_encode($value);}
function get_rocket_option($name,$default=null){return $GLOBALS['rocket'][$name]??$default;}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function current_user_can($cap){return $cap==='manage_options';}
$root=dirname(__DIR__).'/plugin/wordpressconnector/includes/';
foreach(array('Support/Json.php','Support/Fingerprint.php','Security/Policy.php','Runtime/Registry.php','Adapters/OptimizerSettingsAdapter.php') as $file) require_once $root.$file;
$r=new \Webactueel\WordPressConnector\Runtime\Registry();
(new \Webactueel\WordPressConnector\Adapters\OptimizerSettingsAdapter())->register($r);
$assertError=static function($run,$fragment){
 try{$run();}catch(RuntimeException $error){if(strpos($error->getMessage(),$fragment)!==false)return;throw $error;}
 throw new RuntimeException('Expected failure '.$fragment);
};
$orig=get_option('wpassetcleanup_settings');
$baseline=$r->execute('optimizer.settings.inspect',array('provider'=>'asset_cleanup'));
if($baseline['fields']['minify_loaded_css']!=='1')throw new RuntimeException('Asset settings unreadable');
$proposal=array('provider'=>'asset_cleanup','fields'=>array('minify_loaded_css'=>'0'));
$dry=$r->execute('optimizer.settings.update',$proposal,array('dry_run'=>true));
if(get_option('wpassetcleanup_settings')!==$orig)throw new RuntimeException('Dry-run changed settings');
$proposal['expected_before_fingerprint']=$dry['_current_fingerprint'];
$written=$r->execute('optimizer.settings.update',$proposal,array('dry_run'=>false));
$data=json_decode(get_option('wpassetcleanup_settings'),true);
if($data['minify_loaded_css']!=='0'||$data['custom_setting']!=='unchanged')throw new RuntimeException('Asset settings damaged');
$r->execute('optimizer.settings.restore',$written['_rollback']['payload'],array('rollback_mode'=>true));
if(json_decode(get_option('wpassetcleanup_settings'),true)!==json_decode($orig,true))throw new RuntimeException('Asset rollback failed');
$assertError(static function()use($r){$r->execute('optimizer.settings.update',array('provider'=>'asset_cleanup','fields'=>array('minify_loaded_css'=>'1')),array('dry_run'=>true));},'only disable');
$before=get_option('ewww_image_optimizer_lazy_load');
$p=array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_lazy_load'=>'1'));
$dry=$r->execute('optimizer.settings.update',$p,array('dry_run'=>true));
if(get_option('ewww_image_optimizer_lazy_load')!==$before)throw new RuntimeException('EWWW dry run changed value');
$p['expected_before_fingerprint']=$dry['_current_fingerprint'];
$done=$r->execute('optimizer.settings.update',$p,array('dry_run'=>false));
if(get_option('ewww_image_optimizer_lazy_load')!=='1')throw new RuntimeException('EWWW setting not written');
$r->execute('optimizer.settings.restore',$done['_rollback']['payload'],array('rollback_mode'=>true));
if(get_option('ewww_image_optimizer_lazy_load')!==$before)throw new RuntimeException('EWWW rollback failed');
$GLOBALS['rocket']['lazyload']=1;
$assertError(static function()use($r){$r->execute('optimizer.settings.update',array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_lazy_load'=>'1')),array('dry_run'=>true));},'already active');
$GLOBALS['rocket']['lazyload']=0;
$assertError(static function()use($r){$r->execute('optimizer.settings.update',array('provider'=>'ewww','fields'=>array('ewww_image_optimizer_add_missing_dims'=>'1')),array('dry_run'=>true));},'already owns');
$assertError(static function()use($r){$r->execute('optimizer.settings.update',array('provider'=>'ewww','fields'=>array('unlisted_option'=>'1')),array('dry_run'=>true));},'allowlist');
$GLOBALS['versions']['ewww-image-optimizer/ewww-image-optimizer.php']['Version']='8.9.0';
$assertError(static function()use($r){$r->execute('optimizer.settings.inspect',array('provider'=>'ewww'));},'Unsupported');
echo "Optimizer contracts OK\n";
