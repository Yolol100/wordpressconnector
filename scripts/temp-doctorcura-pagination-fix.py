#!/usr/bin/env python3
import base64
import hashlib
import json
import os
import re
import secrets
import subprocess
import sys
import tempfile

SITE = os.environ.get('SITE_URL', '').rstrip('/')
USER = os.environ.get('REST_USERNAME', '')
PASSWORD = os.environ.get('REST_APP_PASSWORD', '')
SNIPPET_API = f'{SITE}/wp-json/code-snippets/v1/snippets/43'
MARKER = 'DoctorCura blog pagination canonical cleanup V8'

SEO_BLOCK = r'''

/* DoctorCura blog pagination canonical cleanup V8 - 2026-09-09 */
add_filter( 'wpseo_canonical', function ( $canonical ) {
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( is_string( $path ) && preg_match( '#^/fr/blogs/(4|5)/?$#', $path, $matches ) ) {
        return home_url( '/fr/blogs/' . (int) $matches[1] . '/' );
    }
    return $canonical;
}, 20 );

add_action( 'template_redirect', function () {
    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $path ) ) {
        return;
    }

    if ( preg_match( '#^/fr/blogs/page/5/?$#', $path ) ) {
        wp_safe_redirect( home_url( '/fr/blogs/5/' ), 301, 'DoctorCura SEO pagination cleanup' );
        exit;
    }

    if ( preg_match( '#^/fr/blogs/6/?$#', $path ) ) {
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


def auth_header():
    raw = f'{USER}:{PASSWORD}'.encode('utf-8')
    return 'Basic ' + base64.b64encode(raw).decode('ascii')


def curl_json(method, url, payload=None, authenticated=False):
    fd, out = tempfile.mkstemp(prefix='dc-json-', suffix='.json')
    os.close(fd)
    cmd = [
        'curl', '--silent', '--show-error', '--location', '--connect-timeout', '10', '--max-time', '45',
        '--header', 'Accept: application/json', '--output', out, '--write-out', '%{http_code}'
    ]
    if authenticated:
        cmd += ['--header', f'Authorization: {auth_header()}']
    if method == 'PUT_OVERRIDE':
        cmd += ['--request', 'POST', '--header', 'X-HTTP-Method-Override: PUT', '--header', 'Content-Type: application/json']
        cmd += ['--data-binary', '@' + payload]
    result = subprocess.run(cmd + [url], capture_output=True, text=True, timeout=55)
    status = int(result.stdout.strip()) if result.stdout.strip().isdigit() else 0
    try:
        data = json.load(open(out, encoding='utf-8'))
    except Exception:
        data = None
    os.unlink(out)
    if result.returncode != 0:
        raise RuntimeError(f'curl failed for {url}: {result.stderr.strip()}')
    return status, data


def get_snippet():
    status, data = curl_json('GET', SNIPPET_API, authenticated=True)
    if status != 200 or not isinstance(data, dict):
        raise RuntimeError(f'Cannot read snippet 43: HTTP {status}')
    if int(data.get('id') or 0) != 43:
        raise RuntimeError('Snippet ID mismatch')
    name = str(data.get('name') or data.get('display_name') or '')
    if 'doctorcura seo indexation cleanup' not in name.lower():
        raise RuntimeError(f'Snippet name mismatch: {name}')
    if not data.get('active'):
        raise RuntimeError('Snippet 43 is not active')
    return data


def update_code(code):
    fd, path = tempfile.mkstemp(prefix='dc-update-', suffix='.json')
    os.close(fd)
    with open(path, 'w', encoding='utf-8') as f:
        json.dump({'code': code}, f, ensure_ascii=False, separators=(',', ':'))
    try:
        status, data = curl_json('PUT_OVERRIDE', SNIPPET_API, payload=path, authenticated=True)
    finally:
        os.unlink(path)
    if status != 200 or not isinstance(data, dict):
        raise RuntimeError(f'Snippet update failed: HTTP {status}')
    return data


def purge_block(token):
    return r'''

/* DoctorCura one-time cache purge helper - removed automatically */
add_action( 'init', function () {
    $expected = '__TOKEN__';
    $actual = isset( $_GET['doctorcura_seo_purge'] ) ? (string) $_GET['doctorcura_seo_purge'] : '';
    if ( ! hash_equals( $expected, $actual ) ) {
        return;
    }
    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
    }
    if ( function_exists( 'wp_cache_flush' ) ) {
        wp_cache_flush();
    }
    header( 'X-DoctorCura-SEO-Purge: done' );
}, 1 );
'''.replace('__TOKEN__', token)


def trigger_purge(token):
    url = f'{SITE}/?doctorcura_seo_purge={token}'
    p = subprocess.run([
        'curl', '--silent', '--show-error', '--location', '--connect-timeout', '10', '--max-time', '45',
        '--output', '/dev/null', '--write-out', '%{http_code}', url
    ], capture_output=True, text=True, timeout=55)
    status = int(p.stdout.strip()) if p.stdout.strip().isdigit() else 0
    if p.returncode != 0 or status not in (200, 301, 302):
        raise RuntimeError(f'Cache purge trigger failed: HTTP {status}; {p.stderr.strip()}')


def canonical_from_html(html):
    patterns = [
        r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)',
        r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']'
    ]
    for pattern in patterns:
        m = re.search(pattern, html, re.I | re.S)
        if m:
            return m.group(1).strip()
    return None


def robots_from_html(html):
    for pattern in [
        r'<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)',
        r'<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']robots["\']'
    ]:
        m = re.search(pattern, html, re.I | re.S)
        if m:
            return m.group(1).strip()
    return None


def probe(url):
    td = tempfile.mkdtemp(prefix='dc-probe-')
    body = os.path.join(td, 'body.html')
    cmd = [
        'curl', '--silent', '--show-error', '--location', '--compressed', '--connect-timeout', '8', '--max-time', '25',
        '--user-agent', 'Mozilla/5.0 (compatible; DoctorCuraPaginationVerify/1.0)',
        '--output', body, '--write-out', '%{http_code}\t%{url_effective}\t%{num_redirects}', url
    ]
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=35)
    parts = p.stdout.strip().split('\t')
    status = int(parts[0]) if parts and parts[0].isdigit() else 0
    final_url = parts[1] if len(parts) > 1 else ''
    redirects = int(parts[2]) if len(parts) > 2 and parts[2].isdigit() else 0
    html = open(body, 'rb').read().decode('utf-8', 'ignore') if os.path.exists(body) else ''
    visible = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html)).strip()
    return {
        'url': url,
        'status': status,
        'final_url': final_url,
        'redirects': redirects,
        'canonical': canonical_from_html(html),
        'meta_robots': robots_from_html(html),
        'visible_text_sha256': hashlib.sha256(visible.encode('utf-8')).hexdigest(),
        'visible_text_length': len(visible),
        'curl_error': None if p.returncode == 0 else p.stderr.strip(),
    }


def verify_live():
    rows = [probe(u) for u in [
        f'{SITE}/fr/blogs/4/',
        f'{SITE}/fr/blogs/5/',
        f'{SITE}/fr/blogs/6/',
        f'{SITE}/fr/blogs/page/5/',
    ]]
    p4, p5, p6, alias = rows
    if not (p4['status'] == 200 and p4['final_url'] == f'{SITE}/fr/blogs/4/' and p4['canonical'] == f'{SITE}/fr/blogs/4/'):
        raise RuntimeError('Page 4 live verification failed')
    if not (p5['status'] == 200 and p5['final_url'] == f'{SITE}/fr/blogs/5/' and p5['canonical'] == f'{SITE}/fr/blogs/5/'):
        raise RuntimeError('Page 5 live verification failed')
    if not (alias['status'] == 200 and alias['final_url'] == f'{SITE}/fr/blogs/5/' and alias['redirects'] >= 1):
        raise RuntimeError('Duplicate /page/5/ redirect verification failed')
    if not (p6['status'] == 404 and p6['final_url'] == f'{SITE}/fr/blogs/6/' and p6['redirects'] == 0):
        raise RuntimeError('Out-of-range page 6 verification failed')
    return rows


def restore(original):
    token = secrets.token_urlsafe(24)
    try:
        update_code(original.rstrip() + purge_block(token) + '\n')
        trigger_purge(token)
    finally:
        update_code(original)


def main():
    if SITE != 'https://doctorcura.com' or not USER or not PASSWORD:
        raise RuntimeError('DoctorCura environment is incomplete or target mismatched')
    snippet = get_snippet()
    original = str(snippet.get('code') or '')
    final = original if MARKER in original else original.rstrip() + SEO_BLOCK + '\n'
    changed = final != original
    applied = False
    try:
        if changed:
            token = secrets.token_urlsafe(24)
            update_code(final.rstrip() + purge_block(token) + '\n')
            applied = True
            trigger_purge(token)
            update_code(final)
        readback = get_snippet()
        code = str(readback.get('code') or '')
        if MARKER not in code:
            raise RuntimeError('V8 marker missing after update')
        if 'one-time cache purge helper' in code:
            raise RuntimeError('Temporary cache helper still present')
        rows = verify_live()
        with open('doctorcura-pagination-fix-verification.json', 'w', encoding='utf-8') as f:
            json.dump({'changed': changed, 'snippet_id': 43, 'active': bool(readback.get('active')), 'verification': rows}, f, ensure_ascii=False, indent=2)
    except Exception:
        if applied:
            restore(original)
        raise


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print(f'ERROR: {exc}', file=sys.stderr)
        raise
