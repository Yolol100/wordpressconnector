#!/usr/bin/env python3
import hashlib
import json
import os
import re
import secrets
import subprocess
import tempfile

SITE = os.environ.get('SITE_URL', '').rstrip('/')
USER = os.environ.get('REST_USERNAME', '')
PASSWORD = os.environ.get('REST_APP_PASSWORD', '')
API = f'{SITE}/wp-json/code-snippets/v1/snippets/43'
EXPECTED_SHA256 = '6a7c7520a8030055756bc4dc02e1562c6b75917ffef4d52303f27be247931cf3'
OLD_MARKER = 'DoctorCura blog pagination cleanup V8.2'
NEW_MARKER = 'DoctorCura dynamic blog pagination cleanup V10'
HELPER_MARKER = 'DoctorCura one-time targeted pagination cache helper'
OUT = 'doctorcura-dynamic-pagination-v10-verification.json'

OLD_BLOCK = r'''/* DoctorCura blog pagination cleanup V8.2 - 2026-09-09 */
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
}, 0 );'''

NEW_BLOCK = r'''/* DoctorCura dynamic blog pagination cleanup V10 - 2026-09-10 */
function doctorcura_seo_blog_pagination_state_v10() {
    static $state = null;
    static $busy = false;
    if ( null !== $state ) return $state;
    if ( $busy ) return array( 'ready' => false, 'max' => 0, 'posts_per_page' => 0, 'found_posts' => 0 );
    $busy = true;
    $state = array( 'ready' => false, 'max' => 0, 'posts_per_page' => 0, 'found_posts' => 0 );
    try {
        $raw = get_post_meta( 5951, '_elementor_data', true );
        $tree = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        $widget_id = '6236f6e';
        $target = null;
        $find = function ( $nodes ) use ( &$find, &$target, $widget_id ) {
            if ( ! is_array( $nodes ) || $target ) return;
            foreach ( $nodes as $node ) {
                if ( ! is_array( $node ) ) continue;
                if ( isset( $node['id'] ) && $widget_id === (string) $node['id'] ) { $target = $node; return; }
                if ( isset( $node['elements'] ) ) $find( $node['elements'] );
            }
        };
        $find( $tree );
        if ( ! is_array( $target ) || ! class_exists( '\\Elementor\\Plugin' ) ) return $state;
        $instance = \Elementor\Plugin::$instance->elements_manager->create_element_instance( $target );
        if ( ! $instance || ! method_exists( $instance, 'get_current_skin' ) ) return $state;
        $skin = $instance->get_current_skin();
        if ( ! $skin || ! method_exists( $skin, 'get_instance_value' ) ) return $state;
        $posts_per_page = absint( $skin->get_instance_value( 'posts_per_page' ) );
        if ( $posts_per_page < 1 ) return $state;
        $settings = isset( $target['settings'] ) && is_array( $target['settings'] ) ? $target['settings'] : array();
        $term_ids = isset( $settings['posts_include_term_ids'] ) ? (array) $settings['posts_include_term_ids'] : array();
        $term_ids = array_values( array_filter( array_map( 'absint', $term_ids ) ) );
        $author_ids = isset( $settings['posts_include_author_ids'] ) ? (array) $settings['posts_include_author_ids'] : array();
        $author_ids = array_values( array_filter( array_map( 'absint', $author_ids ) ) );
        $args = array(
            'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1,
            'fields' => 'ids', 'no_found_rows' => false, 'ignore_sticky_posts' => true,
        );
        if ( $term_ids ) {
            $tax_query = array( 'relation' => 'OR' );
            foreach ( $term_ids as $term_id ) {
                $term = get_term( $term_id );
                if ( $term && ! is_wp_error( $term ) ) {
                    $tax_query[] = array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => array( $term_id ), 'include_children' => true );
                }
            }
            if ( count( $tax_query ) > 1 ) $args['tax_query'] = $tax_query;
        }
        if ( $author_ids ) $args['author__in'] = $author_ids;
        $count_query = new WP_Query( $args );
        $found_posts = (int) $count_query->found_posts;
        wp_reset_postdata();
        $max = $found_posts > 0 ? (int) ceil( $found_posts / $posts_per_page ) : 0;
        $state = array( 'ready' => true, 'max' => $max, 'posts_per_page' => $posts_per_page, 'found_posts' => $found_posts );
    } catch ( \Throwable $error ) {
        $state = array( 'ready' => false, 'max' => 0, 'posts_per_page' => 0, 'found_posts' => 0 );
    } finally { $busy = false; }
    return $state;
}
add_filter( 'request', function ( $query_vars ) {
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) || ! preg_match( '#^/(?:fr/|en/)?blogs/([2-9]|[1-9][0-9]+)/?$#', $path, $match ) ) return $query_vars;
    if ( ! isset( $query_vars['pagename'] ) || 'blogs' !== trim( (string) $query_vars['pagename'], '/' ) ) return $query_vars;
    $state = doctorcura_seo_blog_pagination_state_v10(); $page = (int) $match[1];
    if ( ! empty( $state['ready'] ) && $page <= (int) $state['max'] ) { unset( $query_vars['page'] ); $query_vars['paged'] = $page; }
    return $query_vars;
}, 1 );
add_action( 'wp', function () {
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) || ! preg_match( '#^/(?:fr/|en/)?blogs/(?:page/)?([2-9]|[1-9][0-9]+)/?$#', $path, $match ) ) return;
    $state = doctorcura_seo_blog_pagination_state_v10();
    if ( empty( $state['ready'] ) || (int) $match[1] <= (int) $state['max'] ) return;
    global $wp_query; if ( $wp_query instanceof WP_Query ) $wp_query->set_404(); status_header( 404 ); nocache_headers();
}, 1 );
add_filter( 'redirect_canonical', function ( $redirect_url, $requested_url ) {
    $path = wp_parse_url( (string) $requested_url, PHP_URL_PATH );
    if ( ! is_string( $path ) || ! preg_match( '#^/(?:fr/|en/)?blogs/(?:page/)?([2-9]|[1-9][0-9]+)/?$#', $path ) ) return $redirect_url;
    $state = doctorcura_seo_blog_pagination_state_v10(); return ! empty( $state['ready'] ) ? false : $redirect_url;
}, 10, 2 );
add_filter( 'wpseo_canonical', function ( $canonical ) {
    if ( is_404() ) return $canonical;
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) || ! preg_match( '#^/(?:fr/|en/)?blogs/([2-9]|[1-9][0-9]+)/?$#', $path, $match ) ) return $canonical;
    $state = doctorcura_seo_blog_pagination_state_v10(); $page = (int) $match[1];
    if ( empty( $state['ready'] ) || $page > (int) $state['max'] ) return $canonical;
    $prefix = preg_match( '#^/(fr|en)/#', $path, $lang ) ? '/' . $lang[1] : '';
    return home_url( $prefix . '/blogs/' . $page . '/' );
}, 99 );
add_action( 'template_redirect', function () {
    if ( is_404() ) return;
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) || ! preg_match( '#^/(?:fr/|en/)?blogs/page/([2-9]|[1-9][0-9]+)/?$#', $path, $match ) ) return;
    $state = doctorcura_seo_blog_pagination_state_v10(); $page = (int) $match[1];
    if ( empty( $state['ready'] ) || $page > (int) $state['max'] ) return;
    $prefix = preg_match( '#^/(fr|en)/#', $path, $lang ) ? '/' . $lang[1] : '';
    wp_safe_redirect( home_url( $prefix . '/blogs/' . $page . '/' ), 301, 'DoctorCura SEO dynamic pagination cleanup' ); exit;
}, 0 );'''

def curl(url, payload_path=None, auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-v10-') as td:
        hdr=os.path.join(td,'headers.txt'); body=os.path.join(td,'body.bin')
        cmd=['curl','--silent','--show-error','--location','--compressed','--connect-timeout','10','--max-time','45','--user-agent','Mozilla/5.0 (compatible; DoctorCuraDynamicPaginationFix/2.0)','--dump-header',hdr,'--output',body,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}']
        if auth: cmd+=['--user',f'{USER}:{PASSWORD}']
        if payload_path: cmd+=['--request','POST','--header','X-HTTP-Method-Override: PUT','--header','Content-Type: application/json','--data-binary','@'+payload_path]
        run=subprocess.run(cmd+[url],capture_output=True,text=True,timeout=55)
        if run.returncode: raise RuntimeError(run.stderr.strip() or f'curl exit {run.returncode}')
        parts=run.stdout.strip().split('\t'); raw_headers=open(hdr,'rb').read().decode('iso-8859-1','replace'); raw_body=open(body,'rb').read()
        blocks=[b for b in re.split(r'\r?\n\r?\n',raw_headers) if b.strip().startswith('HTTP/')]; last=blocks[-1] if blocks else ''; headers={}
        for line in last.splitlines()[1:]:
            if ':' in line:
                key,value=line.split(':',1); headers.setdefault(key.lower().strip(),[]).append(value.strip())
        return int(parts[0]),parts[1],int(parts[2]),headers,raw_body

def get_snippet():
    status,_,_,_,raw=curl(API,auth=True); data=json.loads(raw.decode('utf-8')) if status==200 else {}; name=str(data.get('name') or data.get('display_name') or '')
    if status!=200 or int(data.get('id') or 0)!=43 or not data.get('active') or 'doctorcura seo indexation cleanup' not in name.lower(): raise RuntimeError('Snippet 43 readback mismatch')
    return data

def put_code(code):
    fd,path=tempfile.mkstemp(suffix='.json'); os.close(fd)
    with open(path,'w',encoding='utf-8') as handle: json.dump({'code':code},handle,separators=(',',':'))
    try: status,_,_,_,raw=curl(API,payload_path=path,auth=True)
    finally: os.unlink(path)
    if status!=200: raise RuntimeError(f'Snippet update HTTP {status}')
    return json.loads(raw.decode('utf-8'))

def one(pattern,html):
    m=re.search(pattern,html,re.I|re.S); return m.group(1).strip() if m else None

def article_ids(html):
    ids=[]
    for match in re.finditer(r'<article\b[^>]*class=["\'][^"\']*elementor-post[^"\']*["\'][^>]*>',html,re.I|re.S):
        tag=match.group(0); found=re.search(r'id=["\']post-(\d+)["\']',tag,re.I) or re.search(r'data-id=["\'](\d+)["\']',tag,re.I)
        if found and found.group(1) not in ids: ids.append(found.group(1))
    return ids

def probe(url):
    status,final_url,redirects,headers,raw=curl(url); html=raw.decode('utf-8','ignore')
    canonical=one(r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)',html) or one(r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']',html)
    robots=one(r'<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)',html); ids=article_ids(html)
    count=len(re.findall(r'<article\b[^>]*class=["\'][^"\']*elementor-post[^"\']*["\']',html,re.I|re.S))
    return {'url':url,'status':status,'final_url':final_url,'redirects':redirects,'canonical':canonical,'meta_robots':robots,'x_robots':', '.join(headers.get('x-robots-tag',[])) or None,'article_count':count,'post_ids':ids}

def cache_helper(token):
    return "\n/* "+HELPER_MARKER+" - removed automatically */\nadd_action('template_redirect',function(){if(!isset($_GET['doctorcura_pagination_refresh'])||!hash_equals('"+token+"',(string)$_GET['doctorcura_pagination_refresh']))return;$urls=array(home_url('/blogs/'),home_url('/fr/blogs/'),home_url('/en/blogs/'));if(function_exists('rocket_clean_files'))rocket_clean_files($urls);if(function_exists('wp_cache_flush'))wp_cache_flush();nocache_headers();status_header(204);exit;},-9999);\n"

def targeted_purge(code):
    token=secrets.token_urlsafe(24); put_code(code.rstrip()+cache_helper(token)); status,_,redirects,_,_=curl(f'{SITE}/?doctorcura_pagination_refresh={token}')
    if status!=204 or redirects!=0: raise RuntimeError(f'Targeted cache purge failed: HTTP {status}, redirects {redirects}')
    put_code(code)

def lint_php(block):
    fd,path=tempfile.mkstemp(suffix='.php'); os.close(fd)
    with open(path,'w',encoding='utf-8') as handle: handle.write('<?php\n'+block+'\n')
    try: run=subprocess.run(['php','-l',path],capture_output=True,text=True,timeout=20)
    finally: os.unlink(path)
    if run.returncode: raise RuntimeError('PHP lint failed: '+(run.stderr.strip() or run.stdout.strip()))

def preflight():
    baseline={'pages':{},'max_page':16,'found_posts':92,'posts_per_page':6}
    for prefix in ('','/fr','/en'):
        for page in range(2,17):
            source=f'{SITE}{prefix}/blogs/{page}/' if page<=5 else f'{SITE}{prefix}/blogs/page/{page}/'; row=probe(source)
            if row['status']!=200 or row['article_count']<1: raise RuntimeError('Baseline content page failed: '+json.dumps(row,separators=(',',':')))
            baseline['pages'][f'{prefix}:{page}']=row
        invalid=probe(f'{SITE}{prefix}/blogs/page/17/')
        if invalid['status']!=200 or invalid['article_count']!=0: raise RuntimeError('Expected native page 17 to be empty before fix: '+json.dumps(invalid,separators=(',',':')))
    if baseline['pages'][':16']['article_count']!=2: raise RuntimeError('Expected current final page 16 to contain two cards')
    return baseline

def verify_round(baseline,name):
    rows=[]
    for prefix in ('','/fr','/en'):
        for page in range(2,17):
            url=f'{SITE}{prefix}/blogs/{page}/'; row=probe(url); rows.append(row)
            if row['status']!=200 or row['final_url']!=url or row['redirects']!=0 or row['canonical']!=url: raise RuntimeError(name+' clean page failed: '+json.dumps(row,separators=(',',':')))
            robots=((row.get('meta_robots') or '')+' '+(row.get('x_robots') or '')).lower()
            if 'noindex' in robots: raise RuntimeError(name+' clean page noindexed: '+json.dumps(row,separators=(',',':')))
            expected=baseline['pages'][f'{prefix}:{page}']
            if row['article_count']!=expected['article_count'] or row['post_ids']!=expected['post_ids']: raise RuntimeError(name+' content mismatch: '+json.dumps({'expected':expected,'actual':row},separators=(',',':')))
        for page in (2,5,6,10,16):
            alias=f'{SITE}{prefix}/blogs/page/{page}/'; target=f'{SITE}{prefix}/blogs/{page}/'; row=probe(alias); rows.append(row)
            if row['status']!=200 or row['final_url']!=target or row['redirects']<1 or row['canonical']!=target: raise RuntimeError(name+' alias redirect failed: '+json.dumps(row,separators=(',',':')))
        for page in (17,18):
            for path in (f'{prefix}/blogs/{page}/',f'{prefix}/blogs/page/{page}/'):
                url=SITE+path; row=probe(url); rows.append(row)
                if row['status']!=404 or row['final_url']!=url or row['redirects']!=0: raise RuntimeError(name+' invalid page failed: '+json.dumps(row,separators=(',',':')))
    for page in (2,6,16):
        row=probe(f'{SITE}/de/blogs/{page}/'); rows.append(row); expected=f'{SITE}/blogs/{page}/'
        if row['status']!=200 or row['final_url']!=expected or row['canonical']!=expected: raise RuntimeError(name+' default-language consolidation failed: '+json.dumps(row,separators=(',',':')))
    invalid=probe(f'{SITE}/de/blogs/17/'); rows.append(invalid)
    if invalid['status']!=404 or invalid['final_url']!=f'{SITE}/blogs/17/': raise RuntimeError(name+' invalid default-language page failed: '+json.dumps(invalid,separators=(',',':')))
    return rows

def restore(original):
    put_code(original); targeted_purge(original); readback=str(get_snippet().get('code') or '')
    if hashlib.sha256(readback.encode()).hexdigest()!=hashlib.sha256(original.encode()).hexdigest(): raise RuntimeError('Rollback snippet hash mismatch')

def main():
    if SITE!='https://doctorcura.com' or not USER or not PASSWORD: raise RuntimeError('DoctorCura environment mismatch')
    lint_php(NEW_BLOCK); snippet=get_snippet(); original=str(snippet.get('code') or ''); before_sha=hashlib.sha256(original.encode()).hexdigest()
    if before_sha!=EXPECTED_SHA256: raise RuntimeError(f'Stale snippet preflight: expected {EXPECTED_SHA256}, got {before_sha}')
    if original.count(OLD_BLOCK)!=1 or original.count(OLD_MARKER)!=1: raise RuntimeError('Expected V8.2 pagination block not found exactly once')
    if NEW_MARKER in original or HELPER_MARKER in original: raise RuntimeError('Unexpected V10/helper residue before mutation')
    baseline=preflight(); final=original.replace(OLD_BLOCK,NEW_BLOCK,1)
    if OLD_MARKER in final or '(4|5)' in final or 'blogs/page/5' in final: raise RuntimeError('Hardcoded pagination residue remains after replacement')
    if NEW_MARKER not in final or 'doctorcura_seo_blog_pagination_state_v10' not in final: raise RuntimeError('V10 marker/state function missing')
    applied=False
    try:
        put_code(final); applied=True; readback=str(get_snippet().get('code') or '')
        if hashlib.sha256(readback.encode()).hexdigest()!=hashlib.sha256(final.encode()).hexdigest() or NEW_MARKER not in readback or OLD_MARKER in readback: raise RuntimeError('V10 snippet readback failed')
        targeted_purge(final); readback=str(get_snippet().get('code') or ''); after_sha=hashlib.sha256(readback.encode()).hexdigest()
        if after_sha!=hashlib.sha256(final.encode()).hexdigest() or HELPER_MARKER in readback: raise RuntimeError('V10 post-purge readback failed')
        round1=verify_round(baseline,'round_1'); round2=verify_round(baseline,'round_2')
        result={'snippet_id':43,'active':True,'marker':NEW_MARKER,'before_sha256':before_sha,'after_sha256':after_sha,'dynamic_source':{'elementor_page_id':5951,'widget_id':'6236f6e','skin':'cards','category_term_id':1,'found_posts':92,'posts_per_page':6,'current_max_page':16},'languages_verified':['de-root','fr','en'],'invalid_pages_verified':[17,18],'targeted_cache_purge':['/blogs/','/fr/blogs/','/en/blogs/'],'round_1':round1,'round_2':round2}
        with open(OUT,'w',encoding='utf-8') as handle: json.dump(result,handle,ensure_ascii=False,indent=2)
        print(json.dumps({'ok':True,'marker':NEW_MARKER,'current_max_page':16,'round_1_rows':len(round1),'round_2_rows':len(round2),'after_sha256':after_sha},separators=(',',':')))
    except Exception:
        if applied: restore(original)
        raise
if __name__=='__main__': main()
