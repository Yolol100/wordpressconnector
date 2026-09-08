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

## 3. Optional GitHub runtime

The GitHub runtime uses GitHub-hosted runners and the same WordPress Connector REST endpoints. No self-hosted runner, VPS daemon or SSH transport is required.

Requirements:

1. Set repository variable `WPCONNECTOR_SITE_URL` to the HTTPS WordPress site URL.
2. Add repository secret `WPCONNECTOR_REST_USERNAME` for a dedicated WordPress administrator used by the connector transport.
3. Create a WordPress Application Password for that dedicated account and save it only as GitHub Actions secret `WPCONNECTOR_REST_APPLICATION_PASSWORD`.
4. Optionally set `WPCONNECTOR_TRUSTED_REQUEST_ACTOR` when a second explicitly trusted GitHub account may submit runtime requests. If omitted, the repository owner is the allowed actor.
5. Keep the WordPress Connector REST gate enabled. Enable only the semantic write gates required for the requested operation.

Do not paste the Application Password into request JSON, repository files, issues or chat messages.

### Private repository behavior

Private repositories may use the broader connector action catalog. The full connector response may be committed to `results/<request_id>.json` on the temporary request branch.

### Public repository behavior

Public repositories use a reduced public-safe contract because request branches and Actions history are visible to repository readers.

The public allowlist is:

- `post.update` for an existing post/page/CPT; if `status` is supplied it must remain `publish`;
- `acf.update` only when the ACF target is a positive integer post id;
- `connector.batch` with 1-25 operations composed only of the two actions above;
- `connector.rollback` for a known request id.

Public validation rejects secret-like payload keys and `expected_state_token`. Do not place confidential copy, credentials, private customer/order/patient information or unpublished material in a public request branch. Use `expected_fingerprint` for stale-state protection.

The full WordPress response is never committed in public mode. GitHub writes only `receipts/<request_id>.json` with safe execution metadata, readback status and deterministic fingerprints. Raw before/after content, state tokens, rollback payloads and raw connector errors remain inside the temporary runner and are deleted after the job.

## 4. Runtime request flow

Create a short-lived branch from `main`, add exactly one file under `requests/`, and open a pull request to `main`. Optional assets belong under `assets/inbox/`; remember that assets on a public repository branch are public.

Example public-safe dry-run for an existing post:

```json
{
  "version": 1,
  "request_id": "portfolio-update-001-dry",
  "action": "post.update",
  "dry_run": true,
  "confirm": false,
  "payload": {
    "id": 123,
    "excerpt": "Short public portfolio summary."
  }
}
```

Example public-safe ACF write using a fingerprint returned by a prior public receipt:

```json
{
  "version": 1,
  "request_id": "portfolio-update-001-write",
  "action": "acf.update",
  "dry_run": false,
  "confirm": true,
  "expected_fingerprint": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "payload": {
    "post_id": 123,
    "fields": {
      "description_1": "Public portfolio problem text.",
      "description_2": "Public portfolio solution text."
    }
  }
}
```

The request workflow validates the PR without production credentials. A trusted `main` workflow then revalidates the exact PR head, checks connector health, uploads bounded assets, calls `/execute` and verifies the returned request identity.

In private mode it writes a full result. In public mode it builds and writes only a sanitized receipt. After reading the result or receipt, close the runtime PR **without merging it**. Runtime request, asset, result and receipt payloads do not belong on `main`.

## 5. Public stale-state sequence

For a public content mutation:

1. submit a `dry_run:true` request;
2. read `before_fingerprint` from the sanitized receipt;
3. submit the confirmed request with that value as `expected_fingerprint`;
4. require `readback_verified:true` in the write receipt;
5. retain the request id while rollback may still be needed.

For `connector.batch`, receipts provide per-operation before/after fingerprints. Put the relevant `expected_fingerprint` on each nested operation in the confirmed batch.

## 6. Security defaults

Keep real writes, privileged actions, sensitive actions, system updates and filesystem writes disabled until required. Real mutations still require `confirm:true`; GitHub transport does not bypass WordPress-side gates.

## 7. Acceptance sequence

1. Verify `/health` through the intended client route.
2. For private/direct clients, run `connector.discover` and `system.doctor` as needed.
3. For public GitHub mode, submit a harmless `post.update` or `acf.update` dry-run against already-public content.
4. Verify a sanitized receipt is written and no full `results/*.json` file appears.
5. Confirm the receipt contains fingerprints but no before/after content or state token.
6. Perform one confirmed public content write with `expected_fingerprint`.
7. Verify `readback_verified:true` and check the public WordPress page independently.
8. Test `connector.rollback` on disposable content before relying on it for production recovery.

## 8. Elementor JSON admin flow

From Pages, Posts or Saved Templates, use **Import Elementor JSON**. For Elementor-built Pages and Posts use **Export Elementor JSON**; when Theme Builder is available, **Export Elementor + Site Parts** adds the active header/footer when safely resolvable.

## 9. Optional WP-CLI

WP-CLI remains a local maintenance/recovery transport. It is not required for the direct REST route or the GitHub-hosted runtime.
