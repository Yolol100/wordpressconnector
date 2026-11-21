=== WordPress Connector ===
Contributors: webactueel
Tags: rest-api, github, automation, elementor, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.20.16
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

= 1.20.16 =
* Development-only, UNPUBLISHED staging candidate: separately consented native ChatGPT OAuth file scope for existing CSS, TXT and Markdown assets inside plugin/theme directories. File edits require an explicit server opt-in, pinned staging origin, WordPress staging environment, administrator capability, write policy, SHA-256 precondition and confirmation; never enable automatically on production.
* WordPress-native React dashboard and WordPress 6.8.13 / PHP 8.4 compatibility coverage. Installation, real staging write/readback and full backup restore require separate acceptance.

= 1.20.13 =
* Development-only, UNPUBLISHED: opt-in WordPress-hosted OAuth authorization server using the verified ChatGPT CIMD identity, S256 PKCE, explicit administrator consent, one-use authorization codes, 15-minute opaque access tokens, rotating refresh tokens, strict resource binding, and owner revocation. Off by default; no production pairing, release or authorization is claimed.

= 1.20.12 =
* **Unpublished / NO-GO candidate.** Version 1.20.12 prepares source metadata above 1.20.10. On 21 November 2026, Hostinger reports ACDrive is running 1.20.10; its 60 files match the `v1.20.10` source paths and sizes, but installed file hashes are unavailable and a previous 1.20.11 report remains unreconciled. Security PR #1392 is merged. Protected governance PR #1393, real staging backup/restore and error tests, ChatGPT OAuth account pairing, final ZIP/SPDX/source attestation and explicit publication approval remain outstanding. No production deployment or published 1.20.12 release is claimed.

= 1.20.10 =
* Prefix internal bootstrap/uninstall globals and document only precisely scoped PHPCS exceptions for intentional atomic/fresh database operations and stable external hooks. No public API changes.

= 1.20.9 =
* Verified compatibility with WordPress 7.1.4 and legacy 7.0.8, WooCommerce 11.2.1 and Elementor 4.3.5. Existing REST/MCP permissions remain unchanged.

= 1.20.8 =
* Fixed missing request protocol version for WP-CLI actions and doctor. Added CLI regression coverage.

= 1.20.7 =
* Added read-only mutation status diagnostics and safer unresolved-state cleanup.

The complete changelog and historical release notes remain in docs/connector-release-history.md.