=== WordPress Connector ===
Contributors: webactueel
Tags: rest-api, github, automation, wp-cli, elementor, woocommerce, acf, yoast
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.12.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Single controlled GitHub-to-WordPress bridge over authenticated HTTPS REST with optional local WP-CLI recovery.

== Description ==

WordPress Connector is the canonical single-plugin bridge for WordPress posts/pages/CPTs, Gutenberg, Elementor, WooCommerce, ACF, Yoast SEO, media, terms, menus, options, WordPress Additional CSS, controlled filesystem access and controlled system actions behind separate safety gates.

The default GitHub workflow uses a temporary GitHub-hosted Ubuntu runner and authenticates to WordPress over HTTPS with a WordPress Application Password. No GitHub Client ID is stored in WordPress and no VPS or continuously running self-hosted GitHub Actions runner is required.

Elementor writes use Elementor's document save API for element data and page settings, followed by readback verification. The connector exposes active Elementor capability/usage inventory plus complete V3 Form and V4 Atomic Form inspection/upsert with runtime-schema validation and rollback. Elementor-built pages and posts get an "Export Elementor JSON" row action in WordPress admin; saved templates keep Elementor's native export action, with a connector fallback when that action is unavailable. Pages, posts and saved templates also get an "Import Elementor JSON" action for replacing the target Elementor structure and page settings through the same verified document-save/rollback path.

WordPress Additional CSS can be read and replaced through WordPress core Custom CSS APIs. Writes are bounded, privileged, require the normal mutation gate, support stale-state guards, verify exact readback and store a rollback snapshot.

Custom or private plugin ZIPs can be uploaded through the authenticated REST asset endpoint and installed or overwritten through `plugin.install_package`. Packages require a matching SHA-256 checksum and exact plugin identity and are checked for size limits, unsafe paths, symlinks and archive expansion before WordPress Plugin_Upgrader receives them. The generic package action cannot replace the connector itself. Private plugin ZIPs must not be placed on a public GitHub request branch; use direct authenticated REST or a private transport for those packages.

From 1.12.0 onward, `connector.update.check` and `connector.update.apply` provide a dedicated connector self-update route. It is pinned to canonical release assets from `Yolol100/wordpressconnector`, requires the normal privileged/write/system-update gates, downloads only the canonical package/checksum/SBOM set, verifies GitHub asset digests, checksum bytes, SHA-256 and ZIP identity before overwrite, and performs exact version readback. From 1.12.2 onward these two canonical self-update actions may also run through the guarded public GitHub runtime: the public request must use an empty payload, a dry-run is required to obtain the current fingerprint, and a confirmed apply must carry that fingerprint. Sanitized receipts expose only safe version/integrity evidence. Connector self-updates are intentionally non-rollbackable, so staging remains the preferred first target.

Installations that predate the self-update actions cannot bootstrap themselves through those actions. Such installations require one manual upgrade to a release that contains the updater before future updates can use the automated route.

`system.doctor` reports the universal WordPress runtime as healthy when WordPress bootstrap and database access are available. WP-CLI remains a separate informational capability because authenticated REST is the canonical remote transport and local WP-CLI is optional recovery tooling.

Local WP-CLI commands remain available for host-local diagnostics and recovery.

== Security ==

REST endpoints require HTTPS, an authenticated WordPress user and the manage_options capability. Confirmed writes, privileged actions, sensitive actions, filesystem writes and system updates use separate WordPress-side gates under Settings -> WordPress Connector. The connector exposes no generic shell, arbitrary SQL, eval or unrestricted filesystem endpoint.

Public GitHub transport is deliberately reduced. Only explicitly allowlisted public-safe actions may be submitted, full WordPress responses are never persisted, and sensitive actions remain blocked. Store the dedicated WordPress Application Password only in protected GitHub Actions Secrets. Do not commit credentials, passwords, private plugin ZIPs, payment data, patient/medical records or other sensitive production records to GitHub.

== Changelog ==

= 1.12.3 =
* Bind connector self-update to GitHub-provided package/checksum asset SHA-256 digests and require the canonical SPDX SBOM asset before installation.
* Add explicit `update_plugins` and `install_plugins` action capabilities so authorization fails at the registry boundary before deeper lifecycle checks.
* Produce reproducible SPDX 2.3 SBOMs with SHA-1/SHA-256 file checksums and a valid package verification code, while retaining exact ZIP SHA-256 evidence.
* Expand CI across declared PHP 7.4-8.5 support, run the official WordPress Plugin Check action, and keep all third-party actions pinned to full commit SHAs.
* Complete uninstall cleanup for all connector gates, mutation locks, rollback snapshots and idempotency records, including multisite cleanup.
* Remove the one-time self-writing audit migration workflow after use and add regression coverage for the audit hardening boundaries.

= 1.12.2 =
* Add a narrowly marked `public_repository_safe` exception for the two canonical connector self-update actions while keeping sensitive actions blocked and privileged/write/system-update gates mandatory.
* Allow `connector.update.check` and guarded `connector.update.apply` through the public GitHub runtime with empty payloads, dry-run-first fingerprint protection and confirmation for real updates.
* Extend sanitized public receipts with safe connector-version and self-update verification metadata without exposing raw WordPress responses, state tokens, gates or user data.
* Keep `plugin.install_package` blocked in public GitHub runtime so custom/private ZIPs cannot be published through a public request branch.
* Add regression coverage for public self-update allowlisting, stale-state protection, health-data minimization and private-package rejection.

= 1.12.1 =
* Make `system.doctor` treat WP-CLI as optional informational recovery capability instead of a requirement for healthy REST runtime status.
* Finish strict request-identity hardening for rollback request IDs by requiring an absolute regex end boundary.
* Make connector release-contract version checks derive the active release version instead of hardcoding one patch version.

= 1.12.0 =
* Add `connector.update.check` and `connector.update.apply` for a dedicated self-update path from canonical GitHub releases.
* Pin self-updates to `Yolol100/wordpressconnector` release assets and require SHA-256, semantic-version, ZIP path, symlink and exact plugin identity validation before overwrite.
* Add a release workflow that publishes only after successful `Connector CI` on `main`, with package, checksum and reproducible SBOM assets plus provenance attestation.
* Keep self-update behind privileged, write and system-update gates and preserve the generic `plugin.install_package` self-replacement block.

= 1.11.0 =
* Add `plugin.install_package` for verified custom/private plugin ZIP delivery through authenticated REST request assets.
* Require SHA-256 verification, exact expected plugin identity and strict ZIP structure checks before installation or overwrite.
* Keep package operations behind privileged, write and system-update gates and block connector self-replacement.
* Extend the request asset transport with a narrow `plugin-packages/*.zip` allowance while preserving normal media restrictions.

= 1.10.0 =
* Add privileged `custom_css.inspect` and `custom_css.update` actions for WordPress Additional CSS.
* Use WordPress core Custom CSS APIs instead of direct database or filesystem mutation.
* Add a 512 KiB request bound, installed-theme validation, stale-state fingerprint support, exact readback and rollback.
* Align the plugin stable tag with the runtime version and add CI coverage for the Additional CSS contract.

= 1.8.0 =
* Add "Import Elementor JSON" actions for WordPress Pages, Posts and Elementor Saved Templates.
* Allow choosing an existing target and uploading a standard Elementor JSON document up to 5 MB.
* Reuse the canonical ElementorAdapter save/readback/automatic-rollback path; no direct `_elementor_data` writes are introduced.
* Keep row-level export limited to Elementor-built Pages/Posts and the existing Saved Templates native/fallback export behavior.
* Continue the Elementorconnector consolidation into this single canonical live plugin.

= 1.7.0 =
* Restore local Elementor JSON download for Elementor-built WordPress pages and posts from their admin list row actions.
* Keep Elementor's native Saved Templates export action and provide a connector fallback for editable `elementor_library` documents when the native row action is unavailable.
* Build downloads from Elementor's document `get_export_data()` API and emit the standard `content`, `page_settings`, `version`, `title` and `type` fields instead of reading raw `_elementor_data`.
* Require the normal WordPress `edit_post` capability plus a per-document nonce for every local download.

= 1.6.0 =
* Add runtime-aware `elementor.form_capabilities` for V3 Form controls/actions and V4 Atomic Form element/prop schemas.
* Add `elementor.form_inspect` for complete V3/V4 form subtree readback.
* Add `elementor.form_upsert` to insert or fully replace one complete V3 Form widget or V4 Atomic Form subtree with schema checks, readback and rollback.
* Validate V3 field IDs and registered submit actions; validate V4 typed props, registered Atomic types, required messages, exactly one submit button and nested-form rejection.
* Add stale-schema fingerprint guards, exact readback verification and automatic full-document rollback on failed writes.

= 1.5.0 =
* Add bounded WP_Filesystem inspection and existing plugin/theme text-file replacement with dedicated gates, checksums, validation, readback and rollback.

= 1.4.0 =
* Add controlled plugin-settings bridge and plugin-specific safe settings adapters.

= 1.3.0 =
* Add read-only `elementor.inventory` with paginated site-wide Elementor document scanning.
* Identify registered widget provenance as Elementor Core, Elementor Pro, plugin add-on, theme or unknown, including available version metadata.
* Count widget usage per document and report widget types that remain in saved Elementor data but are no longer registered.
* Report legacy section/column, container and Atomic element architecture usage per document.

= 1.2.0 =
* Consolidate the active WordPress/Elementor route into one canonical WordPress Connector plugin.
* Add first-class Yoast SEO inspect/update actions with dry-run, fingerprint, readback and rollback support.
* Add Elementor document/widget/element/dynamic-tag/breakpoint capability inventory.
* Add safe WordPress Abilities API catalog discovery without generic ability execution.
* Save Elementor element data and document settings through Elementor's document API instead of direct _elementor_data writes.
* Add exact Elementor readback checks after create, replace and patch operations.
* Expand the WordPress settings page with the canonical GitHub Actions setup and legacy-bridge migration guidance.

= 1.1.0 =
* Add authenticated HTTPS REST transport using the existing connector action registry.
* Add request-scoped asset upload transport with strict path and size limits.
* Add WordPress-side execution gate settings.
* Switch the default GitHub request workflow to automatically provisioned ubuntu-latest runners.

= 1.0.0 =
* Initial complete connector runtime.
