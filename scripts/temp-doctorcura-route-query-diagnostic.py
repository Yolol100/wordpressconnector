#!/usr/bin/env python3
import json,os,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/');USER=os.environ.get('REST_USERNAME','');PASSWORD=os.environ.get('REST_APP_PASSWORD','')
if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')
def curl(path,auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-hostinger-audit-') as td:
        out=os.path.join(td,'body');cmd=['curl','--silent','--show-error','--compressed','--connect-timeout','10','--max-time','40','--output',out,'--write-out','%{http_code}']
        if auth:cmd+=['--user',f'{USER}:{PASSWORD}']
        r=subprocess.run(cmd+[SITE+path],capture_output=True,text=True,timeout=50)
        if r.returncode:raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        raw=open(out,'rb').read();return int(r.stdout.strip()),raw

def main():
    ps,pr=curl('/wp-json/wp/v2/plugins?status=active&per_page=100',True)
    active=[]
    if ps==200:
        data=json.loads(pr.decode())
        for p in data:
            active.append({'plugin':p.get('plugin'),'name':p.get('name'),'status':p.get('status'),'version':p.get('version')})
    rs,rr=curl('/wp-json/',True)
    routes=[];namespaces=[]
    if rs==200:
        idx=json.loads(rr.decode());namespaces=[n for n in idx.get('namespaces',[]) if any(x in n.lower() for x in ('hostinger','cache','litespeed','rocket'))]
        for route in idx.get('routes',{}):
            if any(x in route.lower() for x in ('hostinger','cache','litespeed','rocket')):routes.append(route)
    print(json.dumps({'plugins_status':ps,'active_plugins':active,'rest_status':rs,'matching_namespaces':namespaces,'matching_routes':routes},ensure_ascii=False,indent=2))
if __name__=='__main__':main()
