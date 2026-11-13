# PHP coding standards baseline

The repository uses the full official `WordPress` PHP_CodeSniffer ruleset to
inspect all PHP files shipped in `plugin/wordpressconnector`.

The unprivileged `WordPress Coding Standards Audit` workflow uses isolated
audit-only Composer dependencies outside the plugin source:

- WordPress Coding Standards 3.4.1.
- PHP_CodeSniffer 3.13.6.
- No ignored sniff categories, per-file suppressions or narrowed standards.

The scan is **baseline/reporting-only**. A green audit job means the scanner
ran and produced a valid report, **not** that the plugin is compliant. The
job summary shows the exact error/warning totals; download the
`wordpress-coding-standards-baseline` artifact for all file/line/sniff details.
Do not hide legacy violations with a wholesale ignore file or automatic PHPCBF.

New changes should reduce actionable findings, prioritizing security,
internationalization, correct escaping/sanitization and interoperability over
cosmetic whitespace. Record comparable results before and after changes.
Never replace the existing PHP runtime, WordPress, WooCommerce, security or
package tests with this additional advisory audit.

Local repeat: install `wp-coding-standards/wpcs:3.4.1` and
`squizlabs/php_codesniffer:3.13.6` using Composer in a separate tool directory,
then run its `vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary`
from the repository root.
