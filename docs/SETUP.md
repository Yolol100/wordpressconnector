# Setup

## 1. Install

Install and activate `plugin/wordpressconnector` on the target WordPress site. Normal GitHub use needs no connector checkboxes, no GitHub site variable, no WordPress username and no Application Password.

The plugin immediately registers:

- HTTPS REST routes under `/wp-json/webactueel-wordpress-connector/v1/`;
- an authenticated MCP endpoint at `/wp-json/webactueel-wordpress-connector/v1/mcp`;
- the semantic action registry;
- an informational `Settings -> WordPress Connector` page;
- optional WP-CLI commands.

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

No long-lived GitHub-to-WordPress credential is needed.

## 3. What zero-config discovery means

Once a domain is known, the plugin can immediately prove it is installed via:

`https://example.com/wp-json/webactueel-wordpress-connector/v1/presence`

GitHub cannot securely enumerate arbitrary unknown WordPress domains merely because this plugin is installed. Automatic “list every connected website” discovery requires a separate authenticated site registry/pairing service. The connector intentionally does not publish a global installation list or crawl the web to guess installations.

## 4. Private full-action control plane

For full connector administration use the private `Yolol100/Wordpress` repository with its trusted `wordpressconnector-request.yml` -> `wordpressconnector-zero-config-execute.yml` flow. Private request branches may use the full registered action catalog and may persist full `results/*.json`. This is still not an unrestricted shell: connector confirmation, WordPress capabilities, semantic payload validation, idempotency, stale-state protection, readback/rollback and filesystem/package guards remain enforced.

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

## 6. Request confirmation and safety

The plugin is operational immediately after activation, but this does not mean destructive actions execute without request controls:

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

Modern MCP clients may use protocol revision `2026-07-28` with `server/discover`; the connector also retains the legacy `2025-11-25` initialize flow. The MCP facade exposes only connector action discovery, runtime discovery and semantic execution. It does not provide global site discovery, billing, quotas, hosted skills or background-job orchestration.

For provider-native WordPress Abilities, first run `wordpress.abilities`; add payload `{"namespace":"elementor"}` to focus discovery on the installed Elementor MCP surface. `wordpress.ability.read` remains generic for client-exposed WordPress Abilities that explicitly declare `readonly=true` and `destructive=false`; native validation and permission callbacks still run and Connector output is bounded/redacted. `wordpress.ability.execute` is narrower: only explicitly MCP-exposed native Elementor Core/Pro mutations whose `execute_guarded` provider ID matches the requested Ability and whose callback source resolves beneath an active canonical Elementor plugin root are eligible; a matching slug or category is insufficient. Explicit `mcp.public=false` stays hidden. Preview mutations with connector `dry_run=true`, then execute only with `dry_run=false`, `confirm=true` and a stable explicit `request_id`. Dry-run is metadata-only and never invokes provider code.

For dedicated connector writes, use the same safe sequence as REST: discovery -> dry-run -> capture fingerprint/state when supported -> confirmed call with a stable explicit `request_id` -> readback -> rollback when supported. Delegated Elementor Ability mutations cannot run inside `connector.batch` and do not claim connector rollback/fingerprint support; validate their target state with Elementor's read surface and appropriate QA. A completed Ability whose response is too large/deep becomes a bounded terminal success; a provider exception/`WP_Error` becomes a stored terminal failure so replay of that request ID does not blindly execute again.

## 10. Acceptance sequence

1. Verify `/presence` on the target staging site.
2. Run an authenticated `/health` through the GitHub OIDC route.
3. Run `connector.discover` / `system.doctor` where the transport allows it.
4. Perform a harmless dry-run.
5. Perform one disposable confirmed staging mutation with stale-state protection.
6. Verify exact readback.
7. Test rollback.
8. For asset-dependent actions, test one bounded upload plus execution.
9. Independently verify the resulting WordPress/Elementor state.

Static CI is not production-runtime proof. First live writes remain staging-first or require equivalent target-runtime evidence.
