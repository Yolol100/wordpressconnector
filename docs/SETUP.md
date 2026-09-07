# Setup

## 1. Install

Install and activate `plugin/wordpressconnector` on the target WordPress site.

The plugin registers:

- authenticated HTTPS REST routes under `/wp-json/webactueel-wordpress-connector/v1/`;
- the semantic action registry;
- `Settings -> WordPress Connector` security gates;
- optional `wp wordpress-connector ...` WP-CLI commands.

## 2. Connect

Use WP Agent or another approved client that can authenticate to the connector over HTTPS. The old GitHub `requests/ -> workflow -> results/` transport is retired and should not be configured.

## 3. Security defaults

Keep real writes, privileged actions, sensitive actions, system updates and filesystem writes disabled until required. REST requires HTTPS, an authenticated WordPress user and the configured connector capability checks.

## 4. Acceptance sequence

1. Verify `/health` and `connector.discover`.
2. Run `system.doctor`.
3. Read one normal page/post.
4. Inspect one Elementor page/template and `elementor.capabilities`.
5. Read representative ACF, WooCommerce, Yoast and media data where applicable.
6. Run dry-runs for the intended mutations.
7. On staging, perform a disposable write and rollback.
8. Test Elementor JSON export/import for Page, Post and Saved Template.
9. Select multiple Elementor items and verify **Export Elementor JSON (ZIP)** contains one JSON file per exported item plus `manifest.json`; verify non-Elementor items are skipped rather than exported.
10. If Elementor Pro Theme Builder is used, test **Export Elementor + Site Parts** on a page with an assigned header/footer.
11. Only then enable the minimum production write gates required for the workflow.

## 5. Elementor JSON admin flow

From Pages, Posts or Saved Templates, use the row action **Import Elementor JSON**. The import screen can replace an explicit existing target or create a new draft.

For Pages and Posts built with Elementor, use **Export Elementor JSON**. When Theme Builder is available, **Export Elementor + Site Parts** adds the active header/footer to a portable connector bundle.

For multiple Pages, Posts or Saved Templates, select the items and choose **Export Elementor JSON (ZIP)** under Bulk actions. One request accepts at most 100 selected items and 50 MB of generated JSON. Over-limit requests fail before a partial ZIP is downloaded.

## 6. Optional WP-CLI

WP-CLI remains a local maintenance/recovery transport. It is not required for the default WP Agent/REST route.
