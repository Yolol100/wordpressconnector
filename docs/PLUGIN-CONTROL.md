# Plugin control bridge

This document records the generic ChatGPT -> GitHub -> WordPress Connector control surface for supported WordPress plugins. The runtime is capability-driven: prefer plugin-owned APIs or narrowly allowlisted fields, never export credentials to GitHub, and use dry-run, state fingerprints, readback and rollback for mutations.

## Runtime gates

- `WPCONNECTOR_ALLOW_PRIVILEGED=1` is required for privileged plugin settings and filesystem reads.
- `WPCONNECTOR_ALLOW_WRITES=1` plus `confirm:true` is required for non-dry-run mutations.
- `WPCONNECTOR_ALLOW_SENSITIVE=1` is additionally required where an adapter explicitly handles sensitive records/settings.
- `WPCONNECTOR_ALLOW_SYSTEM_UPDATES=1` remains required for plugin/theme/core install, update and delete actions.
- `WPCONNECTOR_ALLOW_FILESYSTEM_WRITES=1` is separately required for real `filesystem.write_text`, `filesystem.create_text` and `filesystem.delete_text` operations.
- Secrets, arbitrary plugin-setting code execution and unrestricted filesystem access are not exposed.

## Native update version pinning

For `plugin.update` and `theme.update`, pass `expected_target_version` when the caller intends to deploy an exact release. The dry-run returns the installed version (`from_version`), the cached WordPress update offer (`available_version`), and the original state fingerprint. A confirmed pinned update refreshes the WordPress offer, stops on drift before calling the upgrader, and checks the installed version after the operation.

The check **does not** turn GitHub commits into WordPress update offers. The target plugin/theme still needs a compatible update provider. If no native offer exists, use the guarded private `plugin.install_package` for a vetted plugin ZIP, with the known non-rollbackability caveat. Active themes without native update offers are not a generic ZIP replacement target. This contract is tested with the existing PHP lifecycle fixtures and the private read-only fleet planner; it is not a substitute for live site verification.

## Plugin control matrix

| Plugin / integration | Control mode | Connector surface |
| --- | --- | --- |
| ACF Content Analysis for Yoast SEO | Covered by ACF + Yoast | `acf.*`, `yoast.*` |
| ACF Page Text Manager | Project-specific ACF data | `acf.get`, `acf.update` |
| Advanced Custom Fields | Native adapter | `acf.field_groups`, `acf.get`, `acf.update` |
| All-in-One WP Migration and Backup | High-risk system operation | No generic import/export bridge; staging-first contract required |
| Asset CleanUp | Stored-settings inspection; separate cache maintenance | `optimizer.settings.inspect` for verified 1.4.0.x stored values only; optimizer writes and older rollback snapshots blocked pending provider API verification. Independently scoped `maintenance.cache_capabilities` and `maintenance.cache_flush` retained. |
| Auto Image Attributes | Native allowlist | `auto_image_attributes.inspect`, `auto_image_attributes.update`, `media.*` |
| Broken Link Checker | Version-bound / mixed cloud-local state | Dedicated installed-version contract required before writes; no generic link deletion or cloud account access |
| Code Snippets | Bounded exact patch + fixed catalogue preset | `code_snippets.patch` retains limited exact replacements. `code_snippets.catalog_only.inspect`, `.ensure` and `.undo` manage only the audited WooCommerce no-orders preset; arbitrary caller PHP and full-source public export remain blocked. |
| Content Sync Manager | Project-specific | Dedicated contract required |
| Classic Editor | Core editor preference | No settings write until a bounded editor-preference contract is proven |
| Duplicate Page | No dedicated adapter required | Model with `post.get` + `post.create` |
| Elementor / Elementor Pro | Native adapter | `elementor.capabilities`, `elementor.inspect`, `elementor.inventory`, `elementor.patch_element`, `elementor.replace_document`, `elementor.form_inspect`, `elementor.form_upsert` |
| GTranslate | Version-bound native allowlist | `gtranslate.settings.inspect`, `gtranslate.settings.update` for verified 5.0.x NL/EN settings |
| Joinchat 6.4.x | Native bounded option profile | `plugin.settings.inspect`, `plugin.settings.update` with `plugin=joinchat` for validated phone, WhatsApp text, tracking, placement and simple button fields; no arbitrary option edits |
| Imagify | WordPress Abilities API | `plugin.settings.inspect/update` profile `imagify`; API key is excluded |
| LiteSpeed Cache | Version-bound | Do not tune simultaneously with another active cache layer without an explicit migration decision |
| Loco Translate | Translation/filesystem-bound | Translation-aware workflow required rather than generic raw-file authoring |
| Really Simple Security | Plugin-owned settings helpers | `plugin.settings.inspect/update` profile `really_simple_security`; high-risk fields require sensitive gate |
| Redirection | Provider API required | Redirect writes require provider contract, loop detection and readback; not a generic option rewrite |
| Under Construction | Maintenance gate | No blanket disable or REST bypass; 403/access policy needs hosting/WAF/maintenance diagnosis |
| Site Kit by Google | OAuth/service-bound | OAuth tokens and encrypted service credentials are excluded |
| Wordfence Security | Security/internal-state boundary | No broad settings writes until stable public interfaces and safe allowlists are proven |
| WP File Manager | Shared filesystem interface | `filesystem.inspect`, `filesystem.list`, `filesystem.read_text`, `filesystem.write_text` |
| WP Mail SMTP | Secret/OAuth-bound | SMTP passwords, OAuth tokens and provider secrets are excluded |
| WP Rocket | Plugin-owned option functions | `plugin.settings.inspect/update` profile `wp_rocket` |
| Yoast SEO / Yoast SEO Premium | Native per-content and organization adapter | `yoast.inspect`, `yoast.update`, `yoast.site_representation.inspect`, `yoast.site_representation.update` |

## Yoast per-content control

`yoast.inspect` and `yoast.update` support focus keyphrase, SEO title/description, canonical URL, robots controls, breadcrumb title, cornerstone state, schema page/article type, primary category, OpenGraph/Twitter title/description/image fields and the legacy per-post redirect meta field.

The adapter performs dry-run planning, validation, state fingerprinting, post-write readback and rollback snapshot creation. The per-post redirect field is not a complete replacement for the Premium Redirect Manager.

## Joinchat settings control

The installed Joinchat 6.4.x version has a bounded, non-secret profile for `plugin.settings.inspect` and `plugin.settings.update` (`plugin=joinchat`). Only 12 fields are accepted: `telephone`, `mobile_only`, `button_tip`, `button_delay`, `whatsapp_web`, `message_text`, `message_send`, `message_start`, `position`, `tracking`, `show_brand`, and `color`. Input is validated against the provider's 6.4.x field model. Existing plugin and Premium fields remain untouched; updates support dry-run, state fingerprints, readback and compensating rollback. Changes to custom CSS, campaign IDs, opt-in markup, privacy policy, analytics accounts and security gates remain unsupported.

## Connector self-updates

`connector.update.check` is read-only. Since 1.17.19, `connector.update.apply` accepts an optional legacy boolean `restore_verified` but does not require an external restore attestation. A real update still requires `confirm=true`, a fresh fingerprint and the existing system-update capability gate. The verified ZIP uses WordPress Core's temporary-backup hooks; install/readback failures trigger recovery where possible. Core temporary backups do **not** guarantee recovery from a fatal error first appearing on a later request. An independently tested external restore remains recommended before significant updates.

## WP Rocket control

The adapter uses WP Rocket's `get_rocket_option()` / `update_rocket_option()` functions and an explicit allowlist for cache, CSS/JS optimization, lazy loading, preload, CDN, WebP and purge interval settings. Unknown fields fail closed.

## Imagify control

Imagify exposes `imagify/get-settings` and `imagify/update-settings` through WordPress Abilities. The connector uses those abilities and explicitly excludes `api_key` and internal version state.

## Really Simple Security control

The adapter discovers the plugin's own settings fields and reads/writes through `rsssl_get_option()` / `rsssl_update_option()`. Secret-like keys are excluded. Firewall/login/2FA/hardening/header and similar high-risk fields require the connector sensitive gate in addition to the normal privileged/write gates.

## Auto Image Attributes control

The adapter manages a conservative allowlist of upload/bulk settings stored by the plugin. Individual media title, alt text, caption and description remain available through `media.update`.

## WP File Manager and controlled filesystem access

WP File Manager is treated as the visual administrator interface over the same WordPress filesystem. The connector does **not** call or emulate WP File Manager's private `wp_ajax_mk_file_folder_manager` / elFinder request protocol. Instead it uses WordPress' own `WP_Filesystem` abstraction. A file changed through the connector is therefore visible in WP File Manager immediately because both interfaces address the same underlying file.

Filesystem actions intentionally have a narrower blast radius than the File Manager UI:

- `filesystem.inspect`: reports filesystem method, gates and File Manager integration state without exposing absolute server paths.
- `filesystem.list`: bounded listing inside the WordPress root; dotfiles, secret paths, uploads, caches, backups, upgrade state and security logs are excluded.
- `filesystem.read_text`: reads bounded UTF-8 text files, blocks credential-like files and refuses files that appear to contain embedded secret literals.
- `filesystem.write_text`: replaces **existing** UTF-8 text files only under `wp-content/plugins/*` or `wp-content/themes/*`, with WordPress file-modification policy checks, exact previous SHA-256, secret-literal scanning and readback. HTML/JS/MJS/CSS replacement requires the additional `acknowledge_active_content:true` property and its rollback retains the approval. 
- `filesystem.create_text` / `filesystem.delete_text`: private authenticated actions for **non-PHP** text assets inside existing plugin/theme directories only; create requires an absent target, delete requires exact previous SHA-256. Both have readback and site-local rollback. **HTML, JS/MJS and CSS are browser-active code/configuration** and require the additional explicit `acknowledge_active_content:true` payload property for confirmed writes; dry-runs identify this requirement. Never describe these assets as non-executable. Existing PHP file replacement remains a separate privileged action. No plugin PHP entrypoint, theme stylesheet, theme.json, block.json, configuration, uploaded data, secrets or core files can be deleted this way.
- `plugin.list`: returns a per-plugin status including activation, network status, native WordPress update availability when checked and the permitted management actions. `plugin.directory.search`: reads bounded results from the WordPress.org Plugins API to find candidate plugin slugs; installing still uses `plugin.install` and system-update capability gates. Both are for private authenticated WordPress sessions, not public GitHub PR receipts.
- `system.doctor`: reports DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT and WordPress file-modification/capability checks. A 403 or blocked write does **not** prove a named security plugin caused the problem. Review server configuration, WAF logs and plugin-specific evidence; do not automatically disable security plugins or change their settings.
- WordPress core (`wp-admin`, `wp-includes` and root core files) is read-only.
- Uploads are managed through `media.*`, not raw filesystem writes.
- The connector cannot rewrite its own installed runtime files; connector releases go through the GitHub/release workflow.
- Arbitrary file creation or deletion outside the bounded `filesystem.create_text` / `filesystem.delete_text` scope, rename, chmod, archive extraction and directory writes are not part of this contract.
- Symlink traversal and `..` traversal are rejected.
- Real writes require direct WordPress filesystem mode; GitHub never supplies interactive FTP/SSH filesystem credentials.
- Real writes require the normal write gate plus the dedicated filesystem-write gate, `confirm:true`, and a matching `expected_sha256`.
- PHP/INC replacements are parser-validated, JSON replacements are decoded before write, the written bytes are verified by SHA-256 readback, and rollback stores the previous file contents.

## Fast-path execution and access diagnosis

Call `plugin.settings.catalog` once per installed plugin inventory and reuse its proven action/profile mapping. Public GitHub `plugin.audit.inspect` receipts contain aggregate counts only; named plugin listings and plugin-specific checks require a private, separately authorized REST/MCP client. Do not infer a full private plugin inventory from public receipts. Prefer `connector.read_batch` (1-25 read-only actions with complete permission preflight and no mutation gates) for independent reads in one direct REST/MCP request or single GitHub request. Keep `connector.batch` for confirmed writes that need compensation; never mix an unsupported read into a write batch. Writes still require their own fingerprints, confirmations, compensations, readbacks and permission gates; not every action may be batched safely.

The zero-config GitHub executor intentionally has two trust-separated workflows: a credential-free request verifier and a trusted, short-lived OIDC executor. This boundary must not be collapsed just to reduce runner-start time. Presence probes retry **only** transient transport errors, HTTP 408, HTTP 429 and HTTP 5xx (at most three attempts per canonical/fallback route). Permanent 401/403, other 4xx, and malformed HTTP 200 presence responses fail fast. The standard `/wp-json/.../presence` route gets one `?rest_route=` fallback check; failure gives no permission to disable maintenance, WAF or authentication protections. An upstream block must be repaired by the site's administrator and independently retested.

WP Rocket and Asset CleanUp should not independently perform the same CSS/JS minification, JS combining, font preloading or unused CSS removal. Cache purges use the existing bounded maintenance actions after changes. Skip live performance claims while maintenance mode hides the real page.

## Deliberate exclusions

A request for "all plugin settings" does not mean exposing every option row or every server file. The bridge does not publish SMTP/OAuth/API credentials, security secrets, executable snippet state, backup archives or unrestricted server paths through GitHub. Integrations without a stable public interface remain version-bound until a dedicated allowlist and regression contract are added.
