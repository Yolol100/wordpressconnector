# Elementorconnector -> wordpressconnector migration

## Status

Source consolidation is complete in WordPress Connector 1.9.0. `Yolol100/Elementorconnector` is legacy-only and receives no new product capability.

## Canonical route

`ChatGPT / WP Agent -> WordPress Connector REST -> WordPress/Elementor -> exact readback + rollback`

The old Elementorconnector request/result workflows were retired. **Current WordPress Connector** still uses `.github/workflows/wordpress-request.yml` and `.github/workflows/wordpress-zero-config-execute.yml` as an active authenticated GitHub OIDC transport, separate from direct REST/MCP.

## Parity retained in WordPress Connector

- Elementor document inspect/create/replace/patch through Elementor APIs;
- Elementor Core/Pro/add-on capability inventory;
- Elementor V3/V4 forms;
- ACF, WooCommerce, Yoast and media adapters;
- mutation lock, idempotency and rollback snapshots;
- `expected_fingerprint` plus site-scoped `expected_state_token` stale-state guards;
- Page/Post Elementor JSON export;
- Page/Post/Saved Template JSON import with explicit replace or create-new flow;
- Saved Template native export fallback;
- optional Page/Post export bundle containing the matching Theme Builder header/footer;
- bulk Elementor JSON ZIP export for Pages, Posts, and Saved Templates with `manifest.json` and bounded limits (removal-gate parity in 1.9.1+).

## Runtime removal checklist

Before deleting the legacy repository/plugin, verify on staging:

- `connector.discover`, `system.doctor`, `elementor.capabilities`;
- Elementor inspect/create/replace with exact readback;
- stale fingerprint/token rejection before write;
- idempotent repeated request behavior;
- forced-failure rollback;
- Page/Post JSON create + replace;
- Saved Template import/export;
- bulk JSON ZIP export and `manifest.json` with bounded limits;
- Theme Builder site-parts export when Elementor Pro is present;
- representative ACF/WooCommerce/Yoast/media reads and required writes.

After the target sites run WordPress Connector 1.9.1+ and this checklist passes with independent owner/Website QA acceptance, deactivate the old Elementor JSON Bridge plugin and remove/archive `Yolol100/Elementorconnector`.
