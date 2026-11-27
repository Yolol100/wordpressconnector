# Retention audit: keep everything until central migration is proven

Date: 2026-11-22. Source audited: WordPress Connector 1.20.18 development candidate, 65-file ZIP and GitHub commit f94bfc4f72087a38f8b6651b7dc3c639d57cc234. **Nothing has been deleted.** The user explicitly requires the GitHub workflow to remain functional.

| Component | Decision | Why |
| --- | --- | --- |
| GitHub Actions OIDC, Security/SitePairing.php, Security/GitHubOidc.php | KEEP | Site-specific GitHub authorization, signed tokens, replay protection and established user workflow. |
| Signed GitHub releases and deployment workflow | KEEP | Trusted, versioned packaging and rollback; central MCP is additive, not a replacement. |
| REST/Controller.php and REST/AssetStore.php | KEEP | Bootstrapped authenticated WordPress REST and GitHub operations. |
| MCP/Controller.php, OAuthResourceServer.php, NativeOAuthServer.php | KEEP | Per-site ChatGPT OAuth and MCP connection; required as fallback until every client has migrated. |
| Admin/class-settings.php and connection-doctor.js | KEEP, possibly consolidate UI later | Site-specific admin approvals, tests and backwards compatibility. |
| All registered Adapters (WordPress, Elementor, WooCommerce, ACF, Yoast, media, plugins, filesystem) | KEEP | Explicitly registered in Plugin::boot; optional provider absence on one site does not mean dead code. |
| Runtime Runner, Registry, ProcessedStore, SnapshotStore | KEEP | Authorization, transaction checks, idempotency, recovery and rollback. |
| Elementor JSON import/export and WP-CLI tools | KEEP | Registered in the plugin bootstrap and used by current workflows. |
| Individual per-site ChatGPT app registrations | POSSIBLE RETIRE AFTER MIGRATION | Remove only after each owner migrated, central OAuth confirmed, and old grants revoked. Not now. |
| Direct MCP application-password setup UI | POSSIBLE UI CONSOLIDATION | Inspect real users and migrate existing credentials first. Never revoke silently. |
| Multiple connection health displays | POSSIBLE MERGE | Match full diagnostic coverage and browser usability before merging. |
| Deprecated MCP protocol handling | POSSIBLE LATER REMOVAL | Usage telemetry and backwards-compatibility testing required; not safe to delete now. |
| Any packaged plugin file | NO DELETION APPROVED | All files/services appear reachable or packaging-related; no proven dead file. |

## Migration acceptance checklist

1. Keep GitHub, WordPress REST, per-site OAuth and MCP unchanged.
2. Add independent central service with one ChatGPT OAuth connection.
3. Link each WordPress site with an administrator-confirmed, site-scoped pairing flow.
4. Verify user/tenant isolation, permission revocation and full authenticated read paths.
5. Test write previews, WordPress capabilities, backups, rollback and WooCommerce order safety.
6. Migrate each site; retain the fallback during a verified overlap period.
7. Only then open a separate cleanup PR with exact search evidence, usage data and rollback.

No installation may auto-grant unlimited WordPress rights. WordPress hardening such as DISALLOW_FILE_EDIT remains binding; an authenticated connection is not permission to modify every file.
