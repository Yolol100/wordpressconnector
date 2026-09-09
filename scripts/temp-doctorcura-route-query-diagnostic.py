#!/usr/bin/env python3
import hashlib,json,os,secrets,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/'); USER=os.environ.get('REST_USERNAME',''); PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'; EXPECTED='6a7c7520a8030055756bc4dc02e1562c6b75917ffef4d52303f27be247931cf3'; MARKER='DoctorCura temporary redirect hook diagnostic'
if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')
def curl(url,payload=None,auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-hooks-') as td:
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
/* DoctorCura temporary redirect hook diagnostic - removed automatically */
function doctorcura_tmp_callback_name($callback){
 if(is_string($callback))return $callback;
 if(is_array($callback)&&count($callback)>=2){$owner=$callback[0];$method=$callback[1];if(is_object($owner))return get_class($owner).'->'.$method;if(is_string($owner))return $owner.'::'.$method;}
 if($callback instanceof Closure)return 'Closure';
 if(is_object($callback)&&method_exists($callback,'__invoke'))return get_class($callback).'::__invoke';
 return gettype($callback);
}
function doctorcura_tmp_hook_map($tag){
 global $wp_filter;$out=array();if(!isset($wp_filter[$tag])||!is_object($wp_filter[$tag])||!isset($wp_filter[$tag]->callbacks))return $out;
 foreach($wp_filter[$tag]->callbacks as $priority=>$callbacks){foreach($callbacks as $entry){$out[]=array('priority'=>(int)$priority,'callback'=>doctorcura_tmp_callback_name($entry['function']??null));}}
 return $out;
}
add_filter('request',function($q){
 if(!isset($_GET['doctorcura_hook_probe'])||!hash_equals('__TOKEN__',(string)$_GET['doctorcura_hook_probe']))return $q;
 $path=wp_parse_url(isset($_SERVER['REQUEST_URI'])?(string)$_SERVER['REQUEST_URI']:'',PHP_URL_PATH);
 if(is_string($path)&&preg_match('#^/(?:fr/|en/)?blogs/([2-9]|[1-9][0-9]+)/?$#',$path,$m)&&isset($q['pagename'])&&'blogs'===trim((string)$q['pagename'],'/')){unset($q['page']);$q['paged']=(int)$m[1];}
 return $q;
},1);
add_action('template_redirect',function(){
 if(!isset($_GET['doctorcura_hook_probe'])||!hash_equals('__TOKEN__',(string)$_GET['doctorcura_hook_probe']))return;
 global $wp_query;
 $d=array('is_page'=>is_page(),'is_paged'=>is_paged(),'is_404'=>is_404(),'paged'=>get_query_var('paged'),'page'=>get_query_var('page'),'template_redirect'=>doctorcura_tmp_hook_map('template_redirect'),'redirect_canonical_filters'=>doctorcura_tmp_hook_map('redirect_canonical'),'wp_redirect_filters'=>doctorcura_tmp_hook_map('wp_redirect'),'x_redirect_by_filters'=>doctorcura_tmp_hook_map('x_redirect_by'));
 nocache_headers();status_header(200);header('Content-Type: application/json; charset=utf-8');echo wp_json_encode($d);exit;
},-9999);
'''.replace('__TOKEN__',token)
def main():
    d=get_snippet();original=str(d.get('code') or '');sha=hashlib.sha256(original.encode()).hexdigest()
    if sha!=EXPECTED or MARKER in original: raise RuntimeError('stale/residue preflight')
    token=secrets.token_urlsafe(24);applied=False
    try:
        put(original.rstrip()+'\n'+block(token));applied=True
        if MARKER not in str(get_snippet().get('code') or ''): raise RuntimeError('hook probe readback failed')
        s,f,r,raw=curl(f'{SITE}/blogs/8/?doctorcura_hook_probe={token}')
        if s!=200 or r!=0: raise RuntimeError(f'probe transport {s} {r} {f}')
        print(json.dumps(json.loads(raw.decode()),ensure_ascii=False,indent=2))
    finally:
        if applied: put(original)
        restored=str(get_snippet().get('code') or '');after=hashlib.sha256(restored.encode()).hexdigest()
        if after!=sha or MARKER in restored: raise RuntimeError('rollback verification failed')
        print(json.dumps({'rollback_verified':True,'sha256':after},separators=(',',':')))
if __name__=='__main__': main()
