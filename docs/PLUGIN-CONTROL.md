# Plugin control bridge

This document records the safe ChatGPT -> GitHub -> WordPress Connector control surface for the AndrewBaeten.nl plugin inventory observed on 2026-09-06. The runtime is capability-driven: use plugin-owned APIs or narrowly allowlisted fields where possible; never export secrets to GitHub; use dry-run, fingerprints, readback and rollback for mutations.

## Runtime gates

- `WPCONNECTOR_ALLOW_PRIVILEGED=1` is required for plugin settings reads.
- `WPCONNECTOR_ALLOW_WRITES=1` plus `confirm:true` is required for non-dry-run settings mutations.
- `WPCONNECTOR_ALLOW_SENSITIVE=1` is additionally required for high-risk Really Simple Security fields.
- System updates/install/delete remain behind `WPCONNECTOR_ALLOW_SYSTEM_UPDATES=1`.
- Secrets, arbitrary code and arbitrary filesystem writes are not exposed through the plugin settings bridge.

## Installed plugin matrix

| Plugin | Observed version | Control mode | Connector surface |
| --- | ---: | --- | --- |
| ACF Content Analysis for Yoast SEO | 3.2 | Covered by ACF + Yoast | `acf.*`, `yoast.*` |
| ACF Page Text Manager | 2.3.42 | Project-specific ACF data | `acf.get`, `acf.update` |
| Advanced Custom Fields | 6.8.9 | Native adapter | `acf.field_groups`, `acf.get`, `acf.update` |
| All-in-One WP Migration and Backup | 7.110 | High-risk system operation | No import/export write bridge; staging-first contract required |
| Asset CleanUp: Page Speed Booster | 1.4.0.5 | Version-bound | No generic write yet; unload rules require browser/regression readback |
| Auto Image Attributes From Filename With Bulk Updater | 4.9.1 | Native allowlisted settings adapter | `auto_image_attributes.inspect`, `auto_image_attributes.update`, `media.*` |
| Broken Link Checker | 2.4.14.1 | Version-bound / mixed cloud-local state | No generic settings write yet; exact installed-version contract required |
| Code Snippets | 3.10.2 | Arbitrary-code boundary | Remote snippet-code writes intentionally blocked |
| Content Sync Manager | 1.2.61 | Project-specific | Requires its own contract before configuration writes |
| Duplicate Page | 4.5.9 | No dedicated settings adapter needed | Content duplication can be modeled with `post.get` + `post.create` |
| Elementor | 4.2.4 | Native adapter | `elementor.capabilities`, `elementor.inspect`, `elementor.inventory`, `elementor.patch_element`, `elementor.replace_document` |
| Elementor Pro | 4.0.0 | Native adapter | Same Elementor document surface |
| GTranslate | 3.1.2 | Version-bound | No settings write until an installed-version option/API contract is modeled |
| Imagify | 2.3.3 | WordPress Abilities API | `plugin.settings.inspect/update` profile `imagify`; API key never returned |
| Joinchat | 6.3.2 | Version-bound | No settings write until a stable installed-version contract is modeled |
| LiteSpeed Cache | 7.9.1 | Inactive | No active tuning while WP Rocket is the active cache layer |
| Loco Translate | 2.8.8 | Filesystem-bound | Translation file writes require a separate filesystem/package contract |
| Really Simple Security | 9.8.1 | Plugin-owned settings API | `plugin.settings.inspect/update` profile `really_simple_security`; high-risk fields require sensitive gate |
| Site Kit by Google | 1.186.0 | OAuth/service-bound | Tokens and encrypted service credentials are intentionally excluded from GitHub |
| Wordfence Security | 9.0.0 | Security/internal-state boundary | No broad settings writes until a stable public API/allowlist is proven; secrets never exported |
| WordPress Connector | 1.2.0 observed live | Canonical bridge | Connector admin/runtime gates and repository workflow |
| WP File Manager | 8.0.4 | Arbitrary-filesystem boundary | Generic remote filesystem writes intentionally blocked |
| WP Mail SMTP | 4.9.0 | Secret/OAuth-bound | Mailer passwords, OAuth tokens and provider secrets never exported to GitHub |
| WP Rocket | 3.23.2.2 | Official settings functions | `plugin.settings.inspect/update` profile `wp_rocket` |
| Yoast SEO | 28.4 | Native per-post adapter | `yoast.inspect`, `yoast.update` |
| Yoast SEO Premium | 27.5 observed live | Native per-post adapter + Premium behavior | `yoast.inspect`, `yoast.update`; Redirect Manager itself remains a separate Premium-level contract |

## Yoast per-post control

`yoast.inspect` and `yoast.update` support: `focus_keyphrase`, `title`, `description`, `canonical`, `robots_noindex`, `robots_nofollow`, `robots_advanced`, `breadcrumb_title`, `cornerstone`, `schema_page_type`, `schema_article_type`, `primary_category_term_id`, OpenGraph/Twitter title/description/image/image-id and the legacy/per-post `redirect` meta field.

The adapter performs dry-run planning, validation, state fingerprinting, post-write readback and rollback snapshot creation. The per-post `redirect` field is not a complete replacement for the Premium Redirect Manager.

## WP Rocket control

The adapter uses WP Rocket's own `get_rocket_option()` / `update_rocket_option()` functions and an explicit allowlist for cache, CSS/JS optimization, lazy loading, preload, CDN, WebP and purge interval settings. Unknown fields fail closed.

## Imagify control

Imagify 2.3+ exposes `imagify/get-settings` and `imagify/update-settings` through WordPress Abilities. The connector uses those abilities and explicitly excludes `api_key` and internal version state.

## Really Simple Security control

The adapter discovers the plugin's own settings fields and reads/writes through `rsssl_get_option()` / `rsssl_update_option()`. Secret-like keys are excluded. Firewall/login/2FA/hardening/header and similar high-risk fields require the connector sensitive gate in addition to normal privileged/write gates.

## Auto Image Attributes control

The adapter manages a conservative allowlist of boolean upload/bulk settings stored in the plugin's registered `iaff_settings` option. Individual media title, alt, caption and description remain available through `media.update`.

## Deliberate exclusions

A request for "all plugin settings" does not mean exposing every option row. The bridge must not publish SMTP/OAuth/API credentials, Wordfence secrets, executable snippets, arbitrary files or migration/import payloads through GitHub. Plugins without a stable public API are version-bound and require a dedicated allowlist plus regression tests before writes are enabled.
