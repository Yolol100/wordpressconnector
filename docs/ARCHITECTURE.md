# Architecture

## Canonical runtime

WordPress Connector remains the single live bridge:

`approved client -> HTTPS REST -> WordPress Connector -> semantic adapter -> WordPress/Elementor -> readback/rollback`

Two approved client routes can reach the same authenticated REST boundary:

1. direct approved client access;
2. private GitHub runtime transport: temporary request PR -> secretless request guard -> trusted main-branch executor -> HTTPS REST -> WordPress Connector -> result committed back to the temporary request branch.

The GitHub route is transport and audit history only. It does not duplicate WordPress logic, does not execute request content as code and does not store WordPress credentials in repository source or request PRs.

REST and optional WP-CLI use the same `Registry`, `Runner`, security policy, idempotency store, mutation lock, stale-state guards and rollback snapshots.

## GitHub trust split

`.github/workflows/wordpress-request.yml` runs on the untrusted request PR without production secrets. It accepts only same-repository PRs from the repository owner or configured trusted actor, requires a private repository, limits request and asset size, and validates exactly one request file using the validator from the trusted base.

`.github/workflows/wordpress-execute.yml` is dispatched from `main`. It revalidates the PR, actor, exact head SHA and allowed paths before production credentials are scoped to the HTTPS transport steps. The executor verifies connector health, uploads bounded request assets, sends the request to `/execute`, verifies the returned `request_id` and writes the result only to the unchanged request branch.

Runtime request, asset and result payloads are temporary branch state and must never be merged into `main`.

## State model

Every request has a stable `request_id`, action and payload. Real mutations are idempotent and serialized by a mutation lock.

Two optional stale-state guards are supported:

- `expected_fingerprint`: SHA-256 over normalized current state;
- `expected_state_token`: HMAC-SHA-256 over that fingerprint using the WordPress auth salt.

A mismatch aborts before the real write. Mutations that expose rollback metadata are stored by request ID and can be restored through `connector.rollback`.

## Elementor

Elementor documents are written through Elementor document APIs. Direct `_elementor_data` mutation is not a supported write path.

WordPress admin import/export is implemented in this plugin. `elementorjson` remains an external QA/evidence runtime, not a second production bridge.

## Repository hygiene

Permanent source may contain the guarded GitHub transport workflows, validator and contract tests. Permanent source must not contain production request/result payloads, site credentials or private runtime state.
