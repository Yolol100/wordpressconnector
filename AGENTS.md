# WordPress Connector repository instructions

## Scope

- This repository is the canonical live WordPress/Elementor runtime bridge for Webactueel.
- `webactueel-workflow` remains the controller for cross-domain routing and approvals; WordPress Connector owns only the technical WordPress runtime boundary.
- The primary direct route is `approved client -> authenticated HTTPS REST -> WordPress Connector`.
- The guarded GitHub runtime is zero-config: temporary same-repository request PR -> credential-free validation -> trusted `wordpress-zero-config-execute.yml` on `main` -> short-lived GitHub Actions OIDC -> authenticated HTTPS REST -> WordPress Connector -> private full result or sanitized public receipt.
- GitHub transport must not require WordPress usernames, Application Passwords, repository target variables or connector checkboxes for normal use.
- The GitHub transport is only a client/dispatcher. It must not duplicate connector business logic, bypass semantic actions or become a credential store.
- Do not add arbitrary shell, SQL, unrestricted filesystem, eval or HTTP-proxy primitives.

## Before changing files

Read `README.md`, `docs/ARCHITECTURE.md`, `docs/ACTION-CATALOG.md`, `docs/SCOPE.md`, `docs/SECURITY.md`, `docs/SETUP.md`, CI, `wordpress-request.yml` and `wordpress-zero-config-execute.yml`.

Preserve:

- strict top-level request input validation, including canonical HTTPS `site_url` for GitHub transport;
- the distinction between the GitHub transport envelope and the semantic connector request;
- dry-run and explicit request confirmation;
- sensitive confirmation and public-repository blocking;
- optional server-side `WPCONNECTOR_ALLOW_*` emergency overrides without restoring normal-use checkboxes;
- stable request IDs and idempotency;
- `expected_fingerprint` and site-scoped `expected_state_token` stale-state protection in the connector;
- no `expected_state_token` in public GitHub request branches;
- Elementor document API writes instead of direct `_elementor_data` mutation;
- exact readback and rollback for supported mutations;
- secretless validation of request PRs before trusted execution;
- GitHub OIDC `id-token: write` only on the trusted executor;
- exact issuer, JWKS signature, site audience, repository ID, owner ID, workflow ref, main ref, event, runner, time-window and replay checks on WordPress;
- one short-lived OIDC token per REST operation and no token persistence/logging;
- same-repository, trusted-actor and latest-commit-author validation;
- exact PR head-SHA revalidation before execution and before result/receipt writeback;
- bounded request asset transport and public/private asset restrictions;
- public mode limited to the explicit public-safe action contract;
- only the canonical connector self-update actions may use the narrow `public_repository_safe` privileged exception;
- public connector self-update accepts no caller-supplied package URL/version/archive and still requires normal WordPress capability, write/system-update policy, dry-run and fingerprint confirmation;
- `plugin.install_package` and private/custom plugin ZIP bytes never routed through public request branches;
- public mode never persists full connector responses and never logs response bodies;
- sanitized public receipts contain only bounded status/readback evidence, deterministic fingerprints and explicitly safe connector version/checksum evidence;
- runtime request/result/receipt payloads never merged to `main`;
- every third-party GitHub Action pinned to a full commit SHA;
- release ZIP and SPDX SBOM built deterministically, reproducibly and attested against exact release bytes;
- plugin-declared PHP support covered by CI rather than assumed;
- temporary migration or diagnostic workflows removed immediately after their one-time purpose is complete.

## Discovery boundary

A known domain may expose only the minimal HTTPS `/presence` response needed to identify the connector. Do not claim that GitHub can enumerate unknown plugin installations. Global site discovery requires a separate authenticated registry/pairing component; never emulate it with crawling, anonymous callbacks or embedded GitHub credentials.

## Validation

Run the same canonical contract runner as Connector CI:

```bash
bash scripts/run-contracts.sh
```

Repository CI is source/static or controlled-runtime proof only. Live compatibility and mutation safety remain staging-first or require equivalent target-runtime evidence.

A connector version that predates GitHub OIDC still needs a one-time plugin update before zero-config transport can exist on that site. Never add a generic remote-code workaround to bypass that bootstrap boundary.
