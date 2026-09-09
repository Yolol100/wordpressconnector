#!/usr/bin/env python3
import hashlib,json,os,secrets,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/'); USER=os.environ.get('REST_USERNAME',''); PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'; EXPECTED='6a7c7520a8030055756bc4dc02e1562c6b75917ffef4d52303f27be247931cf3'; MARKER='DoctorCura temporary dynamic pagination boundary probe'
if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')
def curl(url,payload=None,auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-boundary-') as td:
        out=os.path.join(td,'body'); cmd=['curl','--silent','--show-error','--location','--compressed','--connect-timeout','10','--max-time','45','--output',out,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
        if auth: cmd+=['--user',f'{USER}:{PASSWORD}']
        if payload is not None:
            p=os.path.join(td,'payload.json')
            with open(p,'w',encoding='utf-8') as h: json.dump(payload,h,separators=(',',':'))
            cmd+=['--request','POST','--header','X-HTTP-Method-Override: PUT','--header','Content-Type: application/json','--data-binary','@'+p]
        r=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=55)
        if r.returncode: raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        parts=r.stdout.strip().split('\t'); raw=open(out,'rb').read(); return int(parts[0]),parts[1],int(parts[2]),raw
def get_snippet():
    s,_,_,raw=curl(API,auth=True); d=json.loads(raw.decode())
    if s!=200 or int(d.get('id') or 0)!=43 or not d.get('active'): raise RuntimeError('snippet state mismatch')
    return d
def put(code):
    s,_,_,_=curl(API,payload={'code':code},auth=True)
    if s!=200: raise RuntimeError(f'snippet PUT HTTP {s}')
def block(token):
    return r'''
/* DoctorCura temporary dynamic pagination boundary probe - removed automatically */
function doctorcura_tmp_blog_pagination_state(){
 static $state=null,$busy=false;
 if(null!==$state)return $state;
 if($busy)return array('max'=>0,'posts_per_page'=>0,'found_posts'=>0,'ready'=>false);
 $busy=true;$state=array('max'=>0,'posts_per_page'=>0,'found_posts'=>0,'ready'=>false);
 try{
  $raw=get_post_meta(5951,'_elementor_data',true);$tree=is_string($raw)?json_decode($raw,true):$raw;$target=null;$widget_id='6236f6e';
  $find=function($nodes)use(&$find,&$target,$widget_id){if(!is_array($nodes)||$target)return;foreach($nodes as $node){if(!is_array($node))continue;if(isset($node['id'])&&$widget_id===(string)$node['id']){$target=$node;return;}if(isset($node['elements']))$find($node['elements']);}};$find($tree);
  if(!is_array($target)||!class_exists('\\Elementor\\Plugin'))return $state;
  $instance=\Elementor\Plugin::$instance->elements_manager->create_element_instance($target);if(!$instance||!method_exists($instance,'get_current_skin'))return $state;
  $skin=$instance->get_current_skin();if(!$skin||!method_exists($skin,'get_instance_value'))return $state;
  $per_page=absint($skin->get_instance_value('posts_per_page'));if($per_page<1)return $state;
  $settings=isset($target['settings'])&&is_array($target['settings'])?$target['settings']:array();$term_ids=isset($settings['posts_include_term_ids'])?(array)$settings['posts_include_term_ids']:array();$term_ids=array_values(array_filter(array_map('absint',$term_ids)));
  $args=array('post_type'=>'post','post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','no_found_rows'=>false,'ignore_sticky_posts'=>true);
  if($term_ids){$tax=array('relation'=>'OR');foreach($term_ids as $term_id){$term=get_term($term_id);if($term&&!is_wp_error($term))$tax[]=array('taxonomy'=>$term->taxonomy,'field'=>'term_id','terms'=>array($term_id),'include_children'=>true);}if(count($tax)>1)$args['tax_query']=$tax;}
  $q=new WP_Query($args);$found=(int)$q->found_posts;wp_reset_postdata();$max=$found>0?(int)ceil($found/$per_page):0;
  $state=array('max'=>$max,'posts_per_page'=>$per_page,'found_posts'=>$found,'ready'=>true);
 }catch(\Throwable $e){$state=array('max'=>0,'posts_per_page'=>0,'found_posts'=>0,'ready'=>false,'error'=>get_class($e).': '.$e->getMessage());}
 finally{$busy=false;}
 return $state;
}
add_filter('request',function($q){
 if(!isset($_GET['doctorcura_boundary_probe'])||!hash_equals('__TOKEN__',(string)$_GET['doctorcura_boundary_probe']))return $q;
 $path=wp_parse_url(isset($_SERVER['REQUEST_URI'])?(string)$_SERVER['REQUEST_URI']:'',PHP_URL_PATH);if(!is_string($path))return $q;
 if(preg_match('#^/(?:fr/|en/)?blogs/([2-9]|[1-9][0-9]+)/?$#',$path,$m)&&isset($q['pagename'])&&'blogs'===trim((string)$q['pagename'],'/')){$s=doctorcura_tmp_blog_pagination_state();$n=(int)$m[1];if(!empty($s['ready'])&&$n<=(int)$s['max']){unset($q['page']);$q['paged']=$n;}}
 return $q;
},1);
add_action('template_redirect',function(){
 if(!isset($_GET['doctorcura_boundary_probe'])||!hash_equals('__TOKEN__',(string)$_GET['doctorcura_boundary_probe']))return;
 global $wp,$wp_query;$allow=array('pagename','paged','page','error');$a=array();$b=array();foreach($allow as $k){if(isset($wp->query_vars)&&array_key_exists($k,$wp->query_vars))$a[$k]=$wp->query_vars[$k];if(isset($wp_query->query_vars)&&array_key_exists($k,$wp_query->query_vars))$b[$k]=$wp_query->query_vars[$k];}
 $d=array('state'=>doctorcura_tmp_blog_pagination_state(),'request_uri'=>isset($_SERVER['REQUEST_URI'])?wp_parse_url((string)$_SERVER['REQUEST_URI'],PHP_URL_PATH):null,'query_vars'=>$a,'wp_query_vars'=>$b,'is_page'=>is_page(),'is_paged'=>is_paged(),'is_404'=>is_404(),'queried_id'=>get_queried_object_id());nocache_headers();status_header(200);header('Content-Type: application/json; charset=utf-8');echo wp_json_encode($d);exit;
},-9999);
'''.replace('__TOKEN__',token)
def probe(path,token):
    s,f,r,raw=curl(f'{SITE}{path}?doctorcura_boundary_probe={token}')
    if s!=200 or r!=0: raise RuntimeError(f'probe transport {path}: {s} {r} {f}')
    return json.loads(raw.decode())
def main():
    d=get_snippet();original=str(d.get('code') or '');sha=hashlib.sha256(original.encode()).hexdigest()
    if sha!=EXPECTED or MARKER in original: raise RuntimeError('stale/residue preflight')
    token=secrets.token_urlsafe(24);applied=False
    try:
        put(original.rstrip()+'\n'+block(token));applied=True
        if MARKER not in str(get_snippet().get('code') or ''): raise RuntimeError('boundary probe readback failed')
        rows={}
        for prefix in ('','/fr','/en'):
            for n in (6,16,17,18):
                path=f'{prefix}/blogs/{n}/';row=probe(path,token);rows[path]=row;state=row.get('state') or {};q=row.get('wp_query_vars') or {}
                if state.get('max')!=16 or state.get('posts_per_page')!=6 or state.get('found_posts')!=92 or not state.get('ready'): raise RuntimeError('dynamic state mismatch '+path+': '+json.dumps(row,separators=(',',':')))
                if n<=16:
                    if int(q.get('paged') or 0)!=n or int(q.get('page') or 0)!=0 or not row.get('is_page') or not row.get('is_paged') or row.get('is_404'): raise RuntimeError('valid boundary failed '+path+': '+json.dumps(row,separators=(',',':')))
                else:
                    if int(q.get('page') or 0)!=n or int(q.get('paged') or 0)!=0 or not row.get('is_404'): raise RuntimeError('invalid boundary failed '+path+': '+json.dumps(row,separators=(',',':')))
        print(json.dumps({'ok':True,'verified':len(rows),'rows':rows},ensure_ascii=False,indent=2))
    finally:
        if applied: put(original)
        restored=str(get_snippet().get('code') or '');after=hashlib.sha256(restored.encode()).hexdigest()
        if after!=sha or MARKER in restored: raise RuntimeError('rollback verification failed')
        print(json.dumps({'rollback_verified':True,'sha256':after},separators=(',',':')))
if __name__=='__main__': main()
