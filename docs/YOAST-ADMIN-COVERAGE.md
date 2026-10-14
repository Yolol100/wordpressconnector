# Yoast SEO administration coverage and boundaries

This is a capability map for the Yoast SEO navigation, not a claim that every menu entry is a mutable WordPress setting. It is intentionally site-neutral. Effective controls depend on the installed Yoast SEO/Premium versions, active extensions, WordPress roles, available provider APIs and the approved Connector transport. A green CI run does not prove site-level authorization.

| Yoast menu | Actual functionality | Current Connector route | Remaining work / boundary |
| --- | --- | --- | --- |
| General / dashboard | Status, notifications and onboarding | Read-only plugin audit, bounded site identity reads | Onboarding state and notifications need provider-specific, consented actions; not generic options writes |
| Settings / Site features | SEO/readability analysis, XML sitemaps, Schema, social, AI, llms.txt and other switches | Partial; do **not** infer all switches from `wpseo` | Add type-checked provider-version-gated boolean/enumeration adapters and tests per setting |
| Settings / Site basics | WordPress site name and Yoast organization identity, logo, description, alternate name, policies | `site.identity.*`, `yoast.site_representation.*` (limited) | Expand exact site basics fields, require image/media ownership, verified legal identity, readback/rollback |
| Settings / Content types | Homepage, post/page/product title/description, social metadata, schema and editor/indexing options | `yoast.templates.*` (allowlisted templates), `yoast.inspect/update` (post metadata) | Add type-checked indexing/schema/visibility options; per-post Yoast Abilities when installed; version gates for products/custom types |
| Settings / Categories and tags | Default category/tag title/description and social snippets, taxonomy visibility, per-term settings | `yoast.templates.*` text mappings in 1.19.1 candidate | Add guarded per-term metadata, typed taxonomy/noindex and breadcrumb configuration |
| Settings / Advanced | Author/date/special title and description templates, breadcrumbs, RSS, attachments, Schema and crawl optimization | Selected author/date/search/404 text templates only | Separate validated hooks for boolean/indexing, RSS markup, breadcrumb enum/strings, crawl optimizer (Premium), media and file output |
| Integrations | Provider availability, optional integrations, external Semrush/Wincher/Site Kit/Elementor account connections | Generic plugin audit; existing Elementor adapter | Provider-owned install/auth/consent. Tokens, remote API accounts and OAuth secrets are **not** exported or modifiable by arbitrary connector writes |
| Tools | Settings export/import, bulk metadata editor, robots.txt/.htaccess file editor and SEO-data optimization | Per-post metadata edits, bounded filesystem abilities already exist but are **not** a Yoast File Editor integration | Provider-specific export/import with dry-run, protected filesystem change review, backup, restore and indexables reindexing without bypassing host config |
| Academy | Training/course portal | Not a local WordPress setting | External MyYoast course/account access, not a local setting mutation |
| Subscriptions | MyYoast account, payments, licenses, plan/site assignments | Not supported | External identity/billing/licensing: never impersonate users, reveal secrets, enable premium or make purchases through WP options |
| Workouts (Premium) | Guided SEO tasks and their progress | Not supported | Only if actual Premium is installed and licensed; preserve genuine per-user progress; do not mark steps complete artificially |
| Redirects (Premium) | Redirect management, modes, regex rules | Not supported | Only if provider active and authorized; validate source/destination, existing routes, redirect loops, canonical/HTTP results, server mode and rollback |
| Support | Help articles and customer service | Not a local WordPress setting | External support account and conversation; no generic write route |

## Transport and authorization

- Public GitHub request mode deliberately exposes only `category_title` and `product_category_title` from `yoast.templates.inspect/update`. This does **not** expand with new private fields. No public general option values, OAuth credentials, MyYoast subscriptions or customer metadata.
- Full allowlisted templates require an authenticated direct REST/MCP runtime with the relevant WordPress capabilities (`manage_options`; Yoast-native Abilities may additionally require `wpseo_manage_options` or `wpseo_edit_advanced_metadata`). Confirm the site's real role policy and the provider's checks.
- Native Yoast SEO 28.6+ post abilities `yoast-seo/get-post-seo-data` and `yoast-seo/update-post-seo-data` may replace custom post metadata writes when present and genuinely authorized, but they do **not** edit global settings. The existing Connector generic ability writer is intentionally scoped to verified Elementor providers; it must not be broadened merely by a name prefix.
- No blanket `get_option`/ `update_option` endpoint, option-key wildcard, raw SQL, arbitrary PHP, token export or license bypass. Follow Yoast's own published `Settings_Integration::ALLOWED_OPTION_GROUPS` and `DISALLOWED_SETTINGS` policy when designing further bounded adapters; internal values such as `myyoast-oauth`, Semrush/Wincher tokens, workflow progress and import cursors are not normal UI fields.
- A writable site setting needs evidence of the installed provider version, registered option/key, input type/enumeration and permissions, a current-state fingerprint, bounded change preview, explicit confirmation, provider readback, rollback and same-scope rendered SEO verification. High-impact `noindex`, robots and redirect changes require separate SEO approval and staging.

## Current implementation increment

WordPress Connector 1.19.1 candidate extends the **existing** `YoastTemplateSettingsAdapter` with documented, bounded text templates for category/tag, home/post/page/product, author/date, special-search/404 and social snippets. It retains a very small, unchanged public projection. It does **not** implement all toggles, accounts, editor functions, Premium features or remote services.

## Official primary sources (revalidate for installed versions)

- [Yoast SEO configuration guide](https://yoast.com/help/configuration-guide-for-yoast-seo/)
- [Yoast Content types](https://yoast.com/help/yoast-seo-settings-content-types/)
- [Yoast Categories & tags](https://yoast.com/help/yoast-seo-settings-categories-tags/)
- [Yoast Advanced](https://yoast.com/help/yoast-seo-settings-advanced/)
- [Yoast Tools](https://yoast.com/help/yoast-seo-tools-settings/)
- [Yoast Redirect Manager (Premium)](https://yoast.com/help/the-redirect-manager-in-yoast-seo-premium/)
- [Yoast Abilities API](https://developer.yoast.com/features/yoast-seo-abilities/overview/)
- [Yoast settings integration option groups, excluded internal keys](https://github.com/Yoast/wordpress-seo/blob/trunk/src/integrations/settings-integration.php)
- [Yoast title option schema](https://github.com/Yoast/wordpress-seo/blob/trunk/inc/options/class-wpseo-option-titles.php)
