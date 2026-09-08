# WordPress Connector

> **Status:** canonical live WordPress + Elementor bridge for Webactueel.

WordPress Connector is the single production/runtime connector for WordPress, Elementor, Gutenberg, WooCommerce, ACF, Yoast, media and bounded administration actions.

Canonical route:

`ChatGPT / WP Agent -> authenticated HTTPS REST -> WordPress Connector -> WordPress/Elementor -> exact readback + rollback`

The old GitHub request/result execution transport is removed. GitHub remains source control and CI only.

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
- dry-run, confirmations, privileged/sensitive gates, idempotency, mutation locks, exact readback and rollback.

`connector.discover` is the canonical site-overview action: it returns WordPress/PHP runtime data, active theme, installed plugins, post types, taxonomies, builders, media sizes and the registered action catalog without requiring a second connector plugin.

## REST plugin package delivery

`plugin.install` remains the WordPress.org-slug installer. Custom/private plugin packages use `plugin.install_package` instead.

The package flow is deliberately two-step:

1. upload one ZIP through `/wp-json/webactueel-wordpress-connector/v1/assets` using an `asset_path` under `plugin-packages/`;
2. execute `plugin.install_package` through `/wp-json/webactueel-wordpress-connector/v1/execute` with the matching `source_path`, SHA-256 checksum and exact `expected_plugin` file.

The package action is a privileged system-update mutation. Real writes therefore require the normal write gate, privileged gate, system-update gate and `confirm:true`. ZIP packages are bounded and inspected before WordPress' `Plugin_Upgrader` receives them: checksum, size, path traversal, control characters, duplicate paths, symlinks, archive expansion, top-level structure and exact plugin identity are validated. Only a main plugin header directly inside the single top-level plugin directory is considered. The connector refuses to replace its own active runtime through this action.

Use `dry_run:true` first. REST execution deliberately cleans request assets after every attempt, including dry-run. Re-upload the exact same ZIP with the same request ID/path before the confirmed call and pass the dry-run `current_state_token` as `expected_state_token`. The checksum proves the re-uploaded package bytes are unchanged.

See `docs/PLUGIN-PACKAGE-REST.md` for the exact request sequence and payloads.

## Elementor JSON in WordPress admin

Pages and Posts built with Elementor show **Export Elementor JSON** in their row actions.

When Elementor Pro Theme Builder is available, Pages and Posts also show **Export Elementor + Site Parts**. That bundle contains the page/post document plus the matching Theme Builder header/footer when they can be resolved safely.

Saved Templates keep Elementor's native export. WordPress Connector provides a fallback export only when the native action is absent.

Pages, Posts and Saved Templates also expose **Import Elementor JSON**. You can:

- replace the Elementor structure/settings of an explicitly selected existing item; or
- create a new draft from the uploaded Elementor JSON.

Imports use `ElementorAdapter` document create/save/readback/rollback paths. Direct `_elementor_data` writes are not used.

## State safety

Mutations support both:

- `expected_fingerprint`: deterministic SHA-256 stale-state guard;
- `expected_state_token`: site-scoped HMAC state guard derived from the same fingerprint and the WordPress auth salt.

Read actions that return a fingerprint also return a `state_token`. Dry-run mutation previews expose `current_state_token` when a current fingerprint is available.

## Installation

Install `plugin/wordpressconnector` in WordPress and activate it. The plugin exposes authenticated HTTPS REST routes under `/wp-json/webactueel-wordpress-connector/v1/`, the shared semantic action registry, WordPress admin settings and optional WP-CLI commands.

Start read-only. Verify `connector.discover`, `system.doctor`, Elementor capabilities and representative content reads before enabling writes. Use staging for the first disposable writes and rollback tests.

## Repository roles

- `Yolol100/wordpressconnector`: live WordPress/Elementor bridge.
- `Yolol100/elementorjson`: separate Elementor JSON validation/import-roundtrip/render/browser QA lab.
- `Yolol100/Elementorconnector`: legacy migration source only; removable after runtime parity is accepted and no site still runs that plugin.
