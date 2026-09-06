=== WordPress Connector ===
Contributors: webactueel
Tags: rest-api, github, automation, wp-cli, elementor, woocommerce, acf, yoast
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Single controlled GitHub-to-WordPress bridge over authenticated HTTPS REST with optional local WP-CLI recovery.

== Description ==

WordPress Connector is the canonical single-plugin bridge for WordPress posts/pages/CPTs, Gutenberg, Elementor, WooCommerce, ACF, Yoast SEO, media, terms, menus, options, controlled filesystem access and controlled system actions behind separate safety gates.

The default GitHub workflow uses a temporary GitHub-hosted Ubuntu runner and authenticates to WordPress over HTTPS with a WordPress Application Password. No GitHub Client ID is stored in WordPress and no VPS or continuously running self-hosted GitHub Actions runner is required.

Elementor writes use Elementor's document save API for element data and page settings, followed by readback verification. The connector exposes active Elementor capability/usage inventory plus complete V3 Form and V4 Atomic Form inspection/upsert with runtime-schema validation and rollback.

Local WP-CLI commands remain available for host-local diagnostics and recovery.

== Security ==

REST endpoints require HTTPS, an authenticated WordPress user and the manage_options capability. Confirmed writes, privileged actions, sensitive actions, filesystem writes and system updates use separate WordPress-side gates under Settings -> WordPress Connector. The connector exposes no generic shell, arbitrary SQL, eval or unrestricted filesystem endpoint.

Use a private GitHub repository. Store the dedicated WordPress Application Password only in GitHub Actions Secrets. Do not commit credentials, passwords, payment data, patient/medical records or other sensitive production records to GitHub.

== Changelog ==

= 1.6.0 =
* Add runtime-aware `elementor.form_capabilities` for V3 Form controls/actions and V4 Atomic Form element/prop schemas.
* Add `elementor.form_inspect` for complete V3/V4 form subtree readback.
* Add `elementor.form_upsert` to insert or fully replace complete forms while preserving all supplied runtime-supported settings, including email/action configuration.
* Validate V3 field IDs and registered submit actions; validate V4 typed props, registered Atomic types, required messages, exactly one submit button and nested-form rejection.
* Add stale-schema fingerprint guards, exact readback verification and automatic full-document rollback on failed writes.

= 1.5.0 =
* Add bounded WP_Filesystem inspection and existing plugin/theme text-file replacement with dedicated gates, checksums, validation, readback and rollback.

= 1.4.0 =
* Add controlled plugin-settings bridge and plugin-specific safe settings adapters.

= 1.3.0 =
* Add read-only `elementor.inventory` with paginated site-wide Elementor document scanning.
* Identify registered widget provenance as Elementor Core, Elementor Pro, plugin add-on, theme or unknown, including available version metadata.
* Count widget usage per document and report widget types that remain in saved Elementor data but are no longer registered.
* Report legacy section/column, container and Atomic element architecture usage per document.

= 1.2.0 =
* Consolidate the active WordPress/Elementor route into one canonical WordPress Connector plugin.
* Add first-class Yoast SEO inspect/update actions with dry-run, fingerprint, readback and rollback support.
* Add Elementor document/widget/element/dynamic-tag/breakpoint capability inventory.
* Add safe WordPress Abilities API catalog discovery without generic ability execution.
* Save Elementor element data and document settings through Elementor's document API instead of direct _elementor_data writes.
* Add exact Elementor readback checks after create, replace and patch operations.
* Expand the WordPress settings page with the canonical GitHub Actions setup and legacy-bridge migration guidance.

= 1.1.0 =
* Add authenticated HTTPS REST transport using the existing connector action registry.
* Add request-scoped asset upload transport with strict path and size limits.
* Add WordPress-side execution gate settings.
* Switch the default GitHub request workflow to automatically provisioned ubuntu-latest runners.

= 1.0.0 =
* Initial complete connector runtime.
