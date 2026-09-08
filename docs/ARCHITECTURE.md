# Architecture

## Canonical runtime

WordPress Connector remains the single live bridge:

`approved client -> HTTPS REST -> WordPress Connector -> semantic adapter -> WordPress/Elementor -> readback/rollback`

Two approved client routes can reach the same authenticated REST boundary:

1. direct approved client access;
2. guarded GitHub runtime transport: temporary request PR -> secretless request guard -> trusted main-branch executor -> HTTPS REST -> WordPress Connector -> private full result or sanitized public receipt.

The GitHub route is transport and audit history only. It does not duplicate WordPress logic, does not execute request content as code and does not store WordPress credentials in repository source or request PRs.

REST and optional WP-CLI use the same `Registry`, `Runner`, security policy, idempotency store, mutation lock, stale-state guards and rollback snapshots.

## GitHub trust split

`.github/workflows/wordpress-request.yml` runs on the request PR without production secrets. It accepts only same-repository PRs from the repository owner or configured trusted actor, limits request and asset size, and validates exactly one request file using trusted source from the base revision. Public repositories run an additional reduced-action validator at this stage.

`.github/workflows/wordpress-execute.yml` is dispatched from `main`. It revalidates the PR, actor, latest commit author, exact head SHA and allowed paths before production credentials are scoped to HTTPS transport steps. The executor verifies connector health, uploads bounded request assets, sends the request to `/execute` and verifies the returned request identity.

Private repositories may write the full connector result to `results/*.json` on the unchanged temporary branch.

Public repositories never persist the full connector response. The trusted executor runs `scripts/build-public-receipt.php` inside temporary runner storage and commits only `receipts/*.json`. Those receipts contain bounded status/readback evidence and deterministic fingerprints; connector-update receipts may also contain safe semantic versions and a verified package SHA-256. Raw WordPress content, health gates/user ids, state tokens, rollback payloads and raw errors are not persisted.

Runtime request, asset, result and receipt payloads are temporary branch state and must never be merged into `main`.

## Public action boundary

Public mode deliberately does not expose the complete connector action catalog. Its transport allowlist currently contains:

- `post.update`;
- post-targeted `acf.update`;
- `connector.batch` containing only those two content actions;
- `connector.rollback`;
- `connector.update.check` with an empty payload;
- `connector.update.apply` with an empty payload, dry-run-first fingerprint protection and confirmation for a real update.

The public self-update is a special narrow exception, not a general privileged-action bridge. Only the two canonical connector update actions carry `public_repository_safe`; the WordPress privileged gate remains required, and a real apply also requires the write and system-update gates. Sensitive actions remain blocked in public-repository mode.

The self-update request cannot supply package bytes, a package URL, release URL or requested version. The WordPress adapter resolves only the canonical `Yolol100/wordpressconnector` release, verifies the checksum and ZIP/plugin identity, and performs exact installed-version readback. `plugin.install_package` remains excluded from public mode because custom/private ZIP bytes must never pass through a public request branch.

Public request validation also blocks secret-like keys, string ACF targets such as options/users/terms, non-publish status changes and `expected_state_token` values. Public stale-state control uses `expected_fingerprint` values derived from sanitized receipts.

## Bootstrap boundary

A live connector that predates `connector.update.apply` cannot invoke that action. Generic filesystem access and `plugin.install_package` deliberately block connector self-replacement, so older runtimes require one manual package bootstrap to a release containing the self-updater. This is an intentional trust boundary rather than a missing remote-code path.

## State model

Every request has a stable `request_id`, action and payload. Real mutations are idempotent and serialized by a mutation lock.

Two optional stale-state guards are supported by the connector:

- `expected_fingerprint`: SHA-256 over normalized current state;
- `expected_state_token`: HMAC-SHA-256 over that fingerprint using the WordPress auth salt.

A mismatch aborts before the real write. Mutations that expose rollback metadata are stored by request ID and can be restored through `connector.rollback`.

Public GitHub receipts expose only deterministic before/after fingerprints. They do not publish site-scoped HMAC state tokens.

## Elementor

Elementor documents are written through Elementor document APIs. Direct `_elementor_data` mutation is not a supported write path.

WordPress admin import/export is implemented in this plugin. `elementorjson` remains an external QA/evidence runtime, not a second production bridge.

## Repository hygiene

Permanent source may contain the guarded GitHub transport workflows, validators, receipt builder and contract tests. Permanent source must not contain production request/result/receipt payloads, site credentials, private plugin packages or private runtime state.
