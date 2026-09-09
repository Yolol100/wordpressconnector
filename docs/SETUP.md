# Setup

## 1. Install

Install and activate `plugin/wordpressconnector` on the target WordPress site. Normal GitHub use needs no connector checkboxes, no GitHub site variable, no WordPress username and no Application Password.

The plugin immediately registers:

- HTTPS REST routes under `/wp-json/webactueel-wordpress-connector/v1/`;
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

## 4. Public repository restrictions

This repository is public, so request branches and GitHub Actions history are visible. Public runtime remains restricted to the explicit public-safe contract:

- `post.update` on existing published content;
- post-targeted `acf.update`;
- `connector.batch` containing only those public content operations;
- `connector.rollback`;
- `connector.update.check`;
- guarded `connector.update.apply` using dry-run-first fingerprint protection.

Sensitive actions stay blocked. Secret-like payload keys and `expected_state_token` are rejected. Full WordPress responses are never committed publicly.

Private/custom plugin ZIPs must not be placed on a public request branch. Request assets in a public repository are themselves public.

## 5. Request confirmation and safety

The plugin is operational immediately after activation, but this does not mean destructive actions execute without request controls:

- mutations require `dry_run:false` and `confirm:true`;
- action-specific WordPress capabilities remain required;
- stale-state fingerprints/tokens remain supported;
- mutations remain idempotent and serialized;
- supported changes retain readback and rollback;
- sensitive actions require request-level confirmation and a non-public transport.

Optional `WPCONNECTOR_ALLOW_*` constants or environment variables can still disable writes, privileged actions, sensitive actions, system updates or filesystem writes server-side. These are emergency/hosting controls, not normal setup requirements.

## 6. Assets

The zero-config executor preserves request-scoped asset transport:

- maximum 10 assets;
- maximum 25 MiB total;
- safe `assets/inbox/*` paths only;
- a fresh short-lived GitHub OIDC authentication is used for each authenticated asset request and for final execution;
- WordPress cleans request assets after execution.

## 7. Direct REST clients

Non-GitHub clients may still use normal WordPress authentication over HTTPS, including an authenticated WordPress session or Application Password where appropriate. GitHub OIDC only removes persistent credentials from the canonical GitHub runtime.

## 8. Acceptance sequence

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
