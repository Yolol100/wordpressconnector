# WordPress Connector repository instructions

## Scope
- This repository contains the advanced WordPress runtime bridge owned technically by `wordpressqualityarchitect`.
- `webactueel-workflow` remains the controller for cross-skill routing, approvals, handoffs and total workflow closure.
- WP Agent is the standard live transport between ChatGPT and WordPress.
- GitHub is used for source control, CI, review and releases only; do not add a second production request transport here.
- The Connector is not a generic remote shell and does not own SEO, Design or Elementor decisions.

## Before changing files
- Read `README.md`, `docs/ARCHITECTURE.md`, `docs/ACTION-CATALOG.md`, `docs/SCOPE.md`, `docs/SECURITY.md` and the relevant runtime adapter/test.
- Preserve `/health`, `/execute`, Registry, Runner, policy gates, idempotency/fingerprints and rollback unless a migration explicitly replaces them with equal or stronger evidence.
- Prefer WP Agent native capabilities when sufficient; use Connector actions for capabilities WP Agent does not safely provide.
- Prefer native WordPress/WooCommerce/plugin Abilities where they already provide the required typed contract.
- Do not add arbitrary shell, SQL, filesystem-write, eval or HTTP-proxy primitives.
- Do not store live requests, results, credentials or target-specific residue on the default branch.

## Validation
Run the same static checks enforced by Connector CI:

```bash
find plugin/wordpressconnector tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/policy-contract.php
php tests/action-catalog.php
php tests/elementor-inventory-contract.php
php tests/elementor-forms-contract.php
php tests/elementor-json-export-runtime.php
php tests/plugin-control-contract.php
php tests/filesystem-control-contract.php
php tests/repository-hygiene.php
php tests/rest-transport-contract.php
php tests/mutation-lock-contract.php
php tests/single-plugin-contract.php
```

Also verify the installable plugin ZIP and reject dangerous execution primitives as defined in `.github/workflows/ci.yml`.

## Safety and acceptance
- Mutations remain confirmation-gated, idempotent and fingerprint/rollback aware.
- Remote execution requires HTTPS, WordPress authentication, `manage_options`, Connector health and the relevant WordPress-side gates.
- A successful REST execution proves transport/runtime evidence only. `wordpressqualityarchitect` owns WordPress mutation safety/runtime correctness and the originating domain owner still accepts the requested business/content change.
- Broad production changes remain staging-first unless equivalent target-runtime evidence already exists.
