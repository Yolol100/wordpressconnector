# Security model

## Trust boundaries

GitHub PR content is untrusted input. WordPress and connector code installed on the site are trusted execution code. Request JSON and inbox media are data only and are never executed as code.

The default workflow separates untrusted request handling from credentialed execution:

1. the PR-triggered `WordPress Request` workflow validates the request on `ubuntu-latest` **without** WordPress production secrets;
2. after validation it calls GitHub's `workflow_dispatch` API for `wordpress-execute.yml` with `ref=main`;
3. the separately started `WordPress Execute` workflow is therefore loaded from trusted `main`, independently revalidates the PR/head/actor/paths/schema, and only then uses the WordPress Application Password.

No persistent self-hosted runner is required. The request PR may contain only one validated request JSON, optional inbox assets and generated result JSON. Result-only commits do not retrigger execution.

The secretless PR workflow cannot read `WPCONNECTOR_REST_USERNAME` or `WPCONNECTOR_REST_APPLICATION_PASSWORD`; those names exist only in the trusted executor workflow. Do not weaken this boundary by moving production secrets back into a `pull_request` workflow.

## Authentication and authorization

Remote execution requires all of the following:

- private GitHub repository;
- same-repository request branch;
- repository owner or one exact configured trusted request actor;
- latest request commit authored by the repository owner or that same trusted actor;
- trusted executor loaded from `main`;
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
- the trusted executor repeats actor, current-head, changed-path, size and schema validation before credentials are used;
- the PR head is checked again immediately before `/execute` to avoid applying a stale branch state;
- mutations require explicit confirmation;
- stable `request_id` plus a request fingerprint provides idempotency for applied mutations;
- reusing a mutation `request_id` with different content is rejected;
- optional `expected_fingerprint` rejects stale WordPress writes;
- generated rollback snapshots remain available for supported actions;
- a result is pushed only if the request branch still points to the exact executed head SHA;
- result-only commits do not recursively start another execution run.

## Data restrictions

Secret-like option/meta keys are denied. Orders, payments, customer records, medical/patient/intake/prescription submissions and comparable private records are not generic resources unless an explicit sensitive adapter/gate permits the operation.

## Media

REST asset uploads are request-scoped and enforce:

- real HTTP upload provenance with `is_uploaded_file()`;
- WordPress-allowed media extension/MIME allowlisting before temporary storage;
- max 10 files / 25 MiB total and max 20 MiB per file;
- strict relative path segments with no traversal;
- canonical parent/root containment;
- temporary cleanup after execution and opportunistic stale cleanup.

`media.import` then validates the real path under the request asset root and applies WordPress MIME/extension/size checks before attachment creation.

## Operational controls

- keep the repository private;
- leave `WPCONNECTOR_TRUSTED_REQUEST_ACTOR` unset unless a specific automation actor is genuinely required and fully trusted;
- use a dedicated WordPress Application Password and revoke/rotate it if compromise is suspected;
- never commit WordPress credentials to requests, results or source;
- keep write/privileged/sensitive/system-update gates off unless required;
- back up production before broad mutations or system updates;
- keep runtime request PRs short-lived and close without merge after result collection;
- prefer staging for first writes and for broad Elementor/WooCommerce/ACF/media changes.
