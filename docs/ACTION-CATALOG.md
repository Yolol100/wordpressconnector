# Action catalog

This catalog is generated from the connector action registrations. Security labels are authoritative at runtime.

| Action | Security | Purpose |
| --- | --- | --- |
| `acf.delete` | mutation, privileged | Delete one or more ACF field values through delete_field(). |
| `acf.field_groups` | privileged | List active ACF field groups and fields for discovery. |
| `acf.get` | privileged | Read ACF values for a post, term, user or options target. |
| `acf.update` | mutation, privileged | Update one or more ACF fields through update_field(). |
| `cache.flush` | mutation | Flush WordPress object cache. |
| `comment.get` | privileged, sensitive | Read one comment. |
| `comment.list` | privileged, sensitive | List comments including private author metadata. |
| `comment.trash` | mutation, privileged, sensitive | Trash a comment. |
| `comment.update` | mutation, privileged, sensitive | Update a comment. |
| `connector.actions` | read-only | List registered actions and security metadata. |
| `connector.batch` | mutation | Execute up to 25 connector operations with compensation on failure. |
| `connector.cleanup` | mutation, privileged | Delete expired idempotency and rollback state. |
| `connector.discover` | read-only | Discover WordPress, plugins, post types, taxonomies, builders, media sizes and connector capabilities. |
| `connector.rollback` | mutation, privileged | Execute a stored rollback snapshot by request_id. |
| `core.check_updates` | read-only | Check WordPress core updates. |
| `core.update` | read-only | Update WordPress core. Non-rollbackable. |
| `cron.delete` | mutation | Delete scheduled events for one hook. |
| `cron.list` | read-only | List scheduled cron events. |
| `cron.run` | mutation | Run one cron hook immediately. |
| `cron.schedule` | mutation | Schedule a cron event. |
| `elementor.create_document` | mutation | Create a page, post, product or Elementor library document from Elementor JSON. |
| `elementor.inspect` | read-only | Read Elementor document JSON and document metadata. |
| `elementor.patch_element` | mutation | Patch an Elementor element by its stable element id. |
| `elementor.regenerate` | mutation | Clear Elementor generated CSS/files cache. |
| `elementor.replace_document` | mutation | Replace Elementor JSON/settings/type/conditions for a document. |
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
| `multisite.site.create` | mutation | Create a multisite site. |
| `multisite.site.delete` | mutation, system_update | Delete a multisite site. |
| `multisite.site.list` | read-only | List multisite network sites. |
| `multisite.site.update` | mutation | Update multisite site fields. |
| `network_option.delete` | mutation, privileged | Delete a multisite network option. |
| `network_option.get` | privileged | Read a multisite network option. |
| `network_option.update` | mutation, privileged | Update a multisite network option. |
| `option.delete` | mutation, privileged | Delete a WordPress option. |
| `option.get` | privileged | Read a WordPress option. |
| `option.update` | mutation, privileged | Update a WordPress option. |
| `plugin.activate` | mutation | Activate an installed plugin. |
| `plugin.deactivate` | mutation | Deactivate an installed plugin. |
| `plugin.delete` | read-only | Delete an inactive installed plugin. |
| `plugin.install` | read-only | Install a plugin from WordPress.org by slug. |
| `plugin.list` | read-only | List installed plugins and activation state. |
| `plugin.update` | read-only | Update one installed plugin. |
| `post.create` | mutation | Create a post, page or custom post type object. |
| `post.get` | read-only | Read one post object. |
| `post.list` | read-only | List posts, pages or custom post types. |
| `post.restore` | mutation | Restore a trashed post object. |
| `post.restore_revision` | mutation | Restore a WordPress post revision. |
| `post.revisions` | read-only | List WordPress revisions for a post. |
| `post.trash` | mutation | Move a post object to Trash. |
| `post.update` | mutation | Update a post, page or custom post type object. |
| `rewrite.flush` | mutation | Flush rewrite rules. |
| `role.create` | mutation | Create a WordPress role. |
| `role.delete` | mutation | Delete a WordPress role. |
| `role.list` | read-only | List roles and capabilities. |
| `role.update` | mutation | Add or remove capabilities from a role. |
| `system.doctor` | read-only | Run connector/runtime preflight diagnostics. |
| `term.assign` | mutation | Assign taxonomy terms to an object. |
| `term.create` | mutation | Create a taxonomy term. |
| `term.delete` | mutation, privileged | Delete a taxonomy term. |
| `term.get` | read-only | Read one taxonomy term. |
| `term.list` | read-only | List terms in any registered taxonomy. |
| `term.update` | mutation | Update a taxonomy term. |
| `theme.delete` | read-only | Delete an inactive theme. |
| `theme.install` | read-only | Install a theme from WordPress.org by slug. |
| `theme.list` | read-only | List installed themes. |
| `theme.switch` | mutation | Switch the active theme. |
| `theme.update` | read-only | Update one installed theme. |
| `theme_mod.get` | privileged | Read a theme modification. |
| `theme_mod.remove` | mutation, privileged | Remove a theme modification. |
| `theme_mod.update` | mutation, privileged | Update a theme modification. |
| `user.create` | mutation | Create a WordPress user with a server-generated password. |
| `user.delete` | mutation | Delete a WordPress user. |
| `user.get` | read-only | Read a WordPress user. |
| `user.list` | read-only | List WordPress users. |
| `user.update` | mutation | Update WordPress user profile fields and roles. |
| `woocommerce.attribute.create` | mutation | Create a global WooCommerce product attribute. |
| `woocommerce.attribute.delete` | mutation, privileged | Delete a global WooCommerce product attribute. |
| `woocommerce.attribute.list` | read-only | List global WooCommerce product attributes. |
| `woocommerce.attribute.update` | mutation | Update a global WooCommerce product attribute. |
| `woocommerce.coupon.create` | mutation, privileged | Create a WooCommerce coupon. |
| `woocommerce.coupon.get` | privileged | Read a WooCommerce coupon. |
| `woocommerce.coupon.list` | privileged | List WooCommerce coupons without customer/order data. |
| `woocommerce.coupon.update` | mutation, privileged | Update a WooCommerce coupon. |
| `woocommerce.product.create` | mutation | Create a WooCommerce product. |
| `woocommerce.product.get` | read-only | Read a WooCommerce product. |
| `woocommerce.product.list` | read-only | List WooCommerce products through WooCommerce CRUD. |
| `woocommerce.product.update` | mutation | Update a WooCommerce product. |
| `woocommerce.variation.create` | mutation | Create a product variation. |
| `woocommerce.variation.get` | read-only | Read a product variation. |
| `woocommerce.variation.list` | read-only | List product variations. |
| `woocommerce.variation.update` | mutation | Update a product variation. |
