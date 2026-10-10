<?php
declare(strict_types=1);
namespace Webactueel\WordPressConnector\Adapters;
use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

/** Provider REST for creating shipping zones/methods/tax rates and changing zone coverage.
 * Never mutates orders, gateways, checkout or third-party provider credentials.
 */
final class WooCommerceFulfillmentAdapter
{
    private const TYPES = array(
        'shipping_zone' => '/wc/v3/shipping/zones',
        'shipping_method' => '/wc/v3/shipping/zones',
        'tax_rate' => '/wc/v3/taxes',
    );
    public function register(Registry $registry): void
    {
        $read = array('privileged'=>true,'capability'=>'manage_woocommerce');
        $write = array_merge($read,array('mutation'=>true));
        $registry->register('woocommerce.fulfillment.locations.inspect',array($this,'inspectLocations'),$read);
        $registry->register('woocommerce.fulfillment.locations.update',array($this,'updateLocations'),$write);
        $registry->register('woocommerce.fulfillment.locations.restore',array($this,'restoreLocations'),$write);
        $registry->register('woocommerce.fulfillment.create',array($this,'create'),$write);
        $registry->register('woocommerce.fulfillment.restore_created',array($this,'restoreCreated'),$write);
    }

    public function inspectLocations(array $payload,array $context=array()): array
    {
        $this->keys($payload,array('zone_id'));
        $path=$this->locationPath($payload);
        $locations=$this->locationSnapshot($this->api('GET',$path));
        return array('zone_id'=>$payload['zone_id'],'locations'=>$locations,
            'fingerprint'=>Fingerprint::make($locations));
    }

    public function updateLocations(array $payload,array $context): array
    {
        $this->keys($payload,array('zone_id','locations','critical_confirm','restore_verified','expected_before_fingerprint'));
        $path=$this->locationPath($payload);
        $new=$this->normalizeLocations($payload['locations']??null,false);
        $before=$this->locationSnapshot($this->api('GET',$path));
        $fingerprint=Fingerprint::make($before);
        $result=array('zone_id'=>$payload['zone_id'],'before'=>$before,'after'=>$new,
            '_current_fingerprint'=>$fingerprint);
        if(!empty($context['dry_run']))return $result;
        $this->gate($payload,$fingerprint);
        $this->api('PUT',$path,$new,true);
        $after=$this->locationSnapshot($this->api('GET',$path));
        if($after!==$new){
            $this->api('PUT',$path,$before,true);
            if($this->locationSnapshot($this->api('GET',$path))!==$before)
                throw new RuntimeException('Shipping location readback failed and restore was not verified.');
            throw new RuntimeException('Shipping location update rejected; previous locations restored.');
        }
        $result['_rollback']=array('action'=>'woocommerce.fulfillment.locations.restore',
            'payload'=>array('zone_id'=>$payload['zone_id'],'locations'=>$before,
                'expected_after_fingerprint'=>Fingerprint::make($after)));
        return $result;
    }

    public function restoreLocations(array $payload,array $context): array
    {
        if(empty($context['rollback_mode']))throw new RuntimeException('Location restoration is rollback-only.');
        $this->keys($payload,array('zone_id','locations','expected_after_fingerprint'));
        $path=$this->locationPath($payload);
        $current=$this->locationSnapshot($this->api('GET',$path));
        $expected=$payload['expected_after_fingerprint']??null;
        if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)
            ||!hash_equals(Fingerprint::make($current),$expected))
            throw new RuntimeException('Shipping locations changed after the original write.');
        $previous=$this->normalizeLocations($payload['locations']??null,true);
        if(!empty($context['dry_run']))return array('would_restore'=>$previous);
        $this->api('PUT',$path,$previous,true);
        if($this->locationSnapshot($this->api('GET',$path))!==$previous)
            throw new RuntimeException('Shipping location rollback readback failed.');
        return array('restored'=>true,'zone_id'=>$payload['zone_id']);
    }

    public function create(array $payload,array $context): array
    {
        $this->keys($payload,array('type','zone_id','fields','critical_confirm','restore_verified','expected_before_fingerprint'));
        $type=$this->type($payload);
        $path=$this->collectionPath($type,$payload);
        $fields=$this->creationFields($type,$payload['fields']??null);
        $inventory=$this->api('GET',$path,array('per_page'=>100));
        if(count($inventory)>=100){
            throw new RuntimeException('Provider inventory is incomplete; cannot safely prevent duplicate creation.');
        }
        foreach($inventory as $candidate){
            if(!is_array($candidate))continue;
            if($type==='shipping_zone' && strcasecmp((string)($candidate['name']??''),$fields['name'])===0)
                throw new RuntimeException('Shipping zone name already exists.');
            if($type==='shipping_method' && ($candidate['method_id']??'')===$fields['method_id'])
                throw new RuntimeException('Shipping zone already contains this method.');
            if($type==='tax_rate' && ($candidate['country']??'')===$fields['country']
                && ($candidate['rate']??'')===$fields['rate']
                && ($candidate['name']??'')===$fields['name'])
                throw new RuntimeException('A matching tax rate already exists.');
        }
        $fp=Fingerprint::make($this->inventorySnapshot($type,$inventory));
        $result=array('type'=>$type,'fields'=>$fields,'would_create'=>true,'_current_fingerprint'=>$fp);
        if(!empty($context['dry_run']))return $result;
        $this->gate($payload,$fp);
        if($type==='tax_rate' && (!defined('WPCONNECTOR_ALLOW_WOO_TAX_CREATE')
            ||WPCONNECTOR_ALLOW_WOO_TAX_CREATE!==true)){
            throw new RuntimeException('Tax creation requires separate site-local tax policy gate.');
        }
        $created=$this->api('POST',$path,$fields);
        $id=$type==='shipping_method'?($created['instance_id']??null):($created['id']??null);
        if(!is_int($id)||$id<1)throw new RuntimeException('WooCommerce provider creation returned no valid ID; inspect site state manually.');
        $resourcePath=$path.'/'.$id;
        try {
            $current=$this->api('GET',$resourcePath);
            if($type==='shipping_method' && ($current['enabled']??null)!==false){
                // Provider may ignore enabled=false on create: disable before reporting success.
                $this->api('PUT',$resourcePath,array('enabled'=>false));
                $current=$this->api('GET',$resourcePath);
                if(($current['enabled']??null)!==false)
                    throw new RuntimeException('New shipping method could not be disabled.');
            }
            if($type==='shipping_zone' && ($current['name']??'')!==$fields['name'])
                throw new RuntimeException('Created shipping zone name was changed by the provider.');
            if($type==='tax_rate' && ($current['rate']??'')!==$fields['rate'])
                throw new RuntimeException('Created tax rate was changed by the provider.');
        } catch(Throwable $error) {
            try {
                // This object has just been created by this request. Remove
                // it immediately rather than leaving an unpriced method live.
                $this->api('DELETE',$resourcePath,array('force'=>true));
            } catch(Throwable $restoreError) {
                throw new RuntimeException('WooCommerce provisioning failed; newly created resource recovery was not verified.');
            }
            throw new RuntimeException('WooCommerce provisioning readback failed; newly created resource removed.');
        }
        $snapshot=$this->resourceSnapshot($type,$current);
        $result['created']=$snapshot;
        $result['_rollback']=array('action'=>'woocommerce.fulfillment.restore_created','payload'=>array(
            'type'=>$type,'zone_id'=>$payload['zone_id']??null,'id'=>$id,
            'expected_after_fingerprint'=>Fingerprint::make($snapshot),
        ));
        return $result;
    }

    public function restoreCreated(array $payload,array $context): array
    {
        if(empty($context['rollback_mode']))throw new RuntimeException('Created resources may only be restored through connector rollback.');
        $this->keys($payload,array('type','zone_id','id','expected_after_fingerprint'));
        $type=$this->type($payload);
        $id=$this->positive($payload['id']??null,'id');
        $path=$this->collectionPath($type,$payload).'/'.$id;
        $current=$this->api('GET',$path);
        $snapshot=$this->resourceSnapshot($type,$current);
        $expected=$payload['expected_after_fingerprint']??null;
        if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)
            ||!hash_equals(Fingerprint::make($snapshot),$expected))
            throw new RuntimeException('Created WooCommerce resource has changed; automatic removal unsafe.');
        if($type==='shipping_zone'){
            $methods=$this->api('GET',$path.'/methods');
            $locations=$this->api('GET',$path.'/locations');
            if($methods||$locations)throw new RuntimeException('Created zone now has methods or locations; cannot automatically remove.');
        }
        if(!empty($context['dry_run']))return array('would_remove_created_id'=>$id);
        $this->api('DELETE',$path,array('force'=>true));
        return array('restored_created_resource'=>true,'type'=>$type,'id'=>$id);
    }

    private function creationFields(string $type,$fields): array
    {
        if(!is_array($fields)||!$fields||count($fields)>9)
            throw new RuntimeException('WooCommerce provisioning requires explicit bounded fields.');
        $allowed=$type==='shipping_zone'?array('name','order')
            :($type==='shipping_method'?array('method_id'):array('country','state','rate','name','priority','compound','shipping','order','class'));
        if(array_diff(array_keys($fields),$allowed))
            throw new RuntimeException('Provisioning includes unsupported provider settings.');
        if($type==='shipping_zone'){
            $name=$fields['name']??null;
            if(!is_string($name)||strlen(trim($name))<2||strlen($name)>80
                ||preg_match('/[<>\\x00-\\x1F]/',$name))throw new RuntimeException('Invalid shipping zone name.');
            $data=array('name'=>sanitize_text_field($name));
            if(isset($fields['order'])){
                if(!is_int($fields['order'])||$fields['order']<0||$fields['order']>1000)
                    throw new RuntimeException('Invalid zone order.');
                $data['order']=$fields['order'];
            }
            return $data;
        }
        if($type==='shipping_method'){
            $method=$fields['method_id']??null;
            if(!is_string($method)||!preg_match('/^[a-z0-9_]{2,80}$/D',$method))
                throw new RuntimeException('Invalid shipping method provider ID.');
            // Provider decides which method IDs are supported. Newly created
            // methods are forced disabled before the operation is accepted.
            return array('method_id'=>$method,'enabled'=>false);
        }
        foreach(array('country','rate','name')as $required)
            if(!isset($fields[$required]))throw new RuntimeException('Tax creation requires country, rate and name.');
        $country=$fields['country'];$rate=$fields['rate'];$name=$fields['name'];
        if(!is_string($country)||!preg_match('/^[A-Z]{2}$/D',$country)
            ||!is_string($rate)||!preg_match('/^\\d{1,2}(?:\\.\\d{1,4})?$/D',$rate)
            ||!is_string($name)||strlen($name)>100||strlen(trim($name))<2||preg_match('/[<>\\x00-\\x1F]/',$name))
            throw new RuntimeException('Tax rate country/percentage/name is invalid.');
        $clean=array('country'=>$country,'rate'=>$rate,'name'=>sanitize_text_field($name));
        foreach($fields as $key=>$value){
            if(isset($clean[$key]))continue;
            if(in_array($key,array('priority','order'),true)){
                if(!is_int($value)||$value<0||$value>1000)throw new RuntimeException('Invalid tax priority.');
            }elseif(in_array($key,array('compound','shipping'),true)){
                if(!is_bool($value))throw new RuntimeException('Tax boolean must be strict.');
            }else{
                if(!is_string($value)||strlen($value)>80||preg_match('/[<>\\x00-\\x1F]/',$value))
                    throw new RuntimeException('Invalid tax class or state.');
            }
            $clean[$key]=$value;
        }
        return $clean;
    }

    private function locationSnapshot(array $response): array
    {
        $result=array();
        foreach($response as $value){
            if(!is_array($value)||!isset($value['type'],$value['code']))continue;
            $result[]=array('type'=>(string)$value['type'],'code'=>(string)$value['code']);
        }
        return $this->normalizeLocations($result,true);
    }

    private function normalizeLocations($locations,bool $allowEmpty): array
    {
        if(!is_array($locations)||count($locations)>100||(!$allowEmpty&&!$locations))
            throw new RuntimeException('Locations require 1-100 allowed entries.');
        $result=array();
        foreach($locations as $point){
            if(!is_array($point)||array_diff(array_keys($point),array('type','code')))
                throw new RuntimeException('Invalid shipping zone location shape.');
            $type=$point['type']??null;$code=$point['code']??null;
            if(!is_string($type)||!in_array($type,array('country','state','postcode','continent'),true)
                ||!is_string($code)||strlen($code)>24||!preg_match('/^[A-Za-z0-9 :*\\-]{1,24}$/D',$code)){
                throw new RuntimeException('Invalid shipping zone geographic location.');
            }
            $key=$type.':'.$code;$result[$key]=array('type'=>$type,'code'=>$code);
        }
        ksort($result,SORT_STRING);
        return array_values($result);
    }

    private function inventorySnapshot(string $type,array $data): array
    {
        $out=array();
        foreach($data as $item){
            if(!is_array($item))continue;
            $out[]=$this->resourceSnapshot($type,$item);
        }
        return $out;
    }

    private function resourceSnapshot(string $type,array $item): array
    {
        $keys=$type==='shipping_zone'?array('id','name','order')
            :($type==='shipping_method'?array('instance_id','method_id','order','enabled')
            :array('id','country','state','rate','name','priority','compound','shipping','order','class'));
        $out=array();
        foreach($keys as $key)if(array_key_exists($key,$item))$out[$key]=$item[$key];
        return $out;
    }

    private function gate(array $payload,string $fingerprint): void
    {
        if(!defined('WPCONNECTOR_ALLOW_WOO_CRITICAL')||WPCONNECTOR_ALLOW_WOO_CRITICAL!==true
            ||($payload['critical_confirm']??null)!==true||($payload['restore_verified']??null)!==true)
            throw new RuntimeException('WooCommerce provisioning requires the site-local critical gate and tested restore.');
        $expected=$payload['expected_before_fingerprint']??null;
        if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)
            ||!hash_equals($fingerprint,$expected))
            throw new RuntimeException('WooCommerce provisioning preflight fingerprint does not match current state.');
    }

    private function type(array $payload): string
    {
        $type=$payload['type']??null;
        if(!is_string($type)||!isset(self::TYPES[$type]))throw new RuntimeException('Unsupported WooCommerce provisioning type.');
        return $type;
    }
    private function collectionPath(string $type,array $payload): string
    {
        $path=self::TYPES[$type];
        if($type==='shipping_method')$path.='/'.$this->positive($payload['zone_id']??null,'zone_id').'/methods';
        elseif(array_key_exists('zone_id',$payload)&&$payload['zone_id']!==null)
            throw new RuntimeException('Provisioning zone_id is only valid for shipping methods.');
        return $path;
    }
    private function locationPath(array $payload): string
    {
        return '/wc/v3/shipping/zones/'.$this->positive($payload['zone_id']??null,'zone_id').'/locations';
    }
    private function positive($id,string $name): int
    {
        if(!is_int($id)||$id<1||$id>2147483647)throw new RuntimeException($name.' must be a positive integer.');
        return $id;
    }
    private function keys(array $payload,array $allowed): void
    {
        if(array_diff(array_keys($payload),$allowed))throw new RuntimeException('Unexpected WooCommerce provisioning arguments.');
    }

    private function api(string $method,string $path,array $body=array(),bool $listBody=false): array
    {
        if(!class_exists('WP_REST_Request')||!function_exists('rest_do_request'))
            throw new RuntimeException('WooCommerce REST API unavailable.');
        $request=new \WP_REST_Request($method,$path);
        if($listBody){
            if(!method_exists($request,'set_body')||!method_exists($request,'set_header'))
                throw new RuntimeException('WooCommerce REST JSON request API unavailable.');
            $request->set_header('Content-Type','application/json');
            $request->set_body(wp_json_encode($body));
        }else{
            foreach($body as $key=>$value)$request->set_param($key,$value);
        }
        $response=rest_do_request($request);
        if(!is_object($response)||!method_exists($response,'get_status')
            ||!method_exists($response,'get_data'))
            throw new RuntimeException('WooCommerce provider returned an invalid response.');
        $status=(int)$response->get_status();
        $data=$response->get_data();
        if($status<200||$status>=300||!is_array($data))
            throw new RuntimeException('WooCommerce provider returned HTTP '.$status.'.');
        return $data;
    }
}
