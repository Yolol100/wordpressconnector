# DoctorCura SEO repair execution plan — 2026-09-11

Status: EXECUTING
Target: https://doctorcura.com
Base branch/SHA: main @ 40152a89de1c5f4b7cf9572c74415aef2595e1f6
Owners: SEO acceptance = `seo`; WordPress implementation = `wordpressqualityarchitect`; process/closure = `webactueel-workflow`
Project SEO source-set: `2.6.29-pagination-clean-url-validation`

## Prompt strategy

**Technique:** Outcome-First + Plan-and-Execute + Verify/Readback + Phase Gates.

This is a production, multi-system repair. Execution is phased so every write is preceded by a current-state check and followed by exact readback. Previously green scope is frozen unless a fresh failure proves it must change.

## Operational prompt

> Bring DoctorCura's remaining agreed SEO technical issues to a verified clean state. Work only on the agreed scope: (1) replace the explicit HTTP link on WordPress page 11 at its actual Elementor source with the final HTTPS URL; (2) retest GTranslate-generated `/en` and `/fr` root links after the user cleared the GTranslate cache, but do not change GTranslate settings or generated output; (3) collapse `/de/about-us/` to one direct permanent redirect to `/uber-uns/`; (4) restore or replace the single broken image at the actual source so every affected page uses a valid 200 media asset; and (5) retest the touched scope plus sitemap, canonical and hreflang behavior. Preserve all unrelated content and behavior. Before every write, capture the live baseline, current fingerprint/checksum where available, exact mutation surface and rollback path. Use the smallest reversible change. Never edit `_elementor_data` directly; use Elementor's document API. After every write, perform connector readback and independent live HTTP/HTML verification. Use Ahrefs only for Ahrefs-owned crawl evidence and GSC only for Google-owned search/index evidence. Stop rather than guess when the current authenticated capability cannot prove or safely perform a required mutation.

## Success criteria

1. German terms page no longer contains an explicit `http://` internal link at the source.
2. GTranslate `/en` and `/fr` behavior is rechecked after cache clear; no GTranslate change is made unless a new, separately approved task proves it is required.
3. `/de/about-us/` reaches `https://doctorcura.com/uber-uns/` with one permanent redirect and no intermediate `/about-us/` hop.
4. The previously broken image URL returns 200, or all affected source references are moved to a verified 200 replacement.
5. Touched URLs have expected status, final URL, canonical and hreflang behavior; sitemap remains valid and no new 4xx/5xx/redirect-loop regression is introduced.
6. Every real mutation has rollback evidence and post-write readback.

## Anti-goals / frozen scope

- Do not change GTranslate settings in this run.
- Do not add hundreds of Ahrefs-discovered URLs to XML sitemaps automatically.
- Do not change normal WooCommerce empty-cart checkout redirects.
- Do not edit `_elementor_data` or protected WordPress metadata directly.
- Do not broaden into content, design, performance, plugin upgrades or unrelated redirects.
- Do not merge this target-specific plan or runtime evidence into `main`.

## Evidence hierarchy

1. WordPress/Elementor source readback from the authenticated DoctorCura runtime.
2. Independent live HTTP/HTML verification on doctorcura.com.
3. Ahrefs Site Audit project 9289481 for crawl issues and before/after issue evidence.
4. Google Search Console for Google-owned index/search state.
5. Repository source/CI only for implementation and transport contracts, not production-state claims.

## Phase gates

### Phase A — Preflight and source ownership
- Confirm repository base SHA and repository hygiene.
- Confirm the current source owner of each issue.
- Confirm a mutation-capable authenticated DoctorCura runtime before any production write.
- Capture rollback information before every mutation.

### Phase B — Fresh diagnosis
- Retest `/en`, `/en/`, `/fr`, `/fr/` after the GTranslate cache clear.
- Re-resolve Ahrefs broken-image resource and all affected referring pages.
- Re-resolve the `/de/about-us/` redirect chain.
- Recheck page 11 for the exact explicit HTTP link and identify the Elementor element/source.

Gate: only continue to writes for issues still reproduced now.

### Phase C — Minimal repairs
- Issue 1: patch only the offending href in the existing Elementor element using the Elementor document API.
- Issue 3: change only the redirect owner needed to make `/de/about-us/` a direct permanent redirect to `/uber-uns/`.
- Issue 4: restore the existing image if the media attachment/file is recoverable; otherwise replace the reference at its true source with a verified 200 asset. Do not patch six pages independently if one shared source owns the reference.

Gate: each write must pass dry-run/current-state guard, confirmed mutation, exact readback and live verification before the next write.

### Phase D — Targeted retest
- Verify status/redirect chain for all touched URLs.
- Verify page 11 href output.
- Verify broken-image URL/reference state on all affected pages.
- Verify relevant canonical/hreflang output.
- Verify the sitemap index still parses and contains only intended canonical URLs.

### Phase E — Closure
- Compare before/after evidence.
- Mark each scope item FIXED, NO_CHANGE, GOOGLE_RECRAWL_PENDING or BLOCKED.
- Keep this branch unmerged; preserve only necessary evidence, then archive/neutralize or delete the runtime branch after closure.

## Rollback

- Elementor/content write: connector rollback snapshot/request ID plus exact pre-write fingerprint.
- Redirect write: restore the prior redirect rule/snippet state from the pre-write snapshot.
- Media write: restore prior attachment/reference assignment or original file/reference state.
- If readback or live verification fails, rollback immediately and stop that phase.

## Execution log

| Item | Initial state | Action | Verification | Status |
|---|---|---|---|---|
| 1. Explicit HTTP link | Ahrefs previously found it on `/geschaftsbedingungen/`; source identified as Elementor page 11 | Fresh source/live verification, then minimal Elementor patch if still present | Elementor readback + live HTML + Ahrefs retest | RUNNING |
| 2. GTranslate roots | User cleared GTranslate cache | Retest only; no GTranslate write | `/en`, `/en/`, `/fr`, `/fr/` HTTP/final URL | RUNNING |
| 3. `/de/about-us/` chain | Previously two hops via `/about-us/` | Identify redirect owner; collapse if current mutation capability permits | HTTP chain ends at `/uber-uns/` in one permanent hop | RUNNING |
| 4. Broken image | Ahrefs crawl reported one resource affecting six pages | Re-resolve current resource/source; repair only if still broken | Image HTTP 200 + all affected-page readback | RUNNING |
| 5. Retest/closure | Pending | Run after 1–4 | targeted crawl + GSC/Ahrefs/live comparison | PENDING |
