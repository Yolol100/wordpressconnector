# Security model

## Trust boundary

WP Agent is the remote transport. WordPress Connector is the advanced execution layer on the site.

The connector does not trust request content merely because it came through WP Agent. WordPress still enforces HTTPS, authentication, `manage_options` and connector action gates.

## Action gates

Every registered action declares security metadata:
- `mutation`
- `privileged`
- `sensitive`
- `system_update`

Mutations require `confirm=true` and the write gate. Privileged, sensitive, system-update and filesystem-write operations additionally require their specific gates.

## Replay and stale-state protection

- stable `request_id` provides idempotency;
- reusing a mutation request ID with different content is rejected;
- optional `expected_fingerprint` rejects stale writes;
- supported actions store rollback snapshots;
- batch operations compensate completed operations when a later operation fails.

## REST restrictions

Connector REST requires:
- HTTPS;
- an authenticated WordPress user;
- `manage_options`;
- REST transport enabled in WordPress Connector settings.

The connector does not expose arbitrary PHP, shell/process execution, SQL, unrestricted filesystem operations, generic HTTP proxying or secret export.

## Filesystem

Raw filesystem access is deliberately narrow. Secret paths, WordPress core writes, uploads, backups, caches and the connector's own runtime are excluded from generic writes. Real file writes require the dedicated filesystem-write gate, confirmation, checksum protection, parser validation, readback and rollback.

## Assets

The `/assets` route remains bounded and request-scoped for advanced media-import workflows. It enforces WordPress-allowed media types, safe relative paths, size limits and cleanup.

## Operational rule

Keep privileged, sensitive, system-update and filesystem-write gates off unless the current approved task requires them. Use staging for broad or high-impact changes and verify readback after every real mutation.
