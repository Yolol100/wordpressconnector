<?php
declare(strict_types=1);
$GLOBALS['tps_options']=array('wpseo_titles'=>array(
    'title-page'=>'Old %%title%%','metadesc-tax-category'=>'Previous %%term_title%% summary',
    'title-archive-wpseo'=>'Old dated %%date%% posts','open_graph_frontpage_title'=>'Old social identity',
    'breadcrumbs-home'=>'Home', 'breadcrumbs-sep'=>'»',
    'social-description-tax-post_tag'=>'Prior tag social summary','company_name'=>'Tape Solutions','other_key'=>'preserve'));
$GLOBALS['tps_caps']=array();
function get_option($key,$default=null){return $GLOBALS['tps_options'][$key]??$default;}
function update_option($key,$value){$GLOBALS['tps_options'][$key]=$value;return true;}
function sanitize_text_field($text){return trim(strip_tags((string)$text));}
function current_user_can($cap){return in_array($cap,$GLOBALS['tps_caps'],true);}
$root=dirname(__DIR__).'/plugin/wordpressconnector/includes/';
foreach(array('Support/Json.php','Support/Fingerprint.php','Security/Policy.php',
    'Runtime/Registry.php','Adapters/YoastTemplateSettingsAdapter.php') as $file)require $root.$file;
$registry=new \Webactueel\WordPressConnector\Runtime\Registry();
(new \Webactueel\WordPressConnector\Adapters\YoastTemplateSettingsAdapter())->register($registry);
$policy='Webactueel\\WordPressConnector\\Security\\Policy';
$policy::setPublicRepositoryContext(false);
$descriptor=$registry->descriptor('yoast.templates.update');
if(empty($descriptor['mutation'])||empty($descriptor['privileged'])||
    $descriptor['capability']!=='manage_options')throw new RuntimeException('Unsafe descriptor.');
$expectFail=static function(callable $fn,string $needle): void {
    try{$fn();}catch(\RuntimeException $e){
        if(strpos($e->getMessage(),$needle)!==false)return;
        throw new RuntimeException('Wrong error: '.$e->getMessage());
    }
    throw new RuntimeException('Expected failure: '.$needle);
};
$expectFail(static function()use($policy,$descriptor){
    $policy::assertActionAllowed($descriptor,false,true);
},'lacks the required WordPress capability');
$GLOBALS['tps_caps'][]='manage_options';
$policy::assertActionAllowed($descriptor,false,true);
$before=$registry->execute('yoast.templates.inspect',array());
$policy::setPublicRepositoryContext(true);
$publicTitles=$registry->execute('yoast.templates.inspect',array());
if(array_keys($publicTitles['fields'])!==array('category_title','product_category_title')
    || $publicTitles['fingerprint']!==$before['fingerprint']) {
    throw new RuntimeException('Public Yoast template inspect leaked unrelated fields or changed stale-state fingerprint.');
}
$policy::setPublicRepositoryContext(false);
if($before['fields']['page_title']!=='Old %%title%%'||
    $before['fields']['category_description']!=='Previous %%term_title%% summary'||
    $before['fields']['date_archive_title']!=='Old dated %%date%% posts'||
    $before['fields']['home_social_title']!=='Old social identity'||
    $before['fields']['breadcrumb_home_label']!=='Home'||
    $before['fields']['breadcrumb_separator']!=='»'||
    $before['fields']['tag_social_description']!=='Prior tag social summary'||
    !array_key_exists('search_results_title',$before['fields'])||
    !array_key_exists('author_social_title',$before['fields'])||
    $before['fields']['product_description']!==null)throw new RuntimeException('Baseline wrong.');
$changes=array('page_title'=>'%%title%% %%sep%% %%sitename%%',
    'category_description'=>'%%term_title%%: informatie en nieuws van %%sitename%%',
    'date_archive_title'=>'%%date%% %%sep%% %%sitename%%',
    'home_social_title'=>'Proline: %%sitename%%',
    'breadcrumb_home_label'=>'Begin', 'breadcrumb_separator'=>'›',
    'tag_social_description'=>'Bekijk %%term_title%% op %%sitename%%',
    'product_description'=>'%%excerpt%%');
$preview=$registry->execute('yoast.templates.update',array('fields'=>$changes),array('dry_run'=>true));
if($preview['after']['page_title']!==$changes['page_title']||
    $GLOBALS['tps_options']['wpseo_titles']['title-page']!=='Old %%title%%')
    throw new RuntimeException('Dry-run changed state.');
$apply=$registry->execute('yoast.templates.update',array('fields'=>$changes),array('dry_run'=>false));
if($GLOBALS['tps_options']['wpseo_titles']['other_key']!=='preserve'||
    $registry->execute('yoast.templates.inspect',array())['fields']['category_description']!==$changes['category_description']||
    $registry->execute('yoast.templates.inspect',array())['fields']['date_archive_title']!==$changes['date_archive_title']||
    $registry->execute('yoast.templates.inspect',array())['fields']['home_social_title']!==$changes['home_social_title']||
    $registry->execute('yoast.templates.inspect',array())['fields']['breadcrumb_home_label']!==$changes['breadcrumb_home_label']||
    $registry->execute('yoast.templates.inspect',array())['fields']['breadcrumb_separator']!==$changes['breadcrumb_separator']||
    $registry->execute('yoast.templates.inspect',array())['fields']['tag_social_description']!==$changes['tag_social_description']||
    $registry->execute('yoast.templates.inspect',array())['fields']['product_description']!=='%%excerpt%%')
    throw new RuntimeException('Unrelated option changed or write missing.');
$expectFail(static function()use($registry,$apply){
    $registry->execute('yoast.templates.restore',$apply['_rollback']['payload']);
},'Rollback only');
$registry->execute('yoast.templates.restore',$apply['_rollback']['payload'],array('rollback_mode'=>true));
$after=$registry->execute('yoast.templates.inspect',array());
if($after['fields']!==$before['fields']||
    $GLOBALS['tps_options']['wpseo_titles']['other_key']!=='preserve')
    throw new RuntimeException('Rollback failed.');
$expectFail(static function()use($registry,$apply){
    $registry->execute('yoast.templates.restore',$apply['_rollback']['payload'],array('rollback_mode'=>true));
},'changed since write');
foreach(array(
    array('fields'=>array('wpseo_titles'=>'x'),'error'=>'Unsupported'),
    array('fields'=>array('page_title'=>array('x')),'error'=>'Unsupported'),
    array('fields'=>array('page_title'=>''),'error'=>'Invalid template'),
    array('fields'=>array('home_description'=>str_repeat('x',321)),'error'=>'Invalid template'),
    array('fields'=>array('category_description'=>str_repeat('x',321)),'error'=>'Invalid template'),
    array('fields'=>array('tag_social_description'=>str_repeat('x',321)),'error'=>'Invalid template'),
    array('fields'=>array('date_archive_title'=>array('unexpected')),'error'=>'Unsupported'),
    array('fields'=>array('breadcrumb_separator'=>str_repeat('x',21)),'error'=>'Invalid template'),
    array('fields'=>array('breadcrumb_home_label'=>str_repeat('x',121)),'error'=>'Invalid template'),
) as $case){
    $expected=$case['error'];
    $expectFail(static function()use($registry,$case){
        $registry->execute('yoast.templates.update',array('fields'=>$case['fields']),array('dry_run'=>true));
    },$expected);
}
echo "Yoast templates allowlist, roles, dry-run, readback and rollback OK\n";
