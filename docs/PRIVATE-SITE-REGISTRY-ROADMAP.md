# Private multi-site inventory and management: deployment plan

Status: deferred design reference — do not build a second multi-site platform by default.

## Current decision: reuse an existing site manager (2026-10-20)
- For multiple independently hosted WordPress sites, prefer an owner-authorized MainWP Dashboard and the official MainWP MCP integration. Do not recreate its client/site registry, update scheduler or workflow engine in WordPress Connector.
- WordPress Connector stays a *single authenticated site* action bridge. Its private `connector.setup.inspect` now detects MainWP Dashboard, MainWP Child and native WordPress multisite; this is detection only, not proof that any child is paired or that remote writes are authorized.
- The plugin never enumerates unknown websites, saves a customer-site inventory, shares credentials between sites, bypasses MainWP user approval or treats network activation as cross-site permission.
- A private registry below is a **deferred alternative** only if existing site-management software demonstrably cannot satisfy the requirement, the owner expressly approves it and a private authenticated service and site-specific staging tests exist.
- Backups remain the responsibility of the installed approved backup provider. The read-only readiness guide can identify WPvivid (Free/Pro), All-in-One WP Migration and other supported plugin basenames, but cannot certify a completed backup or staging restore. No automated restore runs from this bridge.

Official integration: https://github.com/mainwp/mainwp-mcp

## Deferred alternative (not an approved build target) This repository does not operate a central site inventory or an always-on agent. Upload, activation and public presence are not proof that a remote ChatGPT client is authorized to administer a site.

## Target flow (only after separate owner authorization)
1. Site administrator activates Connector and supplies or approves their site HTTPS origin.
2. An authenticated PRIVATE site registry (future component) verifies origin ownership using a signed challenge and separately performs a read-only, authenticated WordPress canary on exactly that site.
3. Store only site ID, origin, verified WordPress REST base, tenant scope, approval/revocation, last verified status, Connector version, and a reference to credentials in a secret manager.
4. ChatGPT can select an enrolled site; it checks exact site identity, available actions, WordPress permissions and prerequisite plugins before beginning a task.
5. Preview individual changes. Perform only authorized mutations with confirmation, fingerprint, rollback where possible, independent readback and compact per-action outcomes.

## Security and privacy requirements
- Never enumerate random websites for Connector installations. A public presence response means installed, not connected or writable.
- The public GitHub repository cannot host confidential lists of customers, installed plugin versions, directory trees, secrets or WordPress credentials.
- Each site's registration must be bound to an authenticated owner and normalized HTTPS origin. Reject redirect-domain substitution and ownership drift after a clone or move.
- The current short-lived inbound GitHub OIDC token cannot be reused as an outbound write credential to create registry entries. This needs a separate private service and authentication.
- Separate installed, reachable, paired, authenticated read and proven mutation state in any client UI; unknown and stale are not healthy.
- Only a verified site owner can unlink/revoke site registration; do not transmit admin credentials in ChatGPT messages or GitHub request files.

## Already supported (private authenticated REST/MCP)
Plugin list, install via WordPress.org slug, activation, deactivation, single update, inactive deletion, verified ZIP install, Connector self-update, generic options with protected-key blocks, provider-specific settings adapters, and guarded existing theme/plugin text edits.
Version 1.19.7 adds private WordPress.org directory search, update-aware installed-plugin status, and guarded non-PHP theme/plugin file creation/deletion. None are exposed as arbitrary public GitHub actions.

## Remaining deployment boundaries
- public_html means the hosting document root; it may differ from WordPress ABSPATH. A WordPress plugin may safely operate within its WordPress root only, not arbitrary host files.
- wp-config.php, core, credential files, backups, uploads, logs and PHP entrypoints require dedicated WordPress, deployment or hosting/SFTP workflows; never generic arbitrary public-repository file writes.
- For a policy denial, distinguish WordPress capabilities, DISALLOW_FILE_MODS, DISALLOW_FILE_EDIT, FS_METHOD, outgoing GitHub/WordPress.org HTTP errors, server WAF and provider/plugin API limitations.
- A 401/403 alone cannot identify a security plugin as the cause; inspect authorized logs and vendor settings. Never silently disable a security plugin or override hosting/network restrictions.
- Bulk plugin updates must report success, failure, skipped, recovery and version per plugin; successful upgrades are not always rollbackable.
- Future WordPress settings support should use provider or WordPress Settings/REST/Abilities APIs with typed validation and protected secret/system keys, not unrestricted option rows.

## Plugin release flow
GitHub main commit -> green Connector CI -> versioned GitHub Release ZIP with SHA-256 -> WordPress Update URI checks release -> native Plugins Update Now when version increases. Version 1.19.6 adds bounded Plugins-screen polling/manual refresh; no instant push to every website. A central registry could optionally notify enrolled users when an update is published.

Official references:
https://developer.wordpress.org/reference/functions/plugins_api/
https://developer.wordpress.org/reference/classes/plugin_upgrader/
https://developer.wordpress.org/reference/classes/wp_rest_plugins_controller/
https://developer.wordpress.org/reference/functions/wp_is_file_mod_allowed/
https://developer.wordpress.org/reference/functions/wp_filesystem/
https://developer.wordpress.org/advanced-administration/wordpress/edit-files/
