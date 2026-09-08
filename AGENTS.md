# WordPress Connector repository instructions

## Scope

- This repository is the canonical live WordPress/Elementor runtime bridge for Webactueel.
- `webactueel-workflow` remains the controller for cross-domain routing and approvals; WordPress Connector owns only the technical WordPress runtime boundary.
- The primary direct route is `approved client -> authenticated HTTPS REST -> WordPress Connector`.
- A private-only GitHub runtime transport is also supported: temporary same-repository request PR -> credential-free validation -> trusted main-branch executor -> authenticated HTTPS REST -> WordPress Connector -> result on the temporary request branch.
- The GitHub transport is only a client/dispatcher. It must not duplicate connector business logic, bypass semantic actions or become a credential store.
- Do not add arbitrary shell, SQL, unrestricted filesystem, eval or HTTP-proxy primitives.

## Before changing files

Read `README.md`, `docs/ARCHITECTURE.md`, `docs/ACTION-CATALOG.md`, `docs/SCOPE.md`, `docs/SECURITY.md`, `docs/SETUP.md`, the CI workflow and both GitHub runtime workflows.

Preserve:

- dry-run and explicit confirmation gates;
- privileged/sensitive/system-update/filesystem-write separation;
- stable request IDs and idempotency;
- `expected_fingerprint` and site-scoped `expected_state_token` stale-state protection;
- Elementor document API writes instead of direct `_elementor_data` mutation;
- exact readback and rollback for supported mutations;
- private-repository enforcement for GitHub runtime execution;
- secretless validation of request PRs before any production secret is exposed;
- same-repository and trusted-actor validation;
- exact PR head-SHA revalidation before execution and before result writeback;
- runtime request/result payloads never merged to `main`.

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
php tests/filesystem-control-contract.php
php tests/repository-hygiene.php
php tests/rest-transport-contract.php
php tests/request-workflow-contract.php
php tests/execute-workflow-contract.php
php tests/mutation-lock-contract.php
php tests/single-plugin-contract.php
```

Repository CI is source/static proof only. Live compatibility and mutation safety remain staging-first or require equivalent target-runtime evidence.
