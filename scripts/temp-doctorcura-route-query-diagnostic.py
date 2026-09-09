#!/usr/bin/env python3
import json,os,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/'); USER=os.environ.get('REST_USERNAME',''); PASSWORD=os.environ.get('REST_APP_PASSWORD','')
if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')
def get(path,auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-cache-') as td:
        out=os.path.join(td,'body'); cmd=['curl','--silent','--show-error','--location','--compressed','--connect-timeout','10','--max-time','40','--output',out,'--write-out','%{http_code}']
        if auth: cmd+=['--user',f'{USER}:{PASSWORD}']
        r=subprocess.run(cmd+[SITE+path],capture_output=True,text=True,timeout=50)
        if r.returncode: raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        status=int(r.stdout.strip()); raw=open(out,'rb').read()
        if status!=200: raise RuntimeError(f'GET {path} HTTP {status}')
        return json.loads(raw.decode('utf-8'))
def main():
    plugins=get('/wp-json/wp/v2/plugins?status=active&per_page=100',auth=True)
    active=[]
    for p in plugins:
        plugin=str(p.get('plugin') or ''); name=str(p.get('name') or ''); desc=str(p.get('description') or {}).lower() if isinstance(p.get('description'),str) else ''
        if any(k in (plugin+' '+name+' '+desc).lower() for k in ('cache','rocket','litespeed','hostinger','object-cache','redis')):
            active.append({'plugin':plugin,'name':name,'status':p.get('status'),'version':p.get('version')})
    root=get('/wp-json/')
    routes=list((root.get('routes') or {}).keys())
    cache_routes=[r for r in routes if any(k in r.lower() for k in ('cache','rocket','litespeed','hostinger','redis'))]
    namespaces=[n for n in root.get('namespaces',[]) if any(k in n.lower() for k in ('hostinger','litespeed','rocket','cache','redis'))]
    print(json.dumps({'active_cache_plugins':active,'cache_related_namespaces':namespaces,'cache_related_routes':cache_routes},ensure_ascii=False,indent=2))
if __name__=='__main__': main()
