# Plugin control bridge

This document records the safe WordPress Connector control surface for supported WordPress plugins behind WP Agent. Prefer plugin-owned APIs, stable WordPress Abilities or narrowly allowlisted fields. Never expose credentials, arbitrary code execution or unrestricted filesystem access.

## Runtime gates

- `WPCONNECTOR_ALLOW_PRIVILEGED=1` is required for privileged plugin settings and filesystem reads.
- `WPCONNECTOR_ALLOW_WRITES=1` plus `confirm:true` is required for non-dry-run mutations.
- `WPCONNECTOR_ALLOW_SENSITIVE=1` is additionally required where an adapter explicitly handles sensitive records/settings.
- `WPCONNECTOR_ALLOW_SYSTEM_UPDATES=1` is required for plugin/theme/core install, update and delete actions.
- `WPCONNECTOR_ALLOW_FILESYSTEM_WRITES=1` is separately required for real `filesystem.write_text` operations.

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
| Code Snippets | Arbitrary-code boundary | Snippet execution is not exposed; only separately gated file maintenance may be used |
| Content Sync Manager | Project-specific | Dedicated contract required |
| Duplicate Page | No dedicated adapter required | Model with `post.get` + `post.create` |
| Elementor / Elementor Pro | Native adapter | `elementor.*` |
| GTranslate / Joinchat | Version-bound | Dedicated contract required before settings writes |
| Imagify | WordPress Ability | `plugin.settings.inspect/update` profile `imagify`; API key excluded |
| LiteSpeed Cache | Version-bound | Avoid conflicting cache-layer writes without explicit migration decision |
| Loco Translate | Translation/filesystem-bound | Translation-aware workflow required |
| Really Simple Security | Plugin-owned settings helpers | `plugin.settings.inspect/update` profile `really_simple_security` |
| Site Kit by Google | OAuth/service-bound | OAuth tokens and encrypted service credentials excluded |
| Wordfence Security | Security/internal-state boundary | Broad settings writes withheld until safe stable interfaces are proven |
| WP File Manager | Shared filesystem interface | `filesystem.inspect`, `filesystem.list`, `filesystem.read_text`, `filesystem.write_text` |
| WP Mail SMTP | Secret/OAuth-bound | SMTP passwords, OAuth tokens and provider secrets excluded |
| WP Rocket | Plugin-owned option functions | `plugin.settings.inspect/update` profile `wp_rocket` |
| Yoast SEO / Premium | Native adapter | `yoast.inspect`, `yoast.update` |

## Controlled filesystem access

The connector uses WordPress `WP_Filesystem`; it does not emulate WP File Manager's private AJAX/elFinder protocol.

- `filesystem.inspect`: reports safe filesystem capability state.
- `filesystem.list`: bounded listing inside the WordPress root.
- `filesystem.read_text`: bounded non-secret UTF-8 text reads.
- `filesystem.write_text`: replaces existing UTF-8 text files only under `wp-content/plugins/*` or `wp-content/themes/*`.
- WordPress core, uploads, secrets and the connector's own installed runtime remain non-writable through this action.
- Real writes require direct filesystem mode, the normal write gate, the filesystem-write gate, `confirm:true` and matching `expected_sha256`.
- PHP/INC and JSON replacements are validated before write and verified by SHA-256 readback with rollback data.

## Deliberate exclusions

A request for "all plugin settings" does not mean exposing every option or server file. The bridge does not expose SMTP/OAuth/API credentials, security secrets, executable snippet state, backup archives or unrestricted paths. Integrations without a stable public interface remain version-bound until a dedicated safe contract exists.
