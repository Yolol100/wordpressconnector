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
NEW_MARKER = 'DoctorCura dynamic blog pagination cleanup V9.1'
OUT = 'doctorcura-dynamic-pagination-v9-verification.json'

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

NEW_BLOCK = r'''/* DoctorCura dynamic blog pagination cleanup V9.1 - 2026-09-10 */
add_filter( 'request', function ( $query_vars ) {
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) ) return $query_vars;
    if ( preg_match( '#^/(?:fr/|en/)?blogs/([2-9]|[1-9][0-9]+)/?$#', $path, $m )
        && isset( $query_vars['pagename'] )
        && 'blogs' === trim( (string) $query_vars['pagename'], '/' ) ) {
        unset( $query_vars['page'] );
        $query_vars['paged'] = (int) $m[1];
    }
    return $query_vars;
}, 1 );

add_filter( 'redirect_canonical', function ( $redirect_url, $requested_url ) {
    $path = wp_parse_url( (string) $requested_url, PHP_URL_PATH );
    if ( is_string( $path ) && preg_match( '#^/(?:fr/|en/)?blogs/([2-9]|[1-9][0-9]+)/?$#', $path ) ) {
        return false;
    }
    return $redirect_url;
}, 10, 2 );

add_filter( 'wpseo_canonical', function ( $canonical ) {
    if ( is_404() ) return $canonical;
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) ) return $canonical;
    if ( preg_match( '#^/(?:fr/|en/)?blogs/([2-9]|[1-9][0-9]+)/?$#', $path, $m ) ) {
        $prefix = preg_match( '#^/(fr|en)/#', $path, $lang ) ? '/' . $lang[1] : '';
        return home_url( $prefix . '/blogs/' . (int) $m[1] . '/' );
    }
    return $canonical;
}, 99 );

add_action( 'template_redirect', function () {
    if ( is_404() ) return;
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) ) return;
    if ( preg_match( '#^/(?:fr/|en/)?blogs/page/([2-9]|[1-9][0-9]+)/?$#', $path, $m ) ) {
        $prefix = preg_match( '#^/(fr|en)/#', $path, $lang ) ? '/' . $lang[1] : '';
        wp_safe_redirect( home_url( $prefix . '/blogs/' . (int) $m[1] . '/' ), 301, 'DoctorCura SEO dynamic pagination cleanup' );
        exit;
    }
}, 0 );'''

def curl(url, payload_path=None, auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-v91-') as td:
        hdr = os.path.join(td, 'headers.txt')
        body = os.path.join(td, 'body.bin')
        cmd = [
            'curl', '--silent', '--show-error', '--location', '--compressed',
            '--connect-timeout', '10', '--max-time', '45',
            '--user-agent', 'Mozilla/5.0 (compatible; DoctorCuraDynamicPaginationFix/1.1)',
            '--dump-header', hdr, '--output', body,
            '--write-out', '%{http_code}\t%{url_effective}\t%{num_redirects}',
        ]
        if auth:
            cmd += ['--user', f'{USER}:{PASSWORD}']
        if payload_path:
            cmd += [
                '--request', 'POST',
                '--header', 'X-HTTP-Method-Override: PUT',
                '--header', 'Content-Type: application/json',
                '--data-binary', '@' + payload_path,
            ]
        run = subprocess.run(cmd + [url], capture_output=True, text=True, timeout=55)
        if run.returncode:
            raise RuntimeError(run.stderr.strip() or f'curl exit {run.returncode}')
        parts = run.stdout.strip().split('\t')
        raw_headers = open(hdr, 'rb').read().decode('iso-8859-1', 'replace')
        raw_body = open(body, 'rb').read()
        blocks = [b for b in re.split(r'\r?\n\r?\n', raw_headers) if b.strip().startswith('HTTP/')]
        last = blocks[-1] if blocks else ''
        headers = {}
        for line in last.splitlines()[1:]:
            if ':' in line:
                key, value = line.split(':', 1)
                headers.setdefault(key.lower().strip(), []).append(value.strip())
        return int(parts[0]), parts[1], int(parts[2]), headers, raw_body

def get_snippet():
    status, _, _, _, raw = curl(API, auth=True)
    data = json.loads(raw.decode('utf-8')) if status == 200 else {}
    name = str(data.get('name') or data.get('display_name') or '')
    if status != 200 or int(data.get('id') or 0) != 43 or not data.get('active'):
        raise RuntimeError('Snippet 43 readback failed')
    if 'doctorcura seo indexation cleanup' not in name.lower():
        raise RuntimeError('Snippet 43 name mismatch')
    return data

def put_code(code):
    fd, path = tempfile.mkstemp(suffix='.json')
    os.close(fd)
    with open(path, 'w', encoding='utf-8') as handle:
        json.dump({'code': code}, handle, separators=(',', ':'))
    try:
        status, _, _, _, raw = curl(API, payload_path=path, auth=True)
    finally:
        os.unlink(path)
    if status != 200:
        raise RuntimeError(f'Snippet update HTTP {status}')
    return json.loads(raw.decode('utf-8'))

def one(pattern, html):
    match = re.search(pattern, html, re.I | re.S)
    return match.group(1).strip() if match else None

def probe(url):
    status, final_url, redirects, headers, raw = curl(url)
    html = raw.decode('utf-8', 'ignore')
    canonical = one(r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)', html)
    if not canonical:
        canonical = one(r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']', html)
    robots = one(r'<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)', html)
    visible = re.sub(r'<script\b.*?</script>|<style\b.*?</style>', ' ', html, flags=re.I | re.S)
    visible = re.sub(r'<[^>]+>', ' ', visible)
    visible = re.sub(r'\s+', ' ', visible).strip()
    return {
        'url': url,
        'status': status,
        'final_url': final_url,
        'redirects': redirects,
        'canonical': canonical,
        'meta_robots': robots,
        'x_robots': ', '.join(headers.get('x-robots-tag', [])) or None,
        'visible_sha256': hashlib.sha256(visible.encode()).hexdigest(),
        'visible_length': len(visible),
    }

def helper(token):
    return (
        "\n/* DoctorCura one-time dynamic pagination cache helper - removed automatically */\n"
        "add_action('template_redirect',function(){"
        "if(!isset($_GET['doctorcura_pagination_refresh'])||!hash_equals('" + token + "',(string)$_GET['doctorcura_pagination_refresh']))return;"
        "if(function_exists('rocket_clean_domain'))rocket_clean_domain();"
        "if(function_exists('wp_cache_flush'))wp_cache_flush();"
        "nocache_headers();status_header(204);exit;"
        "},-9999);\n"
    )

def refresh(token):
    status, _, redirects, _, _ = curl(f'{SITE}/?doctorcura_pagination_refresh={token}')
    if status != 204 or redirects != 0:
        raise RuntimeError(f'Cache refresh failed: HTTP {status}, redirects {redirects}')

def lint_php(block):
    fd, path = tempfile.mkstemp(suffix='.php')
    os.close(fd)
    with open(path, 'w', encoding='utf-8') as handle:
        handle.write('<?php\n' + block + '\n')
    try:
        run = subprocess.run(['php', '-l', path], capture_output=True, text=True, timeout=20)
    finally:
        os.unlink(path)
    if run.returncode:
        raise RuntimeError('PHP lint failed: ' + (run.stderr.strip() or run.stdout.strip()))

def discover_native_pages():
    pages = []
    for page in range(6, 31):
        url = f'{SITE}/blogs/page/{page}/'
        row = probe(url)
        if row['status'] == 200 and row['final_url'] == url and row['redirects'] == 0 and row['visible_length'] >= 500:
            pages.append(page)
            continue
        break
    if pages[:2] != [6, 7]:
        raise RuntimeError('Expected native pages 6 and 7 were not both live before mutation')
    return pages

def preflight_paths():
    native_pages = discover_native_pages()
    max_page = native_pages[-1]
    baseline = {'clean': {}, 'source': {}, 'native_pages': native_pages, 'max_page': max_page}
    for prefix in ('', '/fr', '/en'):
        for page in (2, 3, 4, 5):
            url = f'{SITE}{prefix}/blogs/{page}/'
            row = probe(url)
            if row['status'] != 200 or row['final_url'] != url or row['visible_length'] < 500:
                raise RuntimeError('Existing clean pagination baseline failed: ' + json.dumps(row, separators=(',', ':')))
            baseline['clean'][f'{prefix}:{page}'] = row
        for page in native_pages:
            url = f'{SITE}{prefix}/blogs/page/{page}/'
            row = probe(url)
            if row['status'] != 200 or row['final_url'] != url or row['redirects'] != 0 or row['visible_length'] < 500:
                raise RuntimeError('Native pagination source baseline failed: ' + json.dumps(row, separators=(',', ':')))
            baseline['source'][f'{prefix}:{page}'] = row
    return baseline

def verify_live(baseline):
    rows = []
    max_page = baseline['max_page']
    valid_pages = list(range(2, max_page + 1))
    for prefix in ('', '/fr', '/en'):
        for page in valid_pages:
            url = f'{SITE}{prefix}/blogs/{page}/'
            row = probe(url)
            rows.append(row)
            if row['status'] != 200 or row['final_url'] != url or row['redirects'] != 0 or row['canonical'] != url:
                raise RuntimeError('Dynamic clean pagination verification failed: ' + json.dumps(row, separators=(',', ':')))
            robots = ((row.get('meta_robots') or '') + ' ' + (row.get('x_robots') or '')).lower()
            if 'noindex' in robots:
                raise RuntimeError('Dynamic pagination unexpectedly noindexed: ' + json.dumps(row, separators=(',', ':')))
            key = f'{prefix}:{page}'
            expected = baseline['clean'].get(key) or baseline['source'].get(key)
            if expected and row['visible_sha256'] != expected['visible_sha256']:
                raise RuntimeError('Pagination content changed during route normalization: ' + json.dumps({'expected': expected, 'actual': row}, separators=(',', ':')))
        alias_samples = sorted(set([2, 5, 6, 7, max_page]))
        for page in alias_samples:
            alias = f'{SITE}{prefix}/blogs/page/{page}/'
            target = f'{SITE}{prefix}/blogs/{page}/'
            row = probe(alias)
            rows.append(row)
            if row['status'] != 200 or row['final_url'] != target or row['redirects'] < 1 or row['canonical'] != target:
                raise RuntimeError('Pagination alias redirect verification failed: ' + json.dumps(row, separators=(',', ':')))
    for page in sorted(set([2, 6, 7, max_page])):
        row = probe(f'{SITE}/de/blogs/{page}/')
        rows.append(row)
        expected = f'{SITE}/blogs/{page}/'
        if row['status'] != 200 or row['final_url'] != expected or row['canonical'] != expected:
            raise RuntimeError('Default-language /de/ consolidation regression: ' + json.dumps(row, separators=(',', ':')))
    return rows

def restore(original):
    token = secrets.token_urlsafe(24)
    try:
        put_code(original.rstrip() + helper(token))
        refresh(token)
    finally:
        put_code(original)

def main():
    if SITE != 'https://doctorcura.com' or not USER or not PASSWORD:
        raise RuntimeError('DoctorCura environment mismatch')
    lint_php(NEW_BLOCK)
    for sample in ('6', '7', '42', '999'):
        if not re.fullmatch(r'([2-9]|[1-9][0-9]+)', sample):
            raise RuntimeError('Future-page pattern test failed for ' + sample)
    snippet = get_snippet()
    original = str(snippet.get('code') or '')
    actual_sha = hashlib.sha256(original.encode()).hexdigest()
    if actual_sha != EXPECTED_SHA256:
        raise RuntimeError(f'Stale snippet preflight: expected {EXPECTED_SHA256}, got {actual_sha}')
    if original.count(OLD_BLOCK) != 1 or original.count(OLD_MARKER) != 1:
        raise RuntimeError('Expected V8.2 pagination block not found exactly once')
    if NEW_MARKER in original or 'one-time dynamic pagination cache helper' in original:
        raise RuntimeError('Unexpected V9.1/helper residue before mutation')
    baseline = preflight_paths()
    final = original.replace(OLD_BLOCK, NEW_BLOCK, 1)
    if OLD_MARKER in final or '(4|5)' in final or 'blogs/page/5' in final:
        raise RuntimeError('Hardcoded pagination residue remains after replacement')
    if NEW_MARKER not in final or "query_vars['paged']" not in final:
        raise RuntimeError('V9.1 dynamic query marker missing after replacement')
    applied = False
    try:
        token = secrets.token_urlsafe(24)
        put_code(final.rstrip() + helper(token))
        applied = True
        refresh(token)
        put_code(final)
        readback_code = str(get_snippet().get('code') or '')
        if NEW_MARKER not in readback_code or OLD_MARKER in readback_code or 'one-time dynamic pagination cache helper' in readback_code:
            raise RuntimeError('V9.1 snippet readback marker verification failed')
        if hashlib.sha256(readback_code.encode()).hexdigest() != hashlib.sha256(final.encode()).hexdigest():
            raise RuntimeError('V9.1 snippet readback hash mismatch')
        rows = verify_live(baseline)
        result = {
            'snippet_id': 43,
            'active': True,
            'marker': NEW_MARKER,
            'before_sha256': actual_sha,
            'after_sha256': hashlib.sha256(final.encode()).hexdigest(),
            'native_pages_before': baseline['native_pages'],
            'max_live_page': baseline['max_page'],
            'future_pattern_samples': [6, 7, 42, 999],
            'default_language': 'de (root URL; /de/ consolidates to root)',
            'languages_verified': ['de-root', 'fr', 'en'],
            'verification': rows,
        }
        with open(OUT, 'w', encoding='utf-8') as handle:
            json.dump(result, handle, ensure_ascii=False, indent=2)
        print(json.dumps({'ok': True, 'marker': NEW_MARKER, 'max_live_page': result['max_live_page'], 'verified_rows': len(rows), 'after_sha256': result['after_sha256']}, separators=(',', ':')))
    except Exception:
        if applied:
            restore(original)
        raise

if __name__ == '__main__':
    main()
