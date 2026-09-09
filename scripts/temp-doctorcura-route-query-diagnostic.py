#!/usr/bin/env python3
import hashlib,json,os,re,subprocess,tempfile

SITE=os.environ.get('SITE_URL','').rstrip('/')
USER=os.environ.get('REST_USERNAME','')
PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'
EXPECTED='83a05b5734751d01d93488fcfbcd601ce2e245a7f41e6d2675beaf38af1dec7b'
MARKER='DoctorCura dynamic blog pagination cleanup V10'
if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')

def curl(url,auth=False,follow=False):
    with tempfile.TemporaryDirectory(prefix='dc-v10-public-qa-') as td:
        hdr=os.path.join(td,'headers.txt');body=os.path.join(td,'body.bin')
        cmd=['curl','--silent','--show-error','--compressed','--connect-timeout','10','--max-time','45','--user-agent','Mozilla/5.0 (compatible; DoctorCuraPublicPaginationQA/1.0)','--dump-header',hdr,'--output',body,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
        if follow: cmd.append('--location')
        if auth: cmd += ['--user',f'{USER}:{PASSWORD}']
        r=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=55)
        if r.returncode: raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        parts=r.stdout.strip().split('\t');raw_headers=open(hdr,'rb').read().decode('iso-8859-1','replace');raw_body=open(body,'rb').read()
        blocks=[b for b in raw_headers.replace('\r\n','\n').split('\n\n') if b.strip().startswith('HTTP/')]
        def parse(block):
            out={}
            for line in block.splitlines()[1:]:
                if ':' in line:
                    k,v=line.split(':',1);out.setdefault(k.lower().strip(),[]).append(v.strip())
            return out
        return int(parts[0]),parts[1],int(parts[2]),parse(blocks[0] if blocks else ''),parse(blocks[-1] if blocks else ''),raw_body

def canonical(raw):
    html=raw.decode('utf-8','ignore')
    for pat in (r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)',r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']'):
        m=re.search(pat,html,re.I|re.S)
        if m:return m.group(1).strip()
    return None

def snippet_state():
    s,_,_,_,_,raw=curl(API,auth=True);d=json.loads(raw.decode()) if s==200 else {}
    code=str(d.get('code') or '');sha=hashlib.sha256(code.encode()).hexdigest()
    return {'status':s,'id':d.get('id'),'active':d.get('active'),'sha256':sha,'marker':MARKER in code,'old_marker':'DoctorCura blog pagination cleanup V8.2' in code}

def row(path,follow=False):
    url=SITE+path;s,f,r,first,last,raw=curl(url,follow=follow)
    return {'path':path,'status':s,'final_url':f,'redirects':r,'location':(first.get('location') or [None])[0],'x_redirect_by':(first.get('x-redirect-by') or [None])[0],'hcdn':(first.get('x-hcdn-cache-status') or [None])[0],'cache_control':(first.get('cache-control') or [None])[0],'canonical':canonical(raw),'x_robots':', '.join(last.get('x-robots-tag',[])) or None}

def main():
    state=snippet_state()
    if state['status']!=200 or int(state['id'] or 0)!=43 or not state['active'] or state['sha256']!=EXPECTED or not state['marker'] or state['old_marker']:
        raise RuntimeError('V10 snippet state mismatch: '+json.dumps(state,separators=(',',':')))
    paths=[]
    for prefix in ('','/fr','/en'):
        paths += [f'{prefix}/blogs/{n}/' for n in (2,5,6,8,16,17)]
        paths += [f'{prefix}/blogs/page/{n}/' for n in (6,16,17)]
    paths += ['/de/blogs/6/','/de/blogs/16/','/de/blogs/17/']
    first=[row(p,False) for p in paths]
    followed=[row(p,True) for p in paths]
    print(json.dumps({'snippet':state,'first_hop':first,'followed':followed},ensure_ascii=False,indent=2))

if __name__=='__main__':main()
