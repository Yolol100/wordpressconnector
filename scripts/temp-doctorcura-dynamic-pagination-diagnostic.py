#!/usr/bin/env python3
import base64
import hashlib
import json
import math
import os
import re
import urllib.error
import urllib.parse
import urllib.request

SITE = os.environ.get('SITE_URL', '').rstrip('/')
USER = os.environ.get('REST_USERNAME', '')
PASSWORD = os.environ.get('REST_APP_PASSWORD', '')
OUT = 'doctorcura-dynamic-pagination-diagnostic.json'

if SITE != 'https://doctorcura.com' or not USER or not PASSWORD:
    raise RuntimeError('DoctorCura environment mismatch')

AUTH = 'Basic ' + base64.b64encode(f'{USER}:{PASSWORD}'.encode()).decode()

class TrackingRedirect(urllib.request.HTTPRedirectHandler):
    def __init__(self):
        super().__init__()
        self.redirects = 0
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        self.redirects += 1
        return super().redirect_request(req, fp, code, msg, headers, newurl)

def request(url, auth=False):
    handler = TrackingRedirect()
    opener = urllib.request.build_opener(handler)
    headers = {
        'User-Agent': 'Mozilla/5.0 (compatible; DoctorCuraDynamicPaginationAudit/1.0)',
        'Accept-Encoding': 'identity',
    }
    if auth:
        headers['Authorization'] = AUTH
    req = urllib.request.Request(url, headers=headers, method='GET')
    try:
        with opener.open(req, timeout=30) as response:
            body = response.read()
            return {
                'status': response.status,
                'final_url': response.geturl(),
                'redirects': handler.redirects,
                'headers': dict(response.headers.items()),
                'body': body,
            }
    except urllib.error.HTTPError as error:
        body = error.read()
        return {
            'status': error.code,
            'final_url': error.geturl(),
            'redirects': handler.redirects,
            'headers': dict(error.headers.items()),
            'body': body,
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
post_total = int(post_headers.get('X-WP-Total') or post_headers.get('x-wp-total') or 0)
calculated_pages = math.ceil(post_total / posts_per_page) if posts_per_page else None

# Probe current live page space plus one future/out-of-range slot.
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
        'has_hardcoded_4_5': "blogs/(4|5)" in code,
        'has_hardcoded_page_5': "blogs/page/5" in code,
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
