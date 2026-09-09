#!/usr/bin/env python3
import hashlib
import json
import math
import os
import re
import subprocess
import tempfile

SITE = os.environ.get('SITE_URL', '').rstrip('/')
USER = os.environ.get('REST_USERNAME', '')
PASSWORD = os.environ.get('REST_APP_PASSWORD', '')
OUT = 'doctorcura-dynamic-pagination-diagnostic.json'

if SITE != 'https://doctorcura.com' or not USER or not PASSWORD:
    raise RuntimeError('DoctorCura environment mismatch')

def request(url, auth=False):
    with tempfile.TemporaryDirectory(prefix='dc-dyn-') as td:
        hdr = os.path.join(td, 'headers.txt')
        body = os.path.join(td, 'body.bin')
        cmd = [
            'curl', '--silent', '--show-error', '--location', '--compressed',
            '--connect-timeout', '10', '--max-time', '40',
            '--user-agent', 'Mozilla/5.0 (compatible; DoctorCuraDynamicPaginationAudit/1.0)',
            '--dump-header', hdr, '--output', body,
            '--write-out', '%{http_code}\t%{url_effective}\t%{num_redirects}',
        ]
        if auth:
            cmd += ['--user', f'{USER}:{PASSWORD}']
        run = subprocess.run(cmd + [url], capture_output=True, text=True, timeout=45)
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
                headers[key.lower().strip()] = value.strip()
        return {
            'status': int(parts[0]),
            'final_url': parts[1],
            'redirects': int(parts[2]),
            'headers': headers,
            'body': raw_body,
        }

def json_get(path, auth=False):
    result = request(SITE + path, auth=auth)
    if result['status'] != 200:
        raise RuntimeError(f'GET {path} failed with HTTP {result["status"]}')
    return json.loads(result['body'].decode('utf-8')), result['headers']

def canonical(html):
    for pattern in (
        r'<link[^>]+rel=["\'][^"\']*canonical[^"\']*["\'][^>]+href=["\']([^"\']+)',
        r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*canonical[^"\']*["\']',
    ):
        match = re.search(pattern, html, re.I | re.S)
        if match:
            return match.group(1).strip()
    return None

def probe(url):
    result = request(url)
    html = result['body'].decode('utf-8', 'ignore')
    visible = re.sub(r'<script\b.*?</script>|<style\b.*?</style>', ' ', html, flags=re.I | re.S)
    visible = re.sub(r'<[^>]+>', ' ', visible)
    visible = re.sub(r'\s+', ' ', visible).strip()
    return {
        'url': url,
        'status': result['status'],
        'final_url': result['final_url'],
        'redirects': result['redirects'],
        'canonical': canonical(html),
        'visible_sha256': hashlib.sha256(visible.encode()).hexdigest(),
        'visible_length': len(visible),
    }

snippet, _ = json_get('/wp-json/code-snippets/v1/snippets/43', auth=True)
if int(snippet.get('id') or 0) != 43 or not snippet.get('active'):
    raise RuntimeError('Snippet 43 is not the expected active snippet')
name = str(snippet.get('name') or snippet.get('display_name') or '')
if 'doctorcura seo indexation cleanup' not in name.lower():
    raise RuntimeError('Snippet 43 name mismatch')
code = str(snippet.get('code') or '')

settings, _ = json_get('/wp-json/wp/v2/settings?context=edit', auth=True)
posts_per_page = int(settings.get('posts_per_page') or 0)
page_for_posts = int(settings.get('page_for_posts') or 0)
show_on_front = str(settings.get('show_on_front') or '')

pages, _ = json_get('/wp-json/wp/v2/pages?slug=blogs&context=edit&per_page=10', auth=True)
blogs_page = None
if isinstance(pages, list) and pages:
    blogs_page = {
        'id': int(pages[0].get('id') or 0),
        'slug': pages[0].get('slug'),
        'status': pages[0].get('status'),
        'template': pages[0].get('template'),
    }

_, post_headers = json_get('/wp-json/wp/v2/posts?per_page=1&_fields=id', auth=False)
post_total = int(post_headers.get('x-wp-total') or 0)
calculated_pages = math.ceil(post_total / posts_per_page) if posts_per_page else None

max_probe = max(7, (calculated_pages or 5) + 2)
rows = []
for prefix in ('', '/fr', '/en', '/de'):
    for page in range(2, max_probe + 1):
        rows.append(probe(f'{SITE}{prefix}/blogs/{page}/'))
    for page in (2, 5, 6, 7):
        rows.append(probe(f'{SITE}{prefix}/blogs/page/{page}/'))

result = {
    'snippet': {
        'id': 43,
        'active': bool(snippet.get('active')),
        'name': name,
        'code_sha256': hashlib.sha256(code.encode()).hexdigest(),
        'has_v82_marker': 'DoctorCura blog pagination cleanup V8.2' in code,
        'has_hardcoded_4_5': 'blogs/(4|5)' in code,
        'has_hardcoded_page_5': 'blogs/page/5' in code,
    },
    'wordpress': {
        'show_on_front': show_on_front,
        'posts_per_page': posts_per_page,
        'page_for_posts': page_for_posts,
        'blogs_page': blogs_page,
        'published_post_total': post_total,
        'calculated_post_pages': calculated_pages,
    },
    'probes': rows,
}
with open(OUT, 'w', encoding='utf-8') as handle:
    json.dump(result, handle, ensure_ascii=False, indent=2)
print(json.dumps(result, ensure_ascii=False, indent=2))
