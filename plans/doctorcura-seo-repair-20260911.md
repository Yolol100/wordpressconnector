# DoctorCura SEO repair execution plan — 2026-09-11

Status: PARTIAL — WORDPRESS SCOPE VERIFIED; ONE UPSTREAM REDIRECT BLOCKER
Target: https://doctorcura.com
Base branch/SHA: main @ 40152a89de1c5f4b7cf9572c74415aef2595e1f6
Owners: SEO acceptance = `seo`; WordPress implementation = `wordpressqualityarchitect`; process/closure = `webactueel-workflow`
Project SEO source-set: `2.6.29-pagination-clean-url-validation`

## Prompt strategy

Technique: Outcome-First + Plan-and-Execute + Verify/Readback + Phase Gates.

## Operational prompt

Bring DoctorCura's remaining agreed SEO technical issues to a verified clean state. Work only on the agreed scope: explicit HTTP link, GTranslate post-cache retest, `/de/about-us/` redirect-chain cleanup, broken featured-image repair, and targeted retest. Preserve unrelated behavior. Before every write, capture live baseline, mutation surface and rollback. Use the smallest reversible change. After every write, perform exact readback plus independent live verification. Stop and rollback if the authenticated WordPress layer cannot take ownership of an upstream behavior.

## Final execution result for this route

1. Explicit HTTP link: NO_CHANGE / RESOLVED. Fresh authenticated Elementor inspection of page 11 and live `/geschaftsbedingungen/` output both found zero explicit `http://doctorcura.com` references. No production edit was justified.
2. GTranslate roots: VERIFIED GREEN. `/en/` and `/fr/` were rechecked after cache clear; no GTranslate write was made.
3. `/de/about-us/`: BLOCKED UPSTREAM OF WORDPRESS. Fresh chain remains `/de/about-us/` -> `/about-us/` -> `/uber-uns/`. A guarded early WordPress `parse_request` direct-redirect change was applied temporarily to snippet 43 after exact SHA preflight and exact post-write readback. The live first hop did not change, so the workflow automatically restored the exact pre-write snippet. Rollback readback SHA: `1b52b70c2d24cad5dd8b4895d2760353feccefcf9a1bcf041e49438782457774`.
4. Broken image: NO_CHANGE / RESOLVED AT CURRENT SOURCE. The stale 404 image filename is no longer referenced by the current article source, its Elementor document, `/blogs/`, `/en/blogs/`, `/fr/blogs/`, or the DE/EN/FR article HTML. The actual current featured media is attachment 5925 and its file returns HTTP 200.
5. WordPress Connector runtime: VERIFIED. DoctorCura connector version 1.12.3 responded through the repository route with write and privileged gates enabled.

## Evidence boundary

The WordPress Connector repository can safely execute authenticated DoctorCura WordPress, Elementor and media operations. It cannot correct the remaining first `/de/about-us/` redirect because the tested request is redirected before the early WordPress hook can take ownership. Cache-bypass probes were Hostinger CDN MISS; the second `/about-us/` redirect is WordPress Redirection-plugin owned, but the first hop is not.

## Rollback status

The only production mutation attempted in this run was the guarded snippet-43 redirect experiment. It failed the live ownership gate and was automatically rolled back. Exact rollback readback matched the pre-write SHA. No content, Elementor, media, GTranslate, or persistent redirect change remains from the failed attempt.

## Closure decision

WordPress-owned scope: COMPLETE for the evidence above.
Overall requested scope: BLOCKED on one upstream redirect hop. A different upstream/GTranslate/hosting control surface is required to collapse `/de/about-us/` to one hop.

This runtime branch and PR are target-specific evidence only and must not be merged into `main`.