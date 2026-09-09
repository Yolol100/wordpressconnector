#!/usr/bin/env python3
import json, os, subprocess, tempfile
from urllib.parse import quote

SITE=os.environ.get('SITE_URL','').rstrip('/')
USER=os.environ.get('REST_USERNAME','')
PASSWORD=os.environ.get('REST_APP_PASSWORD','')
if SITE!='https://doctorcura.com' or not USER or not PASSWORD:
    raise RuntimeError('DoctorCura environment mismatch')

def curl(url, auth=False, follow=False):
    with tempfile.TemporaryDirectory(prefix='dc-redirection-audit-') as td:
        hdr=os.path.join(td,'headers.txt'); body=os.path.join(td,'body.bin')
        cmd=['curl','--silent','--show-error','--compressed','--connect-timeout','10','--max-time','45',
             '--user-agent','Mozilla/5.0 (compatible; DoctorCuraRedirectionAudit/1.0)',
             '--dump-header',hdr,'--output',body,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
        if follow: cmd.append('--location')
        if auth: cmd += ['--user',f'{USER}:{PASSWORD}']
        r=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=55)
        if r.returncode:
            raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        parts=r.stdout.strip().split('\t')
        raw_headers=open(hdr,'rb').read().decode('iso-8859-1','replace')
        raw_body=open(body,'rb').read()
        blocks=[b for b in raw_headers.replace('\r\n','\n').split('\n\n') if b.strip().startswith('HTTP/')]
        first=blocks[0] if blocks else ''
        headers={}
        for line in first.splitlines()[1:]:
            if ':' in line:
                k,v=line.split(':',1); headers.setdefault(k.lower().strip(),[]).append(v.strip())
        return {'status':int(parts[0]),'final_url':parts[1],'redirects':int(parts[2]),'headers':headers,'body':raw_body}

def first_hop(path):
    r=curl(SITE+path,follow=False)
    h=r['headers']
    return {
        'path':path,
        'status':r['status'],
        'location':(h.get('location') or [None])[0],
        'x_redirect_by':(h.get('x-redirect-by') or [None])[0],
        'cache_control':(h.get('cache-control') or [None])[0],
        'x_hcdn_cache_status':(h.get('x-hcdn-cache-status') or [None])[0],
    }

def redirection_list(filter_url):
    q=quote(filter_url,safe='')
    url=f"{SITE}/wp-json/redirection/v1/redirect?per_page=200&filterBy%5Burl%5D={q}"
    r=curl(url,auth=True,follow=False)
    if r['status']!=200:
        return {'status':r['status'],'body':r['body'][:1000].decode('utf-8','replace')}
    data=json.loads(r['body'].decode('utf-8'))
    items=data.get('items',[]) if isinstance(data,dict) else []
    slim=[]
    for item in items:
        if not isinstance(item,dict): continue
        slim.append({k:item.get(k) for k in ('id','url','title','status','match_type','action_type','action_code','action_data','group_id','position') if k in item})
    return {'status':200,'total':data.get('total') if isinstance(data,dict) else None,'items':slim}

def main():
    out={
        'first_hops':[first_hop(p) for p in ('/blogs/6/','/blogs/7/','/blogs/8/','/blogs/9/','/fr/blogs/8/','/en/blogs/8/')],
        'redirection_filters':{
            '/blogs/8/':redirection_list('/blogs/8/'),
            'blogs/8':redirection_list('blogs/8'),
            'blogs':redirection_list('blogs'),
        },
    }
    print(json.dumps(out,ensure_ascii=False,indent=2))

if __name__=='__main__':
    main()
