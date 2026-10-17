# Yoast SEO management through WordPress Connector

Research and implementation: 2026-10-15. This is a connector extension, not a fork of Yoast.

## Supported and guarded surfaces

| Scope | Connector actions | Availability |
|---|---|---|
| Native Yoast capabilities | yoast.native.capabilities | Private authenticated action, manage_options |
| Native post/product SEO data | yoast.native.post.inspect, yoast.native.post.update, yoast.native.post.restore | WordPress 6.9+, Yoast 28.6+, wpseo_edit_advanced_metadata, exact edit_post, private transport |
| Native analysis | yoast.native.scores | SEO, readability and inclusive-language analysis; wpseo_manage_options, private |
| Legacy per-post metadata and focus keyphrase | yoast.inspect, yoast.update | Existing private Yoast adapter |
| Global search/social title/description templates | yoast.templates.inspect, yoast.templates.update | Existing private provider-backed schema |
| Global feature toggles and indexing defaults | yoast.features.inspect, yoast.features.update | Existing private provider-backed action; high impact demands risk acknowledgment |
| Organization identity and logo | yoast.site_representation.inspect, .update | Existing allowlisted capability |
| Public GitHub OIDC templates | yoast.templates.inspect, .update | Four fields: category_title, product_category_title, product_title, product_description |
| Guarded public snippet repair | yoast.snippet.inspect, .update | Published page/post/product SEO title and meta description only; exact fingerprint, readback, rollback |

## Official native Yoast Abilities

- yoast-seo/get-post-seo-data
- yoast-seo/update-post-seo-data
- yoast-seo/get-seo-scores
- yoast-seo/get-readability-scores
- yoast-seo/get-inclusive-language-scores

The native post update supports seo_title, meta_description, canonical,
is_cornerstone, noindex, nofollow, noimageindex, noarchive,
nosnippet, Open Graph title/description, X title/description, and schema
page/article type. Read-only inspection additionally exposes rendered metadata,
focus keyphrase and analysis metadata. Yoast's own native Ability enforces
its input schema and permissions.

These actions do not work on older Yoast/WordPress versions without the API.
Use authenticated legacy Yoast adapters where appropriate or update Yoast
after compatibility tests. The public GitHub fallback is independently limited.

### Safe edit lifecycle in private authorized connector

1. Inspect an exact post ID with yoast.native.post.inspect.
2. Preview yoast.native.post.update with dry_run=true, confirm=false,
   payload={"post_id":42,"fields":{"seo_title":"Product | Brand"}}.
3. Confirm with a new request ID, dry_run=false, confirm=true and the
   fingerprint from the preceding preview. Runner enforces stale-state
   protection. Adapter and Yoast verify the result.
4. Roll back through connector-managed yoast.native.post.restore only
   when the post still matches its expected post-write fingerprint.
5. Inspect actual rendered head tags, clear relevant cache when needed,
   and repeat targeted SEO/functional tests.

Canonical, noindex, nofollow, other robots attributes and schema changes
require risk_acknowledged=true. Never silently reindex transactional URLs or
modify webshop checkout flows.

### Public product template updates

The authorized GitHub/OIDC route can now update product SEO title and
meta-description defaults plus the two prior taxonomy title templates.
Example:
  {"product_title":"%%title%% %%sep%% Byderma",
   "product_description":"%%excerpt%% %%sep%% Byderma"}

The public request validator and WordPress adapter each enforce the exact
four-field allowlist and required content variables. Manual per-product
overrides are unaffected by global defaults. Before applying, inspect
product summaries: %%excerpt%% may produce lengthy or low-quality snippets.

## Not an unrestricted API

Yoast's REST API metadata endpoint is read-only. Premium redirects,
AI/Sparks, licensed integrations, arbitrary Yoast options, config import
or export, robots.txt / .htaccess file changes, unrestricted schema or
indexable-storage operations are not enabled by this adapter. These require
separate supported interfaces, license checks and explicit authorization.
Never bypass licensing, capabilities, native permission callbacks, rollback,
or private/public transport isolation.

Official references (2026-10-15):
- https://developer.yoast.com/features/yoast-seo-abilities/overview/
- https://developer.yoast.com/features/yoast-seo-abilities/posts-seo-data/
- https://developer.yoast.com/features/yoast-seo-abilities/analysis-scores/
- https://developer.yoast.com/customization/apis/rest-api/
- https://developer.yoast.com/features/xml-sitemaps/api/
- https://yoast.com/help/yoast-seo-tools-bulk-editor/
