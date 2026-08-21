=== WordPress Connector ===
Contributors: webactueel
Tags: wp-cli, github, automation, elementor, woocommerce, acf
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WP-CLI-only bridge for controlled GitHub-to-WordPress workflows.

== Description ==

WordPress Connector exposes an explicit action registry to WP-CLI. It supports WordPress posts/pages/CPTs, Gutenberg, Elementor JSON, WooCommerce products and variations, ACF, media, terms, menus, options and privileged system actions behind separate safety gates.

The plugin exposes no public REST or AJAX endpoint. Production writes are disabled until explicitly enabled with environment/WordPress constants.

== Security ==

Do not commit credentials, passwords, payment data, patient/medical records or other sensitive production records to GitHub. Use a private repository for any self-hosted GitHub Actions runner.

== Changelog ==

= 1.0.0 =
* Initial complete connector runtime.
