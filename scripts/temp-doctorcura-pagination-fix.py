#!/usr/bin/env python3
import base64
import hashlib
import json
import os
import re
import subprocess
import tempfile

SITE = os.environ.get('SITE_URL', '').rstrip('/')
USER = os.environ.get('REST_USERNAME', '')
PASSWORD = os.environ.get('REST_APP_PASSWORD', '')
SNIPPET_API = f'{SITE}/wp-json/code-snippets/v1/snippets/43'
V8_MARKER = 'DoctorCura blog pagination canonical cleanup V8'


def auth_header():
    return 'Basic ' + base64.b64encode(f'{USER}:{PASSWORD}'.encode()).decode()


def read_snippet():
    fd, path = tempfile.mkstemp(prefix='dc-snippet-', suffix='.json')
    os.close(fd)
    p = subprocess.run([
        'curl','--silent','--show-error','--location','--connect-timeout','10','--max-time','30',
        '--header',f'Authorization: {auth_header()}','--header','Accept: application/json',
        '--output',path,'--write-out','%{http_code}',SNIPPET_API
    ],capture_output=True,text=True,timeout=40)
    status=int(p.stdout.strip()) if p.stdout.strip().isdigit() else 0
    data=json.load(open(path,encoding='utf-8')) if status==200 else {}
    os.unlink(path)
    if p.returncode or status!=200:
        raise RuntimeError(f'Cannot read snippet 43: HTTP {status}')
    code=str(data.get('code') or '')
    return {
        'id':int(data.get('id') or 0),
        'name':str(data.get('name') or data.get('display_name') or ''),
        'active':bool(data.get('active')),
        'v8_marker_present':V8_MARKER in code,
        'code_sha256':hashlib.sha256(code.encode()).hexdigest(),
        'signals':{
            'wpseo_canonical_count':code.count('wpseo_canonical'),
            'template_redirect_count':code.count('template_redirect'),
            'x_robots_count':code.count('X-Robots-Tag'),
            'rocket_clean_domain_count':code.count('rocket_clean_domain'),
            'purge_helper_present':'one-time cache purge helper' in code,
        }
    }


def probe(url):
    td=tempfile.mkdtemp(prefix='dc-layer-')
    hdr=os.path.join(td,'headers.txt'); body=os.path.join(td,'body.html')
    p=subprocess.run([
        'curl','--silent','--show-error','--location','--compressed','--connect-timeout','8','--max-time','25',
        '--user-agent','Mozilla/5.0 (compatible; DoctorCuraPaginationLayerAudit/1.0)',
        '--dump-header',hdr,'--output',body,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}',url
    ],capture_output=True,text=True,timeout=35)
    parts=p.stdout.strip().split('\t'); status=int(parts[0]) if parts and parts[0].isdigit() else 0
    final=parts[1] if len(parts)>1 else ''; redirects=int(parts[2]) if len(parts)>2 and parts[2].isdigit() else 0
    html=open(body,'rb').read().decode('utf-8','ignore') if os.path.exists(body) else ''
    raw=open(hdr,'rb').read().decode('iso-8859-1','replace') if os.path.exists(hdr) else ''
    blocks=[b for b in re.split(r'\r?\n\r?\n',raw) if b.strip().startswith('HTTP/')]
    last=blocks[-1] if blocks else ''
    headers={}
    for line in last.splitlines()[1:]:
        if ':' in line:
            k,v=line.split(':',1); headers.setdefault(k.lower().strip(),[]).append(v.strip())
    def one(pattern):
        m=re.search(pattern,html,re.I|re.S); return m.group(1).strip() if m else None
    canonical=one(r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)') or one(r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']')
    robots=one(r'<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)') or one(r'<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']robots["\']')
    return {
        'url':url,'status':status,'final_url':final,'redirects':redirects,'canonical':canonical,'meta_robots':robots,
        'cache_headers':{k:headers.get(k) for k in ('cache-control','x-cache','x-rocket-cache','cf-cache-status','age','vary') if headers.get(k)},
        'body_sha256':hashlib.sha256(html.encode()).hexdigest(),
        'curl_error':None if p.returncode==0 else p.stderr.strip(),
    }


def main():
    if SITE!='https://doctorcura.com' or not USER or not PASSWORD:
        raise RuntimeError('DoctorCura environment incomplete')
    snippet=read_snippet()
    urls=[
        f'{SITE}/blogs/4/', f'{SITE}/blogs/5/',
        f'{SITE}/fr/blogs/4/', f'{SITE}/fr/blogs/5/',
        f'{SITE}/en/blogs/4/', f'{SITE}/en/blogs/5/',
        f'{SITE}/fr/blogs/6/', f'{SITE}/fr/blogs/page/5/',
        f'{SITE}/fr/blogs/4/?doctorcura_diag=20260909', f'{SITE}/fr/blogs/5/?doctorcura_diag=20260909'
    ]
    result={'mode':'read_only_diagnostic','snippet':snippet,'probes':[probe(u) for u in urls]}
    with open('doctorcura-pagination-fix-verification.json','w',encoding='utf-8') as f:
        json.dump(result,f,ensure_ascii=False,indent=2)
    print(json.dumps({'snippet':snippet,'probe_count':len(urls)},ensure_ascii=False))

if __name__=='__main__':
    main()
