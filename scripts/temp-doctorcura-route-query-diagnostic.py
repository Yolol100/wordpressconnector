#!/usr/bin/env python3
import hashlib,json,os,secrets,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/'); USER=os.environ.get('REST_USERNAME',''); PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'; EXPECTED='6a7c7520a8030055756bc4dc02e1562c6b75917ffef4d52303f27be247931cf3'; MARKER='DoctorCura temporary Elementor defaults diagnostic'
if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')
def curl(url,payload=None,auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-defaults-') as td:
        out=os.path.join(td,'body'); cmd=['curl','--silent','--show-error','--location','--compressed','--connect-timeout','10','--max-time','40','--output',out,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
        if auth: cmd+=['--user',f'{USER}:{PASSWORD}']
        if payload is not None:
            p=os.path.join(td,'payload.json')
            with open(p,'w',encoding='utf-8') as h: json.dump(payload,h,separators=(',',':'))
            cmd+=['--request','POST','--header','X-HTTP-Method-Override: PUT','--header','Content-Type: application/json','--data-binary','@'+p]
        r=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=50)
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
/* DoctorCura temporary Elementor defaults diagnostic - removed automatically */
add_action('template_redirect',function(){
 if(!isset($_GET['doctorcura_defaults_probe'])||!hash_equals('__TOKEN__',(string)$_GET['doctorcura_defaults_probe']))return;
 $page_id=5951; $widget_id='6236f6e'; $result=array('page_id'=>$page_id,'widget_id'=>$widget_id);
 $term=get_term(1);
 $result['term']=(!is_wp_error($term)&&$term)?array('term_id'=>(int)$term->term_id,'taxonomy'=>(string)$term->taxonomy,'name'=>(string)$term->name,'count'=>(int)$term->count):null;
 $raw=get_post_meta($page_id,'_elementor_data',true); $tree=is_string($raw)?json_decode($raw,true):$raw; $target=null;
 $find=function($nodes) use (&$find,&$target,$widget_id){
  if(!is_array($nodes)||$target)return;
  foreach($nodes as $node){if(!is_array($node))continue;if(isset($node['id'])&&$widget_id===(string)$node['id']){$target=$node;return;}if(isset($node['elements']))$find($node['elements']);}
 };
 $find($tree);
 $result['raw_settings']=is_array($target)&&isset($target['settings'])&&is_array($target['settings'])?$target['settings']:array();
 if(is_array($target)&&class_exists('\\Elementor\\Plugin')){
  try{
   $instance=\Elementor\Plugin::$instance->elements_manager->create_element_instance($target);
   if($instance){
    $settings=$instance->get_settings(); $display=$instance->get_settings_for_display(); $keys=array('posts_per_page','pagination_page_limit','pagination_type','posts_include','posts_include_term_ids','posts_include_author_ids','orderby','order','offset_posts','query_id');
    $picked=array();$picked_display=array();
    foreach($keys as $k){if(is_array($settings)&&array_key_exists($k,$settings))$picked[$k]=$settings[$k];if(is_array($display)&&array_key_exists($k,$display))$picked_display[$k]=$display[$k];}
    $result['resolved_settings']=$picked; $result['resolved_display_settings']=$picked_display; $result['widget_class']=get_class($instance);
   }
  }catch(\Throwable $e){$result['elementor_error']=get_class($e).': '.$e->getMessage();}
 }
 $taxonomy=$result['term']['taxonomy']??null; $count=null;
 if($taxonomy){$q=new WP_Query(array('post_type'=>'post','post_status'=>'publish','posts_per_page'=>1,'tax_query'=>array(array('taxonomy'=>$taxonomy,'field'=>'term_id','terms'=>array(1)))));$count=(int)$q->found_posts;wp_reset_postdata();}
 $result['matching_published_posts']=$count;
 nocache_headers();status_header(200);header('Content-Type: application/json; charset=utf-8');echo wp_json_encode($result);exit;
},-9999);
'''.replace('__TOKEN__',token)
def main():
    d=get_snippet(); original=str(d.get('code') or ''); sha=hashlib.sha256(original.encode()).hexdigest()
    if sha!=EXPECTED or MARKER in original: raise RuntimeError('stale/residue preflight')
    token=secrets.token_urlsafe(24); applied=False
    try:
        put(original.rstrip()+'\n'+block(token)); applied=True
        readback=str(get_snippet().get('code') or '')
        if MARKER not in readback: raise RuntimeError('defaults probe readback failed')
        s,f,r,raw=curl(f'{SITE}/?doctorcura_defaults_probe={token}')
        if s!=200 or r!=0: raise RuntimeError(f'probe transport {s} {r} {f}')
        data=json.loads(raw.decode()); print(json.dumps(data,ensure_ascii=False,indent=2))
    finally:
        if applied: put(original)
        restored=str(get_snippet().get('code') or ''); after=hashlib.sha256(restored.encode()).hexdigest()
        if after!=sha or MARKER in restored: raise RuntimeError('rollback verification failed')
        print(json.dumps({'rollback_verified':True,'sha256':after},separators=(',',':')))
if __name__=='__main__': main()
