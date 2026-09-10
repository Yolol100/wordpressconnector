import fs from 'node:fs';
import crypto from 'node:crypto';

const BASE = 'https://doctorcura.com';
const GSC_FILE = 'doctorcura-audits/deep-seo-20260910-gsc-urls.txt';
const OUT_JSON = 'doctorcura-exhaustive-results.json';
const OUT_SUMMARY = 'doctorcura-exhaustive-summary.txt';
const OUT_ANOM = 'doctorcura-exhaustive-anomalies.tsv';
const UA = 'Mozilla/5.0 (compatible; DoctorCuraDeepSEOAudit/2026-09-10; +https://doctorcura.com/)';
const SAFE_TIMEOUT_MS = 30000;

const decode = (s='') => s.replaceAll('&amp;','&').replaceAll('&quot;','"').replaceAll('&#39;',"'").replaceAll('&lt;','<').replaceAll('&gt;','>');
const strip = (s='') => decode(s.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,' ').replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi,' ').replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim());
const sha = (s='') => crypto.createHash('sha256').update(s).digest('hex');

function attrs(tag) {
  const out = {};
  const re = /([^\s=/>]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/g;
  let m;
  while ((m = re.exec(tag))) out[m[1].toLowerCase()] = decode(m[2] ?? m[3] ?? m[4] ?? '');
  return out;
}

function parseHtml(html, pageUrl) {
  const links = [];
  const hreflangs = [];
  let canonical = '';
  let robots = '';
  let ogUrl = '';
  let title = '';
  let htmlLang = '';
  let h1Count = 0;
  let metaDescription = '';

  const htmlTag = html.match(/<html\b[^>]*>/i);
  if (htmlTag) htmlLang = attrs(htmlTag[0]).lang || '';
  const titleMatch = html.match(/<title\b[^>]*>([\s\S]*?)<\/title>/i);
  if (titleMatch) title = strip(titleMatch[1]);
  h1Count = (html.match(/<h1\b/gi) || []).length;

  for (const m of html.matchAll(/<link\b[^>]*>/gi)) {
    const a = attrs(m[0]);
    const rel = (a.rel || '').toLowerCase().split(/\s+/);
    if (rel.includes('canonical') && a.href && !canonical) {
      try { canonical = new URL(a.href, pageUrl).href; } catch {}
    }
    if (rel.includes('alternate') && a.hreflang && a.href) {
      try { hreflangs.push({lang:a.hreflang.toLowerCase(), href:new URL(a.href, pageUrl).href}); } catch {}
    }
  }
  for (const m of html.matchAll(/<meta\b[^>]*>/gi)) {
    const a = attrs(m[0]);
    const name = (a.name || '').toLowerCase();
    const prop = (a.property || '').toLowerCase();
    if (name === 'robots' && !robots) robots = a.content || '';
    if (name === 'description' && !metaDescription) metaDescription = a.content || '';
    if (prop === 'og:url' && !ogUrl) {
      try { ogUrl = new URL(a.content || '', pageUrl).href; } catch {}
    }
  }
  for (const m of html.matchAll(/<a\b[^>]*>/gi)) {
    const a = attrs(m[0]);
    if (!a.href) continue;
    const raw = a.href.trim();
    if (!raw || raw.startsWith('#') || /^(mailto:|tel:|javascript:|data:)/i.test(raw)) continue;
    try {
      const u = new URL(raw, pageUrl);
      u.hash = '';
      if (u.hostname === 'doctorcura.com' || u.hostname === 'www.doctorcura.com') links.push(u.href);
    } catch {}
  }
  const text = strip(html);
  return {
    canonical, robots, hreflangs, htmlLang, title, h1Count, metaDescription, ogUrl,
    textHash: sha(text), textLength: text.length, internalLinks: [...new Set(links)]
  };
}

async function oneRequest(url) {
  const res = await fetch(url, {
    method: 'GET', redirect: 'manual',
    headers: {'user-agent': UA, 'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'cache-control':'no-cache'},
    signal: AbortSignal.timeout(SAFE_TIMEOUT_MS)
  });
  const headers = Object.fromEntries([...res.headers.entries()].map(([k,v]) => [k.toLowerCase(),v]));
  let body = '';
  const ct = headers['content-type'] || '';
  if (!res.status || res.status < 300 || res.status >= 400 || !headers.location) {
    if (/text|html|xml|json/i.test(ct)) body = await res.text();
    else { try { await res.body?.cancel(); } catch {} }
  } else { try { await res.body?.cancel(); } catch {} }
  return {status:res.status, headers, body};
}

async function auditUrl(startUrl) {
  const chain = [];
  const seen = new Set();
  let url = startUrl;
  let finalResp = null;
  try {
    for (let hop=0; hop<9; hop++) {
      if (seen.has(url)) return {url:startUrl, error:'redirect_loop', chain};
      seen.add(url);
      const r = await oneRequest(url);
      chain.push({url,status:r.status,location:r.headers.location || ''});
      if (r.status >= 300 && r.status < 400 && r.headers.location) {
        url = new URL(r.headers.location, url).href;
        continue;
      }
      finalResp = r;
      break;
    }
    if (!finalResp) return {url:startUrl, error:'redirect_chain_too_long', chain};
    const first = chain[0];
    const final = chain.at(-1);
    const ct = finalResp.headers['content-type'] || '';
    const xRobots = finalResp.headers['x-robots-tag'] || '';
    let parsed = {};
    if (/html/i.test(ct)) parsed = parseHtml(finalResp.body, final.url);
    return {
      url:startUrl,
      firstStatus:first.status,
      firstLocation:first.location,
      finalStatus:final.status,
      finalUrl:final.url,
      redirects:chain.length - 1,
      chain,
      contentType:ct,
      xRobots,
      bodyBytes:Buffer.byteLength(finalResp.body || ''),
      ...parsed
    };
  } catch (e) {
    return {url:startUrl, error:String(e?.name || 'Error') + ':' + String(e?.message || e), chain};
  }
}

async function mapLimit(items, limit, fn) {
  const arr = [...items];
  const out = new Array(arr.length);
  let next = 0;
  async function worker() {
    while (true) {
      const i = next++;
      if (i >= arr.length) return;
      out[i] = await fn(arr[i], i);
      if ((i+1) % 50 === 0) console.log(`PROGRESS ${i+1}/${arr.length}`);
    }
  }
  await Promise.all(Array.from({length:Math.min(limit,arr.length)}, worker));
  return out;
}

function addVariants(set, url) {
  try {
    const u = new URL(url);
    if (u.hostname !== 'doctorcura.com' || u.search) return;
    if (/^\/(?:fr|en)(?:\/|$)/.test(u.pathname)) return;
    if (/\.(?:xml|txt|json)$/i.test(u.pathname)) return;
    const path = u.pathname === '/' ? '/' : u.pathname;
    for (const lang of ['fr','en']) {
      const v = new URL(BASE);
      v.pathname = `/${lang}${path}`.replace(/\/+/g,'/');
      if (path === '/') v.pathname = `/${lang}/`;
      set.add(v.href);
    }
  } catch {}
}

function safeInternalCandidate(url) {
  try {
    const u = new URL(url);
    if (!['doctorcura.com','www.doctorcura.com'].includes(u.hostname)) return false;
    if (u.search) return false;
    if (/\/(?:wp-admin|wp-login\.php|cart|checkout|warenkorb|zur-kasse|mein-konto\/|order-pay|order-received)(?:\/|$)/i.test(u.pathname)) return false;
    if (/\.(?:jpg|jpeg|png|gif|webp|svg|avif|css|js|woff2?|ttf|eot|pdf|zip|xml|txt|json)$/i.test(u.pathname)) return false;
    if (/^\/wp-content\//.test(u.pathname)) return false;
    return true;
  } catch { return false; }
}

const anomalies = [];
const info = [];
const addAnom = (sev,kind,url,detail='') => anomalies.push({severity:sev,kind,url,detail});
const addInfo = (kind,url,detail='') => info.push({kind,url,detail});

// 1) Discover current XML sitemap truth.
const smIndex = await auditUrl(`${BASE}/sitemap_index.xml`);
if (smIndex.finalStatus !== 200 || !/xml/i.test(smIndex.contentType || '')) throw new Error('sitemap_index.xml unavailable');
const sitemapLocs = [...smIndex.body?.matchAll?.(/<loc>([^<]+)<\/loc>/gi) || []].map(m=>decode(m[1].trim()));
// auditUrl intentionally does not expose body; refetch XML only for discovery.
const smIndexRaw = await oneRequest(`${BASE}/sitemap_index.xml`);
const childSitemaps = [...smIndexRaw.body.matchAll(/<loc>([^<]+)<\/loc>/gi)].map(m=>decode(m[1].trim()));
const sitemapEntries = new Set();
const sitemapEntrySources = new Map();
for (const sm of childSitemaps) {
  const r = await oneRequest(sm);
  if (r.status !== 200) { addAnom('HIGH','sitemap_child_status',sm,`status=${r.status}`); continue; }
  for (const m of r.body.matchAll(/<loc>([^<]+)<\/loc>/gi)) {
    const u = decode(m[1].trim());
    sitemapEntries.add(u);
    if (!sitemapEntrySources.has(u)) sitemapEntrySources.set(u, []);
    sitemapEntrySources.get(u).push(sm);
  }
}

const universe = new Set(sitemapEntries);
for (const u of sitemapEntries) addVariants(universe,u);

const gscUrls = fs.readFileSync(GSC_FILE,'utf8').split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
for (const u of gscUrls) universe.add(u);

// Current category truth + legacy spelling matrix.
const categorySlugs = [];
for (const u of sitemapEntries) {
  try {
    const p = new URL(u).pathname;
    const m = p.match(/^\/product-category\/(.+?)\/$/);
    if (m) categorySlugs.push(m[1]);
  } catch {}
}
for (const slug of [...new Set(categorySlugs)]) {
  for (const lang of ['', 'fr/', 'en/']) {
    universe.add(`${BASE}/${lang}product-category/${slug}/`);
    universe.add(`${BASE}/${lang}product-categorie/${slug}/`);
    universe.add(`${BASE}/${lang}product-category/${slug}/feed/`);
  }
}
// Legacy slug whose current target slug changed.
for (const lang of ['', 'fr/', 'en/']) universe.add(`${BASE}/${lang}product-categorie/sexual-problems/`);

// Pagination matrices.
for (let n=1; n<=20; n++) {
  for (const lang of ['', 'fr/', 'en/']) {
    universe.add(`${BASE}/${lang}blogs/${n}/`);
    universe.add(`${BASE}/${lang}blogs/page/${n}/`);
  }
}
for (let n=1; n<=5; n++) {
  for (const lang of ['', 'fr/', 'en/']) {
    universe.add(`${BASE}/${lang}shop/page/${n}/`);
    universe.add(`${BASE}/${lang}product-category/alle-medikamente/page/${n}/`);
  }
}

// Technical/indexation and canonical scenarios. GET only; no payment action endpoints.
[
  '/robots.txt','/feed/','/comments/feed/','/shop/feed/','/wp-json/','/wp-json/wp/v2/','/sitemap','/sitemap/','/sitemap_index.xml','/sitemap_index.xml/',
  '/about-us/','/de/','/de/about-us/','/category/blogs/',
  '/does-not-exist-deep-seo-audit-9f42/','/product/does-not-exist-deep-seo-audit-9f42/','/product-category/does-not-exist-deep-seo-audit-9f42/','/product-categorie/does-not-exist-deep-seo-audit-9f42/',
  '/fr/does-not-exist-deep-seo-audit-9f42/','/en/does-not-exist-deep-seo-audit-9f42/'
].forEach(p=>universe.add(BASE+p));
['http://doctorcura.com/','http://www.doctorcura.com/','https://www.doctorcura.com/','http://doctorcura.com/fr','http://doctorcura.com/en'].forEach(u=>universe.add(u));

const epipenQ='?attribute_pa_medication-strength=300mcg&attribute_pa_amount=3&attribute_pa_medication=epipen';
for (const lang of ['', 'fr/', 'en/']) universe.add(`${BASE}/${lang}product/epipen/${epipenQ}`);
universe.add(`${BASE}/product/epipen/?utm_source=deep-seo-audit`);
universe.add(`${BASE}/mounjaro-nebenwirkungen-risiken/?utm_source=deep-seo-audit`);
universe.add(`${BASE}/shop/?orderby=price`);
universe.add(`${BASE}/?s=viagra`);
universe.add(`${BASE}/fr/product/cialis/`);
universe.add(`${BASE}/en/product/cialis/`);
universe.add(`${BASE}/product/cialis/`);

console.log(`DISCOVERY child_sitemaps=${childSitemaps.length} sitemap_entries=${sitemapEntries.size} category_slugs=${new Set(categorySlugs).size} initial_universe=${universe.size} gsc_urls=${gscUrls.length}`);

// 2) First wave.
let results = await mapLimit([...universe], 12, auditUrl);
let resultMap = new Map(results.map(r=>[r.url,r]));

// 3) Discover safe internal HTML routes from current sitemap pages and translated variants, then second wave.
const discovered = new Set();
for (const r of results) {
  if (r.finalStatus !== 200 || !Array.isArray(r.internalLinks)) continue;
  for (const link of r.internalLinks) if (safeInternalCandidate(link) && !resultMap.has(link)) discovered.add(link);
}
const discoveredList = [...discovered].slice(0,5000);
console.log(`DISCOVERY internal_safe_new=${discovered.size} auditing=${discoveredList.length}`);
if (discoveredList.length) {
  const more = await mapLimit(discoveredList, 12, auditUrl);
  results = results.concat(more);
  for (const r of more) resultMap.set(r.url,r);
}

// Helpers for classification.
const normNoHash = (u) => { try { const x=new URL(u); x.hash=''; return x.href; } catch { return u; } };
const indexable = (r) => r.finalStatus===200 && !`${r.robots||''} ${r.xRobots||''}`.toLowerCase().includes('noindex');
const selfCanonical = (r) => r.canonical && normNoHash(r.canonical)===normNoHash(r.finalUrl);

// 4) Sitemap invariants.
for (const u of sitemapEntries) {
  const r = resultMap.get(u);
  if (!r || r.error) { addAnom('HIGH','sitemap_url_unreachable',u,r?.error||'missing result'); continue; }
  if (r.firstStatus!==200 || r.redirects!==0) addAnom('HIGH','sitemap_url_redirect_or_status',u,`first=${r.firstStatus} redirects=${r.redirects} final=${r.finalStatus}`);
  if (r.finalStatus!==200) addAnom('HIGH','sitemap_url_non200',u,`final=${r.finalStatus}`);
  if (/html/i.test(r.contentType||'')) {
    if (!indexable(r)) addAnom('HIGH','sitemap_url_noindex',u,`robots=${r.robots||''} xrobots=${r.xRobots||''}`);
    if (!selfCanonical(r)) addAnom('HIGH','sitemap_url_canonical',u,`canonical=${r.canonical||''} final=${r.finalUrl}`);
    if (!r.title) addAnom('MEDIUM','missing_title',u,'sitemap HTML URL');
    if (r.h1Count===0) addAnom('MEDIUM','missing_h1',u,'sitemap HTML URL');
    if (!r.metaDescription) addAnom('MEDIUM','missing_meta_description',u,'sitemap HTML URL');
    if (r.ogUrl && normNoHash(r.ogUrl)!==normNoHash(r.canonical||r.finalUrl)) addAnom('LOW','og_url_canonical_mismatch',u,`og=${r.ogUrl} canonical=${r.canonical}`);
  }
  const p = new URL(u).pathname;
  if (p.includes('/product-categorie/')) addAnom('HIGH','legacy_product_categorie_in_sitemap',u,'');
  if (/^\/de\//.test(p) || p==='/de/') addAnom('HIGH','legacy_de_in_sitemap',u,'');
  if (new URL(u).search) addAnom('HIGH','query_url_in_sitemap',u,'');
}

// Sitemap duplicate membership.
for (const [u,sms] of sitemapEntrySources) if (sms.length>1) addAnom('LOW','url_in_multiple_sitemaps',u,sms.join(','));

// 5) Current product-category matrix: base + FR + EN must be direct indexable self-canonical.
for (const slug of [...new Set(categorySlugs)]) {
  for (const lang of ['', 'fr/', 'en/']) {
    const u=`${BASE}/${lang}product-category/${slug}/`;
    const r=resultMap.get(u);
    if (!r || r.error || r.firstStatus!==200 || r.redirects!==0 || !indexable(r) || !selfCanonical(r)) {
      addAnom('HIGH','current_product_category_invalid',u,JSON.stringify({error:r?.error,first:r?.firstStatus,final:r?.finalStatus,redirects:r?.redirects,canonical:r?.canonical,robots:r?.robots,xrobots:r?.xRobots}));
    }
  }
}

// 6) Known legacy migrations exact behavior.
const knownLegacy = new Map([
  [`${BASE}/product-categorie/sexual-problems/`, `${BASE}/product-category/sexuelle-probleme/`],
  [`${BASE}/fr/product-categorie/sexual-problems/`, `${BASE}/fr/product-category/sexuelle-probleme/`],
  [`${BASE}/en/product-categorie/sexual-problems/`, `${BASE}/en/product-category/sexuelle-probleme/`],
  [`${BASE}/product-categorie/menstruation-verschieben/`, `${BASE}/product-category/menstruation-verschieben/`],
  [`${BASE}/fr/product-categorie/menstruation-verschieben/`, `${BASE}/fr/product-category/menstruation-verschieben/`],
  [`${BASE}/en/product-categorie/menstruation-verschieben/`, `${BASE}/en/product-category/menstruation-verschieben/`],
  [`${BASE}/about-us/`, `${BASE}/uber-uns/`]
]);
for (const [u,target] of knownLegacy) {
  const r=resultMap.get(u);
  if (!r || r.firstStatus!==301 || r.finalStatus!==200 || r.finalUrl!==target || !selfCanonical(r)) addAnom('HIGH','known_legacy_migration_invalid',u,JSON.stringify({first:r?.firstStatus,loc:r?.firstLocation,final:r?.finalUrl,status:r?.finalStatus,canonical:r?.canonical,redirects:r?.redirects,expected:target}));
}

// Discover any other product-categorie behavior, but don't call a never-known spelling an error solely because it 404s.
for (const r of results) {
  if (!r.url.includes('/product-categorie/')) continue;
  if (knownLegacy.has(r.url)) continue;
  if (r.firstStatus===200 && indexable(r)) addAnom('HIGH','unexpected_indexable_product_categorie',r.url,`canonical=${r.canonical||''}`);
  else if (r.firstStatus>=300 && r.firstStatus<400) addInfo('other_product_categorie_redirect',r.url,`status=${r.firstStatus} location=${r.firstLocation} final=${r.finalUrl}`);
}

// 7) GSC historical URL revalidation.
const gscSet = new Set(gscUrls);
for (const u of gscUrls) {
  const r=resultMap.get(u);
  if (!r || r.error) { addAnom('HIGH','historical_gsc_url_unreachable',u,r?.error||''); continue; }
  const p=new URL(u).pathname;
  const expectedTechnical = /\/feed\/$/.test(p) || p==='/comments/feed/';
  if (expectedTechnical) {
    if (r.finalStatus!==200 || !`${r.robots||''} ${r.xRobots||''}`.toLowerCase().includes('noindex')) addAnom('MEDIUM','historical_gsc_feed_indexability',u,`status=${r.finalStatus} robots=${r.robots||''} xrobots=${r.xRobots||''}`);
    continue;
  }
  if (u===`${BASE}/sitemap` || u===`${BASE}/sitemap_index.xml` || u===`${BASE}/sitemap_index.xml/`) continue;
  if (r.finalStatus===404) addAnom('MEDIUM','historical_gsc_url_now_404',u,'needs intentional-removal vs migration review');
  if (r.finalStatus===200 && /html/i.test(r.contentType||'') && indexable(r) && r.canonical && !selfCanonical(r)) addInfo('historical_gsc_noncanonical',u,`canonical=${r.canonical}`);
}

// 8) Parameter and filtered URLs.
for (const lang of ['', 'fr/', 'en/']) {
  const u=`${BASE}/${lang}product/epipen/${epipenQ}`;
  const r=resultMap.get(u);
  const target=`${BASE}/${lang}product/epipen/`;
  if (!r || r.finalStatus!==200 || r.canonical!==target) addAnom('HIGH','variation_canonical_invalid',u,`status=${r?.finalStatus} canonical=${r?.canonical} expected=${target}`);
  if (Array.isArray(r?.hreflangs)) {
    const paramAlts=r.hreflangs.filter(h=>new URL(h.href).search);
    if (paramAlts.length) addAnom('MEDIUM','variation_hreflang_contains_parameters',u,paramAlts.map(x=>`${x.lang}:${x.href}`).join(' | '));
  }
}
for (const [u,target] of [[`${BASE}/product/epipen/?utm_source=deep-seo-audit`,`${BASE}/product/epipen/`],[`${BASE}/mounjaro-nebenwirkungen-risiken/?utm_source=deep-seo-audit`,`${BASE}/mounjaro-nebenwirkungen-risiken/`]]) {
  const r=resultMap.get(u); if (!r || r.finalStatus!==200 || r.canonical!==target) addAnom('MEDIUM','utm_canonical_invalid',u,`canonical=${r?.canonical||''} expected=${target}`);
}

// 9) Hreflang: indexable HTML URLs should not point to redirects/broken/noncanonical pages; return links required.
let hreflangPages=0, xDefaultMissing=0;
for (const r of results) {
  if (!indexable(r) || !Array.isArray(r.hreflangs) || !r.hreflangs.length || !/html/i.test(r.contentType||'')) continue;
  hreflangPages++;
  const own = r.hreflangs.find(h=>h.href===r.finalUrl);
  if (!own) addAnom('HIGH','hreflang_missing_self',r.url,`final=${r.finalUrl}`);
  if (!r.hreflangs.some(h=>h.lang==='x-default')) xDefaultMissing++;
  for (const h of r.hreflangs) {
    if (h.lang==='x-default') continue;
    let alt=resultMap.get(h.href);
    if (!alt && safeInternalCandidate(h.href)) {
      alt=await auditUrl(h.href); resultMap.set(h.href,alt); results.push(alt);
    }
    if (!alt || alt.error || alt.firstStatus!==200 || alt.redirects!==0 || alt.finalStatus!==200) {
      addAnom('HIGH','hreflang_redirect_or_broken',r.url,`${h.lang}=${h.href} first=${alt?.firstStatus} final=${alt?.finalStatus} redirects=${alt?.redirects} error=${alt?.error||''}`);
      continue;
    }
    if (alt.canonical && !selfCanonical(alt)) addAnom('HIGH','hreflang_to_noncanonical',r.url,`${h.lang}=${h.href} canonical=${alt.canonical}`);
    if (Array.isArray(alt.hreflangs) && !alt.hreflangs.some(back=>back.href===r.finalUrl)) addAnom('HIGH','hreflang_missing_return',r.url,`${h.lang}=${h.href}`);
  }
  const expectedLang = /^\/fr\//.test(new URL(r.finalUrl).pathname)?'fr':/^\/en\//.test(new URL(r.finalUrl).pathname)?'en':'de';
  if (r.htmlLang && r.htmlLang.toLowerCase().split('-')[0]!==expectedLang) addAnom('HIGH','html_lang_mismatch',r.url,`html=${r.htmlLang} expected=${expectedLang}`);
}
addInfo('x_default_missing_is_recommendation_not_required',BASE,`pages_without_xdefault=${xDefaultMissing}/${hreflangPages}`);

// 10) Internal link hygiene from audited indexable HTML pages.
const checkedLinkTargets = new Set();
for (const r of results) {
  if (!indexable(r) || !Array.isArray(r.internalLinks)) continue;
  for (const link of r.internalLinks) {
    let u; try { u=new URL(link); } catch { continue; }
    if (u.protocol==='http:') addAnom('MEDIUM','internal_http_link',r.url,link);
    if (u.hostname==='www.doctorcura.com') addAnom('MEDIUM','internal_www_link',r.url,link);
    if (u.pathname.includes('/product-categorie/')) addAnom('HIGH','internal_legacy_product_categorie_link',r.url,link);
    if (/^\/de(?:\/|$)/.test(u.pathname)) addAnom('MEDIUM','internal_legacy_de_link',r.url,link);
    if (u.href===`${BASE}/fr` || u.href===`${BASE}/en`) addAnom('LOW','internal_language_home_redirect_link',r.url,link);
    if (/\/page\/1\/$/.test(u.pathname)) addAnom('LOW','internal_page1_redirect_link',r.url,link);
    if (!safeInternalCandidate(link) || checkedLinkTargets.has(link)) continue;
    checkedLinkTargets.add(link);
    const t=resultMap.get(link);
    if (t?.firstStatus>=300 && t.firstStatus<400) addAnom('LOW','internal_link_to_redirect',r.url,`${link} -> ${t.firstStatus} ${t.firstLocation}`);
    if (t?.finalStatus>=400) addAnom('HIGH','internal_link_to_broken_page',r.url,`${link} final=${t.finalStatus}`);
  }
}

// 11) Pagination explicit assertions.
for (const lang of ['', 'fr/', 'en/']) {
  const prefix=`${BASE}/${lang}`;
  const p16=resultMap.get(`${prefix}blogs/16/`);
  const p17=resultMap.get(`${prefix}blogs/17/`);
  if (!p16 || p16.firstStatus!==200 || !selfCanonical(p16)) addAnom('HIGH','blog_last_page_invalid',`${prefix}blogs/16/`,JSON.stringify(p16||{}));
  if (!p17 || p17.firstStatus!==404 || p17.redirects!==0) addAnom('HIGH','blog_out_of_range_not_true_404',`${prefix}blogs/17/`,JSON.stringify({first:p17?.firstStatus,final:p17?.finalStatus,redirects:p17?.redirects,finalUrl:p17?.finalUrl}));
  for (const n of [2,5,8,16]) {
    const alias=resultMap.get(`${prefix}blogs/page/${n}/`);
    const expected=`${prefix}blogs/${n}/`;
    if (!alias || alias.firstStatus!==301 || alias.finalUrl!==expected || alias.finalStatus!==200) addAnom('MEDIUM','blog_page_alias_invalid',`${prefix}blogs/page/${n}/`,`first=${alias?.firstStatus} final=${alias?.finalUrl} expected=${expected}`);
  }
}

// 12) True 404 scenarios must not soft-redirect.
for (const u of [`${BASE}/does-not-exist-deep-seo-audit-9f42/`,`${BASE}/product/does-not-exist-deep-seo-audit-9f42/`,`${BASE}/product-category/does-not-exist-deep-seo-audit-9f42/`,`${BASE}/product-categorie/does-not-exist-deep-seo-audit-9f42/`,`${BASE}/fr/does-not-exist-deep-seo-audit-9f42/`,`${BASE}/en/does-not-exist-deep-seo-audit-9f42/`]) {
  const r=resultMap.get(u); if (!r || r.firstStatus!==404 || r.redirects!==0 || r.finalStatus!==404) addAnom('HIGH','random_404_invalid',u,JSON.stringify({first:r?.firstStatus,final:r?.finalStatus,redirects:r?.redirects,finalUrl:r?.finalUrl}));
}

// 13) Redirect chain hygiene on known host/language normalization.
for (const u of ['http://doctorcura.com/','http://www.doctorcura.com/','https://www.doctorcura.com/','http://doctorcura.com/fr','http://doctorcura.com/en',`${BASE}/de/about-us/`]) {
  const r=resultMap.get(u); if (r?.redirects>1) addAnom('LOW','redirect_chain',u,r.chain.map(x=>`${x.status}:${x.url}`).join(' -> '));
}

// De-duplicate identical anomaly records for useful output.
const uniq = new Map();
for (const a of anomalies) uniq.set(`${a.severity}\t${a.kind}\t${a.url}\t${a.detail}`,a);
const finalAnomalies=[...uniq.values()];
const sevCounts=finalAnomalies.reduce((o,a)=>(o[a.severity]=(o[a.severity]||0)+1,o),{});
const kindCounts=finalAnomalies.reduce((o,a)=>(o[a.kind]=(o[a.kind]||0)+1,o),{});

const summary = {
  generated_at:new Date().toISOString(),
  base:BASE,
  child_sitemaps:childSitemaps,
  sitemap_entries:sitemapEntries.size,
  current_product_category_slugs:new Set(categorySlugs).size,
  historical_gsc_urls:gscUrls.length,
  audited_urls:results.length,
  unique_result_urls:resultMap.size,
  hreflang_pages_checked:hreflangPages,
  xdefault_missing_pages:xDefaultMissing,
  anomalies:finalAnomalies.length,
  severity_counts:sevCounts,
  anomaly_kind_counts:kindCounts,
  info_count:info.length
};
fs.writeFileSync(OUT_JSON, JSON.stringify({summary, anomalies:finalAnomalies, info, results},null,2));
fs.writeFileSync(OUT_ANOM, ['severity\tkind\turl\tdetail',...finalAnomalies.map(a=>[a.severity,a.kind,a.url,a.detail].map(x=>String(x??'').replace(/[\t\r\n]+/g,' ')).join('\t'))].join('\n')+'\n');
const lines=[
  `GENERATED=${summary.generated_at}`,
  `CHILD_SITEMAPS=${childSitemaps.length}`,
  `SITEMAP_ENTRIES=${sitemapEntries.size}`,
  `CATEGORY_SLUGS=${summary.current_product_category_slugs}`,
  `HISTORICAL_GSC_URLS=${gscUrls.length}`,
  `AUDITED_URLS=${results.length}`,
  `UNIQUE_RESULT_URLS=${resultMap.size}`,
  `HREFLANG_PAGES=${hreflangPages}`,
  `XDEFAULT_MISSING_INFO=${xDefaultMissing}`,
  `ANOMALIES=${finalAnomalies.length}`,
  `SEVERITY=${JSON.stringify(sevCounts)}`,
  `KINDS=${JSON.stringify(kindCounts)}`,
  '',
  'TOP_ANOMALIES:',
  ...finalAnomalies.slice(0,300).map(a=>`${a.severity}\t${a.kind}\t${a.url}\t${a.detail}`)
];
fs.writeFileSync(OUT_SUMMARY,lines.join('\n')+'\n');
console.log(lines.join('\n'));
