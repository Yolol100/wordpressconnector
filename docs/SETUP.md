# Setup

## 1. Install

Install and **activate** `plugin/wordpressconnector` as a WordPress administrator on the target site. **Activation itself approves the pinned `Yolol100/wordpressconnector` GitHub workflow**, with WordPress Core activation capability/nonce already enforced: there is no second authorization step in normal wp-admin installations. The connection remains scoped to that WordPress site and the activating administrator; it can be revoked at Settings → WordPress Connector. A file upload without activation, unattended WP-CLI activation, network-wide activation, or upgrade of an older unpaired site does not silently grant GitHub administrator access: an administrator can use **Reconnect trusted GitHub Actions** when necessary. Do not install this remote-management plugin on a site that you do not authorize the named GitHub owner to manage.

The plugin immediately registers:

- HTTPS REST routes under `/wp-json/webactueel-wordpress-connector/v1/`;
- an authenticated MCP endpoint at `/wp-json/webactueel-wordpress-connector/v1/mcp`;
- the semantic action registry;
- an informational `Settings -> WordPress Connector` page;
- optional WP-CLI commands.

## Native Plugins-screen updates (1.19.0+)

After the one-time upgrade from 1.18.9 (which declares `Update URI: false`), WordPress Core checks the canonical GitHub release via the plugin's `Update URI` response filter. From 1.19.6, the Plugins/Updates admin screen also checks the verified release with a bounded two-minute throttle, without resetting other plugins' update transients. Administrators can click **Check GitHub update now** next to WordPress Connector for an explicit immediate refresh. The native **Update Now** row appears when a valid release version is higher than the installed plugin. The existing SHA-256 and plugin ZIP identity verification run again before Core receives the update package. Other GitHub plugins are not intercepted.

Do not confuse this ordinary WordPress dashboard upgrade with the guarded `connector.update.apply` REST action. Both share trusted package verification; WordPress's temporary backup is not a general rollback after a successful installation. Take and test an external backup before a first production upgrade. Automatic background updates are not enabled by this change.

## Yoast SEO templates and secure transport

The existing `yoast.templates.inspect`, `yoast.templates.update` and rollback action use an explicit, bounded field map rather than arbitrary `wpseo_titles` option access. The separate private-only `yoast.features.inspect`, `yoast.features.update`, and `yoast.features.restore` actions cover selected boolean Yoast Site features, integration toggles and archive/indexing toggles, with a one-option-group limit and extra `risk_acknowledged=true` for high-impact SEO switches. No provider OAuth tokens, MyYoast credentials or Premium entitlements are writable. Version 1.19.1 expands the privately authorized snippet template allowlist beyond `category_description` (`metadesc-tax-category`) to text templates for tags, author/date archives, special search/404 results, six bounded breadcrumb text labels, and the existing content type / social appearance sections. This is **not blanket support for every Yoast setting**: booleans, structured options, private provider accounts and Premium workflows require their own validated adapters. See [`YOAST-ADMIN-COVERAGE.md`](YOAST-ADMIN-COVERAGE.md) for the menu-by-menu plan. Current per-post `yoast.inspect` / `yoast.update` remain scoped to their already supported SEO fields. Yoast SEO 28.6+ may expose native `yoast-seo/get-post-seo-data` and `yoast-seo/update-post-seo-data` Abilities when installed and authorized; the generic Connector Ability mutation route must not be broadened to other providers without specific guards.

**Public GitHub runtime does not offer arbitrary Yoast settings.** Its `yoast.templates.inspect` result includes only `category_title` and `product_category_title`, and the public request validator accepts updates to exactly those title fields. In particular `category_description` is intentionally not available through the public transport. Do not relax `Policy` or the public request/receipt guards to reach other settings.

To manage the full allowlisted template map, use a separately authenticated, authorized **private** Connector REST/MCP surface and require WordPress `manage_options`, a dry-run, exact stale-state fingerprint, write confirmation, readback and rollback evidence. Yoast's `/wp-json/yoast/v1/get_head` API is read-only, useful for verifying generated metadata after a change, not for saving options. The official WordPress Abilities API (WordPress 6.9+) and MCP Adapter are an optional future integration route, **not installed or enabled by this release**.

## 2. Zero-config GitHub runtime

A runtime request includes the target site itself:

```json
{
  "version": 1,
  "site_url": "https://example.com",
  "request_id": "example-request-001",
  "action": "post.update",
  "dry_run": true,
  "confirm": false,
  "payload": {
    "id": 123,
    "excerpt": "Preview text"
  }
}
```

Flow:

1. Create a short-lived same-repository branch from `main`.
2. Add exactly one `requests/*.json` file. Optional request assets go under `assets/inbox/*`.
3. Open a pull request to `main`.
4. `wordpress-request.yml` validates the request without production credentials.
5. `wordpress-zero-config-execute.yml` revalidates the PR, actor, latest commit author and exact head SHA.
6. The executor checks the HTTPS `/presence` endpoint on `site_url`.
7. GitHub Actions issues short-lived OIDC tokens with the site REST namespace as audience.
8. WordPress verifies GitHub's JWT signature and trusted claims before accepting asset uploads or `/execute`.
9. Public repositories receive only a sanitized `receipts/*.json`; private repositories may receive a full `results/*.json`.
10. Close the runtime PR without merging its request/result/receipt payloads.

No long-lived GitHub-to-WordPress credential is needed. Normal **administrator activation is now the single pairing step** on new sites. An existing site upgrading from older versions is not silently re-authorized by the update itself: if it has no pairing, use **Settings → WordPress Connector → Reconnect trusted GitHub Actions** once, or deactivate/reactivate deliberately. Moving/cloning to another canonical URL invalidates the existing binding. Presence alone never grants access.

## 3. What zero-config discovery means

Once a domain is known, the plugin can prove it is installed via the public presence route **even if GitHub OIDC is not yet authorized**:

`https://example.com/wp-json/webactueel-wordpress-connector/v1/presence`

GitHub cannot securely enumerate arbitrary unknown WordPress domains merely because this plugin is installed. Automatic “list every connected website” discovery requires a separate authenticated site registry/pairing service. The connector intentionally does not publish a global installation list or crawl the web to guess installations.

## 4. Private full-action control plane

For full connector administration a separately verified **private** `Yolol100/Wordpress` repository is required with its trusted `wordpressconnector-request.yml` -> `wordpressconnector-zero-config-execute.yml` flow. Private request branches may use the full registered action catalog and may persist full `results/*.json`. This is still not an unrestricted shell: connector confirmation, WordPress capabilities, semantic payload validation, idempotency, stale-state protection, readback/rollback and filesystem/package guards remain enforced.

## 5. Repository visibility and public-mode restrictions

This repository is currently public. Private mailbox transport must remain on a separate private control plane; the public WordPress Connector runtime persists only sanitized receipts.

The public runtime remains restricted to the explicit public-safe contract, including:

- `post.update` on existing published content;
- `custom_css.inspect` for the active theme, with public receipts limited to hash/byte evidence;
- guarded `custom_css.patch` for one named managed CSS block on the active theme, with dry-run fingerprint, readback and rollback;
- post-targeted `acf.update`;
- `connector.batch` containing only those public content operations;
- `connector.rollback`;
- `connector.update.check`;
- guarded `connector.update.apply` using dry-run-first fingerprint protection;
- guarded `plugin.install_package` for publishable packages with exact SHA-256/plugin identity, `network_wide=false`, explicit activation intent and a preceding dry-run fingerprint; `overwrite=true` is restricted to `webactueel-mailbox-bridge/webactueel-mailbox-bridge.php`.

Sensitive actions stay blocked in public mode. Secret-like payload keys and `expected_state_token` are rejected. Full/private WordPress or mailbox responses must never be committed to a public branch, log, issue, artifact or receipt.

Private/custom plugin ZIPs must not be placed on a public request branch. Request assets in a public repository are themselves public.

### Bounded site identity on public GitHub

Authenticated owner/trusted-actor OIDC requests may read or update only `blogname`, `blogdescription`, `timezone_string` and `WPLANG` using `site.identity.inspect`/`site.identity.update`. The WordPress actor must still have `manage_options`. Writes require a site-state fingerprint from a prior dry-run; rollback remains site-local. Site identity fields must be plain text, a valid IANA timezone or a supported installed locale. This is not generic option access: `option.get` and `option.update` remain blocked. A separate provider-owned public-safe allowlist permits only GTranslate language settings and Yoast organization name/description (not logos, private options or secrets), always with WordPress `manage_options`, fingerprints, readback, rollback and sanitized public receipts.

## 6. Request confirmation and safety

The direct authenticated REST/MCP surface is operational after activation. **Normal administrator activation approves the pinned GitHub OIDC workflow automatically**; other installation modes remain unpaired until a local administrator reconnects. Pairing never bypasses any request controls:

- mutations require `dry_run:false` and `confirm:true`;
- action-specific WordPress capabilities remain required;
- stale-state fingerprints/tokens remain supported;
- mutations remain idempotent and serialized;
- supported changes retain readback and rollback;
- sensitive actions require request-level confirmation and a non-public transport.

Optional `WPCONNECTOR_ALLOW_*` constants or environment variables can still disable writes, privileged actions, sensitive actions, system updates or filesystem writes server-side. These are emergency/hosting controls, not normal setup requirements.

## 7. Assets

The zero-config executor preserves request-scoped asset transport:

- maximum 10 assets;
- maximum 25 MiB total;
- safe `assets/inbox/*` paths only;
- a fresh short-lived GitHub OIDC authentication is used for each authenticated asset request and for final execution;
- WordPress cleans request assets after execution.

## 8. Direct REST clients

Non-GitHub clients may still use normal WordPress authentication over HTTPS, including an authenticated WordPress session or Application Password where appropriate. GitHub OIDC only removes persistent credentials from the canonical GitHub runtime.

## 9. Native MCP clients

The MCP endpoint uses the same WordPress authentication boundary as the direct REST endpoints. It is not an anonymous pairing endpoint. Connect an approved client with an authenticated WordPress HTTPS session or Application Password where that client supports normal WordPress HTTP authentication.

Modern MCP clients may use protocol revision `2026-07-28` with `server/discover`; the connector also retains the legacy `2025-11-25` initialize flow. The MCP facade keeps its three bounded action tools and additionally advertises **two read-only resources** and **two user-selectable prompts**, without registering a second WordPress execution interface. Modern results carry private cache hints. `resources/list` and `resources/read` expose fixed safety/integration guides at `wordpress-connector://guide/safe-changes` and `wordpress-connector://guide/integrations`. `prompts/list` and `prompts/get` expose `wordpress_connector_readonly_audit` and `wordpress_connector_plan_safe_change` (optional one-line `goal` up to 240 bytes). These guides/templates do not perform or authorize writes, do not read site secrets, and always use the same authenticated HTTPS route. It does not provide global site discovery, billing, quotas, hosted skills or background-job orchestration.

For provider-native WordPress Abilities, first run `wordpress.abilities`; add payload `{"namespace":"elementor"}` to focus discovery on the installed Elementor MCP surface. `wordpress.ability.read` remains generic for client-exposed WordPress Abilities that explicitly declare `readonly=true` and `destructive=false`; native validation and permission callbacks still run and Connector output is bounded/redacted. `wordpress.ability.execute` is narrower: only explicitly MCP-exposed native Elementor Core/Pro mutations whose `execute_guarded` provider ID matches the requested Ability and whose callback source resolves beneath an active canonical Elementor plugin root are eligible; a matching slug or category is insufficient. Explicit `mcp.public=false` stays hidden. Preview mutations with connector `dry_run=true`, then execute only with `dry_run=false`, `confirm=true` and a stable explicit `request_id`. Dry-run is metadata-only and never invokes provider code.

For dedicated connector writes, use the same safe sequence as REST: discovery -> dry-run -> capture fingerprint/state when supported -> confirmed call with a stable explicit `request_id` -> readback -> rollback when supported. Delegated Elementor Ability mutations cannot run inside `connector.batch` and do not claim connector rollback/fingerprint support; validate their target state with Elementor's read surface and appropriate QA. A completed Ability whose response is too large/deep becomes a bounded terminal success; a provider exception/`WP_Error` becomes a stored terminal failure so replay of that request ID does not blindly execute again.

## 10. Acceptance sequence

1. Verify `/presence` on the target staging site; this does **not** prove OIDC authority. A normal admin activation already pairs the site. On older unpaired/CLI/network installations, use Settings → WordPress Connector for recovery.
2. Run an authenticated `/health` through the GitHub OIDC route.
3. Run `connector.discover` / `system.doctor` where the transport allows it.
4. Perform a harmless dry-run.
5. Perform one disposable confirmed staging mutation with stale-state protection.
6. Verify exact readback.
7. Test rollback.
8. For asset-dependent actions, test one bounded upload plus execution.
9. Independently verify the resulting WordPress/Elementor state.

Static CI is not production-runtime proof. First live writes remain staging-first or require equivalent target-runtime evidence. A change on GitHub `main` is not automatically downloadable: `Connector CI` must pass and the `Connector Release` job must publish a new versioned GitHub ZIP. WordPress does not receive an instant push; opening Plugins after the short check interval or clicking **Check GitHub update now** triggers the bounded native update notice check. If the host blocks outgoing GitHub API requests, WordPress cannot show a verified new release.

## ChatGPT and full-site recovery setup assistant (v1.19.17)

Open **WordPress → Settings → WordPress Connector → ChatGPT connection and recovery setup**. The guide is read-only and checks the current HTTPS context, whether GitHub administrator pairing is recorded, and a small fixed list of **active** backup plugins. It shows actual outstanding steps for OAuth, database+files backup, offsite storage and independent staging restoration. Backup-plugin detection **never** marks backup, offsite copy or a restore as verified.

The new private `connector.setup.inspect` action requires authenticated `manage_options`, is excluded from the public GitHub request allowlist, accepts no inputs and cannot create credentials, call third-party servers, run backups, trigger restores, modify settings or grant write permission. If a site has no supported plugin, the host may still manage backups; this guide cannot see host backups without a separately consented, provider-specific API. Core's plugin/theme temporary backup protects update failures only and is not a full-site backup.

**This is not a working ChatGPT OAuth connection.** That still requires a trusted OAuth 2.1 authorization issuer, public protected-resource metadata, PKCE, token audience/scope verification, explicit per-user WordPress mapping and refresh/revocation proof (issue #984), plus user/admin consent in a ChatGPT workspace. An application password for HTTPS Basic Auth cannot substitute for that OAuth contract. Never enter WordPress credentials in GitHub branches, ChatGPT chats or URLs.

**This is not a full backup engine.** A real usable backup needs database + files, storage independent of the site/host, and a verified restore on an isolated staging clone. The site owner must connect/approve a compatible host or backup provider and perform the first restore test. Staging and backup providers differ; no universal authenticated host restore API exists in WordPress Core.

## Direct MCP credential button (admin opt-in)

An authenticated administrator may use **Settings > WordPress Connector > Generate my direct MCP password**. This opt-in creates a dedicated WordPress Application Password for the **currently signed-in** account only; it does not select the first account. The form requires a valid WordPress nonce, explicit acceptance, HTTPS, the `create_app_password` capability and WordPress Application Password availability. Only the one-time response displays the plaintext value, with no-cache headers; revoke it from the WordPress Profile page. Do not post this password to a GitHub repository, workflow, issue, log or conversation.

**ChatGPT web custom MCP is a different authentication client:** current ChatGPT custom MCP connection choices include OAuth and unauthenticated transport; WordPress Application Password / Basic Auth is **not** a replacement for OAuth in that workflow. Do not mark direct ChatGPT linking as working merely because the button generates a password. Use a separately security-reviewed OAuth authorization server or trusted OAuth-capable bridge before enabling ChatGPT connections. The current GitHub workflow already uses short-lived OIDC without Application Passwords, and remains unchanged.

Before using either path on a real site: read back the currently deployed Connector version; validate WordPress REST/MCP authorization, HTTP 401/403 rejection, capability enforcement, explicit confirmation, and revocation. Use an isolated WordPress test and security acceptance before deploying this change.
