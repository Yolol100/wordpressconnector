# Security model

## Trust boundary

The live boundary remains:

`approved client -> HTTPS REST -> WordPress Connector -> semantic action -> WordPress/Elementor`

The optional GitHub runtime is one approved client path. Request PR content is untrusted data. The request workflow receives no WordPress credentials and may only validate same-repository requests from the repository owner or configured trusted actor.

The trusted executor runs from `main`, revalidates the request PR, exact head SHA, actor and changed paths, and only then receives `WPCONNECTOR_REST_USERNAME` and `WPCONNECTOR_REST_APPLICATION_PASSWORD` from GitHub Actions Secrets. Credentials are never written to repository source, request files, results or public receipts.

Every REST request still requires HTTPS, an authenticated WordPress user, `manage_options` and the enabled REST gate. Authentication is not sufficient by itself: semantic actions still pass mutation, privileged, sensitive, system-update and filesystem-write gates.

## Mutation controls

- real mutations require `confirm=true` and the WordPress-side write gate;
- stable `request_id` values make applied mutations idempotent;
- a global mutation lock serializes writes;
- `expected_fingerprint` rejects stale state using deterministic SHA-256 state;
- `expected_state_token` optionally adds the site-scoped HMAC-SHA-256 guard for non-public transports;
- supported mutations return exact readback and rollback metadata;
- `connector.rollback` restores stored rollback snapshots by request ID.

The GitHub transport does not weaken these controls and cannot turn an otherwise blocked semantic action into an allowed one.

## GitHub runtime restrictions

Common restrictions:

- no fork requests;
- repository owner or explicitly configured trusted actor only;
- latest request commit must also be authored by that trusted identity;
- exactly one `requests/*.json` file per runtime PR;
- request JSON maximum 256 KiB;
- at most 10 request assets and 25 MiB total;
- request and asset paths are treated as data, never executable source;
- exact PR head SHA is checked before execution and again before result/receipt writeback;
- runtime PRs do not receive production secrets;
- the trusted executor receives only the REST credentials needed for transport;
- request branches are temporary and are closed without merge.

### Private repositories

Private mode may use the broader connector action catalog and may write the full connector response to `results/*.json` on the temporary branch.

### Public repositories

GitHub Actions history and logs are visible to readers of a public repository. Public mode therefore adds a second fail-closed validator before REST credentials are exposed.

Only these actions are accepted:

- `post.update` on an existing object, without password mutation and without changing status away from `publish`;
- `acf.update` with a positive integer post target only;
- `connector.batch` containing only those two public-safe operations;
- `connector.rollback` for a valid request id.

Public mode also rejects secret-like payload keys and `expected_state_token`. Site-scoped state tokens must never be committed to a public branch. Public stale-state protection uses deterministic `expected_fingerprint` values instead.

The full connector response is kept only inside runner temporary storage. Public branches receive a sanitized `receipts/*.json` file containing only request identity, success/failure category, readback verification and deterministic before/after fingerprints. The receipt builder explicitly omits raw before/after content, state tokens, rollback payloads and raw connector errors.

Trusted public workflows must not print response bodies. HTTP and connector failures are reduced to generic workflow failures plus a bounded error category in the receipt.

## Elementor boundary

Elementor writes use Elementor document APIs. Direct `_elementor_data` writes are not a supported mutation path.

Admin JSON import can replace an explicit existing Page/Post/Saved Template or create a new draft. Existing-target writes are read back and restored automatically if verification fails.

## REST asset uploads

The optional `/assets` endpoint is request-scoped and enforces upload provenance, WordPress MIME/extension allowlisting, safe relative paths, bounded file counts/sizes and cleanup. It exists for semantic media and package imports and is not a generic filesystem upload surface. On a public repository, anything committed under `assets/inbox/` is itself public and must already be safe to disclose.

## Deliberately unsupported primitives

The connector does not provide arbitrary PHP evaluation, shell/process execution, SQL, unrestricted filesystem access, generic HTTP proxying or credential export.

## Operational rules

Start read-only. Keep write/privileged/sensitive/system-update/filesystem-write gates off unless needed. Use staging for first writes and broad Elementor/WooCommerce/ACF/media mutations, or obtain equivalent target-runtime evidence. Never treat green source CI as proof of live WordPress compatibility.
