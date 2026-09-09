#!/usr/bin/env python3
import hashlib,json,os,re,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/');USER=os.environ.get('REST_USERNAME','');PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43';EXPECTED='83a05b5734751d01d93488fcfbcd601ce2e245a7f41e6d2675beaf38af1dec7b';MARKER='DoctorCura dynamic blog pagination cleanup V10'
if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')

def curl(url,auth=False,revalidate=False):
    with tempfile.TemporaryDirectory(prefix='dc-edge-revalidate-') as td:
        hdr=os.path.join(td,'headers.txt');body=os.path.join(td,'body.bin')
        cmd=['curl','--silent','--show-error','--compressed','--connect-timeout','10','--max-time','45','--user-agent','Mozilla/5.0 (compatible; DoctorCuraEdgeRevalidateQA/1.0)','--dump-header',hdr,'--output',body,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
        if auth: cmd += ['--user',f'{USER}:{PASSWORD}']
        if revalidate: cmd += ['--header','Cache-Control: no-cache','--header','Pragma: no-cache']
        r=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=55)
        if r.returncode: raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        parts=r.stdout.strip().split('\t');raw_headers=open(hdr,'rb').read().decode('iso-8859-1','replace');raw_body=open(body,'rb').read()
        blocks=[b for b in raw_headers.replace('\r\n','\n').split('\n\n') if b.strip().startswith('HTTP/')];first=blocks[0] if blocks else '';headers={}
        for line in first.splitlines()[1:]:
            if ':' in line:
                k,v=line.split(':',1);headers.setdefault(k.lower().strip(),[]).append(v.strip())
        return int(parts[0]),parts[1],int(parts[2]),headers,raw_body

def canonical(raw):
    html=raw.decode('utf-8','ignore')
    for pat in (r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)',r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']'):
        m=re.search(pat,html,re.I|re.S)
        if m:return m.group(1).strip()
    return None

def state():
    s,_,_,_,raw=curl(API,auth=True);d=json.loads(raw.decode()) if s==200 else {};code=str(d.get('code') or '')
    return {'status':s,'id':d.get('id'),'active':d.get('active'),'sha256':hashlib.sha256(code.encode()).hexdigest(),'v10':MARKER in code,'v82':'DoctorCura blog pagination cleanup V8.2' in code}

def row(path,revalidate=False):
    url=SITE+path;s,f,r,h,raw=curl(url,revalidate=revalidate)
    return {'path':path,'revalidate':revalidate,'status':s,'final_url':f,'redirects':r,'location':(h.get('location') or [None])[0],'x_redirect_by':(h.get('x-redirect-by') or [None])[0],'hcdn':(h.get('x-hcdn-cache-status') or [None])[0],'age':(h.get('age') or [None])[0],'cache_control':(h.get('cache-control') or [None])[0],'canonical':canonical(raw)}

def good(r):
    return r['status']==200 and r['location'] is None and r['canonical']==SITE+r['path']

def main():
    st=state()
    if st['status']!=200 or int(st['id'] or 0)!=43 or not st['active'] or st['sha256']!=EXPECTED or not st['v10'] or st['v82']: raise RuntimeError('V10 state mismatch '+json.dumps(st,separators=(',',':')))
    paths=['/blogs/8/','/fr/blogs/6/','/fr/blogs/8/','/en/blogs/6/','/en/blogs/8/']
    rounds=[]
    for n in range(1,4):
        before=[row(p,False) for p in paths]
        revalidated=[row(p,True) for p in paths]
        after=[row(p,False) for p in paths]
        rounds.append({'round':n,'before':before,'revalidate':revalidated,'after':after,'remaining_after':[r['path'] for r in after if not good(r)]})
        if not rounds[-1]['remaining_after']: break
    print(json.dumps({'snippet':st,'rounds':rounds,'final_remaining':rounds[-1]['remaining_after']},ensure_ascii=False,indent=2))
if __name__=='__main__':main()
