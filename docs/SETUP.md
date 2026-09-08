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
- `connector.batch` with 1-25 operations composed only of the two content actions above;
- `connector.rollback` for a known request id;
- `connector.update.check` with an empty payload;
- `connector.update.apply` with an empty payload, using dry-run first and a fingerprint-guarded confirmed request for a real update.

Public validation rejects secret-like payload keys and `expected_state_token`. Do not place confidential copy, credentials, private customer/order/patient information, private plugin ZIPs or unpublished material in a public request branch. Use `expected_fingerprint` for stale-state protection.

The two canonical self-update actions are the only privileged actions marked `public_repository_safe`. This does not bypass WordPress-side gates: the privileged gate remains required, and a real connector update also requires the write and system-update gates.

The full WordPress response is never committed in public mode. GitHub writes only `receipts/<request_id>.json` with minimized safe evidence. Public receipts may contain `connector_version`, content fingerprints/readback status, or connector self-update version/checksum evidence. Raw before/after content, health gates, user ids, state tokens, rollback payloads and raw connector errors remain inside the temporary runner and are deleted after the job.

`plugin.install_package` is not available in public GitHub runtime. Custom/private plugin ZIPs must use direct authenticated REST or a private repository transport so their bytes are never published in a public request branch.

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

## 6. Public connector self-update sequence

This sequence is available only when the installed connector already contains `connector.update.apply` (1.12.0+; public GitHub support requires 1.12.2+).

1. Enable the WordPress write, privileged and system-update gates only for the update window.
2. Submit `connector.update.apply` with `dry_run:true`, `confirm:false` and `{}` as payload.
3. Require `update_plan_verified:true` in the sanitized receipt and record `before_fingerprint`.
4. Submit a second `connector.update.apply` with `dry_run:false`, `confirm:true`, the same empty payload and the dry-run fingerprint as `expected_fingerprint`.
5. Require `readback_verified:true`; verify `to_version` and `package_sha256` in the receipt.
6. On a later request, verify `connector_version` reports the expected installed version.
7. Disable privileged/system-update gates again when they are no longer required.

The request cannot supply a release URL, package URL, archive path or version override. WordPress resolves the canonical `Yolol100/wordpressconnector` release and verifies its checksum and plugin identity itself.

### Bootstrap for older installations

An installation that does not contain `connector.update.apply` cannot call a capability it does not have. The generic filesystem and package actions also deliberately refuse connector self-replacement. Therefore an older installation such as 1.10.0 needs one manual WordPress Upload Plugin replacement with a current verified release. After that one bootstrap, future connector releases can use the self-update sequence above.

## 7. Security defaults

Keep real writes, privileged actions, sensitive actions, system updates and filesystem writes disabled until required. Real mutations still require `confirm:true`; GitHub transport does not bypass WordPress-side gates.

## 8. Acceptance sequence

1. Verify `/health` through the intended client route and record the connector version.
2. For private/direct clients, run `connector.discover` and `system.doctor` as needed.
3. For public GitHub mode, submit a harmless `post.update` or `acf.update` dry-run against already-public content.
4. Verify a sanitized receipt is written and no full `results/*.json` file appears.
5. Confirm the receipt contains fingerprints but no before/after content or state token.
6. Perform one confirmed public content write with `expected_fingerprint` when production authorization and rollback are available.
7. Verify `readback_verified:true` and check the public WordPress page independently.
8. Test `connector.rollback` on disposable content before relying on it for production recovery.
9. After the first manual bootstrap to 1.12.2+, test `connector.update.apply` as a dry-run before relying on public GitHub self-update for future releases.

## 9. Elementor JSON admin flow

From Pages, Posts or Saved Templates, use **Import Elementor JSON**. For Elementor-built Pages and Posts use **Export Elementor JSON**; when Theme Builder is available, **Export Elementor + Site Parts** adds the active header/footer when safely resolvable.

## 10. Optional WP-CLI

WP-CLI remains a local maintenance/recovery transport. It is not required for the direct REST route or the GitHub-hosted runtime.
