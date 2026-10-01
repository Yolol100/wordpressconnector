# Capability scope

The connector covers the editable WordPress object model without becoming an unrestricted remote-code channel.

## WordPress Core

Pages, posts, registered custom post types, revisions, trash/restore, registered metadata, taxonomies, classic menus, theme mods, comments, options, network options and WordPress Additional CSS.

Additional CSS is handled through WordPress core `wp_get_custom_css()`, `wp_get_custom_css_post()` and `wp_update_custom_css_post()` APIs. The connector does not write theme stylesheets directly for this feature.

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

Products, variations, global attributes, taxonomies and coupons use WooCommerce CRUD APIs. Orders support bounded read-only summaries through WooCommerce's order query/CRUD APIs; billing and shipping personal data is omitted by default and can only be requested for an individual order. Individual order line items are capped at 50. For WooCommerce core CPT and HPOS stores, the connector reads a bounded count plus at most 50 IDs from WooCommerce's shared order-item table before hydrating items through WooCommerce; unknown custom order data stores fail closed instead of being bypassed. One WooCommerce customer can be read by ID; contact and address data is omitted by default and requires an explicit per-customer request. Tax classes/rates and shipping zone locations are read-only; shipping method settings are excluded because plugins may place credentials or other secrets there. Order/refund/payment/subscription writes and bulk customer exports remain outside the generic connector surface.

## ACF

Read/update/delete ACF values and inspect field groups through ACF APIs, preserving installed field identity and structure.

## Media

List/read/import/update attachment metadata and assign featured images, WooCommerce galleries, Custom Logo and Site Icon. REST asset uploads are bounded and request-scoped.

## Administration

Explicit privileged actions cover users/roles, plugin/theme lifecycle, WordPress core updates, cron, rewrite/cache and multisite management. Software lifecycle actions require the system-update gate.

## Plugin abilities

The WordPress Abilities API catalog discovers exposed abilities from installed plugins and themes in bounded pages of at most 10 descriptors. Oversized schemas are omitted rather than partially returned, and each catalog page is capped at 256 KiB. On WordPress 6.9+, REST-exposed abilities can be invoked only when they explicitly declare both `readonly=true` and `destructive=false`; native input validation and the ability permission callback still run. Discovery fails closed above 10,000 registered abilities. Reading requires explicit confirmation, privileged access and `manage_options`. Mutating abilities are not run through this generic route.

## State and extension contract

Mutations support idempotency, locking, `expected_fingerprint`, site-scoped `expected_state_token`, readback and rollback. Other plugins may register semantic actions through `wpconnector_register_actions`; every extension must declare security metadata and use its owner's supported API/storage contract.
