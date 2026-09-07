# Security model

## Trust boundary

The canonical remote route is:

`approved client / WP Agent -> HTTPS REST -> WordPress Connector -> semantic action -> WordPress/Elementor`

GitHub is source control and CI only. It does not carry production request/result payloads or WordPress credentials.

Every REST request requires HTTPS, an authenticated WordPress user, `manage_options` and the enabled REST gate. Authentication is not sufficient by itself: semantic actions still pass their mutation, privileged, sensitive, system-update and filesystem-write gates.

## Mutation controls

- real mutations require `confirm=true` and the WordPress-side write gate;
- stable `request_id` values make applied mutations idempotent;
- a global mutation lock serializes writes;
- `expected_fingerprint` rejects stale state using a deterministic SHA-256 fingerprint;
- `expected_state_token` optionally adds a site-scoped HMAC-SHA-256 guard derived from that fingerprint and the WordPress auth salt;
- supported mutations return exact readback and rollback metadata;
- `connector.rollback` restores stored rollback snapshots by request ID.

## Elementor boundary

Elementor writes use Elementor document APIs. Direct `_elementor_data` writes are not a supported mutation path.

Admin JSON import can replace an explicit existing Page/Post/Saved Template or create a new draft. Existing-target writes are read back and restored automatically if verification fails.

Single JSON export requires `edit_post` and a document-specific WordPress nonce. Saved Templates keep Elementor's native export when available. Optional Theme Builder site-parts export resolves the active header/footer through Elementor Pro's condition manager.

Bulk Elementor JSON export uses the WordPress bulk-action nonce and checks `edit_post` for every selected item before export. Non-Elementor or unauthorized items are skipped and recorded in `manifest.json`. A request fails closed above 100 selected items or 50 MB of generated JSON, before a partial ZIP is sent.

## REST asset uploads

The optional `/assets` endpoint is request-scoped and enforces upload provenance, WordPress MIME/extension allowlisting, safe relative paths, bounded file counts/sizes and cleanup. It exists for semantic media imports and is not a generic filesystem upload surface.

## Deliberately unsupported primitives

The connector does not provide arbitrary PHP evaluation, shell/process execution, SQL, unrestricted filesystem access, generic HTTP proxying or credential export.

## Operational rules

Start read-only. Keep write/privileged/sensitive/system-update/filesystem-write gates off unless needed. Use staging for first writes, Elementor/WooCommerce/ACF mutations and rollback tests. Never treat a green source CI run as proof of target-site compatibility.
