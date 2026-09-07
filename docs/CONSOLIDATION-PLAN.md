# WordPress Connector consolidation

This branch completes the repository-level consolidation of `Yolol100/Elementorconnector` into `Yolol100/wordpressconnector` while keeping `Yolol100/elementorjson` separate as the controlled Elementor JSON QA/validation lab.

## Canonical ownership

- Live WordPress/Elementor read/write/rollback bridge: `Yolol100/wordpressconnector`.
- Elementor JSON validation, disposable import/roundtrip/render evidence: `Yolol100/elementorjson`.
- Legacy migration source only: `Yolol100/Elementorconnector`.

## Preserved Elementorconnector capability mapping

The old bridge's runtime responsibilities are retained in WordPress Connector through the existing adapters and safety/runtime layers:

- WordPress posts/pages/CPT/taxonomies -> `CoreAdapter` and `GutenbergAdapter`.
- Elementor document inspect/create/replace/patch/save/readback -> `ElementorAdapter`.
- Elementor Core/Pro/add-on/widget/breakpoint capability inventory -> `ElementorCapabilitiesAdapter`.
- Elementor V3/V4 forms -> `ElementorFormsAdapter`.
- ACF field identity and values -> `AcfAdapter`.
- WooCommerce products/variations/taxonomies/coupons -> `WooCommerceAdapter`.
- Yoast SEO metadata/settings -> `YoastAdapter`.
- Same-site media/import/metadata -> `MediaAdapter`.
- WordPress Abilities discovery -> `AbilitiesAdapter`.
- State fingerprinting, idempotency, request locks, snapshots, readback and rollback -> `Runtime/*`, `Security/*`, and adapter-specific rollback logic.
- Controlled GitHub/HTTPS request execution -> `REST/*`, `Runtime/Runner.php`, and the repository workflows.

No direct `_elementor_data` write path is reintroduced.

## Elementor JSON admin UX

WordPress Connector 1.8.0 owns the single live admin implementation:

- Elementor-built Pages and Posts: row action **Export Elementor JSON**.
- Saved Templates: Elementor native export remains canonical, with connector fallback when missing.
- Pages, Posts and Saved Templates: row action/menu **Import Elementor JSON**.
- Import accepts standard Elementor document JSON and replaces the selected target's Elementor `content` and `page_settings` through `ElementorAdapter`.
- Import performs the normal Elementor document API save, exact readback and automatic rollback on failure.

`elementorjson` documents and validates this transfer contract but does not become a second production bridge.

## Removal gate

`Yolol100/Elementorconnector` is the repository that becomes removable/archiveable after this consolidation is merged **and** the target staging runtime passes the parity checklist in `docs/ELEMENTORCONNECTOR-MIGRATION.md`.

Do not remove `Yolol100/elementorjson`; it remains the separate QA/validation evidence layer.
