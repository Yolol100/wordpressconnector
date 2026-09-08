# WordPress Connector

> **Status:** canonical live WordPress + Elementor bridge for Webactueel.

WordPress Connector is the single production/runtime connector for WordPress, Elementor, Gutenberg, WooCommerce, ACF, Yoast, media and bounded administration actions.

Primary route:

`approved client -> authenticated HTTPS REST -> WordPress Connector -> WordPress/Elementor -> exact readback + rollback`

Optional GitHub route:

`temporary request PR -> secretless guard -> trusted main executor -> authenticated HTTPS REST -> WordPress Connector -> private result or sanitized public receipt`

The GitHub route is a transport client, not a second connector. It uses GitHub-hosted runners, keeps WordPress credentials only in GitHub Actions Secrets and preserves connector security gates, strict input validation, stale-state guards, idempotency, readback and rollback.

## Main capabilities

- posts, pages, CPTs, taxonomies, menus, revisions and metadata;
- Gutenberg content and nested block updates;
- Elementor inspect/create/replace/patch via Elementor document APIs;
- Elementor Core/Pro/add-on capability and usage inventory;
- Elementor V3/V4 form control;
- WooCommerce products, variations, attributes and coupons;
- ACF fields and field groups;
- Yoast SEO metadata/settings;
- WordPress Additional CSS inspect/update through core Custom CSS APIs;
- media import and assignment;
- verified plugin ZIP install/overwrite through authenticated REST request assets;
- bounded plugin/theme filesystem inspection and replacement;
- canonical connector self-update from verified GitHub releases;
- dry-run, confirmations, privileged/sensitive gates, idempotency, mutation locks, exact readback and rollback.

`connector.discover` is the canonical site-overview action: it returns WordPress/PHP runtime data, active theme, installed plugins, post types, taxonomies, builders, media sizes and the registered action catalog without requiring a second connector plugin.

## GitHub runtime

The optional GitHub runtime provides repository-driven WordPress operations without a self-hosted runner.

- `.github/workflows/wordpress-request.yml` validates a same-repository request PR without WordPress credentials.
- `.github/workflows/wordpress-execute.yml` runs only through trusted `workflow_dispatch` on `main`, revalidates the exact PR head and then calls the connector over HTTPS.
- `scripts/validate-request.php` validates the common request envelope.
- `scripts/validate-public-request.php` adds the restricted public-repository policy.
- `scripts/build-public-receipt.php` converts a full in-run connector response into a minimized public receipt.
- Runtime requests, results and receipts live only on short-lived request branches and must never be merged to `main`.

### Private repository mode

Private repositories keep the broad transport behavior. A trusted request may use the connector action catalog, and the full connector result can be written back under `results/*.json` on the temporary request branch.

### Public repository mode

Public repositories fail closed to a deliberately small contract instead of exposing broad privileged WordPress operations or full responses.

Allowed actions are currently:

- `post.update` for an existing published post/page/CPT;
- `acf.update` for a positive integer post target only;
- `connector.batch` containing only those two content operations;
- `connector.rollback` for a known connector request id;
- `connector.update.check` with an empty payload;
- `connector.update.apply` with an empty payload, using dry-run first and `expected_fingerprint` plus `confirm:true` for a real update.

The two connector update actions are the only privileged actions explicitly marked `public_repository_safe`. They still require the normal WordPress privileged gate; a real apply also requires the write and system-update gates. The updater is pinned to release assets from `Yolol100/wordpressconnector`, validates SHA-256, package structure, plugin identity and version, and performs exact installed-version readback.

`plugin.install_package` is intentionally **not** public-safe. Never put a private/custom plugin ZIP on a public GitHub request branch. Use direct authenticated REST or a private repository transport for private packages.

Public requests reject secret-like payload keys, non-post ACF targets, non-published status changes and `expected_state_token`. Use `expected_fingerprint` for stale-state protection in public mode.

The executor never commits the full WordPress response in public mode. It writes only `receipts/<request_id>.json` with minimized execution evidence. Content operations expose readback status and deterministic fingerprints. Connector self-update receipts may additionally expose safe semantic versions and the verified package SHA-256. The connector health version may be included as `connector_version`; health gates, user ids, before/after content, state tokens, rollback payloads and raw connector errors are not persisted.

See `docs/SETUP.md` for the required GitHub variable/secrets and request flow.

## REST plugin package delivery

`plugin.install` remains the WordPress.org-slug installer. Custom/private plugin packages use `plugin.install_package` instead.

The package flow is deliberately two-step:

1. upload one ZIP through `/wp-json/webactueel-wordpress-connector/v1/assets` using an `asset_path` under `plugin-packages/`;
2. execute `plugin.install_package` through `/wp-json/webactueel-wordpress-connector/v1/execute` with the matching `source_path`, SHA-256 checksum and exact `expected_plugin` file.

The package action is a privileged system-update mutation. Real writes therefore require the normal write gate, privileged gate, system-update gate and `confirm:true`. ZIP packages are bounded and inspected before WordPress' `Plugin_Upgrader` receives them: checksum, size, path traversal, control characters, duplicate paths, symlinks, archive expansion, top-level structure and exact plugin identity are validated. Only a main plugin header directly inside the single top-level plugin directory is considered. The connector refuses to replace its own active runtime through this action.

Use `dry_run:true` first. REST execution deliberately cleans request assets after every attempt, including dry-run. Re-upload the exact same ZIP with the same request ID/path before the confirmed call and pass the dry-run `current_state_token` as `expected_state_token`. The checksum proves the re-uploaded package bytes are unchanged.

See `docs/PLUGIN-PACKAGE-REST.md` for the exact request sequence and payloads.

## Connector self-update

From 1.12.2 onward the canonical self-update can also be driven through a public GitHub request without publishing package bytes or the full WordPress response. The request payload is always empty: WordPress resolves only the latest canonical `Yolol100/wordpressconnector` release itself.

Use this sequence:

1. run `connector.update.apply` with `dry_run:true`, `confirm:false` and an empty payload;
2. require `update_plan_verified:true` and read `before_fingerprint` from the sanitized receipt;
3. run `connector.update.apply` again with `dry_run:false`, `confirm:true`, the same empty payload and that `expected_fingerprint`;
4. require `readback_verified:true` and check `to_version` plus `package_sha256` in the sanitized receipt;
5. on a later request, verify `connector_version` reports the installed version.

Installations that do not yet contain `connector.update.apply` cannot bootstrap themselves through it. They require one manual installation of a newer release first; after that, subsequent updates can use this route.

## Elementor JSON in WordPress admin

Pages and Posts built with Elementor show **Export Elementor JSON** in their row actions.

When Elementor Pro Theme Builder is available, Pages and Posts also show **Export Elementor + Site Parts**. That bundle contains the page/post document plus the matching Theme Builder header/footer when they can be resolved safely.

Saved Templates keep Elementor's native export. WordPress Connector provides a fallback export only when the native action is absent.

Pages, Posts and Saved Templates also expose **Import Elementor JSON**. You can replace the Elementor structure/settings of an explicitly selected existing item or create a new draft from uploaded Elementor JSON.

Imports use `ElementorAdapter` document create/save/readback/rollback paths. Direct `_elementor_data` writes are not used.

## State safety

Mutations support both:

- `expected_fingerprint`: deterministic SHA-256 stale-state guard;
- `expected_state_token`: site-scoped HMAC state guard derived from the same fingerprint and the WordPress auth salt.

Read actions that return a fingerprint also return a `state_token`. Dry-run mutation previews expose `current_state_token` when a current fingerprint is available. Public GitHub runtime requests deliberately do not publish state tokens; their sanitized receipts expose deterministic fingerprints instead.

## Installation

Install `plugin/wordpressconnector` in WordPress and activate it. The plugin exposes authenticated HTTPS REST routes under `/wp-json/webactueel-wordpress-connector/v1/`, the shared semantic action registry, WordPress admin settings and optional WP-CLI commands.

Start read-only. Verify `connector.discover`, `system.doctor`, Elementor capabilities and representative content reads before enabling writes. Use staging or equivalent target-runtime evidence for first disposable writes and rollback tests.

## Repository roles

- `Yolol100/wordpressconnector`: live WordPress/Elementor bridge plus optional guarded GitHub HTTPS transport.
- `Yolol100/elementorjson`: separate Elementor JSON validation/import-roundtrip/render/browser QA lab.
- `Yolol100/Elementorconnector`: legacy migration source only.
