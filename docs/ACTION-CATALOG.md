# Action catalog

This catalog is generated from the connector action registrations. Security labels are authoritative at runtime.

| Action | Security | Purpose |
| --- | --- | --- |
| `acf.delete` | mutation, privileged | Delete one or more ACF field values through delete_field(). |
| `acf.field_groups` | privileged | List active ACF field groups and fields for discovery. |
| `acf.get` | privileged | Read ACF values for a post, term, user or options target. |
| `acf.update` | mutation, privileged | Update one or more ACF fields through update_field(). |
| `auto_image_attributes.inspect` | privileged | Read safe Auto Image Attributes upload and bulk-update settings. |
| `auto_image_attributes.update` | mutation, privileged | Update allowlisted Auto Image Attributes settings with dry-run, readback and rollback. |
| `cache.flush` | mutation, privileged | Flush WordPress object cache. |
| `comment.get` | privileged, sensitive | Read one comment. |
| `comment.list` | privileged, sensitive | List comments including private author metadata. |
| `comment.trash` | mutation, privileged, sensitive | Trash a comment. |
| `comment.update` | mutation, privileged, sensitive | Update a comment. |
| `connector.actions` | read-only | List registered actions and security metadata. |
| `connector.batch` | mutation | Execute up to 25 connector operations with compensation on failure. |
| `connector.cleanup` | mutation, privileged | Delete expired idempotency and rollback state. |
| `connector.discover` | read-only | Discover WordPress, plugins, post types, taxonomies, builders, media sizes and connector capabilities. |
| `connector.rollback` | mutation, privileged | Execute a stored rollback snapshot by request_id. |
| `core.check_updates` | privileged | Check WordPress core updates. |
| `core.update` | mutation, privileged, system_update | Update WordPress core. Non-rollbackable. |
| `cron.delete` | mutation, privileged | Delete scheduled events for one hook. |
| `cron.list` | privileged | List scheduled cron events. |
| `cron.run` | mutation, privileged | Run one cron hook immediately. |
| `cron.schedule` | mutation, privileged | Schedule a cron event. |
| `elementor.capabilities` | read-only | Inspect active Elementor document types, widgets, elements, dynamic tags and responsive capabilities. |
| `elementor.create_document` | mutation | Create a page, post, product or Elementor library document through the Elementor document API. |
| `elementor.inspect` | read-only | Read Elementor document JSON and document metadata. |
| `elementor.patch_element` | mutation | Patch an Elementor element by stable element id and save through the Elementor document API. |
| `elementor.regenerate` | mutation | Clear Elementor generated CSS/files cache. |
| `elementor.replace_document` | mutation | Replace Elementor elements/settings through the Elementor document API. |
| `gutenberg.inspect` | read-only | Parse Gutenberg/block content into a block tree. |
| `gutenberg.patch` | mutation | Patch one block by nested numeric path. |
| `gutenberg.replace` | mutation | Replace the entire serialized block document. |
| `media.assign` | mutation | Assign media as featured image, Woo gallery image, custom logo or site icon. |
| `media.get` | read-only | Read one media attachment and metadata. |
| `media.import` | mutation | Import a file from the trusted request asset root into the media library. |
| `media.list` | read-only | List media attachments. |
| `media.regenerate` | mutation | Regenerate attachment metadata and image subsizes. |
| `media.update` | mutation | Update attachment title, alt text, caption, description and parent. |
| `menu.create` | mutation | Create a classic navigation menu. |
| `menu.delete` | mutation, privileged | Delete a classic navigation menu. |
| `menu.get` | read-only | Read a classic navigation menu and items. |
| `menu.item_upsert` | mutation | Create or update a classic navigation menu item. |
| `menu.list` | read-only | List classic navigation menus and locations. |
| `menu.location_set` | mutation | Assign a classic menu to a theme location. |
| `menu.update` | mutation | Rename a classic navigation menu. |
| `meta.delete` | mutation, privileged | Delete generic post, term, user or comment metadata. |
| `meta.get` | privileged | Read generic post, term, user or comment metadata. |
| `meta.update` | mutation, privileged | Update generic post, term, user or comment metadata. |
| `multisite.site.create` | mutation, privileged, sensitive | Create a multisite site. |
| `multisite.site.delete` | mutation, privileged, sensitive, system_update | Delete a multisite site. |
| `multisite.site.list` | privileged, sensitive | List multisite network sites. |
| `multisite.site.update` | mutation, privileged, sensitive | Update multisite site fields. |
| `network_option.delete` | mutation, privileged | Delete a multisite network option. |
| `network_option.get` | privileged | Read a multisite network option. |
| `network_option.update` | mutation, privileged | Update a multisite network option. |
| `option.delete` | mutation, privileged | Delete a WordPress option. |
| `option.get` | privileged | Read a WordPress option. |
| `option.update` | mutation, privileged | Update a WordPress option. |
| `plugin.activate` | mutation, privileged | Activate an installed plugin. |
| `plugin.deactivate` | mutation, privileged | Deactivate an installed plugin. |
| `plugin.delete` | mutation, privileged, system_update | Delete an inactive installed plugin. |
| `plugin.install` | mutation, privileged, system_update | Install a plugin from WordPress.org by slug. |
| `plugin.list` | privileged | List installed plugins and activation state. |
| `plugin.settings.catalog` | privileged | List installed plugins and their safe ChatGPT/GitHub control mode without exposing secrets. |
| `plugin.settings.inspect` | privileged | Read allowlisted settings through a plugin-owned API and return a state fingerprint. |
| `plugin.settings.update` | mutation, privileged | Update allowlisted plugin settings through plugin-owned APIs with dry-run, readback and rollback. |
| `plugin.update` | mutation, privileged, system_update | Update one installed plugin. |
| `post.create` | mutation | Create a post, page or custom post type object. |
| `post.get` | read-only | Read one post object. |
| `post.list` | read-only | List posts, pages or custom post types. |
| `post.restore` | mutation | Restore a trashed post object. |
| `post.restore_revision` | mutation | Restore a WordPress post revision. |
| `post.revisions` | read-only | List WordPress revisions for a post. |
| `post.trash` | mutation | Move a post object to Trash. |
| `post.update` | mutation | Update a post, page or custom post type object. |
| `rewrite.flush` | mutation, privileged | Flush rewrite rules. |
| `role.create` | mutation, privileged | Create a WordPress role. |
| `role.delete` | mutation, privileged | Delete a WordPress role. |
| `role.list` | privileged | List roles and capabilities. |
| `role.update` | mutation, privileged | Add or remove capabilities from a role. |
| `system.doctor` | read-only | Run connector/runtime preflight diagnostics. |
| `term.assign` | mutation | Assign taxonomy terms to an object. |
| `term.create` | mutation | Create a taxonomy term. |
| `term.delete` | mutation, privileged | Delete a taxonomy term. |
| `term.get` | read-only | Read one taxonomy term. |
| `term.list` | read-only | List terms in any registered taxonomy. |
| `term.update` | mutation | Update a taxonomy term. |
| `theme.delete` | mutation, privileged, system_update | Delete an inactive theme. |
| `theme.install` | mutation, privileged, system_update | Install a theme from WordPress.org by slug. |
| `theme.list` | privileged | List installed themes. |
| `theme.switch` | mutation, privileged | Switch the active theme. |
| `theme.update` | mutation, privileged, system_update | Update one installed theme. |
| `theme_mod.get` | privileged | Read a theme modification. |
| `theme_mod.remove` | mutation, privileged | Remove a theme modification. |
| `theme_mod.update` | mutation, privileged | Update a theme modification. |
| `user.create` | mutation, privileged, sensitive | Create a WordPress user with a server-generated password. |
| `user.delete` | mutation, privileged, sensitive | Delete a WordPress user. |
| `user.get` | privileged, sensitive | Read a WordPress user. |
| `user.list` | privileged, sensitive | List WordPress users. |
| `user.update` | mutation, privileged, sensitive | Update WordPress user profile fields and roles. |
| `woocommerce.attribute.create` | mutation | Create a global WooCommerce product attribute. |
| `woocommerce.attribute.delete` | mutation, privileged | Delete a global WooCommerce product attribute. |
| `woocommerce.attribute.list` | read-only | List global WooCommerce product attributes. |
| `woocommerce.attribute.update` | mutation | Update a global WooCommerce product attribute. |
| `woocommerce.coupon.create` | mutation, privileged | Create a WooCommerce coupon. |
| `woocommerce.coupon.get` | privileged | Read a WooCommerce coupon. |
| `woocommerce.coupon.list` | privileged | List WooCommerce coupons without customer/order data. |
| `woocommerce.coupon.update` | mutation, privileged | Update a WooCommerce coupon. |
| `woocommerce.product.create` | mutation | Create a WooCommerce product. |
| `woocommerce.product.get` | read-only | Read one WooCommerce product. |
| `woocommerce.product.list` | read-only | List WooCommerce products through WooCommerce CRUD. |
| `woocommerce.product.update` | mutation | Update one WooCommerce product. |
| `woocommerce.variation.create` | mutation | Create a product variation. |
| `woocommerce.variation.get` | read-only | Read one product variation. |
| `woocommerce.variation.list` | read-only | List product variations. |
| `woocommerce.variation.update` | mutation | Update a product variation. |
| `wordpress.abilities` | privileged | Discover exposed WordPress Abilities API entries and schemas without executing them. |
| `yoast.inspect` | privileged | Read supported Yoast SEO and Yoast SEO Premium post fields. |
| `yoast.update` | mutation, privileged | Update supported Yoast SEO and Premium post fields with dry-run, fingerprint, readback and rollback support. |
