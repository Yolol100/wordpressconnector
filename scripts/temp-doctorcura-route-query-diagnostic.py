#!/usr/bin/env python3
import hashlib,json,os,secrets,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/'); USER=os.environ.get('REST_USERNAME',''); PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'; EXPECTED='6a7c7520a8030055756bc4dc02e1562c6b75917ffef4d52303f27be247931cf3'; MARKER='DoctorCura temporary pagination route diagnostic'
if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')
def curl(url,payload=None,auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-route-') as td:
        out=os.path.join(td,'body'); cmd=['curl','--silent','--show-error','--location','--compressed','--connect-timeout','10','--max-time','40','--output',out,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
        if auth: cmd+=['--user',f'{USER}:{PASSWORD}']
        if payload is not None:
            p=os.path.join(td,'payload.json'); json.dump(payload,open(p,'w',encoding='utf-8'),separators=(',',':')); cmd+=['--request','POST','--header','X-HTTP-Method-Override: PUT','--header','Content-Type: application/json','--data-binary','@'+p]
        r=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=50); parts=r.stdout.strip().split('\t'); raw=open(out,'rb').read()
        if r.returncode: raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        return int(parts[0]),parts[1],int(parts[2]),raw
def get():
    s,_,_,raw=curl(API,auth=True); d=json.loads(raw.decode());
    if s!=200 or int(d.get('id') or 0)!=43 or not d.get('active'): raise RuntimeError('snippet state mismatch')
    return d
def put(code):
    s,_,_,_=curl(API,payload={'code':code},auth=True)
    if s!=200: raise RuntimeError(f'snippet PUT HTTP {s}')
def block(token):
    return r'''
/* DoctorCura temporary pagination route diagnostic - removed automatically */
add_action('template_redirect',function(){
 if(!isset($_GET['doctorcura_route_probe'])||!hash_equals('__TOKEN__',(string)$_GET['doctorcura_route_probe']))return;
 global $wp,$wp_query; $allow=array('pagename','page_id','paged','page','name','post_type','error'); $a=array();$b=array();
 foreach($allow as $k){if(isset($wp->query_vars)&&array_key_exists($k,$wp->query_vars))$a[$k]=$wp->query_vars[$k];if(isset($wp_query->query_vars)&&array_key_exists($k,$wp_query->query_vars))$b[$k]=$wp_query->query_vars[$k];}
 $d=array('request_uri'=>isset($_SERVER['REQUEST_URI'])?wp_parse_url((string)$_SERVER['REQUEST_URI'],PHP_URL_PATH):null,'matched_rule'=>isset($wp->matched_rule)?(string)$wp->matched_rule:null,'matched_query'=>isset($wp->matched_query)?(string)$wp->matched_query:null,'query_vars'=>$a,'wp_query_vars'=>$b,'is_page'=>is_page(),'is_paged'=>is_paged(),'is_404'=>is_404(),'queried_id'=>get_queried_object_id());
 nocache_headers();status_header(200);header('Content-Type: application/json; charset=utf-8');echo wp_json_encode($d);exit;
},-9999);
'''.replace('__TOKEN__',token)
def probe(path,token):
    s,f,r,raw=curl(f'{SITE}{path}?doctorcura_route_probe={token}')
    if s!=200 or r!=0: raise RuntimeError(f'probe transport {path}: {s} {r} {f}')
    return json.loads(raw.decode())
def main():
    d=get(); original=str(d.get('code') or ''); sha=hashlib.sha256(original.encode()).hexdigest()
    if sha!=EXPECTED or MARKER in original: raise RuntimeError('stale/residue preflight')
    token=secrets.token_urlsafe(24); applied=False
    try:
        put(original.rstrip()+'\n'+block(token)); applied=True
        routes={}
        for n in range(6,11):
            for path in (f'/blogs/{n}/',f'/blogs/page/{n}/'):
                routes[path]=probe(path,token)
        print(json.dumps({'ok':True,'routes':routes},ensure_ascii=False,indent=2))
    finally:
        if applied: put(original)
        restored=str(get().get('code') or ''); after=hashlib.sha256(restored.encode()).hexdigest()
        if after!=sha or MARKER in restored: raise RuntimeError('rollback verification failed')
        print(json.dumps({'rollback_verified':True,'sha256':after},separators=(',',':')))
if __name__=='__main__': main()
