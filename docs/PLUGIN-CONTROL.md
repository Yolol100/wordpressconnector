# Plugin control bridge

This document records the advanced WordPress Connector control surface used behind WP Agent. The runtime is capability-driven: prefer WP Agent native capabilities or plugin-owned APIs first, then use narrowly modeled Connector actions only when they add a required capability, safety boundary, readback or rollback contract.

GitHub is not part of the live control path and never stores WordPress runtime credentials or request payloads.

## Runtime gates

- `WPCONNECTOR_ALLOW_PRIVILEGED=1` is required for privileged plugin settings and filesystem reads.
- `WPCONNECTOR_ALLOW_WRITES=1` plus `confirm:true` is required for non-dry-run mutations.
- `WPCONNECTOR_ALLOW_SENSITIVE=1` is additionally required where an adapter explicitly handles sensitive records/settings.
- `WPCONNECTOR_ALLOW_SYSTEM_UPDATES=1` remains required for plugin/theme/core install, update and delete actions.
- `WPCONNECTOR_ALLOW_FILESYSTEM_WRITES=1` is separately required for real `filesystem.write_text` operations.
- Secrets, arbitrary plugin-setting code execution and unrestricted filesystem access are not exposed.

## Plugin control matrix

| Plugin / integration | Control mode | Connector surface |
| --- | --- | --- |
| ACF Content Analysis for Yoast SEO | Covered by ACF + Yoast | `acf.*`, `yoast.*` |
| ACF Page Text Manager | Project-specific ACF data | `acf.get`, `acf.update` |
| Advanced Custom Fields | Native adapter | `acf.field_groups`, `acf.get`, `acf.update` |
| All-in-One WP Migration and Backup | High-risk system operation | No generic import/export bridge; staging-first contract required |
| Asset CleanUp | Version-bound | No generic settings write; unload rules need browser/regression readback |
| Auto Image Attributes | Native allowlist | `auto_image_attributes.inspect`, `auto_image_attributes.update`, `media.*` |
| Broken Link Checker | Version-bound / mixed cloud-local state | Dedicated installed-version contract required before writes |
| Code Snippets | Arbitrary-code settings boundary | Snippet execution is not exposed as plugin settings; source maintenance is only possible through the separately gated filesystem contract |
| Content Sync Manager | Project-specific | Dedicated contract required |
| Duplicate Page | No dedicated adapter required | Model with `post.get` + `post.create` |
| Elementor / Elementor Pro | Native adapter | `elementor.capabilities`, `elementor.inspect`, `elementor.inventory`, `elementor.patch_element`, `elementor.replace_document` |
| GTranslate / Joinchat | Version-bound | Dedicated contract required before settings writes |
| Imagify | WordPress Abilities API | `plugin.settings.inspect/update` profile `imagify`; API key is excluded |
| LiteSpeed Cache | Version-bound | Do not tune simultaneously with another active cache layer without an explicit migration decision |
| Loco Translate | Translation/filesystem-bound | Translation-aware workflow required rather than generic raw-file authoring |
| Really Simple Security | Plugin-owned settings helpers | `plugin.settings.inspect/update` profile `really_simple_security`; high-risk fields require sensitive gate |
| Site Kit by Google | OAuth/service-bound | OAuth tokens and encrypted service credentials are excluded |
| Wordfence Security | Security/internal-state boundary | No broad settings writes until stable public interfaces and safe allowlists are proven |
| WP File Manager | Shared filesystem interface | `filesystem.inspect`, `filesystem.list`, `filesystem.read_text`, `filesystem.write_text` |
| WP Mail SMTP | Secret/OAuth-bound | SMTP passwords, OAuth tokens and provider secrets are excluded |
| WP Rocket | Plugin-owned option functions | `plugin.settings.inspect/update` profile `wp_rocket` |
| Yoast SEO / Yoast SEO Premium | Native per-content adapter | `yoast.inspect`, `yoast.update` |

## WordPress Abilities

When WordPress or an installed plugin exposes a suitable typed Ability, prefer it over a duplicate Connector implementation. The connector can discover selected exposed abilities via `wordpress.abilities`.

Imagify is one example where the Connector can use plugin-owned WordPress Abilities while still applying its own allowlist, secret exclusions, fingerprints and rollback behavior.

## Advanced adapter rules

`yoast.inspect` / `yoast.update`, WP Rocket settings, Really Simple Security settings and Auto Image Attributes settings use narrow supported contracts rather than arbitrary option-table access. Unknown or secret-like fields fail closed.

## Controlled filesystem access

WP File Manager is treated as a visual administrator interface over the same WordPress filesystem. The connector does not emulate its private AJAX/elFinder protocol; it uses WordPress' `WP_Filesystem` abstraction.

Filesystem actions intentionally have a narrow blast radius:

- `filesystem.inspect` reports capabilities without exposing absolute paths;
- `filesystem.list` and `filesystem.read_text` are bounded and exclude secret/managed-data paths;
- `filesystem.write_text` replaces existing UTF-8 text files only under permitted plugin/theme locations;
- WordPress core and the Connector's own installed runtime are not writable through this action;
- uploads are managed through `media.*`;
- new-file creation, delete, rename, chmod, archive extraction and arbitrary directory writes are excluded;
- real writes require direct WordPress filesystem mode, the normal write gate, the filesystem-write gate, `confirm:true` and matching `expected_sha256`;
- supported PHP/JSON replacements are validated, verified by SHA-256 readback and rollback stores previous bytes.

Connector releases themselves remain a normal source-control/release operation; GitHub may build and review releases but is not used to transport live WordPress action requests.

## Deliberate exclusions

A request for "all plugin settings" does not mean exposing every option row or server file. The bridge does not publish SMTP/OAuth/API credentials, security secrets, executable snippet state, backup archives or unrestricted server paths. Integrations without a stable public interface remain version-bound until a dedicated allowlist and regression contract are added.
