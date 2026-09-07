# Security model

## Trust boundaries

WP Agent transports authenticated requests to WordPress. WordPress and the installed Connector are the trusted execution environment. Request payloads and uploaded media are data only and are never executed as code.

GitHub is outside the production request path. Repository workflows may lint, test and package source, but they must not hold or relay live WordPress execution requests.

## Authentication and authorization

Connector REST execution requires:

- canonical HTTPS;
- an authenticated WordPress user;
- `manage_options`;
- enabled Connector REST transport;
- the relevant WordPress-side action gates.

Use a dedicated WordPress Application Password/service account for WP Agent or another approved REST client where practical. Authentication does not replace action policy: every semantic action still passes the connector's mutation, privileged, sensitive, system-update and filesystem-write gates.

## Action gates

Every registered action carries explicit security metadata:

- `mutation` — changes state;
- `privileged` — administrative or broadly scoped data;
- `sensitive` — may expose or change sensitive information;
- `system_update` — installs, updates or deletes WordPress software.

Mutations require `confirm=true` and the WordPress-side write gate. Privileged, sensitive and system-update operations additionally require their own gates. Filesystem replacement has a separate filesystem-write gate.

## Deliberately unsupported primitives

The connector does not provide arbitrary:

- PHP evaluation;
- shell/process execution;
- SQL queries;
- unrestricted filesystem writes;
- generic HTTP proxying;
- secret export.

Provider-owned data is changed through WordPress, WooCommerce, ACF, Elementor or other named semantic APIs instead of blind database writes.

## Request and replay controls

- REST request JSON is capped at 256 KiB;
- mutations require explicit confirmation;
- stable `request_id` plus normalized fingerprints provide idempotency;
- reusing a mutation request ID with different content is rejected;
- optional expected fingerprints reject stale writes;
- supported mutations can create rollback snapshots;
- batches compensate completed operations in reverse order when supported;
- REST readback is the runtime evidence source, not a GitHub workflow result.

## Data restrictions

Secret-like option/meta keys are denied. Orders, payments, customer records, medical/patient/intake/prescription submissions and comparable private records are not generic resources unless an explicit sensitive adapter and gate permit the operation.

## Media

REST asset uploads are request-scoped and enforce real HTTP upload provenance, WordPress-allowed media types, path containment and bounded file/total sizes. Temporary request assets are cleaned after execution or by later cleanup.

`media.import` performs its own path, MIME, extension and size validation before attachment creation.

## Filesystem safety

`filesystem.write_text` is deliberately narrow. It can replace existing UTF-8 text files only in permitted plugin/theme locations, requires the normal write gate plus the dedicated filesystem-write gate, requires `confirm=true` and a matching expected SHA-256, validates supported PHP/JSON content, verifies the resulting bytes and stores rollback data.

The connector cannot expose arbitrary server paths, rewrite WordPress core or modify its own installed runtime through this action.

## Operational controls

- keep write, privileged, sensitive, system-update and filesystem-write gates off unless required;
- revoke/rotate the WordPress Application Password if compromise is suspected;
- never store WordPress credentials in repository source, logs or fixtures;
- back up production before broad mutations or system updates;
- prefer staging for first writes and broad Elementor/WooCommerce/ACF/media/filesystem changes;
- use the smallest capability: WP Agent native first, native WordPress/plugin Ability second, Connector advanced action only where it adds necessary functionality or safety.
