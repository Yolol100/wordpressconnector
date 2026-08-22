=== WordPress Connector ===
Contributors: webactueel
Tags: rest-api, github, automation, wp-cli, elementor, woocommerce, acf
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Controlled GitHub-to-WordPress bridge over authenticated HTTPS REST with optional local WP-CLI recovery.

== Description ==

WordPress Connector exposes an explicit semantic action registry for WordPress posts/pages/CPTs, Gutenberg, Elementor JSON, WooCommerce products and variations, ACF, media, terms, menus, options and privileged system actions behind separate safety gates.

The default GitHub workflow uses a temporary GitHub-hosted Ubuntu runner and authenticates to WordPress over HTTPS with a WordPress Application Password. No VPS or continuously running self-hosted GitHub Actions runner is required.

Local WP-CLI commands remain available for host-local diagnostics and recovery.

== Security ==

REST endpoints require HTTPS, an authenticated WordPress user and the manage_options capability. Confirmed writes, privileged actions, sensitive actions and system updates use separate WordPress-side gates under Settings -> WordPress Connector. The connector exposes no generic shell, arbitrary SQL, eval or arbitrary filesystem endpoint.

Use a private GitHub repository. Store the dedicated WordPress Application Password only in GitHub Actions Secrets. Do not commit credentials, passwords, payment data, patient/medical records or other sensitive production records to GitHub.

== Changelog ==

= 1.1.0 =
* Add authenticated HTTPS REST transport using the existing connector action registry.
* Add request-scoped asset upload transport with strict path and size limits.
* Add WordPress-side execution gate settings.
* Switch the default GitHub request workflow to automatically provisioned ubuntu-latest runners.

= 1.0.0 =
* Initial complete connector runtime.
