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
- sensitive actions always blocked in public-repository mode;
- only the canonical connector self-update actions may use the narrow `public_repository_safe` privileged exception;
- public connector self-update accepts no caller-supplied package URL/version/archive and still requires normal WordPress capability, privileged, write and system-update gates plus dry-run/fingerprint confirmation;
- `plugin.install_package` and private/custom plugin ZIP bytes never routed through public request branches;
- public mode never persists full connector responses and never logs response bodies;
- sanitized public receipts contain only bounded status/readback evidence, deterministic fingerprints and explicitly safe connector version/checksum evidence;
- runtime request/result/receipt payloads never merged to `main`;
- every third-party GitHub Action pinned to a full commit SHA;
- release ZIP and SPDX SBOM built deterministically, reproducibly and attested against exact release bytes;
- plugin-declared PHP support covered by CI rather than assumed;
- uninstall removes plugin-owned settings and temporary runtime state;
- temporary migration or self-writing workflows removed immediately after their one-time purpose is complete.

## Validation

Run the same canonical contract runner as Connector CI:

```bash
bash scripts/run-contracts.sh
```

Connector CI additionally verifies the supported PHP matrix, deterministic package/SBOM reproducibility, supply-chain policy and pull-request dependency changes. Repository CI is source/static proof only. Live compatibility and mutation safety remain staging-first or require equivalent target-runtime evidence.

A connector version that predates a capability cannot safely bootstrap itself through that missing capability; keep the manual bootstrap boundary instead of adding a generic remote-code workaround.
