#!/usr/bin/env python3
"""Bounded read-only smoke; public requests only. Report observations, not release Go."""
import html
import json
import re
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

BASE = "https://byderma.nl"
PATHS = [
    "/robots.txt", "/sitemap_index.xml", "/page-sitemap.xml",
    "/product_cat-sitemap.xml", "/sitemap/",
    "/product/masque-nutri-apaisant/", "/winkelwagen/",
    "/mijn-account/", "/shop/page/6/",
]
SAMPLE_TERMS = [
    "/product-categorie/make-up/ogen/",
    "/product-categorie/cadeaus-sets/cadeautips/",
    "/product-categorie/haircare/haarstyling/",
]

def attribute(tag, key):
    if not tag:
        return None
    match = re.search(r'\b' + key + r'=["\']([^"\']+)["\']', tag, re.I)
    return html.unescape(match.group(1)) if match else None

def tag(source, kind, name):
    return next((m.group(0) for m in re.finditer(r'<'+kind+r'\b[^>]*>', source, re.I)
                 if attribute(m.group(0), "name" if kind=="meta" and name not in ("og:url",) else
                              "property" if name == "og:url" else "rel") == name), None)

def check(path):
    request = urllib.request.Request(BASE + path, headers={"User-Agent":"Webactueel SEO read-only audit"})
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            code, content_type, final = response.status, response.headers.get("Content-Type", ""), response.geturl()
            body = response.read(1800000)
    except urllib.error.HTTPError as error:
        code, content_type, final = error.code, error.headers.get("Content-Type", ""), error.geturl()
        body = error.read(100000)
    except Exception as error:
        return {"outcome":"unavailable", "exception":type(error).__name__}
    if urllib.parse.urlsplit(final).hostname != "byderma.nl":
        return {"outcome":"external_redirect", "code":code}
    record = {"outcome":"http_ok" if code==200 else "http_error", "code":code, "bytes":len(body),
              "mime":content_type.split(";")[0]}
    if code != 200:
        return record
    source = body.decode("utf-8", "replace")
    if "text/html" in content_type:
        title = re.search(r'<title[^>]*>(.*?)</title>', source, re.I|re.S)
        robots = tag(source, "meta", "robots")
        desc = tag(source, "meta", "description")
        canonical = tag(source, "link", "canonical")
        og = tag(source, "meta", "og:url")
        record.update({
            "title":html.unescape(title.group(1).strip())[:160] if title else None,
            "meta_chars":len(attribute(desc, "content") or ""),
            "canonical":attribute(canonical, "href"),
            "robots":attribute(robots, "content"),
            "og_url":attribute(og, "content"),
        })
        if path == "/sitemap/":
            record["dead_saswp_anchors"] = sum(
                bool(re.search(r'[?&]p=787[678]\b', match.group(0)))
                for match in re.finditer(r'<a\b[^>]*>', source, re.I))
    elif path.endswith(".xml"):
        try:
            root = ET.fromstring(body)
            element_name = lambda x: x.rsplit("}", 1)[-1]
            urls = [node.text for node in root.iter() if element_name(node.tag)=="loc" and node.text]
            record["root"] = element_name(root.tag)
            record["loc_count"] = len(urls)
            if path == "/sitemap_index.xml":
                record["sitemap_names"] = [urllib.parse.urlsplit(u).path.rsplit("/", 1)[-1] for u in urls[:40]]
            if path == "/product_cat-sitemap.xml":
                record["sample_category_membership"] = {x:any(u.endswith(x) for u in urls) for x in SAMPLE_TERMS}
            if path == "/page-sitemap.xml":
                blocked = ("/winkelwagen/", "/mijn-account/", "/checkout/", "/afrekenen/", "/bedankt-afrekenen/")
                record["transactional_urls_listed"] = [u for u in urls if any(part in u for part in blocked)]
        except Exception:
            record["parse"] = "invalid_xml"
    elif path == "/robots.txt":
        record["sitemap_lines"] = [line.split(":",1)[1].strip() for line in source.splitlines()
                                  if line.lower().startswith("sitemap:")][:10]
    return record

records = {path:check(path) for path in PATHS}
for path,result in records.items():
    print(json.dumps({"path":path, **result}, ensure_ascii=False, sort_keys=True))
print("SUMMARY " + json.dumps({
    "urls_checked":len(records),
    "http_200":sum(x.get("code")==200 for x in records.values()),
    "not_accessible":sum(x.get("outcome") != "http_ok" for x in records.values()),
}, sort_keys=True))
