<?php
declare(strict_types=1);
$GLOBALS['woo_ops_store'] = array(
    'shipping_zone'=>array(
        3=>array('id'=>3,'name'=>'Nederland','order'=>0),
    ),
    'shipping_method'=>array(
        8=>array('instance_id'=>8,'method_id'=>'flat_rate','title'=>'Standaard','order'=>0,'enabled'=>true,
            'settings'=>array(
                'cost'=>array('id'=>'cost','type'=>'text','value'=>'5.00'),
                'private_key'=>array('id'=>'private_key','type'=>'password','value'=>'HIDDEN_SHIPPING_SECRET'),
            )),
    ),
    'tax_rate'=>array(
        5=>array('id'=>5,'country'=>'NL','state'=>'','postcode'=>array(),
            'city'=>array(),'rate'=>'21.0000','name'=>'BTW standaard','priority'=>1,'compound'=>false,
            'shipping'=>true,'order'=>0,'class'=>''),
    ),
    'payment_gateway'=>array(
        'mollie_wc_gateway_ideal'=>array(
            'id'=>'mollie_wc_gateway_ideal','title'=>'iDEAL','description'=>'Betaal veilig met iDEAL',
            'order'=>0,'enabled'=>false,'method_title'=>'Mollie iDEAL',
            'settings'=>array(
                'title'=>array('id'=>'title','type'=>'text','value'=>'iDEAL'),
                'api_key'=>array('id'=>'api_key','type'=>'password','value'=>'HIDDEN_MOLLIE_SECRET'),
                'merchant_account'=>array('id'=>'merchant_account','type'=>'text','value'=>'HIDDEN_MERCHANT_ACCOUNT'),
                'reference_code'=>array('id'=>'reference_code','type'=>'text','value'=>'HIDDEN_PROVIDER_REFERENCE'),
                'live_mode'=>array('id'=>'live_mode','type'=>'checkbox','value'=>'no'),
            ),
        ),
    ),
);
$GLOBALS['woo_ops_put_count']=0;
$GLOBALS['woo_ops_ignore_put']=false;
function sanitize_text_field($text): string { return trim(strip_tags((string)$text)); }
function current_user_can($name): bool { return $name==='manage_woocommerce'; }
class WP_REST_Request {
    public $method; public $route; public $params=array();
    public function __construct($method,$route){$this->method=$method;$this->route=$route;}
    public function set_param($key,$value){$this->params[$key]=$value;}
}
class WooOpsResponse {
    private $status; private $data;
    public function __construct($status,$data){$this->status=$status;$this->data=$data;}
    public function get_status(){return $this->status;}
    public function get_data(){return $this->data;}
}
function rest_do_request($request) {
    $path=explode('/',trim($request->route,'/'));
    if ($path[0]!=='wc'||$path[1]!=='v3')return new WooOpsResponse(404,array('error'=>'wrong prefix'));
    $area=null;$key=null;
    if ($path[2]==='shipping'&&($path[3]??'')==='zones') {
        if (count($path)===4)return new WooOpsResponse(200,array_values($GLOBALS['woo_ops_store']['shipping_zone']));
        if (($path[5]??'')==='methods') {
            $area='shipping_method';
            if (count($path)===6)return new WooOpsResponse(200,array_values($GLOBALS['woo_ops_store']['shipping_method']));
            $key=(int)$path[6];
        }else{$area='shipping_zone';$key=(int)$path[4];}
    }elseif($path[2]==='taxes'){
        $area='tax_rate';
        if(count($path)===3)return new WooOpsResponse(200,array_values($GLOBALS['woo_ops_store'][$area]));
        $key=(int)$path[3];
    }elseif($path[2]==='payment_gateways'){
        $area='payment_gateway';
        if(count($path)===3)return new WooOpsResponse(200,array_values($GLOBALS['woo_ops_store'][$area]));
        $key=$path[3];
    }else{return new WooOpsResponse(404,array('error'=>'unsupported endpoint'));}
    $store=&$GLOBALS['woo_ops_store'][$area][$key];
    if(!is_array($store))return new WooOpsResponse(404,array('error'=>'item not found'));
    if($request->method==='PUT'){
        $GLOBALS['woo_ops_put_count']++;
        if(!$GLOBALS['woo_ops_ignore_put']){
            foreach($request->params as $name=>$value){
                if($name==='settings'){
                    foreach($value as $settingId=>$settingValue)
                        $store['settings'][$settingId]['value']=$settingValue;
                }else{$store[$name]=$value;}
            }
        }
    }
    return new WooOpsResponse(200,$store);
}
$root=dirname(__DIR__).'/plugin/wordpressconnector/includes/';
foreach(array('Support/Json.php','Support/Fingerprint.php','Security/Policy.php',
    'Runtime/Registry.php','Adapters/WooCommerceOperationsAdapter.php') as $file)require $root.$file;
$registry=new \Webactueel\WordPressConnector\Runtime\Registry();
(new \Webactueel\WordPressConnector\Adapters\WooCommerceOperationsAdapter())->register($registry);
$d=$registry->descriptor('woocommerce.operations.update');
if(empty($d['mutation'])||empty($d['privileged'])||
    $d['capability']!=='manage_woocommerce')
    throw new RuntimeException('WooCommerce provider write did not require expected permissions.');
$err=static function(callable $callback,string $text): void{
    try{$callback();}catch(RuntimeException $e){
        if(strpos($e->getMessage(),$text)!==false)return;
        throw new RuntimeException('Unexpected failure: '.$e->getMessage());
    }throw new RuntimeException('Missing expected failure: '.$text);
};
foreach(array(
    array('area'=>'shipping_zones','expected'=>1),
    array('area'=>'shipping_methods','zone_id'=>3,'expected'=>1),
    array('area'=>'tax_rates','expected'=>1),
    array('area'=>'payment_gateways','expected'=>1),
)as $list){
    $expected=$list['expected'];unset($list['expected']);
    $result=$registry->execute('woocommerce.operations.list',$list);
    if($result['count']!==$expected)throw new RuntimeException('Missing WooCommerce provider inventory.');
    if(strpos(json_encode($result),'HIDDEN_')!==false)throw new RuntimeException('Provider list leaked a secret.');
}
$page=$registry->execute('woocommerce.operations.list',array('area'=>'tax_rates','page'=>1,'per_page'=>1));
if($page['count']!==1||!$page['has_more']||$page['page']!==1)
    throw new RuntimeException('Tax rate pagination contract is incomplete.');
$err(static function()use($registry){
    $registry->execute('woocommerce.operations.list',array('area'=>'tax_rates','page'=>-1));
},'outside safe bounds');
$gateway=array('area'=>'payment_gateway','id'=>'mollie_wc_gateway_ideal');
$found=$registry->execute('woocommerce.operations.inspect',$gateway);
if($found['item']['settings']['api_key']['mode']!=='secret_blocked'||
    isset($found['item']['settings']['api_key']['value'])||
    strpos(json_encode($found),'HIDDEN_MOLLIE_SECRET')!==false
    || strpos(json_encode($found),'HIDDEN_MERCHANT_ACCOUNT')!==false
    || strpos(json_encode($found),'HIDDEN_PROVIDER_REFERENCE')!==false)
    throw new RuntimeException('Payment gateway secret was exposed.');
$err(static function()use($registry){$registry->execute('woocommerce.operations.update',array('area'=>'payment_gateway',
    'id'=>'mollie_wc_gateway_ideal','fields'=>array('settings'=>array('merchant_account'=>'NEW'))),
    array('dry_run'=>true));},'Protected');
$err(static function()use($registry){$registry->execute('woocommerce.operations.inspect',
    array('area'=>'payment_gateway','id'=>'../../options'));},'Invalid');
$err(static function()use($registry){$registry->execute('woocommerce.operations.inspect',
    array('area'=>'tax_rate','id'=>'5'));},'positive integer');
$err(static function()use($registry){$registry->execute('woocommerce.operations.update',
    array('area'=>'payment_gateway','id'=>'mollie_wc_gateway_ideal',
        'fields'=>array('settings'=>array('api_key'=>'TESTKEY'))),array('dry_run'=>true));},'Protected');

$err(static function()use($registry){
    $registry->execute('woocommerce.operations.update',array(
        'area'=>'payment_gateway','id'=>'mollie_wc_gateway_ideal',
        'fields'=>array('settings'=>array('live_mode'=>'yes'))
    ),array('dry_run'=>true));
},'Protected');
$tax=array('area'=>'tax_rate','id'=>5,'fields'=>array('rate'=>'9.0000'));
$before=$registry->execute('woocommerce.operations.update',$tax,array('dry_run'=>true));
if($before['requested']['rate']!=='9.0000'||$GLOBALS['woo_ops_put_count']!==0||
    $GLOBALS['woo_ops_store']['tax_rate'][5]['rate']!=='21.0000')
    throw new RuntimeException('Tax rate preview had a side effect.');
$err(static function()use($registry,$tax){
    $registry->execute('woocommerce.operations.update',$tax,array('dry_run'=>false));
},'site-local critical gate');
define('WPCONNECTOR_ALLOW_WOO_CRITICAL',true);
$err(static function()use($registry,$tax){
    $registry->execute('woocommerce.operations.update',array_merge($tax,array(
        'critical_confirm'=>true,'restore_verified'=>true,
        'expected_before_fingerprint'=>str_repeat('0',64))),array('dry_run'=>false));
},'changed since preview');
$tax['critical_confirm']=true;$tax['restore_verified']=true;
$tax['expected_before_fingerprint']=$before['_current_fingerprint'];
$written=$registry->execute('woocommerce.operations.update',$tax,array('dry_run'=>false));
if($written['after']['rate']!=='9.0000')throw new RuntimeException('Tax rate write not verified.');
$err(static function()use($registry,$written){
    $registry->execute('woocommerce.operations.restore',$written['_rollback']['payload'],array('dry_run'=>false));
},'rollback-only');
$restored=$registry->execute('woocommerce.operations.restore',$written['_rollback']['payload'],array('rollback_mode'=>true));
if(!$restored['restored']||$GLOBALS['woo_ops_store']['tax_rate'][5]['rate']!=='21.0000')
    throw new RuntimeException('Tax provider rollback failed.');
$err(static function()use($registry,$written){
    $registry->execute('woocommerce.operations.restore',$written['_rollback']['payload'],array('rollback_mode'=>true));
},'changed remote state');

$shipping=array('area'=>'shipping_method','zone_id'=>3,'id'=>8,
    'fields'=>array('settings'=>array('cost'=>'7.50')));
$shPreview=$registry->execute('woocommerce.operations.update',$shipping,array('dry_run'=>true));
$shipping=array_merge($shipping,array('critical_confirm'=>true,'restore_verified'=>true,
    'expected_before_fingerprint'=>$shPreview['_current_fingerprint']));
$shWritten=$registry->execute('woocommerce.operations.update',$shipping,array('dry_run'=>false));
if($GLOBALS['woo_ops_store']['shipping_method'][8]['settings']['cost']['value']!=='7.50')
    throw new RuntimeException('Shipping method settings not written.');
$registry->execute('woocommerce.operations.restore',$shWritten['_rollback']['payload'],array('rollback_mode'=>true));
if($GLOBALS['woo_ops_store']['shipping_method'][8]['settings']['cost']['value']!=='5.00')
    throw new RuntimeException('Shipping method rollback not applied.');

$gatewayWrite=array_merge($gateway,array('fields'=>array('enabled'=>true)));
$gwPreview=$registry->execute('woocommerce.operations.update',$gatewayWrite,array('dry_run'=>true));
$gatewayWrite=array_merge($gatewayWrite,array('critical_confirm'=>true,'restore_verified'=>true,
    'expected_before_fingerprint'=>$gwPreview['_current_fingerprint']));
$err(static function()use($registry,$gatewayWrite){
    $registry->execute('woocommerce.operations.update',$gatewayWrite,array('dry_run'=>false));
},'separate site-local payment gate');
define('WPCONNECTOR_ALLOW_WOO_PAYMENT_ENABLE',true);
$err(static function()use($registry,$gatewayWrite){
    $registry->execute('woocommerce.operations.update',$gatewayWrite,array('dry_run'=>false));
},'separate site-local payment gate');
$gatewayWrite['sandbox_verified']=true;
$gatewayApplied=$registry->execute('woocommerce.operations.update',$gatewayWrite,array('dry_run'=>false));
if(!$GLOBALS['woo_ops_store']['payment_gateway']['mollie_wc_gateway_ideal']['enabled'])
    throw new RuntimeException('Confirmed sandbox gateway was not enabled.');
$registry->execute('woocommerce.operations.restore',$gatewayApplied['_rollback']['payload'],array('rollback_mode'=>true));
if($GLOBALS['woo_ops_store']['payment_gateway']['mollie_wc_gateway_ideal']['enabled'])
    throw new RuntimeException('Payment gateway rollback did not disable the gateway.');

$GLOBALS['woo_ops_ignore_put']=true;
$err(static function()use($registry,$tax){
    $registry->execute('woocommerce.operations.update',$tax,array('dry_run'=>false));
},'previous state restored');
$GLOBALS['woo_ops_ignore_put']=false;
if($GLOBALS['woo_ops_store']['tax_rate'][5]['rate']!=='21.0000')
    throw new RuntimeException('Unaccepted provider write corrupted tax rate.');
echo "WooCommerce gateway, Mollie, tax and shipping inventory, secrets, gates, dry-run, write and restore OK\n";
