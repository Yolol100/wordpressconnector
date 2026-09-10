#!/usr/bin/env bash
set -euo pipefail

base="${SITE_URL%/}"
[[ "$base" == 'https://doctorcura.com' ]] || { echo "FAIL unexpected site=$base"; exit 2; }
test -n "${REST_USERNAME:-}" && test -n "${REST_APP_PASSWORD:-}"

expected_snippet='939d9bc10b33e053c1c668b58f13491b2ac6a87c9673bd9ae76ade739bc77c40'
expected_page11='b8c1496e5bc666df6242fadd55a4bfc427da6234033d4366303fc4f2acbb428e'
old_http='http://www.doctorcura.com'
var_q='attribute_pa_medication-strength=300mcg&attribute_pa_amount=3&attribute_pa_medication=epipen'

c=(--silent --show-error --connect-timeout 10 --max-time 45 --retry 3 --retry-delay 2 --retry-all-errors --compressed --header 'Cache-Control: no-cache')
auth=(--user "${REST_USERNAME}:${REST_APP_PASSWORD}" --header 'Accept: application/json')
tmp="$RUNNER_TEMP/doctorcura-phase6-qa"; mkdir -p "$tmp"

contract_sha=$(sha256sum "$0" | awk '{print $1}')
echo "QA_CONTRACT_SHA=$contract_sha"
echo "QA_SITE=$base"

# Manual first-hop request. A zero-byte 200 from the translation proxy is an infrastructure-only attempt;
# retain it in the log and retry serially rather than accepting it as product behavior.
manual(){
  local url="$1" stem="$2" attempt status h b bytes
  for attempt in 1 2 3; do
    h="$tmp/$stem-$attempt.h"; b="$tmp/$stem-$attempt.body"
    status=$(curl "${c[@]}" --max-redirs 0 --dump-header "$h" --output "$b" --write-out '%{http_code}' "$url" || true)
    bytes=$(wc -c < "$b" | tr -d ' ')
    if [[ "$status" == 200 && "$bytes" == 0 ]]; then
      echo "INFRA_EMPTY200 url=$url attempt=$attempt" >&2
      sleep 1
      continue
    fi
    printf '%s\t%s\t%s\t%s\n' "$status" "$h" "$b" "$bytes"
    return 0
  done
  echo "FAIL persistent empty 200 url=$url" >&2; return 1
}

location_of(){ awk 'BEGIN{IGNORECASE=1}/^location:/{sub(/^[^:]+:[[:space:]]*/,"");gsub(/\r/,"");print;exit}' "$1"; }
canonical_of(){ python3 - "$1" <<'PY'
import html,re,sys
s=open(sys.argv[1],encoding='utf-8',errors='ignore').read()
for t in re.findall(r'<link\b[^>]*>',s,re.I):
    if re.search(r'\brel=["\'][^"\']*canonical[^"\']*["\']',t,re.I):
        m=re.search(r'\bhref=["\']([^"\']+)',t,re.I)
        if m: print(html.unescape(m.group(1)),end=''); break
PY
}
robots_of(){ python3 - "$1" <<'PY'
import html,re,sys
s=open(sys.argv[1],encoding='utf-8',errors='ignore').read()
for t in re.findall(r'<meta\b[^>]*>',s,re.I):
    n=re.search(r'\bname=["\']([^"\']+)',t,re.I)
    if n and n.group(1).lower()=='robots':
        c=re.search(r'\bcontent=["\']([^"\']*)',t,re.I)
        if c: print(html.unescape(c.group(1)),end='')
        break
PY
}
assert_direct_self(){
  local url="$1" stem="$2" row status h b bytes can
  row=$(manual "$url" "$stem"); IFS=$'\t' read -r status h b bytes <<< "$row"
  [[ "$status" == 200 ]] || { echo "FAIL direct200 url=$url status=$status"; return 1; }
  can=$(canonical_of "$b")
  [[ "$can" == "$url" ]] || { echo "FAIL selfcanonical url=$url canonical=$can"; return 1; }
}
assert_true_404(){
  local url="$1" stem="$2" row status h b bytes loc rob
  row=$(manual "$url" "$stem"); IFS=$'\t' read -r status h b bytes <<< "$row"
  loc=$(location_of "$h"); rob=$(robots_of "$b")
  [[ "$status" == 404 && -z "$loc" ]] || { echo "FAIL true404 url=$url status=$status location=$loc"; return 1; }
  [[ "${rob,,}" == *noindex* ]] || { echo "FAIL 404-noindex url=$url robots=$rob"; return 1; }
}
assert_onehop(){
  local src="$1" dst="$2" stem="$3" row status h b bytes loc
  row=$(manual "$src" "$stem-src"); IFS=$'\t' read -r status h b bytes <<< "$row"; loc=$(location_of "$h")
  [[ "$status" == 301 && "$loc" == "$dst" ]] || { echo "FAIL onehop src=$src status=$status loc=$loc expected=$dst"; return 1; }
  assert_direct_self "$dst" "$stem-dst"
}

# 1. Production source/readback hashes and helper residue.
echo '=== SOURCE HASHES ==='
curl "${c[@]}" --location "${auth[@]}" "$base/wp-json/code-snippets/v1/snippets/43" > "$tmp/snippet43.json"
python3 - "$tmp/snippet43.json" "$expected_snippet" <<'PY'
import hashlib,json,sys
s=json.load(open(sys.argv[1],encoding='utf-8')); code=str(s.get('code') or ''); h=hashlib.sha256(code.encode()).hexdigest()
helper=code.count('DoctorCura one-time cache purge helper')
marker=code.count('DoctorCura product variation hreflang cleanup V8.4d')
print(f'SNIPPET43 hash={h} active={bool(s.get("active"))} helper={helper} hreflang_marker={marker}')
if s.get('id')!=43 or not s.get('active') or h!=sys.argv[2] or helper!=0 or marker!=1: raise SystemExit(1)
PY

curl "${c[@]}" --location "${auth[@]}" "$base/wp-json/wp/v2/pages/11?context=edit" > "$tmp/page11.json"
python3 - "$tmp/page11.json" "$expected_page11" "$old_http" <<'PY'
import hashlib,json,sys
p=json.load(open(sys.argv[1],encoding='utf-8')); c=p.get('content') or {}; raw=str(c.get('raw') or ''); rendered=str(c.get('rendered') or '')
h=hashlib.sha256(raw.encode()).hexdigest(); old=sys.argv[3]
print(f'PAGE11 status={p.get("status")} raw_hash={h} raw_old={raw.count(old)} rendered_old={rendered.count(old)}')
if p.get('status')!='publish' or h!=sys.argv[2] or raw.count(old)!=0 or rendered.count(old)!=0: raise SystemExit(1)
PY

# 2. Phase 2A duplicate article state.
echo '=== PHASE2A ==='
for id in 900067 900066; do curl "${c[@]}" --location "${auth[@]}" "$base/wp-json/wp/v2/posts/$id?context=edit" > "$tmp/post-$id.json"; done
python3 - "$tmp/post-900067.json" "$tmp/post-900066.json" <<'PY'
import json,sys
p=json.load(open(sys.argv[1])); d=json.load(open(sys.argv[2])); print(f'POSTS canonical={p.get("status")} duplicate={d.get("status")}')
if p.get('status')!='publish' or d.get('status')!='draft': raise SystemExit(1)
PY
dup="$base/welche-medikamente-beeinflussen-die-erektion-2/"
clean="$base/welche-medikamente-beeinflussen-die-erektion/"
assert_onehop "$dup" "$clean" dup-post

# 3A. Exact weight migration in all language namespaces.
echo '=== PHASE3A ==='
for lang in '' 'fr/' 'en/'; do
  assert_onehop "$base/${lang}product-categorie/gewichtsverlust/" "$base/${lang}product-category/gewichtsverlust/" "weight-${lang//\//_}"
done

# 3B. Root-only historical privacy migration.
echo '=== PHASE3B ==='
assert_onehop "$base/datenschutz/" "$base/privacy-policy/" datenschutz

# 3C. Public rendered terms in DE/FR/EN are free of the historical http://www link.
echo '=== PHASE3C ==='
for lang in '' 'fr/' 'en/'; do
  u="$base/${lang}geschaftsbedingungen/"; row=$(manual "$u" "terms-${lang//\//_}"); IFS=$'\t' read -r status h b bytes <<< "$row"
  [[ "$status" == 200 ]] || { echo "FAIL terms status url=$u status=$status"; exit 1; }
  count=$(python3 - "$b" "$old_http" <<'PY'
import sys
print(open(sys.argv[1],encoding='utf-8',errors='ignore').read().count(sys.argv[2]))
PY
)
  [[ "$count" == 0 ]] || { echo "FAIL terms old-http url=$u count=$count"; exit 1; }
  echo "TERMS_OK lang=${lang:-de} bytes=$bytes"
done

# 3D. WooCommerce variation requests retain visitor URL, canonicalize cleanly, and expose only query-free hreflangs.
echo '=== PHASE3D ==='
for lang in '' 'fr/' 'en/'; do
  cleanp="$base/${lang}product/epipen/"; u="${cleanp}?$var_q"; row=$(manual "$u" "epipen-${lang//\//_}"); IFS=$'\t' read -r status h b bytes <<< "$row"
  [[ "$status" == 200 ]] || { echo "FAIL epipen status url=$u status=$status"; exit 1; }
  can=$(canonical_of "$b"); [[ "$can" == "$cleanp" ]] || { echo "FAIL epipen canonical url=$u canonical=$can"; exit 1; }
  python3 - "$b" <<'PY'
import html,re,sys,urllib.parse
s=open(sys.argv[1],encoding='utf-8',errors='ignore').read(); alts=[]
for t in re.findall(r'<link\b[^>]*hreflang=[^>]*>',s,re.I):
    hm=re.search(r'href=["\']([^"\']+)',t,re.I); lm=re.search(r'hreflang=["\']([^"\']+)',t,re.I)
    if hm: alts.append((lm.group(1).lower() if lm else '',html.unescape(hm.group(1))))
print('EPIPEN_ALTS '+','.join(f'{l}:{u}' for l,u in alts))
if len(alts)!=3 or {l for l,_ in alts}!={'de','en','fr'}: raise SystemExit(1)
if any(urllib.parse.urlsplit(u).query for _,u in alts): raise SystemExit(1)
PY
done

# 4. Pagination boundary and aliases.
echo '=== PAGINATION ==='
for lang in '' 'fr/' 'en/'; do
  assert_direct_self "$base/${lang}blogs/16/" "blog16-${lang//\//_}"
  assert_true_404 "$base/${lang}blogs/17/" "blog17-${lang//\//_}"
  assert_onehop "$base/${lang}blogs/page/5/" "$base/${lang}blogs/5/" "blogalias-${lang//\//_}"
done

# 5. Previously frozen exact legacy mappings.
echo '=== LEGACY CANARIES ==='
for lang in '' 'fr/' 'en/'; do
  assert_onehop "$base/${lang}product-categorie/sexual-problems/" "$base/${lang}product-category/sexuelle-probleme/" "sexual-${lang//\//_}"
  assert_onehop "$base/${lang}product-categorie/menstruation-verschieben/" "$base/${lang}product-category/menstruation-verschieben/" "menstruation-${lang//\//_}"
done
assert_onehop "$base/about-us/" "$base/uber-uns/" about-us

# 6. All current category slugs from sitemap: root + FR + EN direct and self-canonical.
echo '=== CURRENT CATEGORY MATRIX ==='
curl "${c[@]}" --location "$base/sitemap_index.xml" > "$tmp/sitemap-index.xml"
python3 - "$tmp/sitemap-index.xml" > "$tmp/children.txt" <<'PY'
import html,re,sys
s=open(sys.argv[1],encoding='utf-8',errors='ignore').read()
for x in re.findall(r'<loc>([^<]+)</loc>',s,re.I): print(html.unescape(x.strip()))
PY
[[ $(wc -l < "$tmp/children.txt" | tr -d ' ') == 5 ]] || { echo 'FAIL child sitemap count'; exit 1; }
: > "$tmp/all-sitemap-urls.txt"
while IFS= read -r sm; do
  curl "${c[@]}" --location "$sm" > "$tmp/child.xml"
  python3 - "$tmp/child.xml" >> "$tmp/all-sitemap-urls.txt" <<'PY'
import html,re,sys
s=open(sys.argv[1],encoding='utf-8',errors='ignore').read()
for x in re.findall(r'<loc>([^<]+)</loc>',s,re.I): print(html.unescape(x.strip()))
PY
done < "$tmp/children.txt"
sort -u "$tmp/all-sitemap-urls.txt" > "$tmp/all-sitemap-urls-uniq.txt"
entries=$(wc -l < "$tmp/all-sitemap-urls-uniq.txt" | tr -d ' ')
[[ "$entries" == 176 ]] || { echo "FAIL sitemap entries=$entries"; exit 1; }
! grep -Fq '/product-categorie/' "$tmp/all-sitemap-urls-uniq.txt"
! grep -Eq '[?&]attribute_pa_|\?' "$tmp/all-sitemap-urls-uniq.txt"
! grep -Eq '^https://doctorcura\.com/de(/|$)' "$tmp/all-sitemap-urls-uniq.txt"
grep -E '^https://doctorcura\.com/product-category/[^/]+/$' "$tmp/all-sitemap-urls-uniq.txt" | sed -E 's#^https://doctorcura\.com/product-category/([^/]+)/$#\1#' | sort -u > "$tmp/category-slugs.txt"
[[ $(wc -l < "$tmp/category-slugs.txt" | tr -d ' ') == 22 ]] || { echo 'FAIL category slug count'; exit 1; }
while IFS= read -r slug; do
  for lang in '' 'fr/' 'en/'; do assert_direct_self "$base/${lang}product-category/$slug/" "cat-${lang//\//_}-$slug"; done
done < "$tmp/category-slugs.txt"
echo "CATEGORY_MATRIX_OK slugs=22 variants=66 sitemap_entries=$entries"

# 7. Random 404s remain true 404s, not homepage/soft redirects.
echo '=== TRUE 404 CANARIES ==='
assert_true_404 "$base/does-not-exist-phase6-9f42/" random-root
assert_true_404 "$base/fr/does-not-exist-phase6-9f42/" random-fr
assert_true_404 "$base/en/does-not-exist-phase6-9f42/" random-en
assert_true_404 "$base/product/does-not-exist-phase6-9f42/" random-product
assert_true_404 "$base/product-category/does-not-exist-phase6-9f42/" random-category
assert_true_404 "$base/product-categorie/does-not-exist-phase6-9f42/" random-legacy-category

# 8. Historical sitemap route state: old human sitemap path and current Yoast XML index are distinct and reachable.
echo '=== SITEMAP ROUTES ==='
row=$(manual "$base/sitemap" sitemap-old); IFS=$'\t' read -r status h b bytes <<< "$row"; loc=$(location_of "$h")
[[ "$status" == 301 && "$loc" == "$base/sitemap/" ]] || { echo "FAIL /sitemap state status=$status loc=$loc"; exit 1; }
assert_direct_self "$base/sitemap/" sitemap-html
row=$(manual "$base/sitemap_index.xml" sitemap-xml); IFS=$'\t' read -r status h b bytes <<< "$row"
[[ "$status" == 200 ]] || { echo 'FAIL sitemap_index status'; exit 1; }
grep -Eiq '^content-type:[[:space:]]*(text|application)/xml' "$h" || { echo 'FAIL sitemap_index content-type'; exit 1; }

# 9. Historical feed route reality: comments feed is a one-hop homepage redirect; root feed is XML and noindex via header.
echo '=== FEED ROUTES ==='
row=$(manual "$base/comments/feed/" comments-feed); IFS=$'\t' read -r status h b bytes <<< "$row"; loc=$(location_of "$h")
[[ "$status" == 301 && "$loc" == "$base" ]] || { echo "FAIL comments feed status=$status loc=$loc"; exit 1; }
row=$(manual "$base/feed/" root-feed); IFS=$'\t' read -r status h b bytes <<< "$row"
[[ "$status" == 200 ]] || { echo "FAIL root feed status=$status"; exit 1; }
grep -Eiq '^x-robots-tag:.*noindex' "$h" || { echo 'FAIL root feed xrobots'; exit 1; }

echo 'PHASE6_QA_GREEN'
echo "PHASE6_PRODUCTION_HASHES snippet=$expected_snippet page11=$expected_page11"
echo 'LIVE_GSC_STATUS=not_tested_in_this_runtime'
