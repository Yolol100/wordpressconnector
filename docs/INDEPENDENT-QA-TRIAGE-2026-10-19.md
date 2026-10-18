# Independent QA triage: WordPress Connector v1.19.18 → v1.19.19

This is an evidence-bound triage document, **not** a declaration that every strict external scan passes. Source target: `54ddce0841bd62032d92e3b8a480f902a86bf432`. Independent audit: [Yolol100/programmeren run 39167632787](https://github.com/Yolol100/programmeren/actions/runs/39167632787), with its uploaded `wordpress-plugin-audit-39167632787` and `plugin-check-results` artifacts.

## Baseline and root causes

| Check | Observed baseline | Meaning |
| --- | --- | --- |
| PHP syntax | PASS | No parser error in scanned plugin PHP. |
| WPCS (`--standard=WordPress`) | FAIL | 58,781 PHP_CodeSniffer errors over 57 affected files; roughly 57,081 auto-fix markers. Predominantly compressed formatting, whitespace, braces and documentation; individual security findings still require manual triage. Do **not** mass-reformat the entire plugin without a source-diff and full runtime matrix. |
| PHPCompatibilityWP (`testVersion=7.4-`) | FAIL | One false parse of `break` within an unbraced `foreach` in `SystemAdapter::coreUpdate`. Braces now make the loop explicit. PHP 7.4–8.5 native contract tests are the actual runtime compatibility evidence. |
| PHPStan level 5, WordPress extension | FAIL | 499 findings: 337 in `WooCommerceAdapter.php`, 51 in `AcfAdapter.php`, plus 111 in other files. Many report undefined WC/ACF/Elementor/CLI symbols because the generic harness has WordPress Core stubs but not the optional provider stubs. Remaining issues include redundant guard checks and the real `wpdb::$dbh` visibility error. |
| Strict WordPress Plugin Check | FAIL | 413 individual messages: 324 errors, 89 warnings. 297 are `EscapeOutput.ExceptionNotEscaped`, mostly thrown exceptions, not direct HTML output; do not HTML-escape exception values before they reach their output context. Other findings cover direct DB calls, identifier quoting, file operations, and WordPress.org-specific policy. |
| Gitleaks / Semgrep / Trivy / SBOM | PASS execution | These checks ran successfully; scanner exit 0 does not certify freedom from vulnerabilities. |
| Generic WordPress runtime | PASS | Plugin activation/deactivation and public WordPress endpoints worked in isolated `wp-env`; this is not live website or OAuth authorization proof. |

## Implemented fixes in PR #1001

1. **WordPress diagnostic fatal risk:** `wpdb::$dbh` is a protected property in supported WordPress versions. `system.doctor` now reads public `wpdb::$ready`. Fixture tests cover ready, not-ready and missing database services. The actual WordPress CI matrix now calls system.doctor to catch integration regressions.
2. **PHPCompatibilityWP:** Convert a compressed `foreach` to explicit braces and preserve the early `break`.
3. **PHPStan callback contract:** Align the `elementor/mcp/pre_execute_guard` registered argument count with its one-argument closure. Existing capability and denial behavior remain.
4. **WordPress SQL identifier safety:** Use `wpdb::prepare('%i', ...)` for WooCommerce shipping and tax table names, while preserving typed value placeholders and page/row limits; update the test double to model official `%i` quoting. Supported since WordPress 6.2 (plugin minimum 6.4).
5. **Readme:** Align upcoming v1.19.19 plugin metadata, reduce WordPress.org tags to five and describe the actual fix scope.

## Remaining gates — not waived

- **WPCS**: Review security sniffs separately from auto-fixable formatting. Migrate styling file-by-file with PHPCBF diffs, preserve the PHP 7.4 minimum, and run all source-sensitive contracts plus the WordPress/WooCommerce/Elementor runtime matrix after each bounded batch. No blanket `phpcs:ignore` or edit to pretend the full WordPress ruleset is compliant.
- **PHPStan**: Load accurately versioned provider stubs (official `php-stubs/woocommerce-stubs`, `php-stubs/acf-pro-stubs`, optional CLI/Elementor information) into a dedicated, locked analysis environment. Re-run to separate missing symbols from real diagnostics, repair confirmed code faults and review only narrowly justified, version-conditional guards. Do not baseline all 499 entries as accepted defects.
- **Strict Plugin Check**: Review the remaining HTML output sinks, prepared SQL, readme, file operations and provider compatibility. The `plugin_updater_detected` and WordPress trademark warnings are for WordPress.org directory acceptance; the current product uses intentionally separate GitHub distribution and `Update URI`. Do **not** remove update integrity checks or rename a deployed plugin solely to make a WordPress.org gate green.
- **Existing CI policy**: The repository's official `plugin-check` job excludes `plugin_review_phpcs`, `plugin_updater`, `trademarks`, `late_escaping` and `performant_wp_query_params`. This is a distinct, documented private-distribution gate; its success is not equal to a clean strict WordPress.org Plugin Check scan.
- **Release readiness**: Require green PR checks on the **latest head SHA**, repeat isolated Docker WordPress tests, validate reproducible ZIP/version/hash after merge, and check the actual installed site separately. A clean PHP syntax check or a previously green commit is not enough.

Official technical oracles:
- [WordPress `wpdb::prepare` identifiers and version gate](https://developer.wordpress.org/reference/classes/wpdb/prepare/)
- [WordPress `wpdb` class and public readiness flag](https://developer.wordpress.org/reference/classes/wpdb/)
- [Official Plugin Check action configuration](https://github.com/WordPress/plugin-check-action)
- [PHPStan WordPress extension and WC stubs](https://github.com/szepeviktor/phpstan-wordpress)
