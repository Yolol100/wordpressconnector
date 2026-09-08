# WordPress Connector repository instructions

## Scope

- This repository is the canonical live WordPress/Elementor runtime bridge for Webactueel.
- `webactueel-workflow` remains the controller for cross-domain routing and approvals; WordPress Connector owns only the technical WordPress runtime boundary.
- The primary direct route is `approved client -> authenticated HTTPS REST -> WordPress Connector`.
- A guarded GitHub runtime transport is also supported: temporary same-repository request PR -> credential-free validation -> trusted main-branch executor -> authenticated HTTPS REST -> WordPress Connector -> private full result or sanitized public receipt.
- The GitHub transport is only a client/dispatcher. It must not duplicate connector business logic, bypass semantic actions or become a credential store.
- Do not add arbitrary shell, SQL, unrestricted filesystem, eval or HTTP-proxy primitives.

## Before changing files

Read `README.md`, `docs/ARCHITECTURE.md`, `docs/ACTION-CATALOG.md`, `docs/SCOPE.md`, `docs/SECURITY.md`, `docs/SETUP.md`, the CI workflow and both GitHub runtime workflows.

Preserve:

- strict top-level request input validation;
- dry-run and explicit confirmation gates;
- privileged/sensitive/system-update/filesystem-write separation;
- stable request IDs and idempotency;
- `expected_fingerprint` and site-scoped `expected_state_token` stale-state protection in the connector;
- no `expected_state_token` in public GitHub request branches;
- Elementor document API writes instead of direct `_elementor_data` mutation;
- exact readback and rollback for supported mutations;
- secretless validation of request PRs before any production secret is exposed;
- same-repository, trusted-actor and latest-commit-author validation;
- exact PR head-SHA revalidation before execution and before result/receipt writeback;
- public mode limited to the explicit public-safe action contract;
- public mode never persists full connector responses and never logs response bodies;
- sanitized public receipts contain only bounded status/readback evidence and deterministic fingerprints;
- runtime request/result/receipt payloads never merged to `main`.

## Validation

Run the same checks as Connector CI:

```bash
find plugin/wordpressconnector tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/policy-contract.php
php tests/action-catalog.php
php tests/custom-css-contract.php
php tests/elementor-inventory-contract.php
php tests/elementor-forms-contract.php
php tests/elementor-json-export-runtime.php
php tests/elementor-json-import-contract.php
php tests/state-token-contract.php
php tests/plugin-control-contract.php
php tests/plugin-package-contract.php
php tests/connector-update-contract.php
php tests/strict-input-contract.php
php tests/filesystem-control-contract.php
php tests/repository-hygiene.php
php tests/rest-transport-contract.php
php tests/request-workflow-contract.php
php tests/execute-workflow-contract.php
php tests/public-runtime-contract.php
php tests/mutation-lock-contract.php
php tests/single-plugin-contract.php
```

Repository CI is source/static proof only. Live compatibility and mutation safety remain staging-first or require equivalent target-runtime evidence.
