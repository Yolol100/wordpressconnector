# Action catalog

This catalog is generated from the connector action registrations. Security labels are authoritative at runtime.

| Action | Security | Purpose |
| --- | --- | --- |
| `acf.delete` | mutation, privileged | Delete one or more ACF field values through delete_field(). |
| `acf.field_groups` | privileged | List active ACF field groups and fields for discovery. |
| `acf.get` | privileged | Read ACF values for a post, term, user or options target. |
| `acf.portfolio_stats_update` | mutation, privileged | Update the eight portfolio-stat ACF text fields on one standard post with dry-run, stale-state protection and rollback. |
| `acf.schema.ensure_text_fields` | mutation, privileged | Create a bounded set of new ACF text fields in an existing field group without altering existing fields. |
| `acf.schema.remove_text_fields` | mutation, privileged | Remove exact ACF text fields created by a prior bounded schema ensure operation. |
| `acf.schema.ensure_fields` | mutation, privileged | Create bounded complex ACF fields in an existing field group without altering existing fields. |
| `acf.schema.remove_fields` | mutation, privileged | Remove exact complex ACF fields created by a prior bounded schema ensure operation. |
| `acf.schema.create_field_group` | mutation, privileged | Create a new bounded ACF field group with nested fields and location rules. |
| `acf.schema.delete_field_group` | mutation, privileged | Delete an exact connector-created ACF field group only when its schema still matches. |
| `acf.update` | mutation, privileged | Update ACF values for a post, term, user or options target. |
| `auto_image_attributes.inspect` | privileged | Read safe Auto Image Attributes upload and bulk-update settings. |
| `auto_image_attributes.update` | mutation, privileged | Update allowlisted Auto Image Attributes settings with dry-run, readback and rollback. |
| `cache.flush` | mutation, privileged | Flush WordPress object cache. |
| `comment.get` | privileged, sensitive | Read one comment. |
| `comment.list` | privileged, sensitive | List comments including private author metadata. |
| `comment.trash` | mutation, privileged, sensitive | Trash a comment. |
| `comment.update` | mutation, privileged, sensitive | Update a comment. |
| `code_snippets.catalog_only.inspect` | privileged | Inspect the provider-backed managed WooCommerce catalogue snippet without exposing PHP. |
| `code_snippets.catalog_only.ensure` | mutation, privileged | Create and activate only the audited WooCommerce catalogue-only Code Snippets preset, with readback and rollback. |
| `code_snippets.catalog_only.undo` | mutation, privileged | Internal rollback of the managed catalogue-only snippet guarded by exact code identity. |
| `code_snippets.patch` | mutation, privileged | Patch one uniquely identified existing Code Snippets PHP snippet through exact bounded replacements with stale-state protection, readback and rollback. |
| `code_snippets.restore_code` | mutation, privileged | Internal rollback action that restores the previous code for a guarded Code Snippets patch. |
| `connector.actions` | read-only | List registered actions and security metadata. |
| `connector.batch` | mutation | Execute up to 25 connector operations with compensation on failure. |
| `connector.read_batch` | read-only | Run 1-25 non-sensitive, non-mutating reads after preflighting every leaf; supports REST/MCP and the existing GitHub transport. |
| `connector.setup.inspect` | privileged | Read private OAuth/backup/staging readiness evidence; no remote authentication, backup or write is performed. |
| `connector.rest_catalog` | privileged | Inventory bounded sanitized REST namespaces and route patterns with advertised HTTP methods. Does not dispatch endpoint or permission callbacks. Requires private, authenticated manage_options context. |
| `connector.cleanup` | mutation, privileged | Delete expired idempotency and rollback state. |
| `connector.discover` | read-only | Discover WordPress, plugins, post types, taxonomies, builders, media sizes and connector capabilities. |
| `connector.update.check` | privileged | Check the canonical GitHub release for a newer verified WordPress Connector package. |
| `connector.update.apply` | mutation, privileged, system_update | Verify the canonical release and apply via WordPress Core's temporary-backup update path; installation failures trigger Core restoration. Real updates require fingerprint confirmation but no externally tested restore attestation; `restore_verified` remains an ignored optional legacy boolean. Post-success rollback is not guaranteed. |
| `connector.rollback` | mutation, privileged | Execute a stored rollback snapshot by request_id. |
| `core.check_updates` | privileged | Check WordPress core updates. |
| `core.update` | mutation, privileged, system_update | Update WordPress core. Non-rollbackable. |
| `custom_css.inspect` | privileged | Read WordPress Additional CSS for one installed theme stylesheet. |
| `custom_css.update` | mutation, privileged | Replace WordPress Additional CSS with stale-state protection, readback and rollback. |
| `custom_css.patch` | mutation, privileged | Upsert or remove one named managed Additional CSS block while preserving unrelated CSS, with fingerprint, readback and rollback. |
| `cron.delete` | mutation, privileged | Delete scheduled events for one hook. |
| `cron.list` | privileged | List scheduled cron events. |
| `cron.run` | mutation, privileged | Run one cron hook immediately. |
| `cron.schedule` | mutation, privileged | Schedule a cron event. |
| `elementor.capabilities` | read-only | Inspect active Elementor document types, widgets, elements, dynamic tags and responsive capabilities. |
| `elementor.create_document` | mutation | Create a page, post, product or Elementor library document through the Elementor document API. |
| `elementor.form_capabilities` | read-only | Inspect target-runtime V3 Form controls/actions and V4 Atomic Form element/prop schemas. |
| `elementor.form_inspect` | read-only | Read complete V3 and V4 Elementor form subtrees from one document. |
| `elementor.form_upsert` | mutation | Insert or fully replace one complete V3 Form widget or V4 Atomic Form subtree with schema checks, readback and rollback. |
| `elementor.header_links.inspect` | read-only | Inspect eligible Icon List links and exact-match counts in an existing Elementor header without modifying the template. |
| `elementor.header_links.replace` | mutation | Replace bounded same-site Icon List links in a published Elementor header; requires edit_theme_options, confirmation and verified readback. Failed saves attempt automatic restoration; successful writes do not expose a stored rollback. |
| `elementor.inspect` | read-only | Read Elementor document JSON and document metadata. |
| `elementor.inventory` | read-only | Inventory registered Elementor widget provenance and actual widget/element usage across Elementor documents. |
| `elementor.patch_element` | mutation | Patch an Elementor element by stable element id and save through the Elementor document API. |
| `elementor.regenerate` | mutation | Clear Elementor generated CSS/files cache. |
| `elementor.replace_document` | mutation | Replace Elementor elements/settings through the Elementor document API. |
| `filesystem.create_text` | mutation, privileged | Create a non-PHP plugin/theme text asset in an existing directory with guarded readback and rollback. |
| `filesystem.delete_text` | mutation, privileged | Delete a non-PHP plugin/theme text asset after exact SHA-256 guard with readback and rollback. |
| `filesystem.inspect` | privileged | Inspect safe filesystem capabilities and WP File Manager integration state without exposing absolute paths. |
| `filesystem.list` | privileged | List a bounded directory inside the WordPress root while excluding secret and managed-data paths. |
| `filesystem.read_text` | privileged | Read a bounded non-secret UTF-8 text file inside the WordPress root. |
| `filesystem.write_text` | mutation, privileged | Replace an existing plugin/theme text file with dedicated gate, checksum guard, parser validation, readback and rollback. |
| `gutenberg.inspect` | read-only | Parse Gutenberg/block content into a block tree. |
| `gutenberg.patch` | mutation | Patch one block by nested numeric path. |
| `gutenberg.replace` | mutation | Replace the entire serialized block document. |
| `media.assign` | mutation | Assign media as featured image, Woo gallery image, custom logo or site icon. |
| `media.get` | read-only | Read one media attachment and metadata. |
| `media.import` | mutation | Import a file from the trusted request asset root into the media library. |
| `media.list` | read-only | List media attachments. |
| `media.regenerate` | mutation | Regenerate attachment metadata and image subsizes. |
| `media.rename` | mutation, privileged | Rename an eligible image by copying original/subsizes to SEO filename, switching WordPress attachment metadata while preserving old URL files; unsupported image patterns fail closed. |\n| `media.update` | mutation | Update attachment title, alt text, caption, description and parent. |
| `maintenance.cache_capabilities` | privileged | Inspect bounded cache-maintenance availability for Elementor, WP Rocket and Asset CleanUp. |
| `maintenance.cache_flush` | mutation, privileged | Flush exactly one bounded cache layer: Elementor generated files/data, WP Rocket domain cache or Asset CleanUp CSS/JS cache metadata. |
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
| `plugin.directory.search` | privileged | Search WordPress.org plugin directory for bounded metadata and installed-plugin matching. |
| `plugin.deactivate` | mutation, privileged | Deactivate an installed plugin. |
| `plugin.delete` | mutation, privileged, system_update | Delete an inactive installed plugin. |
| `plugin.install` | mutation, privileged, system_update | Install a plugin from WordPress.org by slug. |
| `plugin.install_package` | mutation, privileged, system_update | Install or overwrite a plugin from an exact-checksum ZIP in the trusted request asset root; requires install_plugins and the active package safety policy. |
| `plugin.list` | privileged | List installed plugins with activation, WordPress update state, and permitted actions. |
| `gtranslate.settings.inspect` | privileged | Inspect a bounded nonsecret GTranslate 5.0.x language/settings snapshot. |
| `gtranslate.settings.update` | mutation, privileged | Set Dutch/English language choices with strict provider/version checks, dry-run, readback and rollback. |
| `gtranslate.settings.restore` | mutation, privileged | Internal stale-state protected restoration of earlier GTranslate language settings. |
| `optimizer.settings.inspect` | privileged | Inspect provider-backed EWWW 8.8.x settings or stored-only Asset CleanUp 1.4.0.x settings; mark effective provider verification explicitly. |
| `optimizer.settings.update` | mutation, privileged | Change one allowlisted EWWW 8.8.x display setting using provider APIs, confirmed fingerprint, readback and rollback. Asset CleanUp optimizer writes are blocked. |
| `optimizer.settings.restore` | mutation, privileged | Rollback-only guarded EWWW settings restoration; legacy Asset CleanUp optimizer rollbacks are blocked. |
| `plugin.audit.inspect` | privileged, public-safe | Inventory every installed plugin without exposing plugin-header text, versions or credentials. Identify registered integration coverage and limited, fixed nonsecret checks for WooCommerce, Yoast, GTranslate, Joinchat, WP Rocket, EWWW, Asset CleanUp and WP Mail SMTP. The four Hostinger plugins have fixed identities and explicit limited support classifications; unknown providers remain inventory-only. Joinchat phone readiness is reported as an enum without exposing phone numbers. WordPress must-use plugin and drop-in totals are reported separately without names or paths, and unknown counts remain nullable. Requires administrator capability and an empty dry-run request; public receipts strictly validate and redact all fields. No email, translation, checkout or payment browser acceptance is claimed. |
| `plugin.settings.catalog` | privileged | List installed plugins and their safe ChatGPT/GitHub control mode without exposing secrets. |
| `plugin.settings.inspect` | privileged | Read allowlisted settings through a plugin-owned API and return a state fingerprint. |
| `plugin.settings.update` | mutation, privileged | Update allowlisted plugin settings through plugin-owned APIs with dry-run, readback and rollback. |
| `plugin.update` | mutation, privileged, system_update | Update one installed plugin. |
| `portfolio.case_text_update` | mutation, privileged | Update portfolio intro content plus description fields on one standard post with stale-state protection and rollback. |
| `post.create` | mutation | Create a post, page or custom post type object. |
| `post.get` | read-only | Read one post object. |
| `post.list` | read-only | List posts, pages or custom post types. |
| `post.restore` | mutation | Restore a trashed post object. |
| `post.restore_revision` | mutation | Restore a WordPress post revision. |
| `post.revisions` | read-only | List WordPress revisions for a post. |
| `post.trash` | mutation | Move a post object to Trash. |
| `post.update` | mutation | Update a post, page or custom post type object. |
| `post.literal_replace` | mutation | Replace one bounded literal phrase on an existing published page; dry-run fingerprint, verified readback and site-local rollback. |
| `site_editor.global_styles.inspect` | privileged | Read active-theme global styles through WordPress Core Site Editor REST permissions; no writes. |
| `site_editor.template.inspect` | privileged | Read one active-theme Site Editor template or part, including safe current fingerprint. |
| `site_editor.template.list` | privileged | List bounded active-theme Site Editor templates or parts. |
| `site_editor.template.restore` | mutation, privileged | Rollback-only restore of a previously customized template after exact state check. |
| `site_editor.template.update` | mutation, privileged | Modify a previously customized template or part via Core REST with explicit server opt-in, fingerprint, readback and guarded rollback. |
| `site.identity.inspect` | privileged, public-safe | Read four nonsecret WordPress identity fields, requiring manage_options. |
| `site.identity.update` | mutation, privileged, public-safe | Update approved site identity fields with dry-run fingerprint, readback, rollback and locale-pack checks. |
| `site.identity.restore` | mutation, privileged | Restore a prior site identity snapshot only through a verified, state-guarded Connector rollback. |
| `gtranslate.settings.inspect` / `gtranslate.settings.update` | privileged, public-safe | Inspect or update only provider-supported Dutch/English language choices with manage_options, fingerprints, redacted receipts and rollback. |
| `yoast.site_representation.inspect` / `yoast.site_representation.update` | privileged, public-safe | Inspect Yoast organization and patch only company_name/org_description via the guarded public runtime. Logos and arbitrary options remain blocked. |
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
| `theme.install_package` | mutation, privileged, system_update | Install or replace an inactive theme from a verified request ZIP; requires install_themes, checksum and overwrite protections. |
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
| `user.force_password_reset` | mutation, privileged, sensitive | Immediately rotate a WordPress user password, revoke active sessions and send a dedicated security reset link without exposing secret material. Non-rollbackable. |
| `woocommerce.attribute.create` | mutation | Create a global WooCommerce product attribute. |
| `woocommerce.attribute.delete` | mutation, privileged | Delete a global WooCommerce product attribute. |
| `woocommerce.attribute.list` | read-only | List global WooCommerce product attributes. |
| `woocommerce.attribute.update` | mutation | Update a global WooCommerce product attribute. |
| `woocommerce.customer.get` | privileged, sensitive | Read one WooCommerce customer summary with `manage_woocommerce`; contact and address data require explicit opt-in. Subscriber accounts and custom roles with order history are supported; sites may extend recognized roles with `wpc_connector_woocommerce_customer_roles`. |
| `woocommerce.order.get` | privileged, sensitive | Read one WooCommerce order summary with `manage_woocommerce`; customer/address data require explicit opt-in. Core CPT/HPOS stores use a bounded count plus at most 50 order-item IDs before WooCommerce hydration; unknown custom order stores fail closed. |
| `woocommerce.order.list` | privileged, sensitive | List bounded WooCommerce order summaries with `manage_woocommerce`; customer identifiers, contact data and line-item details are omitted. |
| `woocommerce.shipping_zone.get` | privileged | Read one WooCommerce shipping zone with at most 100 geographic locations and truncation/count evidence; shipping method settings are omitted. |
| `woocommerce.shipping_zone.list` | privileged | Paginate at most 50 WooCommerce shipping zones, including the default zone last, and cap each zone at 100 geographic locations with truncation/count evidence. |
| `woocommerce.tax_class.list` | privileged | List WooCommerce tax classes. |
| `woocommerce.tax_rate.list` | privileged | Paginate at most 50 WooCommerce tax rates per request; include up to 100 city/state/postcode locations per rate with truncation evidence. |
| `woocommerce.coupon.create` | mutation, privileged | Create a WooCommerce coupon. |
| `woocommerce.coupon.get` | privileged | Read a WooCommerce coupon. |
| `woocommerce.coupon.list` | privileged | List WooCommerce coupons without customer/order data. |
| `woocommerce.coupon.update` | mutation, privileged | Update a WooCommerce coupon. |
| `woocommerce.product.create` | mutation | Create a WooCommerce product. |
| `woocommerce.product.get` | read-only | Read a WooCommerce product. |
| `woocommerce.fulfillment.locations.inspect` | privileged | Read shipping zone country/state/postcode coverage through WooCommerce REST. |
| `woocommerce.fulfillment.locations.update` | mutation, privileged | Update locations of one shipping zone with strict JSON, confirmed restore, fingerprint and rollback. |
| `woocommerce.fulfillment.locations.restore` | mutation, privileged | Internal rollback-only shipping zone location restoration. |
| `woocommerce.fulfillment.create` | mutation, privileged | Create a shipping zone/method or guarded tax rate with preflight and rollback checkpoint. |
| `woocommerce.fulfillment.restore_created` | mutation, privileged | Internal guarded removal of a newly created resource with stale-state and dependency checks. |
| `woocommerce.operations.list` | privileged | Read bounded provider shipping zones/methods, tax rates and payment gateway summaries with credentials removed. |
| `woocommerce.operations.inspect` | privileged | Inspect one provider shipping zone/method, tax rate, or gateway, redacting secrets. |
| `woocommerce.operations.update` | mutation, privileged | High-risk provider-owned settings update with site-local gate, tested restore, exact fingerprint, readback and rollback. |
| `woocommerce.operations.restore` | mutation, privileged | Internal fingerprint-protected rollback of high-risk WooCommerce provider settings. |
| `woocommerce.settings.catalog` | privileged | Discover actual WooCommerce core REST settings groups and available admin tabs/subtabs without exposing credentials or setting values. |
| `woocommerce.settings.inspect` | privileged | Bounded paged REST settings-group inspection; redact secrets and report per-field write eligibility. |
| `woocommerce.settings.section.inspect` | privileged | Discover a WooCommerce admin tab's actual subtab fields, including plugin-defined fields, without disclosing sensitive options. |
| `woocommerce.settings.update` | mutation, privileged | Apply one allowlisted low-risk WooCommerce-owned setting through provider REST, with dry-run, readback, rollback. |
| `woocommerce.settings.restore` | mutation, privileged | Internal rollback-only restore of one setting, fingerprint-guarded. |
| `woocommerce.product.list` | read-only | List WooCommerce products through WooCommerce CRUD. |
| `woocommerce.product.update` | mutation | Update a WooCommerce product. |
| `woocommerce.variation.create` | mutation | Create a product variation. |
| `woocommerce.variation.get` | read-only | Read a WooCommerce product variation. |
| `woocommerce.variation.list` | read-only | List product variations. |
| `woocommerce.variation.update` | mutation | Update a WooCommerce product variation. |
| `wordpress.abilities` | privileged | Discover exposed WordPress Abilities API entries without executing them; reject registries above 10,000 entries, paginate at most 10 descriptors per page, omit over-budget schemas, and cap each response page at 256 KiB. |
| `wordpress.ability.execute_approved` | mutation, privileged, sensitive | Run only a non-destructive MCP-exposed native Ability from an explicitly approved, currently active third-party plugin with exact callback-source verification. Private authenticated client and administrator/server opt-in only; no generic plugin code execution or guaranteed rollback. |
| `wordpress.ability.execute` | mutation, privileged | Execute an explicitly mutating, MCP-exposed native Elementor/Core-or-Pro Ability only after its execute callback source resolves inside the installed Elementor plugin root. Slugs/categories alone never establish trust. Dry-run never invokes provider code; completed over-budget results and ambiguous provider failures become bounded terminal outcomes so stable request IDs are not blindly re-executed. Delegated Ability mutations are blocked inside `connector.batch` and in public-repository mode. |
| `wordpress.ability.read` | privileged, sensitive | Read through a client-exposed WordPress Ability only when it explicitly declares `readonly=true` and `destructive=false`. Native validation/permission still run; credential-shaped values are redacted and output is bounded to 10,000 values, depth 20 and 256 KiB. Mutation-capable or ambiguous abilities remain blocked from this route. |
| `yoast.features.inspect` | privileged | Inspect typed, explicitly allowlisted Yoast site-feature, integration and archive switches; private manage_options only; no provider tokens or arbitrary option keys. |
| `yoast.features.update` | mutation, privileged | Change up to eight bool switches from one Yoast option group after dry-run fingerprint, manage_options and explicit risk acknowledgement for indexation/sitemap/Schema/archives; provider readback and guarded rollback. |
| `yoast.features.restore` | mutation, privileged | Internal rollback-only restore of previous allowlisted Yoast switches after exact state check; public GitHub transport forbidden. |
| `yoast.native.capabilities` | privileged | Discover official Yoast SEO Abilities and version/availability through authenticated private transport. |
| `yoast.native.scores` | privileged | Read bounded official Yoast SEO, readability and inclusive-language analysis scores. |
| `yoast.native.post.inspect` | privileged | Read official Yoast post, page or WooCommerce product SEO metadata and rendered output with provider permissions. |
| `yoast.native.post.update` | mutation, privileged | Update one post's Yoast SEO metadata via the official Ability with stale-state fingerprint, readback and rollback. |
| `yoast.native.post.restore` | mutation, privileged | Rollback-only restore of a previous native Yoast SEO metadata update. |
| `yoast.snippet.inspect` | privileged, public-safe | Inspect published content's bounded Yoast title and meta-description overrides; public transport uses restricted post targets. |
| `yoast.snippet.update` | mutation, privileged, public-safe | Update only public Yoast title/meta-description overrides with edit_post permission, confirmation and state-guarded readback/rollback. |
| `yoast.snippet.restore` | mutation, privileged | Internal stale-state-guarded rollback of an earlier Yoast snippet update. |
| `yoast.templates.inspect` | privileged | Inspect allowlisted global Yoast title and meta-description templates with a settings fingerprint. |
| `yoast.templates.update` | mutation, privileged | Patch supported site, page, post, product and product category snippet templates; dry-run and guarded readback/rollback. |
| `yoast.templates.restore` | mutation, privileged | Internal rollback with expected-after fingerprint; cannot be called as a direct write. |
| `yoast.site_representation.inspect` | privileged | Inspect bounded Yoast organization/logo fields (requires manage_options). |
| `yoast.site_representation.update` | mutation, privileged | Patch organization name, description and validated WordPress logo attachment with dry-run/readback/rollback (requires manage_options). |
| `yoast.site_representation.restore` | mutation, privileged | Internal compensation for a prior organization settings update (requires manage_options and rollback mode). |
| `yoast.inspect` | privileged | Read supported Yoast SEO and Yoast SEO Premium post fields. |
| `yoast.update` | mutation, privileged | Update supported Yoast SEO and Premium post fields with dry-run, fingerprint, readback and rollback support. |
