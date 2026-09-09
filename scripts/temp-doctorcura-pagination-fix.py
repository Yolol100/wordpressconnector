#!/usr/bin/env python3
import base64, json, os, re, secrets, subprocess, tempfile

SITE=os.environ.get('SITE_URL','').rstrip('/')
USER=os.environ.get('REST_USERNAME','')
PASSWORD=os.environ.get('REST_APP_PASSWORD','')
API=f'{SITE}/wp-json/code-snippets/v1/snippets/43'
MARKER='DoctorCura blog pagination origin cleanup V8.1'

BLOCK=r'''

/* DoctorCura blog pagination origin cleanup V8.1 - 2026-09-09 */
add_filter( 'wpseo_canonical', function ( $canonical ) {
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) ) {
        return $canonical;
    }
    if ( preg_match( '#^/(?:fr/|en/)?blogs/(4|5)/?$#', $path, $m ) ) {
        $prefix = preg_match( '#^/(fr|en)/#', $path, $lang ) ? '/' . $lang[1] : '';
        return home_url( $prefix . '/blogs/' . (int) $m[1] . '/' );
    }
    return $canonical;
}, 99 );

add_action( 'template_redirect', function () {
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) ) {
        return;
    }
    if ( preg_match( '#^/(?:fr/|en/)?blogs/page/5/?$#', $path ) ) {
        $prefix = preg_match( '#^/(fr|en)/#', $path, $lang ) ? '/' . $lang[1] : '';
        wp_safe_redirect( home_url( $prefix . '/blogs/5/' ), 301, 'DoctorCura SEO pagination cleanup' );
        exit;
    }
    if ( preg_match( '#^/(?:fr/|en/)?blogs/6/?$#', $path ) ) {
        global $wp_query;
        if ( $wp_query instanceof WP_Query ) {
            $wp_query->set_404();
        }
        status_header( 404 );
        nocache_headers();
        $template = get_404_template();
        if ( $template ) {
            include $template;
        } else {
            echo '404 Not Found';
        }
        exit;
    }
}, 0 );
'''

def auth():
    return 'Basic '+base64.b64encode(f'{USER}:{PASSWORD}'.encode()).decode()

def request(method,url,payload=None,auth_required=False,follow=True):
    fd,path=tempfile.mkstemp(prefix='dc-',suffix='.out'); os.close(fd)
    cmd=['curl','--silent','--show-error','--connect-timeout','10','--max-time','45','--output',path,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
    if follow: cmd.append('--location')
    if auth_required: cmd += ['--header',f'Authorization: {auth()}']
    if method=='POST': cmd += ['--request','POST','--header','X-HTTP-Method-Override: PUT','--header','Content-Type: application/json','--data-binary','@'+payload]
    p=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=55)
    parts=p.stdout.strip().split('\t'); status=int(parts[0]) if parts and parts[0].isdigit() else 0
    final=parts[1] if len(parts)>1 else ''; redirects=int(parts[2]) if len(parts)>2 and parts[2].isdigit() else 0
    raw=open(path,'rb').read(); os.unlink(path)
    if p.returncode: raise RuntimeError(p.stderr.strip() or 'curl failed')
    return status,final,redirects,raw

def get_snippet():
    status,_,_,raw=request('GET',API,auth_required=True)
    if status!=200: raise RuntimeError(f'Cannot read snippet: HTTP {status}')
    d=json.loads(raw.decode('utf-8'))
    if int(d.get('id') or 0)!=43 or 'doctorcura seo indexation cleanup' not in str(d.get('name') or d.get('display_name') or '').lower() or not d.get('active'):
        raise RuntimeError('Snippet 43 identity/state validation failed')
    return d

def update_code(code):
    fd,path=tempfile.mkstemp(prefix='dc-update-',suffix='.json'); os.close(fd)
    with open(path,'w',encoding='utf-8') as f: json.dump({'code':code},f,separators=(',',':'))
    try: status,_,_,raw=request('POST',API,payload=path,auth_required=True)
    finally: os.unlink(path)
    if status!=200: raise RuntimeError(f'Snippet update failed: HTTP {status}')
    return json.loads(raw.decode('utf-8'))

def purge_helper(token):
    return r'''

/* DoctorCura one-time cache purge helper - removed automatically */
add_action( 'init', function () {
    $expected='__TOKEN__';
    $actual=isset($_GET['doctorcura_seo_purge']) ? (string) $_GET['doctorcura_seo_purge'] : '';
    if ( ! hash_equals($expected,$actual) ) return;
    if ( function_exists('rocket_clean_domain') ) rocket_clean_domain();
    if ( function_exists('wp_cache_flush') ) wp_cache_flush();
    header('X-DoctorCura-SEO-Purge: done');
}, 1 );
'''.replace('__TOKEN__',token)

def purge(token):
    status,_,_,_=request('GET',f'{SITE}/?doctorcura_seo_purge={token}')
    if status!=200: raise RuntimeError(f'Cache purge trigger failed: HTTP {status}')

def canonical(html):
    for pat in [r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)',r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']']:
        m=re.search(pat,html,re.I|re.S)
        if m:return m.group(1).strip()
    return None

def probe(url,follow=True):
    status,final,redirects,raw=request('GET',url,follow=follow)
    html=raw.decode('utf-8','ignore')
    return {'url':url,'status':status,'final_url':final,'redirects':redirects,'canonical':canonical(html)}

def verify():
    urls=[
      f'{SITE}/blogs/4/',f'{SITE}/blogs/5/',
      f'{SITE}/fr/blogs/4/',f'{SITE}/fr/blogs/5/',
      f'{SITE}/en/blogs/4/',f'{SITE}/en/blogs/5/',
      f'{SITE}/fr/blogs/page/5/',f'{SITE}/fr/blogs/6/'
    ]
    rows=[probe(u) for u in urls]
    expected=[
      (200,f'{SITE}/blogs/4/',f'{SITE}/blogs/4/'),(200,f'{SITE}/blogs/5/',f'{SITE}/blogs/5/'),
      (200,f'{SITE}/fr/blogs/4/',f'{SITE}/fr/blogs/4/'),(200,f'{SITE}/fr/blogs/5/',f'{SITE}/fr/blogs/5/'),
      (200,f'{SITE}/en/blogs/4/',f'{SITE}/en/blogs/4/'),(200,f'{SITE}/en/blogs/5/',f'{SITE}/en/blogs/5/')]
    for row,(status,final,canon) in zip(rows[:6],expected):
        if row['status']!=status or row['final_url']!=final or row['canonical']!=canon:
            raise RuntimeError('Canonical verification failed: '+json.dumps(row,separators=(',',':')))
    alias=rows[6]
    if alias['status']!=200 or alias['final_url']!=f'{SITE}/fr/blogs/5/' or alias['redirects']<1:
        raise RuntimeError('Duplicate alias redirect verification failed: '+json.dumps(alias,separators=(',',':')))
    p6=rows[7]
    if p6['status']!=404 or p6['final_url']!=f'{SITE}/fr/blogs/6/':
        raise RuntimeError('Out-of-range 404 verification failed: '+json.dumps(p6,separators=(',',':')))
    return rows

def restore(original):
    token=secrets.token_urlsafe(24)
    try:
        update_code(original.rstrip()+purge_helper(token)+'\n'); purge(token)
    finally:
        update_code(original)

def main():
    if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment incomplete')
    d=get_snippet(); original=str(d.get('code') or '')
    final=original if MARKER in original else original.rstrip()+BLOCK+'\n'
    applied=False
    try:
        token=secrets.token_urlsafe(24)
        update_code(final.rstrip()+purge_helper(token)+'\n'); applied=True; purge(token); update_code(final)
        rb=get_snippet(); code=str(rb.get('code') or '')
        if MARKER not in code or 'one-time cache purge helper' in code: raise RuntimeError('Snippet readback failed')
        rows=verify()
        with open('doctorcura-pagination-fix-verification.json','w',encoding='utf-8') as f: json.dump({'snippet_id':43,'active':True,'marker':MARKER,'verification':rows},f,indent=2)
    except Exception:
        if applied: restore(original)
        raise

if __name__=='__main__': main()
