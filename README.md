# WordPress Connector

> **Status:** canonical live WordPress + Elementor bridge for Webactueel.

WordPress Connector is the single production/runtime connector for WordPress, Elementor, Gutenberg, WooCommerce, ACF, Yoast, media and bounded administration actions.

Primary route:

`approved client -> authenticated HTTPS REST -> WordPress Connector -> WordPress/Elementor -> exact readback + rollback`

Optional private GitHub route:

`temporary request PR -> secretless guard -> trusted main executor -> authenticated HTTPS REST -> WordPress Connector -> result on request branch`

The GitHub route is a transport client, not a second connector. It is private-repository only, uses GitHub-hosted runners, keeps WordPress credentials only in GitHub Actions Secrets and preserves all connector security gates, stale-state guards, idempotency, readback and rollback.

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

## Private GitHub runtime

The optional GitHub runtime restores repository-driven WordPress operations without returning to the old self-hosted runner model.

- `.github/workflows/wordpress-request.yml` validates a same-repository request PR without WordPress credentials.
- `.github/workflows/wordpress-execute.yml` runs only through trusted `workflow_dispatch` on `main`, revalidates the exact PR head and then calls the connector over HTTPS.
- `scripts/validate-request.php` validates the request envelope, including `expected_fingerprint` and `expected_state_token`.
- Runtime requests and results live only on short-lived request branches and must never be merged to `main`.
- Public repositories are rejected by both runtime workflows.

See `docs/SETUP.md` for the required GitHub variable/secrets and the request flow.

## REST plugin package delivery

`plugin.install` remains the WordPress.org-slug installer. Custom/private plugin packages use `plugin.install_package` instead.

The package flow is deliberately two-step:

1. upload one ZIP through `/wp-json/webactueel-wordpress-connector/v1/assets` using an `asset_path` under `plugin-packages/`;
2. execute `plugin.install_package` through `/wp-json/webactueel-wordpress-connector/v1/execute` with the matching `source_path`, SHA-256 checksum and exact `expected_plugin` file.

The package action is a privileged system-update mutation. Real writes require the normal write gate, privileged gate, system-update gate and `confirm:true`. Packages are bounded and inspected before WordPress' `Plugin_Upgrader` receives them. The connector refuses to replace its own active runtime through this action.

## Elementor JSON in WordPress admin

Pages and Posts built with Elementor show **Export Elementor JSON**. When Elementor Pro Theme Builder is available, **Export Elementor + Site Parts** also includes the safely resolved active header/footer.

Pages, Posts and Saved Templates expose **Import Elementor JSON** for explicit replacement or creation of a new draft. Imports use Elementor document APIs; direct `_elementor_data` writes are not used.

## State safety

Mutations support both:

- `expected_fingerprint`: deterministic SHA-256 stale-state guard;
- `expected_state_token`: site-scoped HMAC state guard derived from the same fingerprint and the WordPress auth salt.

Read actions that return a fingerprint also return a `state_token`. Dry-run mutation previews expose `current_state_token` when available.

## Installation

Install `plugin/wordpressconnector` in WordPress and activate it. The plugin exposes authenticated HTTPS REST routes under `/wp-json/webactueel-wordpress-connector/v1/`, the semantic action registry, WordPress admin settings and optional WP-CLI commands.

Start read-only. Verify health/discovery and representative reads before writes. Use staging or equivalent target-runtime evidence for first disposable writes and rollback tests.

## Repository roles

- `Yolol100/wordpressconnector`: live WordPress/Elementor bridge plus optional private GitHub HTTPS transport.
- `Yolol100/elementorjson`: separate Elementor JSON validation/import-roundtrip/render/browser QA lab.
- `Yolol100/Elementorconnector`: legacy migration source only.
