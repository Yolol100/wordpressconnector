<?php
declare(strict_types=1);
namespace Webactueel\WordPressConnector\Adapters;
use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;
/** Allowlisted global Yoast snippet templates, not arbitrary options. */
final class YoastTemplateSettingsAdapter {
    private const KEYS = array(
        'home_title'=>'title-home-wpseo','home_description'=>'metadesc-home-wpseo',
        'page_title'=>'title-page','page_description'=>'metadesc-page',
        'post_title'=>'title-post','post_description'=>'metadesc-post',
        'product_title'=>'title-product','product_description'=>'metadesc-product',
        'product_category_title'=>'title-tax-product_cat',
        'product_category_description'=>'metadesc-tax-product_cat');
    public function register(Registry $registry): void {
        $security=array('privileged'=>true,'capability'=>'manage_options');
        $registry->register('yoast.templates.inspect',array($this,'inspect'),$security);
        $registry->register('yoast.templates.update',array($this,'update'),array_merge($security,array('mutation'=>true)));
        $registry->register('yoast.templates.restore',array($this,'restore'),array_merge($security,array('mutation'=>true)));
    }
    public function inspect(array $payload=array(),array $context=array()): array {
        $fields=$this->snapshot();
        return array('fields'=>$fields,'fingerprint'=>Fingerprint::make($fields));
    }
    public function update(array $payload,array $context): array {
        $changes=$this->validateFields($payload['fields']??null,false);
        $before=$this->snapshot();$after=array_replace($before,$changes);
        $result=array('before'=>$before,'after'=>$after,'_current_fingerprint'=>Fingerprint::make($before));
        if(!empty($context['dry_run']))return $result;
        $options=$this->options();
        foreach($changes as $key=>$value)$options[self::KEYS[$key]]=$value;
        update_option('wpseo_titles',$options);
        $verified=$this->snapshot();
        if($verified!==$after)throw new RuntimeException('Yoast template readback mismatch.');
        $result['after']=$verified;
        $result['_rollback']=array('action'=>'yoast.templates.restore','payload'=>array(
            'fields'=>array_intersect_key($before,$changes),
            'expected_after_fingerprint'=>Fingerprint::make($verified)));
        return $result;
    }
    public function restore(array $payload,array $context): array {
        if(empty($context['rollback_mode']))throw new RuntimeException('Rollback only.');
        $current=$this->snapshot();
        $expected=$payload['expected_after_fingerprint']??'';
        if(!is_string($expected)||!hash_equals(Fingerprint::make($current),$expected))
            throw new RuntimeException('Yoast templates changed since write.');
        $previous=$this->validateFields($payload['fields']??null,true);
        if(!empty($context['dry_run']))return array('would_restore'=>array_keys($previous));
        $options=$this->options();
        foreach($previous as $key=>$value){
            if($value===null)unset($options[self::KEYS[$key]]);
            else $options[self::KEYS[$key]]=$value;
        }
        update_option('wpseo_titles',$options);
        if(array_intersect_key($this->snapshot(),$previous)!==$previous)
            throw new RuntimeException('Yoast template rollback readback mismatch.');
        return array('restored'=>true);
    }
    private function validateFields($fields,bool $restore): array {
        if(!is_array($fields)||!$fields||count($fields)>count(self::KEYS))
            throw new RuntimeException('Expected bounded template fields.');
        $clean=array();
        foreach($fields as $key=>$value){
            if(!is_string($key)||!isset(self::KEYS[$key])||
                (!is_string($value)&&!($restore&&$value===null)))
                throw new RuntimeException('Unsupported Yoast template field.');
            if($value!==null){
                if(strlen($value)>(strpos($key,'description')!==false?320:190)||
                    (!$restore&&trim($value)===''))
                    throw new RuntimeException('Invalid template length.');
                $value=$restore?$value:sanitize_text_field($value);
                if(!$restore&&trim($value)==='')throw new RuntimeException('Invalid template text.');
            }
            $clean[$key]=$value;
        }
        return $clean;
    }
    private function options(): array {
        $value=get_option('wpseo_titles',null);
        if(!is_array($value))throw new RuntimeException('Yoast settings unavailable.');
        return $value;
    }
    private function snapshot(): array {
        $opts=$this->options();$fields=array();
        foreach(self::KEYS as $key=>$option)$fields[$key]=isset($opts[$option])?(string)$opts[$option]:null;
        return $fields;
    }
}
