<?php
declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Installed-version contracts for EWWW and Asset CleanUp, designed to keep
 * WP Rocket as the only CSS/JS minifier and unused CSS engine. This adapter
 * does not unload assets, trigger image re-optimization, or export credentials.
 */
final class OptimizerSettingsAdapter
{
    private const ASSET_FIELDS = array(
        'minify_loaded_css', 'minify_loaded_js', 'combine_loaded_css',
        'combine_loaded_js', 'inline_css_files', 'critical_css_status'
    );
    private const EWWW_FIELDS = array(
        'ewww_image_optimizer_lazy_load', 'ewww_image_optimizer_ll_autoscale',
        'ewww_image_optimizer_ll_abovethefold', 'ewww_image_optimizer_add_missing_dims',
        'ewww_image_optimizer_maxmediawidth', 'ewww_image_optimizer_maxmediaheight',
        'ewww_image_optimizer_metadata_remove', 'ewww_image_optimizer_webp',
        'ewww_image_optimizer_backup_files', 'ewww_image_optimizer_webp_conversion_method',
    );
    private const EWWW_WRITE = array(
        'ewww_image_optimizer_lazy_load', 'ewww_image_optimizer_ll_autoscale',
        'ewww_image_optimizer_ll_abovethefold', 'ewww_image_optimizer_add_missing_dims'
    );

    public function register(Registry $registry): void
    {
        $read = array('privileged'=>true,'capability'=>'manage_options');
        $write = array_merge($read,array('mutation'=>true));
        $registry->register('optimizer.settings.inspect',array($this,'inspect'),$read);
        $registry->register('optimizer.settings.update',array($this,'update'),$write);
        $registry->register('optimizer.settings.restore',array($this,'restore'),$write);
    }

    public function inspect(array $payload,array $context=array()): array
    {
        $this->keys($payload,array('provider'));
        $provider=$this->provider($payload);
        $state=$this->state($provider);
        return array(
            'provider'=>$provider,
            'fields'=>$this->publicFields($provider,$state),
            'write_scope'=>$provider==='asset_cleanup' ? 'disable_duplicate_optimizers_only' : 'image_display_options_only',
            'fingerprint'=>Fingerprint::make($state),
            'wp_rocket'=>$this->rocketSummary(),
        );
    }

    public function update(array $payload,array $context): array
    {
        $this->keys($payload,array('provider','fields','expected_before_fingerprint'));
        $provider=$this->provider($payload);
        $fields=$payload['fields']??null;
        if(!is_array($fields)||count($fields)!==1)
            throw new RuntimeException('Optimizer updates must change exactly one approved setting.');
        $key=array_key_first($fields);
        if(!is_string($key))throw new RuntimeException('Invalid optimizer setting field.');
        $value=$this->validate($provider,$key,$fields[$key]);
        $old=$this->state($provider);
        $fingerprint=Fingerprint::make($old);
        $before=$this->publicFields($provider,$old);
        $after=$before;
        $after[$key]=$value;
        $this->conflicts($provider,$key,$value);
        $out=array(
            'provider'=>$provider,'before'=>$before,'after'=>$after,
            '_current_fingerprint'=>$fingerprint,
        );
        if(!empty($context['dry_run']))return $out;
        $this->expected($payload['expected_before_fingerprint']??null,$fingerprint);
        $previousExists=array_key_exists($key,$old);
        $previousValue=$previousExists?$old[$key]:null;
        try {
            $this->write($provider,$old,$key,$value);
            $next=$this->state($provider);
            if(!array_key_exists($key,$next) || (string)$next[$key]!==$value){
                throw new RuntimeException('Optimizer settings readback did not match.');
            }
            foreach($old as $other=>$oldValue){
                if($other!==$key && (!array_key_exists($other,$next)||$next[$other]!==$oldValue)){
                    throw new RuntimeException('Optimizer changed an unrelated provider setting.');
                }
            }
        }catch(Throwable $error){
            try{
                $this->write($provider,$this->state($provider),$key,$previousExists?$previousValue:null,$previousExists);
            }catch(Throwable $recoverError){
                throw new RuntimeException('Optimizer write failed; previous state restoration not verified.');
            }
            if($this->state($provider)!==$old){
                throw new RuntimeException('Optimizer write failed and prior setting was not restored.');
            }
            throw new RuntimeException('Optimizer did not retain requested setting; previous state restored.');
        }
        $out['after']=$this->publicFields($provider,$next);
        $out['_rollback']=array('action'=>'optimizer.settings.restore','payload'=>array(
            'provider'=>$provider,'field'=>$key,'exists'=>$previousExists,
            'value'=>$previousValue,'expected_after_fingerprint'=>Fingerprint::make($next),
        ));
        return $out;
    }

    public function restore(array $payload,array $context): array
    {
        if(empty($context['rollback_mode']))throw new RuntimeException('Optimizer restoration is rollback-only.');
        $this->keys($payload,array('provider','field','exists','value','expected_after_fingerprint'));
        $provider=$this->provider($payload);
        $key=$payload['field']??null;
        if(!is_string($key)||!$this->isWritable($provider,$key)
            ||!array_key_exists('exists',$payload)||!is_bool($payload['exists']))
            throw new RuntimeException('Optimizer rollback field is invalid.');
        if($payload['exists']===true){
            $this->validate($provider,$key,$payload['value']??null,true);
        }
        $current=$this->state($provider);
        $this->expected($payload['expected_after_fingerprint']??null,Fingerprint::make($current));
        if(!empty($context['dry_run']))return array('would_restore'=>$key);
        $this->write($provider,$current,$key,$payload['value']??null,$payload['exists']);
        $readback=$this->state($provider);
        if($payload['exists']===true){
            if(!array_key_exists($key,$readback)
                ||$readback[$key]!==$payload['value'])throw new RuntimeException('Optimizer rollback mismatch.');
        }elseif(array_key_exists($key,$readback)){
            throw new RuntimeException('Optimizer rollback failed to remove the new field.');
        }
        return array('restored'=>true,'provider'=>$provider,'field'=>$key);
    }

    private function state(string $provider): array
    {
        $this->version($provider);
        if($provider==='asset_cleanup'){
            $stored=get_option('wpassetcleanup_settings',null);
            if(is_array($stored)){
                if(count($stored)>250)throw new RuntimeException('Asset CleanUp settings have unexpected size.');
                return $stored;
            }
            if(!is_string($stored)||strlen($stored)>250000||$stored===''){
                throw new RuntimeException('Asset CleanUp has no supported serialized settings baseline.');
            }
            $data=json_decode($stored,true);
            if(!is_array($data)||json_last_error()!==JSON_ERROR_NONE||count($data)>250)
                throw new RuntimeException('Asset CleanUp stored settings are not valid JSON.');
            return $data;
        }
        $fields=array();
        foreach(self::EWWW_FIELDS as $key){
            $value=get_option($key,null);
            if($value!==null && !is_scalar($value)){
                throw new RuntimeException('EWWW option has an unsupported provider value.');
            }
            $fields[$key]=$value;
        }
        return $fields;
    }

    private function write(string $provider,array $state,string $key,$value,bool $exists=true): void
    {
        if($provider==='asset_cleanup'){
            if($exists)$state[$key]=$value;else unset($state[$key]);
            $raw=get_option('wpassetcleanup_settings',null);
            if(is_string($raw)){
                if(!function_exists('wp_json_encode'))throw new RuntimeException('WP JSON serializer is unavailable.');
                $encoded=wp_json_encode($state);
                if(!is_string($encoded)||strlen($encoded)>250000)
                    throw new RuntimeException('Asset CleanUp JSON exceeds write boundary.');
                update_option('wpassetcleanup_settings',$encoded);
            }else{
                update_option('wpassetcleanup_settings',$state);
            }
            return;
        }
        if($exists)update_option($key,$value);
        else delete_option($key);
    }

    private function publicFields(string $provider,array $state): array
    {
        $keys=$provider==='asset_cleanup'?self::ASSET_FIELDS:self::EWWW_FIELDS;
        $out=array();
        foreach($keys as $key){
            if(!array_key_exists($key,$state)){
                $out[$key]=null;
            }else{
                $raw=$state[$key];
                $out[$key]=is_scalar($raw)||$raw===null?$raw:'[unsupported_value_withheld]';
            }
        }
        return $out;
    }

    private function validate(string $provider,string $key,$value,bool $restore=false): string
    {
        if(!$this->isWritable($provider,$key) || (!is_string($value)&&!is_int($value)&&!is_bool($value)))
            throw new RuntimeException('Optimizer setting is not in the safe write allowlist.');
        $value=(string)$value;
        if($provider==='asset_cleanup'){
            if($restore){
                if($key==='critical_css_status' && in_array($value,array('on','off'),true))return $value;
                if($key!=='critical_css_status'&&in_array($value,array('0','1'),true))return $value;
            }
            if($key==='critical_css_status' && $value==='off')return $value;
            if($key!=='critical_css_status' && $value==='0')return $value;
            throw new RuntimeException('Asset CleanUp may only disable duplicate CSS/JS optimization.');
        }
        if($key==='ewww_image_optimizer_ll_abovethefold'){
            if(preg_match('/^[0-6]$/D',$value))return $value;
            throw new RuntimeException('EWWW above-fold count must be between 0 and 6.');
        }
        if($value!=='0'&&$value!=='1')throw new RuntimeException('EWWW toggle accepts only zero or one.');
        return $value;
    }

    private function conflicts(string $provider,string $key,string $value): void
    {
        $rocket=$this->rocketSummary();
        if($provider!=='ewww'||$value!=='1')return;
        if($key==='ewww_image_optimizer_lazy_load'
            && ($rocket['lazyload']??null)==='1')
            throw new RuntimeException('WP Rocket image LazyLoad is already active.');
        if($key==='ewww_image_optimizer_add_missing_dims'
            && ($rocket['image_dimensions']??null)==='1')
            throw new RuntimeException('WP Rocket already owns missing image dimensions.');
    }

    private function rocketSummary(): array
    {
        if(!function_exists('get_rocket_option'))return array('available'=>false);
        return array(
            'available'=>true,
            'lazyload'=>(string)get_rocket_option('lazyload',0),
            'image_dimensions'=>(string)get_rocket_option('image_dimensions',0),
            'remove_unused_css'=>(string)get_rocket_option('remove_unused_css',0),
        );
    }

    private function isWritable(string $provider,string $key): bool
    {
        return in_array($key,$provider==='asset_cleanup'?self::ASSET_FIELDS:self::EWWW_WRITE,true);
    }

    private function version(string $provider): void
    {
        if((!function_exists('get_plugins')||!function_exists('is_plugin_active'))
            && defined('ABSPATH'))require_once ABSPATH.'wp-admin/includes/plugin.php';
        if(!function_exists('get_plugins')||!function_exists('is_plugin_active'))
            throw new RuntimeException('WordPress plugin inventory is unavailable.');
        $file=$provider==='asset_cleanup'?'wp-asset-clean-up/wpacu.php':'ewww-image-optimizer/ewww-image-optimizer.php';
        $version=get_plugins()[$file]['Version']??'';
        $pattern=$provider==='asset_cleanup'?'/^1\\.4\\.0\\.[0-9]+$/D':'/^8\\.8\\.[0-9]+$/D';
        if(!is_plugin_active($file)||!is_string($version)||!preg_match($pattern,$version))
            throw new RuntimeException('Unsupported or inactive optimizer plugin version.');
    }

    private function provider(array $payload): string
    {
        $provider=$payload['provider']??null;
        if($provider!=='asset_cleanup'&&$provider!=='ewww')
            throw new RuntimeException('Unsupported optimizer provider.');
        return $provider;
    }

    private function expected($expected,string $current): void
    {
        if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)
            ||!hash_equals($current,$expected))
            throw new RuntimeException('Optimizer state changed since dry-run.');
    }

    private function keys(array $payload,array $allowed): void
    {
        if(array_diff(array_keys($payload),$allowed))
            throw new RuntimeException('Unexpected optimizer settings request fields.');
    }
}
