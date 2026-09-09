#!/usr/bin/env python3
import hashlib,json,os,secrets,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/'); USER=os.environ.get('REST_USERNAME',''); PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'; EXPECTED='6a7c7520a8030055756bc4dc02e1562c6b75917ffef4d52303f27be247931cf3'; MARKER='DoctorCura temporary Elementor pagination diagnostic'
if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')
def curl(url,payload=None,auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-elementor-') as td:
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
/* DoctorCura temporary Elementor pagination diagnostic - removed automatically */
add_action('template_redirect',function(){
 if(!isset($_GET['doctorcura_elementor_probe'])||!hash_equals('__TOKEN__',(string)$_GET['doctorcura_elementor_probe']))return;
 $page_id=5951; $raw=get_post_meta($page_id,'_elementor_data',true); $tree=is_string($raw)?json_decode($raw,true):$raw; $widgets=array();
 $walk=function($nodes) use (&$walk,&$widgets){
  if(!is_array($nodes))return;
  foreach($nodes as $node){
   if(!is_array($node))continue;
   if(isset($node['widgetType'])){
    $settings=isset($node['settings'])&&is_array($node['settings'])?$node['settings']:array(); $picked=array();
    foreach($settings as $k=>$v){if(preg_match('/query|post|page|pagin|include|exclude|tax|term|order|offset|template|skin|source|limit|count/i',(string)$k))$picked[$k]=$v;}
    if(preg_match('/post|loop|archive/i',(string)$node['widgetType'])||$picked){$widgets[]=array('id'=>$node['id']??null,'widgetType'=>$node['widgetType'],'settings'=>$picked);}
   }
   if(isset($node['elements']))$walk($node['elements']);
  }
 };
 $walk($tree);
 $counts=wp_count_posts('post'); $data=array('page_id'=>$page_id,'status'=>get_post_status($page_id),'template_type'=>get_post_meta($page_id,'_elementor_template_type',true),'published_posts'=>isset($counts->publish)?(int)$counts->publish:null,'widgets'=>$widgets);
 nocache_headers();status_header(200);header('Content-Type: application/json; charset=utf-8');echo wp_json_encode($data);exit;
},-9999);
'''.replace('__TOKEN__',token)
def main():
    d=get_snippet(); original=str(d.get('code') or ''); sha=hashlib.sha256(original.encode()).hexdigest()
    if sha!=EXPECTED or MARKER in original: raise RuntimeError('stale/residue preflight')
    token=secrets.token_urlsafe(24); applied=False
    try:
        put(original.rstrip()+'\n'+block(token)); applied=True
        s,f,r,raw=curl(f'{SITE}/?doctorcura_elementor_probe={token}')
        if s!=200 or r!=0: raise RuntimeError(f'probe transport {s} {r} {f}')
        data=json.loads(raw.decode()); print(json.dumps(data,ensure_ascii=False,indent=2))
    finally:
        if applied: put(original)
        restored=str(get_snippet().get('code') or ''); after=hashlib.sha256(restored.encode()).hexdigest()
        if after!=sha or MARKER in restored: raise RuntimeError('rollback verification failed')
        print(json.dumps({'rollback_verified':True,'sha256':after},separators=(',',':')))
if __name__=='__main__': main()
