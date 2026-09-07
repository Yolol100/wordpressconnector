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
- media import and assignment;
- bounded plugin/theme filesystem inspection and replacement;
- dry-run, confirmations, privileged/sensitive gates, idempotency, mutation locks, exact readback and rollback.

## Elementor JSON in WordPress admin

Pages and Posts built with Elementor show **Export Elementor JSON** in their row actions.

When Elementor Pro Theme Builder is available, Pages and Posts also show **Export Elementor + Site Parts**. That bundle contains the page/post document plus the matching Theme Builder header/footer when they can be resolved safely.

Saved Templates keep Elementor's native export. WordPress Connector provides a fallback export only when the native action is absent.

Pages, Posts and Saved Templates also expose **Import Elementor JSON**. You can:

- replace the Elementor structure/settings of an explicitly selected existing item; or
- create a new draft from the uploaded Elementor JSON.

For multiple Pages, Posts or Saved Templates, select the items and choose **Export Elementor JSON (ZIP)** under Bulk actions. The ZIP contains one JSON file per exported Elementor document plus `manifest.json` with exported and skipped items. One run is limited to 100 selected items and 50 MB of generated JSON; larger selections fail before a partial download is produced.

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
