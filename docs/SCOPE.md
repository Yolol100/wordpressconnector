# Capability scope

The connector aims to cover the editable WordPress object model without becoming an unrestricted remote-code channel.

## WordPress Core

Pages, posts, registered custom post types, status/title/slug/content/excerpt/author/parent/menu order/template, featured images, revisions, trash/restore, generic registered metadata, categories/tags/custom taxonomies, term metadata, classic menus, theme mods, comments, options and network options.

Full Site Editing objects such as templates, template parts, navigation, patterns and global styles are WordPress post types and can be discovered/managed where their runtime representation permits it. Non-public object types are not exported in public-repository mode.

## Gutenberg

Parse, inspect, replace and patch serialized block trees using WordPress block parsing/serialization rather than regular-expression editing. Nested block paths and attributes can be updated while preserving unrelated blocks.

## Elementor

Inspect/create/replace Elementor documents and patch nested elements by stable Elementor element ID. Supports Elementor document JSON, edit mode, page settings, template type and stored Theme Builder conditions. Cache/CSS invalidation is requested after writes when Elementor exposes the relevant API.

## WooCommerce

Products and variations are managed through WooCommerce product CRUD objects, not direct product-table/postmeta assumptions. Supported surface includes descriptions, SKU, pricing/sales, stock/backorders, tax, shipping, dimensions, virtual/downloadable state, downloads, categories/tags, images/gallery, upsells/cross-sells, attributes/defaults and variations. Global attributes and coupons are supported; coupons are privileged.

Orders, refunds, subscriptions, payments and customer records are intentionally outside the generic connector surface because they are transactional/sensitive business data.

## ACF

Read/update/delete ACF values and inspect field groups using ACF APIs. Complex values such as Image, Gallery, Repeater, Group, Flexible Content, Relationship/Post Object and nested structures can be supplied in the structure expected by the installed ACF field definition.

## Media

List/read/import/update attachment metadata and regenerate image metadata. Assignments include post featured image, WooCommerce gallery, Custom Logo and Site Icon. Elementor/Gutenberg/ACF image references are changed through their respective adapters, including in a batch request.

## Administration

Explicit privileged actions cover users/roles, plugin/theme lifecycle, WordPress core updates, cron, rewrite/cache and multisite site management. Software install/update/delete actions require the separate system-update gate.

## Extension contract

Plugins can add semantic actions to the runtime registry through the `wpconnector_register_actions` hook. Every added action must declare its security metadata and use its owner's supported API/storage contract. Unknown custom tables are not blindly mutated.
