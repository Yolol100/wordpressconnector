#!/usr/bin/env python3
import hashlib, importlib.util, json, os, subprocess, tempfile

SITE=os.environ.get('SITE_URL','').rstrip('/')
USER=os.environ.get('REST_USERNAME','')
PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'
EXPECTED='6a7c7520a8030055756bc4dc02e1562c6b75917ffef4d52303f27be247931cf3'
TRACE_MARKER='DoctorCura temporary origin redirect tracer'
if SITE!='https://doctorcura.com' or not USER or not PASSWORD:
    raise RuntimeError('DoctorCura environment mismatch')

spec=importlib.util.spec_from_file_location('dcv10','scripts/temp-doctorcura-dynamic-pagination-fix-v9.py')
dcv10=importlib.util.module_from_spec(spec); spec.loader.exec_module(dcv10)

def curl(url, payload=None, auth=False, bypass=False):
    with tempfile.TemporaryDirectory(prefix='dc-origin-trace-') as td:
        hdr=os.path.join(td,'headers.txt'); body=os.path.join(td,'body.bin')
        cmd=['curl','--silent','--show-error','--compressed','--connect-timeout','10','--max-time','45',
             '--user-agent','Mozilla/5.0 (compatible; DoctorCuraOriginTrace/1.0)',
             '--dump-header',hdr,'--output',body,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
        if bypass:
            cmd += ['--header','Cache-Control: no-cache, no-store, max-age=0','--header','Pragma: no-cache','--header','X-DoctorCura-Diagnostic: origin-trace']
        if auth: cmd += ['--user',f'{USER}:{PASSWORD}']
        if payload is not None:
            p=os.path.join(td,'payload.json')
            with open(p,'w',encoding='utf-8') as h: json.dump(payload,h,separators=(',',':'))
            cmd += ['--request','POST','--header','X-HTTP-Method-Override: PUT','--header','Content-Type: application/json','--data-binary','@'+p]
        r=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=55)
        if r.returncode: raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        parts=r.stdout.strip().split('\t'); raw_headers=open(hdr,'rb').read().decode('iso-8859-1','replace'); raw_body=open(body,'rb').read()
        blocks=[b for b in raw_headers.replace('\r\n','\n').split('\n\n') if b.strip().startswith('HTTP/')]
        first=blocks[0] if blocks else ''
        headers={}
        for line in first.splitlines()[1:]:
            if ':' in line:
                k,v=line.split(':',1); headers.setdefault(k.lower().strip(),[]).append(v.strip())
        return int(parts[0]),parts[1],int(parts[2]),headers,raw_body

def get_snippet():
    s,_,_,_,raw=curl(API,auth=True); d=json.loads(raw.decode()) if s==200 else {}
    if s!=200 or int(d.get('id') or 0)!=43 or not d.get('active'):
        raise RuntimeError('snippet readback mismatch')
    return d

def put(code):
    s,_,_,_,raw=curl(API,payload={'code':code},auth=True)
    if s!=200: raise RuntimeError(f'snippet PUT HTTP {s}')
    return json.loads(raw.decode())

TRACE_BLOCK=r'''
/* DoctorCura temporary origin redirect tracer - removed automatically */
add_action('send_headers',function(){
 if(isset($_SERVER['HTTP_X_DOCTORCURA_DIAGNOSTIC'])&&'origin-trace'===(string)$_SERVER['HTTP_X_DOCTORCURA_DIAGNOSTIC']){
  nocache_headers(); header('X-DoctorCura-Origin: reached');
 }
},-9999);
add_filter('wp_redirect',function($location,$status){
 if(!isset($_SERVER['HTTP_X_DOCTORCURA_DIAGNOSTIC'])||'origin-trace'!==(string)$_SERVER['HTTP_X_DOCTORCURA_DIAGNOSTIC'])return $location;
 $frames=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,12);$names=array();
 foreach($frames as $frame){$name='';if(isset($frame['class']))$name.=$frame['class'].(isset($frame['type'])?$frame['type']:'');if(isset($frame['function']))$name.=$frame['function'];if($name&&false===strpos($name,'{closure}'))$names[]=$name;if(count($names)>=6)break;}
 header('X-DoctorCura-Redirect-Caller: '.substr(implode(' <- ',$names),0,700));
 header('X-DoctorCura-Redirect-Status: '.(int)$status);
 return $location;
},9999,2);
'''

def row(path,bypass):
    s,f,r,h,_=curl(SITE+path,bypass=bypass)
    return {'path':path,'bypass':bypass,'status':s,'final_url':f,'redirects':r,
            'location':(h.get('location') or [None])[0],
            'x_redirect_by':(h.get('x-redirect-by') or [None])[0],
            'hcdn':(h.get('x-hcdn-cache-status') or [None])[0],
            'origin':(h.get('x-doctorcura-origin') or [None])[0],
            'caller':(h.get('x-doctorcura-redirect-caller') or [None])[0],
            'trace_status':(h.get('x-doctorcura-redirect-status') or [None])[0]}

def main():
    d=get_snippet(); original=str(d.get('code') or ''); sha=hashlib.sha256(original.encode()).hexdigest()
    if sha!=EXPECTED or original.count(dcv10.OLD_BLOCK)!=1 or TRACE_MARKER in original:
        raise RuntimeError('stale V8.2 preflight')
    candidate=original.replace(dcv10.OLD_BLOCK,dcv10.NEW_BLOCK,1).rstrip()+TRACE_BLOCK
    applied=False
    try:
        put(candidate); applied=True
        live=str(get_snippet().get('code') or '')
        if dcv10.NEW_MARKER not in live or TRACE_MARKER not in live:
            raise RuntimeError('temporary V10 readback failed')
        rows=[]
        for path in ('/blogs/6/','/blogs/7/','/blogs/8/','/blogs/9/','/fr/blogs/8/','/en/blogs/8/'):
            rows.append(row(path,True))
        print(json.dumps({'candidate_marker':dcv10.NEW_MARKER,'rows':rows},ensure_ascii=False,indent=2))
    finally:
        if applied: put(original)
        restored=str(get_snippet().get('code') or ''); after=hashlib.sha256(restored.encode()).hexdigest()
        if after!=sha or TRACE_MARKER in restored or dcv10.NEW_MARKER in restored:
            raise RuntimeError('rollback verification failed')
        print(json.dumps({'rollback_verified':True,'sha256':after},separators=(',',':')))

if __name__=='__main__': main()
