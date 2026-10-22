=== WordPress Connector ===
Contributors: webactueel
Tags: rest-api, github, automation, elementor, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.19.19
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zero-config controlled WordPress automation bridge over authenticated HTTPS REST, native MCP and optional local WP-CLI recovery.

== Description ==

WordPress Connector links an authorized WordPress administrator to bounded REST and MCP management tools. It supports guarded content, Elementor, WooCommerce and SEO operations with role checks, previews and recovery paths. The plugin distributes signed, checksum-verified releases outside the WordPress.org directory. See the Changelog below and the full technical history on GitHub.

== Security ==

REST endpoints require HTTPS. The canonical GitHub runtime uses short-lived GitHub Actions OIDC authentication bound to the canonical repository and workflow; ordinary authenticated WordPress administrators remain supported for direct REST access. The connector exposes no generic shell, arbitrary SQL, eval or unrestricted filesystem endpoint.

Confirmed writes still require request confirmation. Sensitive actions require explicit request confirmation and are blocked in public-repository mode. Privileged/system/filesystem action classes can be disabled server-side through WPCONNECTOR_ALLOW_* constants or environment variables. Public GitHub transport never persists full WordPress responses and never permits sensitive actions. The bounded public ACF exception is evaluated dynamically against the exact published post, existing ACF textual-field identity (`text`, `textarea` or `wysiwyg`), applicable field group and edit capability; textarea/WYSIWYG updates are plain-text only in public-repository mode, and the broad privileged ACF action remains blocked outside that bounded case. The dedicated portfolio-stat action is separately limited to the standard `post` type, five normal content statuses, the eight `portfolio_stat_*` text fields and exact readback; public execution is admitted only after payload validation and confirmed writes require the preceding dry-run fingerprint. It does not make unpublished post content readable.

Do not commit credentials, passwords, private plugin ZIPs, payment data, patient/medical records or other sensitive production records to GitHub.

== Changelog ==

= 1.19.19 =
* Release-candidate hardening: guarded WordPressCS formatting with PHP-token preservation, WooCommerce registered custom product-type factory validation, fail-closed product readback, and PHP 7.4–8.5 regressions.
* Prevent system.doctor from reading protected wpdb internals and verify healthy/unhealthy database readiness in mock and live WordPress CI.
* Clarify the WordPress Core update foreach loop to satisfy PHPCompatibilityWP without changing update behavior.
* Correct Elementor MCP pre-execution callback registration to match its single argument.
* Escape WooCommerce table identifiers via wpdb::prepare %i (WordPress 6.2+), retain prepared value placeholders, and update test fixture SQL parser.
* Prepare WordPress options table identifiers in snapshot, processed-request and uninstall SQL while preserving expiration/cleanup boundaries.
* Use WordPress wp_parse_url() for verified plugin/theme directory packages and Yoast logo URLs.
* Reject malformed administrator notice, Elementor JSON export and cache loopback inputs; sanitize consent/method fields and restrict the one-time MCP credential HTML response with a dedicated WordPress KSES allowlist.
* Keep WordPress capabilities, confirmation, read-only probes, and GitHub update provenance unchanged. Strict full WPCS/PHPStan/WordPress.org review remains a separate quality task.

= 1.19.18 =
* Compare current OpenAI OAuth requirements and WordPress/Elementor/WooCommerce/MainWP/WP Umbrella MCP management patterns in a comprehensive evidence-backed repository report.
* Add a one-click authenticated WordPress-browser MCP connection test and credential-free copy endpoint control under Settings > WordPress Connector.
* Verify modern MCP server/discover with the signed-in WordPress REST nonce. Report blocked REST/headers/WAF without pretending that ChatGPT OAuth or a complete backup has been verified.
* Exercise admin-only behavior, successful/denied/WAF JavaScript states, no credential transport, and real WordPress admin rendering/enqueue behavior.
* Preserve existing permissions, confirmation and backup limits; OAuth account linking and external full-site staging restore still require separately verified providers and consent.

All earlier releases and detailed version notes are preserved in the [full release history](https://github.com/Yolol100/wordpressconnector/blob/main/docs/FULL-RELEASE-HISTORY.md).
