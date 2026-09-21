<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
class WP_Post {
    public int $ID; public string $post_type='post'; public string $post_status; public string $post_password=''; public string $post_content;
    public function __construct(int $id,string $status='draft',string $content='old intro'){ $this->ID=$id;$this->post_status=$status;$this->post_content=$content; }
}
$GLOBALS['pct_post']=new WP_Post(5104,'draft','old intro');
$GLOBALS['pct_fields']=array('field_69deb8ddbcd8d'=>'old problem','field_69deb8e4bcd8e'=>'old solution');
function get_post($id){ return (int)$id===5104 ? clone $GLOBALS['pct_post'] : null; }
function current_user_can($cap,...$args){ return 'edit_post'===$cap; }
function acf_get_field_groups($args=array()){ return array(array('key'=>'group_portfolio','ID'=>555)); }
function acf_get_field($key){
    $m=array(
      'field_69deb8ddbcd8d'=>array('key'=>'field_69deb8ddbcd8d','name'=>'description_1','type'=>'wysiwyg','parent'=>555),
      'field_69deb8e4bcd8e'=>array('key'=>'field_69deb8e4bcd8e','name'=>'description_2','type'=>'wysiwyg','parent'=>555),
    ); return $m[$key]??null;
}
function get_field($key,$postId,$format=false){ return $GLOBALS['pct_fields'][$key]??null; }
function update_field($key,$value,$postId){ $GLOBALS['pct_fields'][$key]=$value; return true; }
function wp_update_post($data,$wpError=false){ $GLOBALS['pct_post']->post_content=(string)$data['post_content']; return (int)$data['ID']; }
function is_wp_error($value){ return false; }
function wp_json_encode($value){ return json_encode($value); }
function get_option($key,$default=false){ return $default; } function update_option($key,$value,$autoload=null){ return true; }
function delete_option($key){ return true; } function add_option($key,$value,$deprecated='',$autoload=true){ return true; }
function wp_cache_delete($key,$group=''){ return true; }

$root=dirname(__DIR__);
require_once $root.'/plugin/wordpressconnector/includes/Support/Json.php';
require_once $root.'/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once $root.'/plugin/wordpressconnector/includes/Security/Policy.php';
require_once $root.'/plugin/wordpressconnector/includes/Runtime/Request.php';
require_once $root.'/plugin/wordpressconnector/includes/Runtime/Result.php';
require_once $root.'/plugin/wordpressconnector/includes/Runtime/SnapshotStore.php';
require_once $root.'/plugin/wordpressconnector/includes/Runtime/ProcessedStore.php';
require_once $root.'/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once $root.'/plugin/wordpressconnector/includes/Adapters/PortfolioStatsAdapter.php';
require_once $root.'/plugin/wordpressconnector/includes/Runtime/Runner.php';

use Webactueel\WordPressConnector\Adapters\PortfolioStatsAdapter;
use Webactueel\WordPressConnector\Runtime\ProcessedStore;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Runtime\SnapshotStore;
use Webactueel\WordPressConnector\Security\Policy;

Policy::setPublicRepositoryContext(true);
$registry=new Registry(); (new PortfolioStatsAdapter())->register($registry);
$runner=new Runner($registry,new SnapshotStore(),new ProcessedStore());
$payload=array('post_id'=>5104,'content'=>'new intro','description_1'=>'new problem','description_2'=>'new solution');
$dry=$runner->run(Request::fromArray(array('version'=>1,'request_id'=>'portfolio-case-text-dry-0001','action'=>'portfolio.case_text_update','dry_run'=>true,'confirm'=>false,'payload'=>$payload)));
if(empty($dry['ok']) || empty($dry['data']['_current_fingerprint']) && empty($dry['meta']['current_state_token'])) { fwrite(STDERR,"dry run failed\n"); exit(1); }
$fingerprint=Webactueel\WordPressConnector\Support\Fingerprint::make(array('content'=>'old intro','description_1'=>'old problem','description_2'=>'old solution'));
$live=$runner->run(Request::fromArray(array('version'=>1,'request_id'=>'portfolio-case-text-live-0001','action'=>'portfolio.case_text_update','dry_run'=>false,'confirm'=>true,'expected_fingerprint'=>$fingerprint,'payload'=>$payload)));
if(empty($live['ok']) || $GLOBALS['pct_post']->post_content!=='new intro' || $GLOBALS['pct_fields']['field_69deb8ddbcd8d']!=='new problem' || $GLOBALS['pct_fields']['field_69deb8e4bcd8e']!=='new solution') { fwrite(STDERR,"live update failed\n"); exit(1); }
echo "portfolio case text contract OK\n";
