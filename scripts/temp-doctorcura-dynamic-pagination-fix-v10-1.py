#!/usr/bin/env python3
import hashlib
import importlib.util
import json
import os
import re
import secrets
import subprocess
import tempfile
from urllib.parse import urlsplit, urlunsplit, parse_qsl, urlencode

SITE=os.environ.get('SITE_URL','').rstrip('/')
USER=os.environ.get('REST_USERNAME','')
PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'
EXPECTED='6a7c7520a8030055756bc4dc02e1562c6b75917ffef4d52303f27be247931cf3'
OUT='doctorcura-dynamic-pagination-v10-verification.json'
HELPER_MARKER='DoctorCura one-time full pagination cache purge V10.1'
if SITE!='https://doctorcura.com' or not USER or not PASSWORD:
    raise RuntimeError('DoctorCura environment mismatch')

spec=importlib.util.spec_from_file_location('dcv10','scripts/temp-doctorcura-dynamic-pagination-fix-v9.py')
dcv10=importlib.util.module_from_spec(spec); spec.loader.exec_module(dcv10)

def curl(url,payload=None,auth=False,follow=False,headers_extra=None):
    with tempfile.TemporaryDirectory(prefix='dc-v101-') as td:
        hdr=os.path.join(td,'headers.txt'); body=os.path.join(td,'body.bin')
        cmd=['curl','--silent','--show-error','--compressed','--connect-timeout','10','--max-time','45',
             '--user-agent','Mozilla/5.0 (compatible; DoctorCuraPaginationV10.1/1.0)',
             '--dump-header',hdr,'--output',body,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
        if follow: cmd.append('--location')
        if auth: cmd += ['--user',f'{USER}:{PASSWORD}']
        for header in headers_extra or []: cmd += ['--header',header]
        if payload is not None:
            p=os.path.join(td,'payload.json')
            with open(p,'w',encoding='utf-8') as h: json.dump(payload,h,separators=(',',':'))
            cmd += ['--request','POST','--header','X-HTTP-Method-Override: PUT','--header','Content-Type: application/json','--data-binary','@'+p]
        r=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=55)
        if r.returncode: raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        parts=r.stdout.strip().split('\t'); raw_headers=open(hdr,'rb').read().decode('iso-8859-1','replace'); raw_body=open(body,'rb').read()
        blocks=[b for b in raw_headers.replace('\r\n','\n').split('\n\n') if b.strip().startswith('HTTP/')]
        def parse(block):
            out={}
            for line in block.splitlines()[1:]:
                if ':' in line:
                    k,v=line.split(':',1); out.setdefault(k.lower().strip(),[]).append(v.strip())
            return out
        return int(parts[0]),parts[1],int(parts[2]),parse(blocks[0] if blocks else ''),parse(blocks[-1] if blocks else ''),raw_body

def get_snippet():
    s,_,_,_,_,raw=curl(API,auth=True); d=json.loads(raw.decode()) if s==200 else {}
    name=str(d.get('name') or d.get('display_name') or '')
    if s!=200 or int(d.get('id') or 0)!=43 or not d.get('active') or 'doctorcura seo indexation cleanup' not in name.lower():
        raise RuntimeError('Snippet 43 readback mismatch')
    return d

def put_code(code):
    s,_,_,_,_,raw=curl(API,payload={'code':code},auth=True)
    if s!=200: raise RuntimeError(f'Snippet update HTTP {s}')
    return json.loads(raw.decode())

def add_verify_query(url,token):
    p=urlsplit(url); q=parse_qsl(p.query,keep_blank_values=True); q.append(('doctorcura_v101_verify',token))
    return urlunsplit((p.scheme,p.netloc,p.path,urlencode(q),p.fragment))

def one(pattern,html):
    m=re.search(pattern,html,re.I|re.S); return m.group(1).strip() if m else None

def content_info(raw):
    html=raw.decode('utf-8','ignore')
    canonical=one(r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)',html) or one(r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']',html)
    robots=one(r'<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)',html)
    articles=re.findall(r'<article\b[^>]*class=["\'][^"\']*elementor-post[^"\']*["\'][^>]*>.*?</article>',html,re.I|re.S)
    links=[]
    for article in articles:
        m=re.search(r'<a\b[^>]*href=["\']([^"\']+)["\']',article,re.I|re.S)
        if m: links.append(m.group(1))
    return {'canonical':canonical,'meta_robots':robots,'article_count':len(articles),'article_links':links}

def request_row(url,verify_token=None,follow=False):
    request_url=add_verify_query(url,verify_token) if verify_token else url
    headers=[]
    if verify_token: headers=['Cache-Control: no-cache, no-store, max-age=0','Pragma: no-cache']
    s,f,r,first,last,raw=curl(request_url,follow=follow,headers_extra=headers)
    info=content_info(raw)
    return {'url':url,'request_url':request_url,'status':s,'final_url':f,'redirects':r,
            'location':(first.get('location') or [None])[0],'x_redirect_by':(first.get('x-redirect-by') or [None])[0],
            'hcdn':(first.get('x-hcdn-cache-status') or [None])[0],'x_litespeed_cache':(first.get('x-litespeed-cache') or [None])[0],
            'x_robots':', '.join(last.get('x-robots-tag',[])) or None,**info}

def discover_max_page():
    token=secrets.token_urlsafe(18); last=5
    for page in range(6,41):
        row=request_row(f'{SITE}/blogs/page/{page}/',token)
        if row['status']==200 and row['article_count']>0: last=page; continue
        if row['status']==200 and row['article_count']==0: return last
        raise RuntimeError('Native pagination discovery failed: '+json.dumps(row,separators=(',',':')))
    raise RuntimeError('Pagination discovery exceeded safety bound 40')

def baseline(max_page):
    token=secrets.token_urlsafe(18); pages={}
    for prefix in ('','/fr','/en'):
        for page in range(2,max_page+1):
            url=f'{SITE}{prefix}/blogs/{page}/' if page<=5 else f'{SITE}{prefix}/blogs/page/{page}/'
            row=request_row(url,token)
            if row['status']!=200 or row['article_count']<1:
                raise RuntimeError('Baseline content page failed: '+json.dumps(row,separators=(',',':')))
            pages[f'{prefix}:{page}']={'article_count':row['article_count'],'article_links':row['article_links']}
        empty=request_row(f'{SITE}{prefix}/blogs/page/{max_page+1}/',token)
        if empty['status']!=200 or empty['article_count']!=0:
            raise RuntimeError('Expected first native empty page before fix: '+json.dumps(empty,separators=(',',':')))
    return pages

def cache_helper(token):
    return ("\n/* "+HELPER_MARKER+" - removed automatically */\n"
            "add_action('template_redirect',function(){"
            "if(!isset($_GET['doctorcura_pagination_refresh'])||!hash_equals('"+token+"',(string)$_GET['doctorcura_pagination_refresh']))return;"
            "if(function_exists('rocket_clean_domain'))rocket_clean_domain();"
            "if(function_exists('wp_cache_flush'))wp_cache_flush();"
            "header('X-LiteSpeed-Purge: *');nocache_headers();status_header(204);exit;},-9999);\n")

def purge_available_layers(code):
    token=secrets.token_urlsafe(24); put_code(code.rstrip()+cache_helper(token))
    s,_,r,first,_,_=curl(f'{SITE}/?doctorcura_pagination_refresh={token}')
    if s!=204 or r!=0: raise RuntimeError(f'Cache purge helper failed HTTP {s}, redirects {r}')
    put_code(code)
    return {'status':s,'hcdn':(first.get('x-hcdn-cache-status') or [None])[0],'litespeed_purge_sent':True,'wp_rocket_domain_purge':True,'object_cache_flush':True}

def verify_origin_round(pages,max_page,name):
    token=secrets.token_urlsafe(18); rows=[]
    for prefix in ('','/fr','/en'):
        for page in range(2,max_page+1):
            url=f'{SITE}{prefix}/blogs/{page}/'; row=request_row(url,token); rows.append(row)
            if row['status']!=200 or row['location'] is not None or row['canonical']!=url:
                raise RuntimeError(name+' clean origin page failed: '+json.dumps(row,separators=(',',':')))
            robots=((row.get('meta_robots') or '')+' '+(row.get('x_robots') or '')).lower()
            if 'noindex' in robots: raise RuntimeError(name+' clean origin page noindexed: '+json.dumps(row,separators=(',',':')))
            expected=pages[f'{prefix}:{page}']
            if row['article_count']!=expected['article_count'] or (expected['article_links'] and row['article_links']!=expected['article_links']):
                raise RuntimeError(name+' clean origin content mismatch: '+json.dumps({'expected':expected,'actual':row},separators=(',',':')))
        for page in sorted(set((2,5,6,max_page))):
            if page>max_page: continue
            alias=f'{SITE}{prefix}/blogs/page/{page}/'; target=f'{SITE}{prefix}/blogs/{page}/'; row=request_row(alias,token); rows.append(row)
            if row['status'] not in (301,302) or row['location']!=target:
                raise RuntimeError(name+' alias origin redirect failed: '+json.dumps(row,separators=(',',':')))
        for page in (max_page+1,max_page+2):
            for path in (f'{prefix}/blogs/{page}/',f'{prefix}/blogs/page/{page}/'):
                url=SITE+path; row=request_row(url,token); rows.append(row)
                if row['status']!=404 or row['location'] is not None:
                    raise RuntimeError(name+' invalid origin page failed: '+json.dumps(row,separators=(',',':')))
    return rows

def edge_snapshot(max_page):
    rows=[]
    samples=sorted(set(p for p in (2,5,6,7,8,9,max_page) if p<=max_page))
    for prefix in ('','/fr','/en'):
        for page in samples:
            url=f'{SITE}{prefix}/blogs/{page}/'; rows.append(request_row(url))
        rows.append(request_row(f'{SITE}{prefix}/blogs/{max_page+1}/'))
    pending=[]
    for row in rows:
        path=urlsplit(row['url']).path
        m=re.search(r'/blogs/(\d+)/$',path); page=int(m.group(1)) if m else 0
        valid=page<=max_page
        if valid:
            if row['status']!=200 or row['location'] is not None or row['canonical']!=row['url']: pending.append(row)
        elif row['status']!=404 or row['location'] is not None: pending.append(row)
    return rows,pending

def restore(original):
    put_code(original); purge_available_layers(original)
    readback=str(get_snippet().get('code') or '')
    if hashlib.sha256(readback.encode()).hexdigest()!=hashlib.sha256(original.encode()).hexdigest():
        raise RuntimeError('Rollback snippet hash mismatch')

def main():
    snippet=get_snippet(); original=str(snippet.get('code') or ''); before=hashlib.sha256(original.encode()).hexdigest()
    if before!=EXPECTED: raise RuntimeError(f'Stale snippet preflight: expected {EXPECTED}, got {before}')
    if original.count(dcv10.OLD_BLOCK)!=1 or original.count(dcv10.OLD_MARKER)!=1: raise RuntimeError('Expected V8.2 block not found exactly once')
    if dcv10.NEW_MARKER in original or HELPER_MARKER in original: raise RuntimeError('Unexpected V10/helper residue')
    max_page=discover_max_page(); pages=baseline(max_page)
    final=original.replace(dcv10.OLD_BLOCK,dcv10.NEW_BLOCK,1)
    if dcv10.OLD_MARKER in final or '(4|5)' in final or 'blogs/page/5' in final: raise RuntimeError('Hardcoded pagination residue remains')
    applied=False
    try:
        put_code(final); applied=True
        readback=str(get_snippet().get('code') or '')
        final_sha=hashlib.sha256(final.encode()).hexdigest()
        if hashlib.sha256(readback.encode()).hexdigest()!=final_sha or dcv10.NEW_MARKER not in readback: raise RuntimeError('V10 readback failed')
        purge=purge_available_layers(final)
        readback=str(get_snippet().get('code') or '')
        if hashlib.sha256(readback.encode()).hexdigest()!=final_sha or HELPER_MARKER in readback: raise RuntimeError('V10 post-purge readback failed')
        round1=verify_origin_round(pages,max_page,'round_1')
        round2=verify_origin_round(pages,max_page,'round_2')
        edge,edge_pending=edge_snapshot(max_page)
        result={'ok':True,'snippet_id':43,'marker':dcv10.NEW_MARKER,'before_sha256':before,'after_sha256':final_sha,
                'dynamic_current_max_page':max_page,'languages_origin_verified':['de-root','fr','en'],
                'origin_round_1_count':len(round1),'origin_round_2_count':len(round2),
                'cache_purge':purge,'edge_pending_count':len(edge_pending),'edge_pending':edge_pending,
                'edge_snapshot':edge,'origin_round_1':round1,'origin_round_2':round2}
        with open(OUT,'w',encoding='utf-8') as h: json.dump(result,h,ensure_ascii=False,indent=2)
        print(json.dumps({'ok':True,'marker':dcv10.NEW_MARKER,'dynamic_current_max_page':max_page,'origin_rounds_green':2,'edge_pending_count':len(edge_pending),'after_sha256':final_sha},separators=(',',':')))
    except Exception:
        if applied: restore(original)
        raise

if __name__=='__main__': main()
