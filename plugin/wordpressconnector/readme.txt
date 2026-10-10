=== WordPress Connector ===
Contributors: webactueel
Tags: rest-api, github, automation, wp-cli, elementor, woocommerce, acf, yoast
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.17.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zero-config controlled WordPress automation bridge over authenticated HTTPS REST, native MCP and optional local WP-CLI recovery.

== Description ==

Version 1.17.13 redacts merchant, bank and provider-specific WooCommerce values in settings inventories by default. Nested payment-gateway and shipping-method settings now expose only explicit safe fields; arbitrary extension values and credential-like settings cannot be read or updated through GitHub. Regression tests verify secret isolation and allowed shipping cost/rollback behavior.


Version 1.17.12 adds critical-gated WooCommerce shipping zone/location, shipping method and tax-rate provisioning. Shipping location edits support exact JSON REST, dry-run and rollback, as do new zone/method/tax-rate creates with changed-state safeguards. New shipping methods are disabled by default. Tax creation has an additional site-only policy gate; no existing resources are deleted by normal actions.


Version 1.17.11 widens provider REST support to all nonsecret WooCommerce core settings with opt-in critical server gate, exact state checks and rollback; adds provider-owned shipping zone, shipping method, tax-rate and payment gateway read/guarded-update actions, including Mollie gateway display settings where available. Payment activation requires a separate local gate and successful sandbox verification. Credentials, irreversible operations and live orders remain inaccessible through GitHub.


Version 1.17.10 adds bounded WooCommerce settings-group and admin tab/subtab discovery, paged admin subtab field inspection, safe settings readback with secret redaction, and capability-guarded updates for a small explicit list of low-risk core display/product-review settings through WooCommerce's REST API. Payment, tax, shipping method, POS and third-party provider settings remain read-only or require dedicated staging-first controls. All writes include dry-run, readback and rollback.


Version 1.17.9 advertises the guarded global Yoast snippet template actions in the installed-plugin capabilities catalog. No new permission surface.


Version 1.17.8 adds capability-guarded global Yoast SEO snippet template read, bounded update and restore for homepage, pages, posts, WooCommerce products and product categories. Changes use a strict field allowlist, dry-run, exact readback and rollback, preserving unrelated provider options.


Version 1.17.7 adds an explicitly non-mutating fast read batch (up to 25 independently authorized reads), safe structured error diagnostics, and a guarded Core temporary-backup path for Connector self-updates. A confirmed update requires `restore_verified=true`, an owner-tested external restore, and normal state/permission gates. WordPress Core's temporary-backup recovery is limited to install/readback failures and does not guarantee post-success rollback.


Version 1.17.4 hardens complex ACF schema creation compensation. If a nested field creation fails after its parent or earlier sub fields were persisted, the connector now tracks the exact created field IDs, removes them in reverse order, verifies cleanup readback and reports compensation failure instead of leaving silent partial schema residue.

Version 1.17.3 adds guarded private/direct ACF schema creation for new field groups and bounded complex fields, including image, relationship, group and repeater structures with nested sub fields. The new routes fail closed on existing ownership conflicts, enforce bounded schemas and location rules, require normal connector confirmation/stale-state handling, verify exact readback and retain rollback definitions. The existing public GitHub ACF text-schema route remains unchanged; complex schema mutation is not public.

Version 1.17.2 adds an exact private GitHub OIDC control-plane trust path for `Yolol100/Wordpress`. The existing public `Yolol100/wordpressconnector` runtime remains restricted to its public-safe allowlist; the private control plane may use the full registered connector action catalog while preserving request confirmation, WordPress capabilities, idempotency, stale-state guards, readback/rollback and bounded asset transport.

Version 1.17.1 hardens the Ability bridge introduced in 1.17.0. Generic delegated reads remain available for client-exposed WordPress Abilities with explicit readonly=true and destructive=false annotations, native validation/permissions, and bounded redacted output. Delegated writes require explicit MCP exposure plus native Elementor Core/Pro callback provenance, an exact provider-ID match and an active canonical plugin root; slugs, categories and annotations alone do not establish mutation trust. Explicit MCP opt-outs are authoritative, delegated writes cannot run inside connector.batch, completed over-budget results become bounded terminal successes, and provider failures after execution starts become stored terminal failures so stable request-ID retries do not blindly execute again.

Version 1.17.0 adds namespace-filtered WordPress Ability discovery and guarded Elementor Ability execution on private/direct authenticated transports. Mutating Ability dry-runs are metadata-only; live calls still require connector confirmation and a stable request ID plus native WordPress/Elementor validation, permissions and provider guards.

Version 1.16.1 adds guarded public GitHub-runtime access to WordPress Additional CSS without making full CSS replacement public. It introduces a managed `custom_css.patch` action that upserts or removes one named CSS block, requires dry-run fingerprint protection for live writes, preserves unrelated CSS, verifies exact readback and stores rollback. Public CSS inspection and mutation receipts expose only hashes, byte counts and patch metadata, never the CSS body. The action is restricted to the active theme stylesheet and the WordPress `edit_css` capability.

Version 1.16.0 adds a bounded authenticated MCP endpoint over the existing semantic action registry and Runner. It supports modern 2026-07-28 discovery plus the legacy 2025-11-25 initialize flow, exposes only action discovery, runtime discovery and semantic execution tools, defaults execution to dry-run and requires confirm=true plus an explicit stable request_id for real writes. MCP reuses the existing WordPress/OIDC authorization, capabilities, stale-state, idempotency, readback and rollback controls and adds no generic shell, SQL or unrestricted proxy primitive.

WordPress Connector is the canonical single-plugin bridge for WordPress posts/pages/CPTs, Gutenberg, Elementor, WooCommerce, ACF, Yoast SEO, media, terms, menus, options, WordPress Additional CSS, controlled filesystem access and controlled system actions.

Version 1.15.11 allows guarded public overwrite only for the already-installed Webactueel Mailbox Bridge. The request remains checksum-, identity- and dry-run-fingerprint-gated, network-wide install remains forbidden, and all other public plugin overwrites remain blocked.

Version 1.15.10 closes the remaining WooCommerce order-read compatibility gap with bounded stock CPT/HPOS item queries across pre-11.1 and current WooCommerce, declares HPOS compatibility, and makes ambiguous WordPress Ability annotations fail closed. CI now includes real MySQL-backed WordPress/WooCommerce runtime coverage for the supported storage paths.

Version 1.15.9 allows the existing plugin.install_package action through public GitHub runtime only for publishable ZIPs with exact SHA-256 and plugin identity, overwrite:false, network_wide:false, explicit activation intent and a required dry-run fingerprint before confirmation. Public receipts remain sanitized and private/confidential packages remain forbidden on public request branches.

Version 1.15.8 adds bounded WooCommerce customer, shipping-zone and tax catalog reads, with WooCommerce capability checks and sensitive-data opt-ins. Shipping zones and tax rates use paginated fixed queries and return bounded geographic restrictions with counts and truncation evidence. Order summaries omit customer identifiers and line-item details by default, validate sensitive identifiers strictly, and load at most 50 individual-order line items through the active WooCommerce data-store boundary with fail-closed fallback when bounded item-ID access is unavailable. REST-exposed read-only WordPress Ability results redact credential-shaped fields and are capped at 10,000 values, 20 levels and 256 KiB; Ability discovery is paginated to 10 descriptors per page, omits over-budget schemas and caps each page at 256 KiB.

Version 1.15.7 adds a guarded emergency user-password reset action for incident response. It rotates the password server-side, revokes all sessions, creates a fresh reset key, sends a dedicated security reset link, never returns secret material, and remains blocked from public-repository runtime.

Version 1.15.6 makes Asset CleanUp cache verification version-tolerant by planting a short-lived `wpacu_css_*` sentinel transient and requiring Asset CleanUp's own cache-clear routine to remove it.

Version 1.15.5 aligns Asset CleanUp cache maintenance with the current 1.4.0.6 runtime: it uses the plugin's own namespace-to-classes autoload contract during REST/OIDC requests, calls `clearCache()` when available, and verifies completion through `wpacu_clear_cache_after` plus the plugin's `_last_clear_cache` transient.

Version 1.15.4 fixes Asset CleanUp cache clearing in REST/OIDC requests by loading the plugin's own helper classes needed by its official `clearAllCache()` path before execution. No generic cache-directory deletion is introduced.

Version 1.15.3 adds a bounded WP Rocket fallback: if the normal full-domain purge cannot produce completion evidence in the REST runtime, the connector uses WP Rocket's own full cache-directory purge and verifies its official completion hook.\n\nVersion 1.15.2 hardens the cache-maintenance adapter by accepting WP Rocket's own successful purge return as readback evidence, lazily loading Asset CleanUp's official cache class from its own verified plugin directory during REST requests, and separating provider versions from the receipt schema.\n\nVersion 1.15.1 adds bounded cache maintenance for Elementor, WP Rocket and Asset CleanUp. It exposes read-only availability and an exact one-layer flush action with dry-run fingerprint protection and sanitized readback evidence.\n\nVersion 1.15.0 adds a narrowly bounded `code_snippets.patch` action for one existing Code Snippets PHP record. It permits only exact replacements on a uniquely identified target, requires dry-run fingerprint protection for public writes, preserves snippet metadata and activation state, verifies exact readback and stores a guarded rollback without exposing snippet source in public receipts.\n\nVersion 1.14.9 adds a narrowly bounded `portfolio.case_text_update` action for portfolio intro, problem and solution copy on standard posts, including unpublished targets. It is fingerprint-gated for writes, exact-readback verified and rollbackable without exposing draft content in public receipts.\n\nVersion 1.14.8 extends the guarded public ACF editorial route to existing `text`, `textarea` and `wysiwyg` fields that belong to an applicable field group on a published editable post. Multiline/WYSIWYG values remain plain-text only in public-repository mode, keep the existing 1000-byte limit, and retain capability, field-key, stale-state, readback and rollback boundaries.

Version 1.14.7 makes the public sanitized receipt builder self-contained so trusted executors no longer depend on a separately materialized legacy helper. Existing receipt contracts, including connector self-update and bounded portfolio-stat evidence, remain preserved and are covered by an isolated workspace regression test.

Version 1.14.6 fixes the public GitHub trusted-workspace packaging regression introduced in 1.14.5 by provisioning the preserved legacy validator alongside the portfolio-specific validator in every affected request and executor workflow. It adds isolated trusted-workspace regression coverage for portfolio dry/live gating and existing public post, ACF, Elementor, rollback and connector-update routes.

Version 1.14.5 aligns the public GitHub transport prefilter with the guarded portfolio-stat runtime introduced in 1.14.4. A bounded `acf.portfolio_stats_update` dry-run can now reach Runner admission without `confirm=true`; the transport independently enforces the fixed eight-field allowlist and requires an expected fingerprint plus confirmation for live writes. All non-portfolio public request validation continues through the preserved legacy validator unchanged.

Version 1.14.4 hardens the dedicated portfolio-stat action introduced in 1.14.3. Public GitHub execution now uses Runner-level payload admission instead of the self-update-only `public_repository_safe` bypass, confirmed writes require the fingerprint from the preceding dry-run, unpublished portfolio rollbacks use the same bounded action and exact readback, failed-write compensation is readback-verified, and sanitized receipts expose deterministic before/after fingerprints plus mutation readback and rollback evidence without exposing field values.

Version 1.14.3 adds a dedicated, narrowly bounded `acf.portfolio_stats_update` action for the eight existing portfolio-stat text fields on standard WordPress posts, including draft, pending, private, future and password-protected published targets. It does not relax generic public post reads or generic `acf.update`: the action requires edit capability, an applicable ACF field group, exact allowlisted field names, text-only values, confirmation for writes and exact readback, with immediate compensation if a write/readback fails.

Version 1.14.2 fixes the guarded public-runtime contract for post-targeted ACF updates and rollback. Public `acf.update` remains privileged by default, but the runtime may admit only a narrowly validated existing text-field update on one published editable post; rollback snapshots now retain their source/public provenance so public rollback cannot cross into unrelated privileged snapshots.

Version 1.14.1 fixes ACF schema text-field creation so top-level fields use the field group's persistent numeric parent ID, verifies readback against that parent and safely recovers an orphaned generated text field left by an interrupted 1.14.0 schema write.

Version 1.14.0 adds narrowly bounded public-runtime Elementor inspection/settings patching and controlled ACF text-field schema creation for verified public targets. Confirmed writes remain fingerprint-gated, sanitized receipts expose only bounded evidence, and rollback is retained for supported mutations.

Version 1.13.0 made the guarded GitHub runtime zero-config after plugin activation. The WordPress admin no longer requires connector checkboxes, GitHub site variables, WordPress usernames or Application Passwords for the canonical GitHub path. The trusted GitHub Actions executor requests a short-lived GitHub OIDC token and WordPress validates its signature, audience, repository identity, owner identity, main-branch workflow identity, runner environment, validity window and replay identifier before execution.

A minimal HTTPS presence endpoint allows a known site URL to prove that the connector is installed without exposing WordPress content. The target site URL travels with the temporary runtime request and is validated before execution. GitHub cannot securely enumerate arbitrary unknown websites merely because a plugin was installed; global site discovery requires a separate authenticated registry and is intentionally not simulated with public crawling or leaked site lists.

Mutation safety remains request-scoped: real writes require confirm=true; stale-state fingerprints/tokens remain available; mutations remain idempotent and serialized; supported changes retain exact readback and rollback. Public post-targeted ACF writes are additionally limited to existing ACF text, textarea or WYSIWYG fields whose field groups apply to the selected published post. Public textarea/WYSIWYG values must remain plain text without HTML markup, and public rollback is limited to snapshots created by the guarded public runtime itself. Optional WPCONNECTOR_ALLOW_* constants or environment variables can still disable execution classes server-side without requiring WordPress admin setup.

Public GitHub repositories remain deliberately restricted. Public runtime branches may only use the explicit public-safe action contract, sensitive actions stay blocked, full WordPress responses are never persisted publicly, and only sanitized receipts are written back.

Elementor writes use Elementor's document save API for element data and page settings, followed by readback verification. The connector exposes active Elementor capability/usage inventory plus complete V3 Form and V4 Atomic Form inspection/upsert with runtime-schema validation and rollback. Elementor-built pages and posts get an "Export Elementor JSON" row action in WordPress admin; saved templates keep Elementor's native export action, with a connector fallback when that action is unavailable. Pages, posts and saved templates also get an "Import Elementor JSON" action for replacing the target Elementor structure and page settings through the same verified document-save/rollback path.

WordPress Additional CSS can be read and replaced through WordPress core Custom CSS APIs. Writes are bounded, privileged, support stale-state guards, verify exact readback and store a rollback snapshot.

Custom or private plugin ZIPs can be uploaded through the authenticated REST asset endpoint and installed or overwritten through `plugin.install_package`. Packages require a matching SHA-256 checksum and exact plugin identity and are checked for size limits, unsafe paths, symlinks and archive expansion before WordPress Plugin_Upgrader receives them. The generic package action cannot replace the connector itself. Private plugin ZIPs must not be placed on a public GitHub request branch; use direct authenticated REST or a private transport for those packages.

From 1.12.0 onward, `connector.update.check` and `connector.update.apply` provide a dedicated connector self-update route. It is pinned to canonical release assets from `Yolol100/wordpressconnector`, downloads only the canonical package/checksum/SBOM set, verifies GitHub asset digests, checksum bytes, SHA-256 and ZIP identity before overwrite, and performs exact version readback. From 1.12.2 onward these two canonical self-update actions may also run through the guarded public GitHub runtime: the public request must use an empty payload, a dry-run is required to obtain the current fingerprint, and a confirmed apply must carry that fingerprint. Sanitized receipts expose only safe version/integrity evidence. Connector self-updates are intentionally non-rollbackable, so staging remains the preferred first target.

Installations that predate the self-update actions cannot bootstrap themselves through those actions. Such installations require one manual upgrade to a release that contains the updater before future updates can use the automated route.

`system.doctor` reports the universal WordPress runtime as healthy when WordPress bootstrap and database access are available. WP-CLI remains a separate informational capability because authenticated REST is the canonical remote transport and local WP-CLI is optional recovery tooling.

Local WP-CLI commands remain available for host-local diagnostics and recovery.

== Security ==

REST endpoints require HTTPS. The canonical GitHub runtime uses short-lived GitHub Actions OIDC authentication bound to the canonical repository and workflow; ordinary authenticated WordPress administrators remain supported for direct REST access. The connector exposes no generic shell, arbitrary SQL, eval or unrestricted filesystem endpoint.

Confirmed writes still require request confirmation. Sensitive actions require explicit request confirmation and are blocked in public-repository mode. Privileged/system/filesystem action classes can be disabled server-side through WPCONNECTOR_ALLOW_* constants or environment variables. Public GitHub transport never persists full WordPress responses and never permits sensitive actions. The bounded public ACF exception is evaluated dynamically against the exact published post, existing ACF textual-field identity (`text`, `textarea` or `wysiwyg`), applicable field group and edit capability; textarea/WYSIWYG updates are plain-text only in public-repository mode, and the broad privileged ACF action remains blocked outside that bounded case. The dedicated portfolio-stat action is separately limited to the standard `post` type, five normal content statuses, the eight `portfolio_stat_*` text fields and exact readback; public execution is admitted only after payload validation and confirmed writes require the preceding dry-run fingerprint. It does not make unpublished post content readable.

Do not commit credentials, passwords, private plugin ZIPs, payment data, patient/medical records or other sensitive production records to GitHub.

== Changelog ==

= 1.17.4 =
* Track every persisted node during complex ACF field-tree creation so mid-tree failures can be compensated exactly.
* Delete created nodes in reverse order, verify readback and surface compensation failures.
* Add failure-injection runtime coverage proving no parent/subfield residue remains after a nested creation error.

= 1.17.3 =
* Add private/direct `acf.schema.ensure_fields` and `acf.schema.remove_fields` for bounded complex fields in existing field groups.
* Add private/direct `acf.schema.create_field_group` and `acf.schema.delete_field_group` with exact schema ownership/readback guards and rollback.
* Support text, textarea, number, email, URL, image, relationship, group and repeater schemas with bounded nested sub fields and location rules.
* Keep complex schema actions outside the public GitHub allowlist and preserve the existing public text-field schema contract.

= 1.17.2 =
* Trust the exact private `Yolol100/Wordpress` GitHub OIDC executor in addition to the canonical public runtime.
* Keep public-repository restrictions unchanged while private runtime execution can reach the full registered action catalog subject to normal connector security gates.
* Add OIDC regression coverage for repository identity, workflow identity and visibility separation.

= 1.17.1 =
* Keep generic delegated reads compatible with client-exposed WordPress Abilities that explicitly declare readonly=true and destructive=false, while requiring verified active Elementor Core/Pro callback provenance, native execute_guarded and an exact provider-ID match for delegated writes; third-party mutation spoofing remains non-executable.
* Treat explicit `mcp.public=false` as an authoritative discovery/execution opt-out even when `show_in_rest=true`.
* Convert completed over-budget Elementor mutations to bounded terminal successes and provider exceptions/`WP_Error` outcomes to stored terminal failures, preserving stable request-ID idempotency on retry.
* Block delegated Ability mutations inside `connector.batch` because they have no Connector rollback/compensation guarantee.
* Add real WordPress 6.9 Abilities and WordPress 7.1 + Elementor 4.3.4 controlled-runtime coverage, including native read, disposable create-page mutation/readback, spoof rejection and replay.
* Keep delegated Ability writes blocked from public GitHub runtime and preserve native WordPress/Elementor validation, permissions and provider guards.

= 1.17.0 =
* Add `wordpress.ability.execute` for explicitly annotated, client-exposed mutating WordPress Abilities on private/direct authenticated transports.
* Add namespace-filtered Ability discovery so `elementor/*` can expose native Atomic/V4 capabilities without duplicating Elementor business logic.
* Respect an explicit provider `mcp.public=false` execution gate even when the Ability is otherwise REST-exposed.
* Keep mutating Ability dry-run metadata-only; real execution requires normal connector confirmation/idempotency plus native schema, permission and provider guards.
* Keep generic Ability writes out of public GitHub runtime and report no fabricated rollback support.
* Extend contract coverage for Elementor-like non-destructive and destructive Ability execution, namespace filtering, MCP disablement and output redaction.

= 1.16.3 =
* Route Asset CleanUp cache purges from REST execution through a short-lived, single-use internal admin-post loopback so Asset CleanUp Lite loads in its supported non-REST context.
* Protect the loopback with a 256-bit random bearer token stored only as a short-lived SHA-256 transient and consumed before execution.
* Keep cache authorization in the connector policy while using Asset CleanUp's own clearCache completion signals for readback verification.

= 1.16.2 =
* Fix Asset CleanUp cache-flush verification to rely on the plugin's own completion hook and last-clear transient instead of a connector-owned sentinel, preventing false-negative flush failures.

= 1.16.1 =
* Add guarded `custom_css.inspect` and managed `custom_css.patch` support to the public GitHub runtime while keeping full `custom_css.update` off the public allowlist.
* Restrict public CSS actions to the active theme and the WordPress `edit_css` capability.
* Require dry-run fingerprint protection for confirmed CSS patch writes, exact readback and rollback.
* Sanitize public CSS receipts to hashes, byte counts and patch metadata only; never persist CSS bodies.
* Update the WordPress 7.1 runtime matrix to 7.1.3.

= 1.16.0 =
* Add an authenticated native MCP endpoint at /wp-json/webactueel-wordpress-connector/v1/mcp as a thin transport over the existing Registry and Runner.
* Support MCP 2026-07-28 server/discover plus the legacy 2025-11-25 initialize flow with bounded tools/list and tools/call handling.
* Expose only action discovery, runtime discovery and semantic connector execution; preserve WordPress/OIDC authorization and all existing policy/capability gates.
* Keep MCP execution dry-run by default and require confirm=true plus an explicit stable request_id for real writes.
* Add fail-closed modern header/metadata validation and controlled-runtime contract coverage.

= 1.15.11 =
* Allow guarded public overwrite only for `webactueel-mailbox-bridge/webactueel-mailbox-bridge.php` with exact SHA/plugin identity and prior dry-run fingerprint; keep all other public plugin overwrites blocked.

= 1.15.10 =
* Bound core WooCommerce line-item reads with COUNT plus LIMIT 50 for both legacy CPT and HPOS stores, including WooCommerce releases before get_item_ids() existed.
* Fail closed for unknown custom order data stores instead of bypassing their storage contract.
* Declare HPOS compatibility and add MySQL-backed WordPress/WooCommerce runtime CI for legacy and HPOS modes.
* Require WordPress Abilities to declare both readonly=true and destructive=false, and cap discovery at 10,000 registered abilities.

= 1.15.9 =
* Allow guarded public plugin package installation only for publishable, checksum-pinned packages with exact identity, no overwrite/network-wide install, explicit activation intent and prior dry-run fingerprint.

= 1.15.8 =
* Add bounded WooCommerce customer, shipping-zone and tax-class/tax-rate reads with explicit management capability requirements and bounded tax-rate location details.
* Paginate shipping zones and cap each zone's geographic locations; include the default zone at the end of the list and reject noncanonical zone IDs.
* Include the default shipping zone in zone discovery and support subscriber accounts plus customer roles with WooCommerce order history.
* Keep order list summaries free of customer identifiers and line-item details; cap single-order line items at 50 and accept either WooCommerce order-status form.
* Redact credential-shaped values from REST-exposed read-only WordPress Ability results.
* Add isolated WooCommerce read contracts and keep the plugin header and stable tag aligned at 1.15.8.

= 1.15.7 =
* Add `user.force_password_reset` as a sensitive, privileged, confirmed mutation for incident response.
* Rotate the password server-side, revoke all sessions, generate a fresh reset key and send a dedicated security reset link without exposing password or reset-key material.
* Keep the action blocked from public-repository runtime and cover the flow with a regression contract that forbids the normal `retrieve_password()` email path after rotation.

= 1.15.6 =
* Verify Asset CleanUp clearing with a short-lived `wpacu_css_*` sentinel transient removed by the provider-owned cache clear.
* Retain the existing hook/marker evidence as a compatibility fallback and clean the sentinel if verification fails.

= 1.15.5 =
* Align Asset CleanUp REST cache maintenance with the current plugin autoload convention instead of hardcoding legacy helper paths.
* Prefer the current `OptimizeCommon::clearCache()` API, retain legacy `clearAllCache()` fallback, and verify execution through Asset CleanUp's completion hook plus last-clear transient.
* Keep the integration bounded to Asset CleanUp's own classes and cache API; no generic filesystem delete is added.

= 1.15.4 =
* Load Asset CleanUp `Misc`, `Tools`, CSS/JS optimizer and plugin helper classes from its verified plugin root when REST requests do not preload them.
* Keep execution on Asset CleanUp's own `OptimizeCommon::clearAllCache()` API and retain JSON-cache verification; no generic filesystem deletion is added.

= 1.15.3 =
* If `rocket_clean_domain()` cannot provide completion evidence in the REST runtime, fall back only to WP Rocket's own `rocket_clean_cache_dir()` API.
* Verify the fallback with WP Rocket's `after_rocket_clean_cache_dir` completion hook and keep the action bounded to the WP Rocket cache layer.
* Preserve dry-run fingerprint protection, public receipt minimization and all existing cache-maintenance contracts.

= 1.15.2 =
* Accept WP Rocket's successful `rocket_clean_domain()` return or its completion hook as bounded purge verification.
* Load Asset CleanUp's official `OptimizeCommon` cache class from its verified plugin directory when REST requests do not preload it.
* Preserve public receipt schema version and expose cache plugin versions as `provider_version`.

= 1.15.1 =
* Add bounded cache capability discovery and one-layer cache flush for Elementor, WP Rocket and Asset CleanUp.
* Require dry-run fingerprint protection for public cache mutations and provider-specific execution verification.
* Keep cache maintenance non-rollbackable and sanitize public receipts to provider/version/layer/readback metadata only.

= 1.15.0 =
* Add bounded `code_snippets.patch` for one existing site-scoped PHP snippet using 1-4 exact replacements and a unique ID or code marker.
* Require dry-run-first fingerprint protection for confirmed public writes, preserve activation/scope/priority metadata, verify exact readback and store a guarded rollback snapshot.
* Keep arbitrary snippet creation/execution, direct public rollback actions and full snippet source out of the public transport/receipt contract.

= 1.14.9 =
* Add bounded `portfolio.case_text_update` for post content plus the fixed `description_1` and `description_2` ACF fields on standard posts, including unpublished portfolio records.
* Require edit capability, allowed status, exact field identity, dry-run fingerprint for confirmed public writes, exact readback and compensating rollback.
* Keep public receipts content-free by exposing only post id, verification state and before/after fingerprints.

= 1.14.8 =
* Extend guarded public `acf.update` to existing ACF `textarea` and `wysiwyg` fields in addition to `text` fields when the field belongs to an applicable group on the published target.
* Keep public multiline/WYSIWYG values plain-text only, bounded to the existing 1000-byte value limit and protected by the existing field-key, capability, stale-state, readback and rollback controls.
* Add regression coverage for allowed textarea/WYSIWYG updates, non-text rejection and HTML-markup rejection.

= 1.14.7 =
* Make `build-public-receipt.php` self-contained so trusted public executors do not require a separately materialized legacy receipt helper.
* Preserve sanitized connector-update, post, ACF, Elementor, rollback, batch and portfolio-stat receipt behavior.
* Add an isolated workspace regression proving connector update-check/apply and portfolio receipts work with only the main receipt builder present.

= 1.14.6 =
* Provision `validate-public-request-legacy.php` alongside the delegated public validator in every affected WordPress and DoctorCura trusted workspace.
* Add isolated trusted-workspace coverage proving bounded portfolio dry/live validation and existing public post, ACF, Elementor, rollback and connector-update routes still validate.
* Keep unsafe public actions fail-closed and retain fingerprint plus confirmation requirements for live portfolio writes.

= 1.14.5 =
* Admit bounded `acf.portfolio_stats_update` dry-runs through the public GitHub transport without requiring mutation confirmation.
* Enforce the exact eight portfolio field keys, positive integer post ID and bounded text values before dispatching the request.
* Require `confirm=true` and the preceding dry-run fingerprint for live portfolio-stat requests at both transport and Runner layers.
* Preserve all non-portfolio public request validation through the existing validator and add transport regression coverage.

= 1.14.4 =
* Route `acf.portfolio_stats_update` through payload-aware Runner admission instead of the self-update-only `public_repository_safe` marker.
* Require confirmed public portfolio-stat writes to carry the exact fingerprint produced by the preceding dry-run.
* Store portfolio-stat rollback snapshots using the same bounded unpublished-capable action and retain guarded compatibility with existing 1.14.3 snapshots.
* Verify immediate compensation by exact readback if a write or post-write readback fails.
* Emit sanitized portfolio-stat receipts with readback status, deterministic before/after fingerprints and rollback availability without exposing ACF values.
* Add end-to-end contract coverage for guarded draft/private writes, fingerprint enforcement, executable public rollback and receipt minimization.

= 1.14.3 =
* Add dedicated `acf.portfolio_stats_update` for the eight existing portfolio-stat ACF text fields on standard WordPress posts, including unpublished targets.
* Keep generic `post.get`, `post.list` and `acf.update` visibility rules unchanged; the exception cannot expose unpublished post content or update arbitrary ACF fields.
* Require target edit capability, a normal content status, applicable ACF field groups, exact allowlisted field names, text-only values and write confirmation.
* Verify exact ACF readback and immediately restore the captured pre-write values if any field write or readback fails.
* Add regression coverage for draft/private success and custom post types, Trash, non-text, wrong-group, non-allowlisted and malformed field failures.

= 1.14.2 =
* Align the public transport and runtime contracts for post-targeted `acf.update` without marking the broad privileged ACF action public-safe.
* Permit only existing ACF text field keys whose field groups apply to the selected published, non-password-protected post and require target edit capability.
* Reject field names, options/user/term targets, non-text fields, secret-like names, unknown payload keys and fields outside the target post's applicable groups.
* Persist rollback source/public provenance and allow guarded public rollback only for eligible snapshots created by the public runtime itself.
* Add regression coverage proving the raw privileged ACF action remains blocked while the bounded runtime exception succeeds and unsafe variants fail closed.

= 1.14.1 =
* Resolve ACF field-group keys to persistent numeric group IDs before creating top-level text fields.
* Verify ACF schema readback and rollback against the persistent parent ID returned by ACF.
* Recover only matching generated orphan text fields with parent 0, while failing closed on conflicting global fields.
* Append newly created/recovered fields with deterministic menu order and add regression coverage for the parent/orphan boundary.

= 1.14.0 =
* Add bounded public-runtime `elementor.inspect` and settings-only `elementor.patch_element` with mandatory fingerprint protection for confirmed writes.
* Add sanitized Elementor receipts that expose only safe element metadata, selected public settings, fingerprints and rollback/readback evidence.
* Add bounded ACF field-group discovery and text-field schema creation for existing field groups that apply to verified public posts.
* Preserve existing ACF fields and values; schema rollback removes only exact fields created by the guarded schema action.
* Add regression coverage for forbidden full-element replacement, widget-type changes, secret-like fields, unsafe identifiers, schema-in-batch attempts and public receipt leakage.

= 1.13.0 =
* Replace long-lived GitHub-to-WordPress Application Password transport with short-lived GitHub Actions OIDC for the canonical GitHub executor.
* Remove normal connector setup checkboxes, GitHub site variables and WordPress credential requirements from the canonical GitHub route.
* Add an HTTPS-only zero-config presence endpoint and validated per-request `site_url` targeting.
* Bind OIDC tokens to the canonical repository, repository/owner IDs, main-branch workflow, GitHub-hosted runner, site-specific audience, validity window and replay protection.
* Preserve public-repository privacy restrictions, request confirmation, WordPress capabilities, stale-state protection, idempotency, readback and rollback.
* Keep optional server-side WPCONNECTOR_ALLOW_* overrides as emergency kill switches without WordPress admin setup.

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
* Add "Import Elementor JSON" actions for WordPress Pages, Posts and Saved Templates.
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
* Save Elementor element data and document settings through Elementor's document API instead of direct _elementor_data mutation.
* Add exact Elementor readback checks after create, replace and patch operations.
* Expand the WordPress settings page with the canonical GitHub Actions setup and legacy-bridge migration guidance.

= 1.1.0 =
* Add authenticated HTTPS REST transport using the existing connector action registry.
* Add request-scoped asset upload transport with strict path and size limits.
* Add WordPress-side execution gate settings.
* Switch the default GitHub request workflow to automatically provisioned ubuntu-latest runners.

= 1.0.0 =
* Initial complete connector runtime.
