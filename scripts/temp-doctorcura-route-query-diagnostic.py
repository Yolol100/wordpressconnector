#!/usr/bin/env python3
import hashlib
import json
import os
import secrets
import subprocess
import tempfile

SITE = os.environ.get('SITE_URL', '').rstrip('/')
USER = os.environ.get('REST_USERNAME', '')
PASSWORD = os.environ.get('REST_APP_PASSWORD', '')
API = f'{SITE}/wp-json/code-snippets/v1/snippets/43'
EXPECTED_SHA = '6a7c7520a8030055756bc4dc02e1562c6b75917ffef4d52303f27be247931cf3'
MARKER = 'DoctorCura temporary pagination route diagnostic'

if SITE != 'https://doctorcura.com' or not USER or not PASSWORD:
    raise RuntimeError('DoctorCura environment mismatch')

def curl(url, payload=None, auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-route-') as td:
        body = os.path.join(td, 'body')
        cmd = [
            'curl', '--silent', '--show-error', '--location', '--compressed',
            '--connect-timeout', '10', '--max-time', '40',
            '--user-agent', 'Mozilla/5.0 (compatible; DoctorCuraRouteAudit/1.0)',
            '--output', body, '--write-out', '%{http_code}\t%{url_effective}\t%{num_redirects}',
        ]
        if auth:
            cmd += ['--user', f'{USER}:{PASSWORD}']
        payload_path = None
        if payload is not None:
            fd, payload_path = tempfile.mkstemp(dir=td, suffix='.json')
            os.close(fd)
            with open(payload_path, 'w', encoding='utf-8') as h:
                json.dump(payload, h, separators=(',', ':'))
            cmd += [
                '--request', 'POST',
                '--header', 'X-HTTP-Method-Override: PUT',
                '--header', 'Content-Type: application/json',
                '--data-binary', '@' + payload_path,
            ]
        run = subprocess.run(cmd + [url], capture_output=True, text=True, timeout=50)
        if run.returncode:
            raise RuntimeError(run.stderr.strip() or f'curl exit {run.returncode}')
        parts = run.stdout.strip().split('\t')
        raw = open(body, 'rb').read()
        return int(parts[0]), parts[1], int(parts[2]), raw

def get_snippet():
    status, _, _, raw = curl(API, auth=True)
    if status != 200:
        raise RuntimeError(f'snippet GET HTTP {status}')
    data = json.loads(raw.decode('utf-8'))
    if int(data.get('id') or 0) != 43 or not data.get('active'):
        raise RuntimeError('snippet 43 state mismatch')
    return data

def put_code(code):
    status, _, _, raw = curl(API, payload={'code': code}, auth=True)
    if status != 200:
        raise RuntimeError(f'snippet PUT HTTP {status}')
    return json.loads(raw.decode('utf-8'))

def diagnostic_block(token):
    return r'''

/* DoctorCura temporary pagination route diagnostic - removed automatically */
add_action( 'template_redirect', function () {
    if ( ! isset( $_GET['doctorcura_route_probe'] ) || ! hash_equals( '__TOKEN__', (string) $_GET['doctorcura_route_probe'] ) ) {
        return;
    }
    global $wp, $wp_query;
    $query_vars = is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ? $wp->query_vars : array();
    $wpq_vars = is_object( $wp_query ) && isset( $wp_query->query_vars ) && is_array( $wp_query->query_vars ) ? $wp_query->query_vars : array();
    $allow = array( 'pagename', 'page_id', 'paged', 'page', 'name', 'post_type', 'error', 'attachment', 'attachment_id' );
    $safe_query = array();
    $safe_wpq = array();
    foreach ( $allow as $key ) {
        if ( array_key_exists( $key, $query_vars ) ) $safe_query[ $key ] = $query_vars[ $key ];
        if ( array_key_exists( $key, $wpq_vars ) ) $safe_wpq[ $key ] = $wpq_vars[ $key ];
    }
    $data = array(
        'request_uri'   => isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : null,
        'matched_rule'  => is_object( $wp ) && isset( $wp->matched_rule ) ? (string) $wp->matched_rule : null,
        'matched_query' => is_object( $wp ) && isset( $wp->matched_query ) ? (string) $wp->matched_query : null,
        'query_vars'    => $safe_query,
        'wp_query_vars' => $safe_wpq,
        'is_page'       => is_page(),
        'is_paged'      => is_paged(),
        'is_404'        => is_404(),
        'queried_id'    => get_queried_object_id(),
    );
    nocache_headers();
    status_header( 200 );
    header( 'Content-Type: application/json; charset=utf-8' );
    echo wp_json_encode( $data );
    exit;
}, -9999 );
'''.replace('__TOKEN__', token)

def probe(path, token):
    separator = '&' if '?' in path else '?'
    status, final, redirects, raw = curl(f'{SITE}{path}{separator}doctorcura_route_probe={token}')
    if status != 200 or redirects != 0:
        raise RuntimeError(f'probe transport failed for {path}: HTTP {status}, redirects {redirects}, final {final}')
    try:
        return json.loads(raw.decode('utf-8'))
    except Exception as exc:
        raise RuntimeError(f'probe JSON failed for {path}: {raw[:200]!r}') from exc

def main():
    data = get_snippet()
    original = str(data.get('code') or '')
    before_sha = hashlib.sha256(original.encode()).hexdigest()
    if before_sha != EXPECTED_SHA:
        raise RuntimeError(f'stale snippet: expected {EXPECTED_SHA}, got {before_sha}')
    if MARKER in original:
        raise RuntimeError('diagnostic residue already present')
    token = secrets.token_urlsafe(24)
    instrumented = original.rstrip() + diagnostic_block(token)
    applied = False
    try:
        put_code(instrumented)
        applied = True
        readback = str(get_snippet().get('code') or '')
        if MARKER not in readback:
            raise RuntimeError('diagnostic readback failed')
        results = {}
        for path in (
            '/blogs/6/', '/blogs/page/6/',
            '/fr/blogs/6/', '/fr/blogs/page/6/',
            '/en/blogs/6/', '/en/blogs/page/6/',
        ):
            results[path] = probe(path, token)
        print(json.dumps({'ok': True, 'before_sha256': before_sha, 'routes': results}, ensure_ascii=False, indent=2))
    finally:
        if applied:
            put_code(original)
        restored = str(get_snippet().get('code') or '')
        after_sha = hashlib.sha256(restored.encode()).hexdigest()
        if after_sha != before_sha or MARKER in restored:
            raise RuntimeError(f'rollback verification failed: expected {before_sha}, got {after_sha}')
        print(json.dumps({'rollback_verified': True, 'sha256': after_sha}, separators=(',', ':')))

if __name__ == '__main__':
    main()
