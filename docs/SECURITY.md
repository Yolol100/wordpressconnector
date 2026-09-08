# Security model

## Trust boundary

The live boundary remains:

`approved client -> HTTPS REST -> WordPress Connector -> semantic action -> WordPress/Elementor`

The optional private GitHub runtime is one approved client path. Request PR content is untrusted data. The request workflow receives no WordPress credentials and may only validate same-repository requests from the repository owner or configured trusted actor. It requires a private repository before dispatching the trusted executor.

The trusted executor runs from `main`, revalidates the request PR, exact head SHA, actor and changed paths, and only then receives `WPCONNECTOR_REST_USERNAME` and `WPCONNECTOR_REST_APPLICATION_PASSWORD` from GitHub Actions Secrets. Credentials are never written to repository source, request files or results.

Every REST request still requires HTTPS, an authenticated WordPress user, `manage_options` and the enabled REST gate. Authentication is not sufficient by itself: semantic actions still pass mutation, privileged, sensitive, system-update and filesystem-write gates.

## Mutation controls

- real mutations require `confirm=true` and the WordPress-side write gate;
- stable `request_id` values make applied mutations idempotent;
- a global mutation lock serializes writes;
- `expected_fingerprint` rejects stale state using deterministic SHA-256 state;
- `expected_state_token` optionally adds the site-scoped HMAC-SHA-256 guard;
- supported mutations return exact readback and rollback metadata;
- `connector.rollback` restores stored rollback snapshots by request ID.

The GitHub transport does not weaken these controls and cannot turn an otherwise blocked semantic action into an allowed one.

## GitHub runtime restrictions

- private repositories only;
- no fork requests;
- repository owner or explicitly configured trusted actor only;
- exactly one `requests/*.json` file per runtime PR;
- request JSON maximum 256 KiB;
- at most 10 request assets and 25 MiB total;
- request and asset paths are treated as data, never executable source;
- exact PR head SHA is checked before execution and again before result writeback;
- request/result branches are temporary and are closed without merge;
- runtime PRs do not receive production secrets;
- the trusted executor receives only the REST credentials needed for transport.

## Elementor boundary

Elementor writes use Elementor document APIs. Direct `_elementor_data` writes are not a supported mutation path.

Admin JSON import can replace an explicit existing Page/Post/Saved Template or create a new draft. Existing-target writes are read back and restored automatically if verification fails.

## REST asset uploads

The optional `/assets` endpoint is request-scoped and enforces upload provenance, WordPress MIME/extension allowlisting, safe relative paths, bounded file counts/sizes and cleanup. It exists for semantic media and package imports and is not a generic filesystem upload surface.

## Deliberately unsupported primitives

The connector does not provide arbitrary PHP evaluation, shell/process execution, SQL, unrestricted filesystem access, generic HTTP proxying or credential export.

## Operational rules

Start read-only. Keep write/privileged/sensitive/system-update/filesystem-write gates off unless needed. Use staging for first writes and broad Elementor/WooCommerce/ACF/media mutations, or obtain equivalent target-runtime evidence. Never treat green source CI as proof of live WordPress compatibility.
