# Private multi-site inventory and management: deployment plan

Status: design only. This repository does not operate a central site inventory or an always-on agent. Upload, activation and public presence are not proof that a remote ChatGPT client is authorized to administer a site.

## Target flow
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

## Read-only private release planning (initial implementation)
`scripts/plan-fleet-release.py` creates a **non-executing** candidate plan from a separately prepared private site inventory. It is an offline planning and validation helper, **not** a hosted registry, an OAuth server, a live WordPress connection, GitHub webhook handling or a deployment executor. Do not pass real customer inventories to a public repository, branch, issue, pull request or workflow artifact.

Use from a trusted private workspace:

```sh
python3 scripts/plan-fleet-release.py --input /private/enrolled-sites.json --output /private/release-plan.json
```

The input schema is version 1, with one release and a bounded list of site references. Example values are fictional:

```json
{
  "schema_version": 1,
  "release": {
    "kind": "plugin",
    "identity": "example-plugin/example-plugin.php",
    "repository": "example-owner/example-plugin",
    "version": "2.1.0",
    "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
  },
  "sites": [
    {
      "site_ref": "site-001",
      "authorized": true,
      "read_verified": true,
      "components": [
        {
          "kind": "plugin",
          "identity": "example-plugin/example-plugin.php",
          "repository": "example-owner/example-plugin",
          "version": "2.0.0",
          "active": true,
          "available_version": "2.1.0"
        }
      ]
    }
  ]
}
```

The operator or a future private controller must independently prove each site's owner authorization, canonical HTTPS origin and authenticated readback **before** marking `authorized` and `read_verified` true. This script cannot validate self-reported flags, check a live site, authenticate a WordPress account or verify whether Hostinger backups can actually be restored. The release checksum describes a proposed package; it is not an actual signature verification.

The planner selects only active, installed, exact-identity and exact-repository matches with safely comparable numeric versions. It distinguishes eligible native plugin/theme update offers, private verified plugin ZIP candidates, skipped sites and blocked sites. Different offers or unknown version metadata block, and an active theme without a matching native update offer is blocked. Output contains no credentials, cannot execute changes (`execution_enabled: false`) and is created as a new private file (0600 on POSIX). A real executor must recheck identity, site permissions, offer/version, artifact SHA-256, confirmation, and post-write readback at execution time, plus have an accepted recovery path. The existing private REST/MCP actions remain the only WordPress runtime action owners.

### Pinned native updates
The private `plugin.update` and `theme.update` actions now optionally accept `expected_target_version`. Dry-run exposes the cached native update offer and installed version. A confirmed pinned update refreshes the native offer, rejects a changed offer **before** invoking WordPress's upgrader, and verifies the actual installed version afterward. The legacy request without a pin remains supported but cannot promise an exact target version. Neither path guarantees rollback after an upgrade. Active theme package **replacement** stays prohibited by `theme.install_package`; a verified native `theme.update` offer is a different, supported WordPress Core path.

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
