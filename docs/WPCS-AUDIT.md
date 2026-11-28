# PHP coding standards baseline

The repository uses the full official `WordPress` PHP_CodeSniffer ruleset to
inspect all PHP files shipped in `plugin/wordpressconnector`.

The unprivileged `WordPress Coding Standards Audit` workflow uses isolated
audit-only Composer dependencies outside the plugin source:

- WordPress Coding Standards 3.4.1.
- PHP_CodeSniffer 3.13.6.
- Full `WordPress` ruleset, without blanket exclusions or narrowed standards.
- Existing inline source suppression directives remain subject to the protected
  regression gate; no new, moved or broadened directives are accepted.

The audit enforces the **mandatory exact-base WPCS no-regression gate** on
pull requests. A green audit job means the scan and regression comparison
succeeded, **not** that the plugin has zero findings. The gate compares each
finding and suppression directive against the trusted base, including its
file path and source context. Moving otherwise identical code can therefore
fail the gate; a lower total is not sufficient for acceptance.
The job summary shows the exact error/warning totals; download the
`wordpress-coding-standards-baseline` artifact for all file/line/sniff details.
Do not hide legacy violations with a wholesale ignore file or automatic PHPCBF.

New changes should reduce actionable findings, prioritizing security,
internationalization, correct escaping/sanitization and interoperability over
cosmetic whitespace. Record comparable results before and after changes.
Never replace the existing PHP runtime, WordPress, WooCommerce, security or
package tests with this mandatory regression audit.

Local repeat: install `wp-coding-standards/wpcs:3.4.1` and
`squizlabs/php_codesniffer:3.13.6` using Composer in a separate tool directory,
then run its `vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary`
from the repository root.

### Backward-compatible adapter filenames

The canonical loader uses the lowercase class filenames for Code Snippets and
Filesystem adapters. The original include paths remain as minimal require-once
forwarders so existing direct PHP includes continue to resolve the same classes.
The complete audit still scans these forwarders and reports their legacy casing.
Do not remove them merely to reduce the report count.
