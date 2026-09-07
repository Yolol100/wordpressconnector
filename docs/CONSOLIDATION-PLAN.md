# WordPress Connector consolidation

Repository-level consolidation is complete.

## Canonical ownership

- Live WordPress/Elementor read/write/rollback bridge: `Yolol100/wordpressconnector`.
- Elementor JSON validation, disposable import/roundtrip/render/browser evidence: `Yolol100/elementorjson`.
- Legacy source only: `Yolol100/Elementorconnector`.

## Preserved capability mapping

WordPress Connector owns WordPress content, Gutenberg, Elementor documents/capabilities/forms, ACF, WooCommerce, Yoast, media, runtime locking, idempotency, stale-state protection, exact readback and rollback.

Version 1.9.0 closes the core repository parity gaps:

- admin Elementor JSON import can replace an explicit existing Page/Post/Template or create a new draft;
- Page/Post export can include the matching Elementor Pro Theme Builder header/footer as a site-parts bundle;
- `expected_state_token` adds a site-scoped HMAC stale-state guard alongside `expected_fingerprint`;
- the retired GitHub `requests/`, `results/`, request schemas/examples and request/execute workflows are removed.

Version 1.9.1 adds bounded **Export Elementor JSON (ZIP)** for multiple Pages, Posts and Saved Templates, with per-item permission checks, a manifest, a 100-item limit and a 50 MB generated-JSON limit.

## Removal gate for Elementorconnector

`Yolol100/Elementorconnector` can be removed after all of these are true:

1. WordPress Connector 1.9.1+ is installed on every site that previously used Elementorconnector.
2. Read-only capability checks pass.
3. A representative Elementor create/replace/readback test passes on staging.
4. A rollback test passes.
5. Page/Post import create + replace pass.
6. Saved Template import/export passes.
7. Bulk **Export Elementor JSON (ZIP)** passes for a mixed selection and its `manifest.json` matches the exported/skipped items.
8. Theme Builder site-parts export passes where Elementor Pro is used.
9. No active WordPress site still has the legacy Elementor JSON Bridge enabled.

`Yolol100/elementorjson` must remain; it is the separate QA/evidence layer.
