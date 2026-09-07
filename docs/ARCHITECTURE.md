# Architecture

## Canonical runtime

`ChatGPT / WP Agent -> HTTPS REST -> WordPress Connector -> semantic adapter -> WordPress/Elementor -> readback/rollback`

WordPress Connector owns the live bridge. GitHub is source control and CI, not a request/result transport layer.

REST and optional WP-CLI use the same `Registry`, `Runner`, security policy, idempotency store, mutation lock, stale-state guards and rollback snapshots.

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

Permanent source must not contain runtime request/result payloads. The retired GitHub request workflows, request/result schemas and example request transport are forbidden by repository-hygiene tests.
