# Capability scope

WordPress Connector exists only for advanced WordPress-side capabilities that WP Agent or WordPress core do not already cover safely enough.

## Keep

- advanced Elementor document/forms/capability operations;
- Gutenberg semantic block operations when richer than generic REST edits;
- ACF field and field-group operations;
- WooCommerce product, variation, attribute and coupon operations;
- Yoast-specific metadata operations;
- media operations that need connector-side semantics;
- bounded filesystem inspection and controlled existing plugin/theme text-file replacement;
- selected plugin settings and advanced administration;
- capability discovery, dry-run, readback, idempotency, stale-state fingerprints, batch compensation and rollback.

## Prefer upstream capabilities first

If WP Agent, WordPress core REST, WooCommerce or a stable WordPress/plugin Ability already provides the required safe operation, prefer that capability instead of adding duplicate connector code.

## Exclude

The connector must not become a generic remote execution channel. It intentionally excludes arbitrary PHP, shell/process execution, SQL, unrestricted filesystem access, generic HTTP proxying, credential export and generic transactional/private-record access.

## Extension rule

Add a new adapter only when a real capability gap exists and the owning plugin/platform exposes a supported API or storage contract that can be wrapped with explicit security classification, validation, readback and rollback where applicable.
