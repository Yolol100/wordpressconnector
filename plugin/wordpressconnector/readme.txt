=== WordPress Connector ===
Contributors: webactueel
Tags: rest-api, github, automation, elementor, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.20.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zero-config controlled WordPress automation bridge over authenticated HTTPS REST, native MCP and optional local WP-CLI recovery.

== Description ==

WordPress Connector provides authenticated HTTPS REST, native MCP and optional WP-CLI access to administrator-approved WordPress operations. Supported integrations include Elementor, WooCommerce and SEO tools. Actions use scoped authorization and guarded execution; external hosting backups and a verified full-site restore remain separate requirements for production updates.
Security: HTTPS and administrator permission checks are required for privileged actions. Confirmed writes use guarded authorization and fingerprint checks; provider-hosted full-site backup and restore must be validated separately before production upgrades. This plugin does not expose a general shell or SQL endpoint.

For full historic release notes and security boundaries, see https://github.com/Yolol100/wordpressconnector/blob/main/docs/connector-release-history.md.

== Installation ==

1. Install the canonical WordPress Connector package from the trusted GitHub release.
2. Activate as a WordPress administrator and approve the exact site for GitHub OIDC if using the GitHub workflow.
3. Check the built-in connection diagnostics and verify HTTPS, capabilities and a tested external restore path before updates.

== Changelog ==

= 1.20.9 =
* Verified compatibility with WordPress 7.1.4 and legacy 7.0.8, WooCommerce 11.2.1 and Elementor 4.3.5. Existing REST/MCP permissions remain unchanged.

= 1.20.8 =
* Fixed missing request protocol version for WP-CLI actions and doctor. Added CLI regression coverage.

= 1.20.7 =
* Added read-only mutation status diagnostics and safer unresolved-state cleanup.

The complete changelog and historical release notes remain in docs/connector-release-history.md.