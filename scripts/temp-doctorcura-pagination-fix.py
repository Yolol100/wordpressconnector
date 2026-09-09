#!/usr/bin/env python3
import base64,json,os,re,secrets,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/'); USER=os.environ.get('REST_USERNAME',''); PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'; MARKER='DoctorCura blog pagination cleanup V8.2'
BLOCK=r'''

/* DoctorCura blog pagination cleanup V8.2 - 2026-09-09 */
add_filter( 'wpseo_canonical', function ( $canonical ) {
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) ) return $canonical;
    if ( preg_match( '#^/(?:fr/|en/)?blogs/(4|5)/?$#', $path, $m ) ) {
        $prefix = preg_match( '#^/(fr|en)/#', $path, $lang ) ? '/' . $lang[1] : '';
        return home_url( $prefix . '/blogs/' . (int) $m[1] . '/' );
    }
    return $canonical;
}, 99 );
add_action( 'template_redirect', function () {
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) ) return;
    if ( preg_match( '#^/(?:fr/|en/)?blogs/page/5/?$#', $path ) ) {
        $prefix = preg_match( '#^/(fr|en)/#', $path, $lang ) ? '/' . $lang[1] : '';
        wp_safe_redirect( home_url( $prefix . '/blogs/5/' ), 301, 'DoctorCura SEO pagination cleanup' );
        exit;
    }
}, 0 );
'''
def ah(): return 'Basic '+base64.b64encode(f'{USER}:{PASSWORD}'.encode()).decode()
def curl(url,payload=None,auth=False):
    fd,out=tempfile.mkstemp(); os.close(fd); cmd=['curl','-sS','-L','--connect-timeout','10','--max-time','45','-o',out,'-w','%{http_code}\t%{url_effective}\t%{num_redirects}']
    if auth: cmd += ['-H',f'Authorization: {ah()}']
    if payload: cmd += ['-X','POST','-H','X-HTTP-Method-Override: PUT','-H','Content-Type: application/json','--data-binary','@'+payload]
    p=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=55); parts=p.stdout.strip().split('\t'); raw=open(out,'rb').read(); os.unlink(out)
    if p.returncode: raise RuntimeError(p.stderr.strip())
    return int(parts[0]),parts[1],int(parts[2]),raw
def get():
    s,_,_,r=curl(API,auth=True); d=json.loads(r.decode()) if s==200 else {}
    if s!=200 or int(d.get('id') or 0)!=43 or not d.get('active') or 'doctorcura seo indexation cleanup' not in str(d.get('name') or d.get('display_name') or '').lower(): raise RuntimeError('snippet readback failed')
    return d
def put(code):
    fd,p=tempfile.mkstemp(suffix='.json'); os.close(fd); open(p,'w').write(json.dumps({'code':code},separators=(',',':')))
    try: s,_,_,r=curl(API,p,True)
    finally: os.unlink(p)
    if s!=200: raise RuntimeError(f'update HTTP {s}')
    return json.loads(r.decode())
def helper(t): return "\n/* DoctorCura one-time cache purge helper - removed automatically */\nadd_action('init',function(){if(!isset($_GET['doctorcura_seo_purge'])||!hash_equals('"+t+"',(string)$_GET['doctorcura_seo_purge']))return;if(function_exists('rocket_clean_domain'))rocket_clean_domain();if(function_exists('wp_cache_flush'))wp_cache_flush();},1);\n"
def purge(t):
    s,_,_,_=curl(f'{SITE}/?doctorcura_seo_purge={t}');
    if s!=200: raise RuntimeError(f'purge HTTP {s}')
def canon(h):
    for p in [r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)',r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']']:
        m=re.search(p,h,re.I|re.S)
        if m:return m.group(1)
def probe(u):
    s,f,n,r=curl(u); return {'url':u,'status':s,'final_url':f,'redirects':n,'canonical':canon(r.decode('utf-8','ignore'))}
def verify():
    urls=[f'{SITE}/blogs/4/',f'{SITE}/blogs/5/',f'{SITE}/fr/blogs/4/',f'{SITE}/fr/blogs/5/',f'{SITE}/en/blogs/4/',f'{SITE}/en/blogs/5/',f'{SITE}/fr/blogs/page/5/']
    rows=[probe(u) for u in urls]
    for row in rows[:6]:
        if row['status']!=200 or row['final_url']!=row['url'] or row['canonical']!=row['url']: raise RuntimeError('canonical failed '+json.dumps(row,separators=(',',':')))
    if rows[6]['status']!=200 or rows[6]['final_url']!=f'{SITE}/fr/blogs/5/' or rows[6]['redirects']<1: raise RuntimeError('alias failed '+json.dumps(rows[6],separators=(',',':')))
    return rows
def restore(original):
    t=secrets.token_urlsafe(24)
    try: put(original.rstrip()+helper(t)); purge(t)
    finally: put(original)
def main():
    if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('environment mismatch')
    d=get(); original=str(d.get('code') or ''); final=original if MARKER in original else original.rstrip()+BLOCK+'\n'; applied=False
    try:
        t=secrets.token_urlsafe(24); put(final.rstrip()+helper(t)); applied=True; purge(t); put(final)
        rb=get(); code=str(rb.get('code') or '')
        if MARKER not in code or 'one-time cache purge helper' in code: raise RuntimeError('readback marker failed')
        rows=verify(); json.dump({'snippet_id':43,'active':True,'marker':MARKER,'verification':rows,'known_blocker':'/fr/blogs/6/ still requires pre-GTranslate server 404 rule'},open('doctorcura-pagination-fix-verification.json','w'),indent=2)
    except Exception:
        if applied: restore(original)
        raise
if __name__=='__main__': main()
