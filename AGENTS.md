# WordPress Connector repository instructions

## Scope
- This repository is the remote WordPress live-runtime bridge owned technically by `wordpressqualityarchitect`; it is not a generic remote shell and does not own the originating SEO, Design or Elementor decision.
- `webactueel-workflow` remains the controller for cross-skill routing, approvals, handoffs and total workflow closure.
- Prefer an exact local Codex/WP-CLI runtime when it can safely deliver the same readback/mutation/rollback evidence. Use this GitHub bridge when a remote WordPress runtime is needed and the full connector preflight is satisfied.

## Before changing files
- Read `README.md`, `docs/ACTION-CATALOG.md`, `docs/SCOPE.md`, request/result schemas and all three workflows under `.github/workflows/`.
- Preserve the separation between secretless PR validation and the trusted `main` workflow-dispatch executor.
- Keep runtime request state on request branches/PRs; never place production credentials, WordPress Application Passwords or client secrets in repository files, logs or artifacts.
- Do not add arbitrary shell, SQL, filesystem-write, eval or HTTP-proxy primitives.

## Validation
Run the same static checks enforced by Connector CI:

```bash
find plugin/wordpressconnector scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
find scripts -name '*.sh' -print0 | xargs -0 -r -n1 bash -n
for file in examples/*.json; do php scripts/validate-request.php "$file"; done
php tests/policy-contract.php
php tests/action-catalog.php
php tests/repository-hygiene.php
php tests/request-workflow-contract.php
php tests/execute-workflow-contract.php
php tests/rest-transport-contract.php
php tests/mutation-lock-contract.php
php tests/single-plugin-contract.php
```

When transport or workflow guards change, also ensure `.github/workflows/ci.yml` passes on the exact PR head.

## Safety and acceptance
- Mutations remain dry-run/confirmation gated, idempotent and fingerprint/rollback aware.
- Remote execution requires the repository/private/trusted-actor/HTTPS/credential/connector-health gates defined by the runtime contract; write, privileged, sensitive and system-update gates remain separate.
- A successful PR or GitHub runner proves transport/runtime evidence only. `wordpressqualityarchitect` owns WordPress mutation safety/runtime correctness, and the originating domain owner still owns the requested business/content change.
- Do not merge or perform production mutations solely because CI is green.
