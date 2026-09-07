# Capability scope

The connector covers the editable WordPress object model without becoming an unrestricted remote-code channel.

## WordPress Core

Pages, posts, registered custom post types, revisions, trash/restore, registered metadata, taxonomies, classic menus, theme mods, comments, options and network options.

## Gutenberg

Parse, inspect, replace and patch serialized block trees using WordPress block parsing/serialization while preserving unrelated blocks.

## Elementor

Inspect/create/replace Elementor documents and patch nested elements by stable Elementor element ID. Elementor page settings, edit mode, template type, Theme Builder conditions, capability inventory and V3/V4 forms are supported through Elementor APIs.

WordPress admin adds:

- **Export Elementor JSON** for Elementor-built Pages and Posts;
- Saved Template native export with connector fallback when Elementor's row action is absent;
- **Export Elementor + Site Parts** for Pages/Posts when Elementor Pro Theme Builder can resolve the active header/footer;
- **Import Elementor JSON** for Pages, Posts and Saved Templates, with explicit replace-existing or create-new-draft behavior.

Imports and connector mutations do not directly write `_elementor_data`.

## WooCommerce

Products, variations, global attributes, taxonomies and coupons use WooCommerce CRUD APIs. Transactional orders/refunds/payments/subscriptions and generic customer exports remain outside the generic connector surface.

## ACF

Read/update/delete ACF values and inspect field groups through ACF APIs, preserving installed field identity and structure.

## Media

List/read/import/update attachment metadata and assign featured images, WooCommerce galleries, Custom Logo and Site Icon. REST asset uploads are bounded and request-scoped.

## Administration

Explicit privileged actions cover users/roles, plugin/theme lifecycle, WordPress core updates, cron, rewrite/cache and multisite management. Software lifecycle actions require the system-update gate.

## State and extension contract

Mutations support idempotency, locking, `expected_fingerprint`, site-scoped `expected_state_token`, readback and rollback. Other plugins may register semantic actions through `wpconnector_register_actions`; every extension must declare security metadata and use its owner's supported API/storage contract.
