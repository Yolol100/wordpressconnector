# Security model

## Trust boundaries

GitHub PR content is untrusted input. The WordPress host and connector code from the trusted base commit are trusted execution code. Request JSON and inbox media are data only and are never executed as code.

The workflow uses a two-stage design:

1. a GitHub-hosted `guard` job validates the runtime request;
2. only a successful guard can schedule the persistent self-hosted WordPress runner.

The self-hosted job never checks out or executes connector code from the request PR head. It obtains executable connector code from the trusted PR base SHA and extracts only the validated request/media paths from the request head.

## Action gates

Every registered action carries explicit metadata:

- `mutation` — changes state;
- `privileged` — administrative or broadly scoped data;
- `sensitive` — may expose/change sensitive information;
- `system_update` — installs/updates/deletes WordPress software.

Mutations require `confirm=true` and `WPCONNECTOR_ALLOW_WRITES`. Privileged, sensitive and system-update actions additionally require their own flags. Public-repository mode blocks privileged/sensitive actions.

## Deliberately unsupported primitives

The connector does not provide arbitrary:

- PHP evaluation;
- shell/process execution;
- SQL queries;
- filesystem paths/writes;
- HTTP proxying;
- secret export.

Provider-owned data is changed through WordPress/WooCommerce/ACF/Elementor semantics instead of blind database writes.

## Data restrictions

Secret-like option/meta keys are denied. Orders, payments, customer records, medical/patient/intake/prescription submissions and comparable private records are not generic public-mode resources. Add any future business-specific sensitive capability as a named adapter with explicit `sensitive`/`privileged` metadata and private-repository runtime acceptance.

## Media

`media.import` can only read a real path below the workflow-provided asset root. Paths are canonicalized, sibling-prefix escapes and symlinks are rejected, and WordPress validates extension/MIME/size before attachment creation.

## Operational controls

- prefer a private repository;
- use a dedicated non-root runner account;
- do not expose the runner to untrusted organizations/forks;
- keep all write flags off by default;
- back up production before broad mutations/system updates;
- keep request PRs short-lived and close without merge after result collection;
- rotate/remove the runner if compromise is suspected.
