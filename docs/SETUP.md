# Setup

## 1. Install

Install and activate `plugin/wordpressconnector` on the target WordPress site.

The plugin registers:

- authenticated HTTPS REST routes under `/wp-json/webactueel-wordpress-connector/v1/`;
- the semantic action registry;
- `Settings -> WordPress Connector` security gates;
- optional `wp wordpress-connector ...` WP-CLI commands.

## 2. Direct connection

Any approved client may authenticate to the connector over HTTPS. REST requires HTTPS, an authenticated WordPress administrator and the configured connector gates.

## 3. Optional private GitHub runtime

The GitHub runtime uses GitHub-hosted runners and the same WordPress Connector REST endpoints. No self-hosted runner, VPS daemon or SSH transport is required.

Requirements:

1. The repository must be **private**. The workflows fail closed on a public repository.
2. Set repository variable `WPCONNECTOR_SITE_URL` to the HTTPS WordPress site URL, for example `https://andrewbaeten.nl`.
3. Add repository secret `WPCONNECTOR_REST_USERNAME` for a dedicated WordPress administrator used by the connector transport.
4. Create a WordPress Application Password for that dedicated account and save it only as GitHub Actions secret `WPCONNECTOR_REST_APPLICATION_PASSWORD`.
5. Optionally set `WPCONNECTOR_TRUSTED_REQUEST_ACTOR` when a second explicitly trusted GitHub account may submit runtime requests. If omitted, the repository owner is the allowed actor.
6. Keep the WordPress Connector REST gate enabled. Enable only the semantic write gates required for the requested operation.

Do not paste the Application Password into request JSON, repository files, issues or chat messages.

## 4. Runtime request flow

Create a short-lived branch from `main`, add exactly one file under `requests/`, and open a pull request to `main`. Optional request assets belong under `assets/inbox/`.

A request JSON uses the same connector contract as REST:

```json
{
  "version": 1,
  "request_id": "portfolio-read-001",
  "action": "post.get",
  "dry_run": true,
  "confirm": false,
  "payload": {
    "id": 123
  }
}
```

For stale-state protection, `expected_fingerprint` and `expected_state_token` may be supplied when returned by the corresponding read or dry-run.

The request workflow validates the PR without production credentials. A trusted `main` workflow then revalidates the exact PR head, checks connector health, uploads bounded assets, calls `/execute`, verifies the returned request ID and commits `results/<request_id>.json` back to the unchanged request branch.

After reading the result, close the runtime PR **without merging it**. Runtime request, asset and result payloads do not belong on `main`.

## 5. Security defaults

Keep real writes, privileged actions, sensitive actions, system updates and filesystem writes disabled until required. Real mutations still require `confirm:true`; GitHub transport does not bypass WordPress-side gates.

## 6. Acceptance sequence

1. Verify `/health` through the intended client route.
2. Run `connector.discover` and `system.doctor`.
3. Read one normal page/post.
4. Read representative ACF, WooCommerce, Yoast and media data where applicable.
5. Run a dry-run for the intended mutation.
6. On staging or another disposable target, perform a write and rollback.
7. Verify exact readback and stale-state protection.
8. Only then use the minimum production gates required for the workflow.

## 7. Elementor JSON admin flow

From Pages, Posts or Saved Templates, use **Import Elementor JSON**. For Elementor-built Pages and Posts use **Export Elementor JSON**; when Theme Builder is available, **Export Elementor + Site Parts** adds the active header/footer when safely resolvable.

## 8. Optional WP-CLI

WP-CLI remains a local maintenance/recovery transport. It is not required for the direct REST route or the GitHub-hosted runtime.
