# Security model

## Trust boundaries

GitHub PR content is untrusted input. WordPress and connector code installed on the site are trusted execution code. Request JSON and inbox media are data only and are never executed as code.

The default workflow uses two GitHub-hosted stages:

1. `guard` validates the runtime request without WordPress credentials;
2. after the guard succeeds, GitHub provisions a fresh `ubuntu-latest` VM for `execute`, which authenticates to the installed WordPress Connector over HTTPS.

No persistent self-hosted runner is required. The request PR may contain only one validated request JSON, optional inbox assets and generated result JSON. Result-only commits do not retrigger execution.

## Authentication and authorization

Remote execution requires all of the following:

- private GitHub repository;
- same-repository request branch;
- repository owner or one exact configured trusted request actor;
- canonical `https://` WordPress site URL;
- dedicated WordPress Application Password stored in GitHub Actions Secrets;
- authenticated WordPress user;
- `manage_options` capability;
- enabled REST transport in `Settings -> WordPress Connector`.

Authentication does not replace action policy. A valid administrator credential can call the transport, but the semantic action still passes the connector's mutation/privileged/sensitive/system-update gates.

## Action gates

Every registered action carries explicit metadata:

- `mutation` — changes state;
- `privileged` — administrative or broadly scoped data;
- `sensitive` — may expose/change sensitive information;
- `system_update` — installs/updates/deletes WordPress software.

Mutations require `confirm=true` and the WordPress-side write gate. Privileged, sensitive and system-update actions additionally require their own gates. Constants/environment values can still override the WordPress options for managed hosting or local WP-CLI operation.

## Deliberately unsupported primitives

The connector does not provide arbitrary:

- PHP evaluation;
- shell/process execution;
- SQL queries;
- filesystem paths/writes;
- HTTP proxying;
- secret export.

Provider-owned data is changed through WordPress/WooCommerce/ACF/Elementor semantics instead of blind database writes.

## Request and replay controls

- request JSON is schema-validated and capped at 256 KiB;
- mutations require explicit confirmation;
- stable `request_id` plus a request fingerprint provides idempotency for applied mutations;
- reusing a mutation `request_id` with different content is rejected;
- optional `expected_fingerprint` rejects stale writes;
- generated rollback snapshots remain available for supported actions;
- result-only commits do not recursively start another execution run.

## Data restrictions

Secret-like option/meta keys are denied. Orders, payments, customer records, medical/patient/intake/prescription submissions and comparable private records are not generic resources unless an explicit sensitive adapter/gate permits the operation.

## Media

REST asset uploads are request-scoped and enforce:

- real HTTP upload provenance with `is_uploaded_file()`;
- max 10 files / 25 MiB total and max 20 MiB per file;
- strict relative path segments with no traversal;
- canonical parent/root containment;
- temporary cleanup after execution and opportunistic stale cleanup.

`media.import` then validates the real path under the request asset root and applies WordPress MIME/extension/size checks before attachment creation.

## Operational controls

- keep the repository private;
- use a dedicated WordPress Application Password and revoke/rotate it if compromise is suspected;
- never commit WordPress credentials to requests, results or source;
- keep write/privileged/sensitive/system-update gates off unless required;
- back up production before broad mutations or system updates;
- keep runtime request PRs short-lived and close without merge after result collection;
- prefer staging for first writes and for broad Elementor/WooCommerce/ACF/media changes.
