<?php
declare(strict_types=1);
$GLOBALS['fulfill'] = array(
    'zones'=>array(3=>array('id'=>3,'name'=>'Europa','order'=>0)),
    'locations'=>array(3=>array(array('type'=>'country','code'=>'NL'))),
    'methods'=>array(3=>array(8=>array('instance_id'=>8,'method_id'=>'flat_rate','order'=>0,'enabled'=>false))),
    'taxes'=>array(5=>array('id'=>5,'country'=>'NL','state'=>'','rate'=>'21.0000',
        'name'=>'BTW standaard','priority'=>1,'compound'=>false,'shipping'=>true,'order'=>0,'class'=>'')),
    'next_zone'=>4,'next_method'=>9,'next_tax'=>6,
);
$GLOBALS['fulfill_mutations']=0;
$GLOBALS['fulfill_refuse_disable']=false;
function sanitize_text_field($value): string{return trim(strip_tags((string)$value));}
function wp_json_encode($value){return json_encode($value);}
function current_user_can($capability): bool{return $capability==='manage_woocommerce';}
class WP_REST_Request{
    public $method;public $route;public $params=array();public $raw=null;public $headers=array();
    public function __construct($method,$route){$this->method=$method;$this->route=$route;}
    public function set_param($key,$value){$this->params[$key]=$value;}
    public function set_header($key,$value){$this->headers[$key]=$value;}
    public function set_body($data){$this->raw=$data;}
}
class FulfillResponse{
    private $status;private $data;
    public function __construct($status,$data){$this->status=$status;$this->data=$data;}
    public function get_status(){return $this->status;}
    public function get_data(){return $this->data;}
}
function rest_do_request($r){
    $p=explode('/',trim($r->route,'/'));
    if($p[0]!=='wc'||$p[1]!=='v3')return new FulfillResponse(404,array('error'=>'route'));
    $store=&$GLOBALS['fulfill'];$method=$r->method;
    if($p[2]==='shipping'&&($p[3]??'')==='zones'){
        if(count($p)===4){
            if($method==='POST'){
                $id=$store['next_zone']++;
                $store['zones'][$id]=array('id'=>$id,'name'=>$r->params['name'],'order'=>$r->params['order']??0);
                $store['locations'][$id]=array();$store['methods'][$id]=array();$GLOBALS['fulfill_mutations']++;
                return new FulfillResponse(201,$store['zones'][$id]);
            }
            return new FulfillResponse(200,array_values($store['zones']));
        }
        $zone=(int)$p[4];
        if(!isset($store['zones'][$zone]))return new FulfillResponse(404,array('error'=>'zone'));
        if(count($p)===5){
            if($method==='DELETE'){
                unset($store['zones'][$zone],$store['locations'][$zone],$store['methods'][$zone]);$GLOBALS['fulfill_mutations']++;
                return new FulfillResponse(200,array('id'=>$zone));
            }
            return new FulfillResponse(200,$store['zones'][$zone]);
        }
        if($p[5]==='locations'){
            if($method==='PUT'){
                if(!isset($r->headers['Content-Type'])||$r->headers['Content-Type']!=='application/json')
                    return new FulfillResponse(415,array('error'=>'json required'));
                $store['locations'][$zone]=json_decode($r->raw,true);
                $GLOBALS['fulfill_mutations']++;
            }
            return new FulfillResponse(200,$store['locations'][$zone]);
        }
        if($p[5]==='methods'){
            if(count($p)===6){
                if($method==='POST'){
                    $id=$store['next_method']++;
                    // Simulate a provider that initially enables new methods.
                    $store['methods'][$zone][$id]=array('instance_id'=>$id,
                        'method_id'=>$r->params['method_id'],'order'=>0,'enabled'=>true);
                    $GLOBALS['fulfill_mutations']++;
                    return new FulfillResponse(201,$store['methods'][$zone][$id]);
                }
                return new FulfillResponse(200,array_values($store['methods'][$zone]));
            }
            $id=(int)$p[6];
            if(!isset($store['methods'][$zone][$id]))return new FulfillResponse(404,array('error'=>'method'));
            if($method==='DELETE'){
                unset($store['methods'][$zone][$id]);$GLOBALS['fulfill_mutations']++;
                return new FulfillResponse(200,array('instance_id'=>$id));
            }
            if($method==='PUT'){
                if(!($GLOBALS['fulfill_refuse_disable']===true
                    && ($r->params['enabled']??null)===false)){
                    foreach($r->params as $key=>$value)$store['methods'][$zone][$id][$key]=$value;
                }
                $GLOBALS['fulfill_mutations']++;
            }
            return new FulfillResponse(200,$store['methods'][$zone][$id]);
        }
    }
    if($p[2]==='taxes'){
        if(count($p)===3){
            if($method==='POST'){
                $id=$store['next_tax']++;
                $store['taxes'][$id]=array_merge(array('id'=>$id,'state'=>'','priority'=>1,
                    'compound'=>false,'shipping'=>true,'order'=>0,'class'=>''),$r->params);
                $GLOBALS['fulfill_mutations']++;
                return new FulfillResponse(201,$store['taxes'][$id]);
            }
            return new FulfillResponse(200,array_values($store['taxes']));
        }
        $id=(int)$p[3];
        if(!isset($store['taxes'][$id]))return new FulfillResponse(404,array('error'=>'rate'));
        if($method==='DELETE'){
            unset($store['taxes'][$id]);$GLOBALS['fulfill_mutations']++;
            return new FulfillResponse(200,array('id'=>$id));
        }
        return new FulfillResponse(200,$store['taxes'][$id]);
    }
    return new FulfillResponse(404,array('error'=>'not found'));
}
$root=dirname(__DIR__).'/plugin/wordpressconnector/includes/';
foreach(array('Support/Json.php','Support/Fingerprint.php','Security/Policy.php',
    'Runtime/Registry.php','Adapters/WooCommerceFulfillmentAdapter.php')as $file)require $root.$file;
$registry=new \Webactueel\WordPressConnector\Runtime\Registry();
(new \Webactueel\WordPressConnector\Adapters\WooCommerceFulfillmentAdapter())->register($registry);
$d=$registry->descriptor('woocommerce.fulfillment.create');
if(!$d['mutation']||!$d['privileged']||$d['capability']!=='manage_woocommerce')
    throw new RuntimeException('Missing WooCommerce provisioning authorization.');
$fail=static function(callable $func,string $text): void{
    try{$func();}catch(RuntimeException $error){
        if(strpos($error->getMessage(),$text)!==false)return;
        throw new RuntimeException('Unexpected error: '.$error->getMessage());
    }
    throw new RuntimeException('Expected failure '.$text);
};
$loc=$registry->execute('woocommerce.fulfillment.locations.inspect',array('zone_id'=>3));
if($loc['locations']!==array(array('type'=>'country','code'=>'NL')))
    throw new RuntimeException('Current shipping zones not read.');
$locPayload=array('zone_id'=>3,'locations'=>array(
    array('type'=>'country','code'=>'BE'),array('type'=>'country','code'=>'NL')));
$preview=$registry->execute('woocommerce.fulfillment.locations.update',$locPayload,array('dry_run'=>true));
if($GLOBALS['fulfill_mutations']!==0||count($preview['after'])!==2)
    throw new RuntimeException('Locations dry-run mutated site state.');
$fail(static function()use($registry,$locPayload){
    $registry->execute('woocommerce.fulfillment.locations.update',$locPayload,array('dry_run'=>false));
},'site-local critical gate');
define('WPCONNECTOR_ALLOW_WOO_CRITICAL',true);
$fail(static function()use($registry,$locPayload){
    $registry->execute('woocommerce.fulfillment.locations.update',array_merge($locPayload,array(
        'critical_confirm'=>true,'restore_verified'=>true,
        'expected_before_fingerprint'=>str_repeat('0',64))),array('dry_run'=>false));
},'fingerprint');
$locPayload=array_merge($locPayload,array('critical_confirm'=>true,'restore_verified'=>true,
    'expected_before_fingerprint'=>$preview['_current_fingerprint']));
$written=$registry->execute('woocommerce.fulfillment.locations.update',$locPayload,array('dry_run'=>false));
if(count($GLOBALS['fulfill']['locations'][3])!==2)throw new RuntimeException('Shipping zone locations were not updated.');
$registry->execute('woocommerce.fulfillment.locations.restore',$written['_rollback']['payload'],array('rollback_mode'=>true));
if($GLOBALS['fulfill']['locations'][3]!==array(array('type'=>'country','code'=>'NL')))
    throw new RuntimeException('Shipping zone locations were not restored.');
$fail(static function()use($registry,$written){
    $registry->execute('woocommerce.fulfillment.locations.restore',$written['_rollback']['payload'],array('rollback_mode'=>true));
},'changed after');
$fail(static function()use($registry){
    $registry->execute('woocommerce.fulfillment.locations.update',array(
        'zone_id'=>3,'locations'=>array(array('type'=>'country','code'=>'<script>'))),array('dry_run'=>true));
},'Invalid');

$zone=array('type'=>'shipping_zone','fields'=>array('name'=>'Belgie','order'=>1));
$zPreview=$registry->execute('woocommerce.fulfillment.create',$zone,array('dry_run'=>true));
if($zPreview['fields']['name']!=='Belgie'||$GLOBALS['fulfill']['next_zone']!==4)
    throw new RuntimeException('Zone provisioning preview mutated site.');
$zone=array_merge($zone,array('critical_confirm'=>true,'restore_verified'=>true,
    'expected_before_fingerprint'=>$zPreview['_current_fingerprint']));
$zWritten=$registry->execute('woocommerce.fulfillment.create',$zone,array('dry_run'=>false));
if($zWritten['created']['id']!==4||$GLOBALS['fulfill']['zones'][4]['name']!=='Belgie')
    throw new RuntimeException('Zone provisioning readback mismatch.');
$fail(static function()use($registry,$zone){
    $registry->execute('woocommerce.fulfillment.create',$zone,array('dry_run'=>true));
},'already exists');
$registry->execute('woocommerce.fulfillment.restore_created',$zWritten['_rollback']['payload'],array('rollback_mode'=>true));
if(isset($GLOBALS['fulfill']['zones'][4]))throw new RuntimeException('New zone rollback failed.');

$method=array('type'=>'shipping_method','zone_id'=>3,'fields'=>array('method_id'=>'free_shipping'));
$mPrev=$registry->execute('woocommerce.fulfillment.create',$method,array('dry_run'=>true));
$method=array_merge($method,array('critical_confirm'=>true,'restore_verified'=>true,
    'expected_before_fingerprint'=>$mPrev['_current_fingerprint']));
$mWritten=$registry->execute('woocommerce.fulfillment.create',$method,array('dry_run'=>false));
if($mWritten['created']['instance_id']!==9||$GLOBALS['fulfill']['methods'][3][9]['enabled'])
    throw new RuntimeException('New shipping method was enabled at checkout or missing.');
$registry->execute('woocommerce.fulfillment.restore_created',$mWritten['_rollback']['payload'],array('rollback_mode'=>true));
if(isset($GLOBALS['fulfill']['methods'][3][9]))throw new RuntimeException('New shipping method rollback failed.');

$broken=array('type'=>'shipping_method','zone_id'=>3,'fields'=>array('method_id'=>'local_pickup'));
$brokenPreview=$registry->execute('woocommerce.fulfillment.create',$broken,array('dry_run'=>true));
$broken=array_merge($broken,array('critical_confirm'=>true,'restore_verified'=>true,
    'expected_before_fingerprint'=>$brokenPreview['_current_fingerprint']));
$GLOBALS['fulfill_refuse_disable']=true;
$fail(static function()use($registry,$broken){
    $registry->execute('woocommerce.fulfillment.create',$broken,array('dry_run'=>false));
},'newly created resource removed');
$GLOBALS['fulfill_refuse_disable']=false;
if(isset($GLOBALS['fulfill']['methods'][3][10]))
    throw new RuntimeException('Failed shipping method creation left a live method enabled.');

$tax=array('type'=>'tax_rate','fields'=>array('country'=>'NL','rate'=>'9.0000','name'=>'BTW laag'));
$tPrev=$registry->execute('woocommerce.fulfillment.create',$tax,array('dry_run'=>true));
$tax=array_merge($tax,array('critical_confirm'=>true,'restore_verified'=>true,
    'expected_before_fingerprint'=>$tPrev['_current_fingerprint']));
$fail(static function()use($registry,$tax){
    $registry->execute('woocommerce.fulfillment.create',$tax,array('dry_run'=>false));
},'separate site-local tax policy gate');
define('WPCONNECTOR_ALLOW_WOO_TAX_CREATE',true);
$tWritten=$registry->execute('woocommerce.fulfillment.create',$tax,array('dry_run'=>false));
if(($tWritten['created']['id']??0)!==6)throw new RuntimeException('Tax rate creation did not return a stable ID.');
$registry->execute('woocommerce.fulfillment.restore_created',$tWritten['_rollback']['payload'],array('rollback_mode'=>true));
if(isset($GLOBALS['fulfill']['taxes'][6]))throw new RuntimeException('New tax rate rollback failed.');
echo "WooCommerce zone/method/rate creation, location JSON, gates, dry-run, permissions and rollback OK\n";
