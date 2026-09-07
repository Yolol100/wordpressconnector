# WordPress Connector repository instructions

## Scope

- This repository is the canonical live WordPress/Elementor runtime bridge for Webactueel.
- `webactueel-workflow` remains the controller for cross-domain routing and approvals; WordPress Connector owns only the technical WordPress runtime boundary.
- The default remote route is `ChatGPT / WP Agent -> authenticated HTTPS REST -> WordPress Connector`.
- GitHub is source control and CI only. Do not reintroduce request/result branches, schemas or execution workflows.
- Do not add arbitrary shell, SQL, unrestricted filesystem, eval or HTTP-proxy primitives.

## Before changing files

Read `README.md`, `docs/ARCHITECTURE.md`, `docs/ACTION-CATALOG.md`, `docs/SCOPE.md`, `docs/SECURITY.md` and the single CI workflow.

Preserve:

- dry-run and explicit confirmation gates;
- privileged/sensitive/system-update/filesystem-write separation;
- stable request IDs and idempotency;
- `expected_fingerprint` and site-scoped `expected_state_token` stale-state protection;
- Elementor document API writes instead of direct `_elementor_data` mutation;
- exact readback and rollback for supported mutations.

## Validation

Run the same checks as Connector CI:

```bash
find plugin/wordpressconnector tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/policy-contract.php
php tests/action-catalog.php
php tests/elementor-inventory-contract.php
php tests/elementor-forms-contract.php
php tests/elementor-json-export-runtime.php
php tests/elementor-json-import-contract.php
php tests/state-token-contract.php
php tests/request-input-boundaries.php
php tests/plugin-control-contract.php
php tests/filesystem-control-contract.php
php tests/repository-hygiene.php
php tests/rest-transport-contract.php
php tests/mutation-lock-contract.php
php tests/single-plugin-contract.php
```

Repository CI is source/static proof only. Live compatibility and mutation safety remain staging-first.
