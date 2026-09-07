# WordPress Connector repository instructions

## Scope
- This repository owns the advanced WordPress execution plugin behind WP Agent.
- WP Agent is the canonical ChatGPT-to-WordPress transport.
- `webactueel-workflow` remains controller for cross-skill routing and closure.
- `wordpressqualityarchitect` owns plugin/runtime safety and correctness.
- Domain Skills such as SEO, Design and Elementor decide what should change; this plugin only executes supported actions safely.

## Keep the repository small
Keep only:
- `plugin/wordpressconnector/**`;
- runtime/security/rollback/idempotency code required by the plugin;
- tests for the retained runtime;
- `.github/workflows/ci.yml` plus minimal repository maintenance metadata;
- current documentation for actions, scope, security, setup and architecture.

Do not reintroduce:
- request/result queues;
- GitHub Actions as live WordPress transport;
- request/result schemas used only by that retired transport;
- generic remote shell, arbitrary SQL or unrestricted filesystem execution;
- target-specific runtime residue.

## Before changing files
- Read `README.md`, `docs/ACTION-CATALOG.md`, `docs/SCOPE.md` and `docs/SECURITY.md`.
- Preserve `/execute`, Registry, Runner, policy gates, idempotency/fingerprints, rollback and readback contracts unless an explicit migration proves a replacement.
- Prefer native WordPress/plugin Abilities or supported APIs when they safely cover a capability; avoid duplicate adapters.

## Validation
Run the same source/runtime checks enforced by Connector CI. CI proves code/package contracts only; target WordPress behavior still needs appropriate runtime readback.

## Safety
- Mutations remain dry-run/confirmation gated, idempotent and fingerprint/rollback aware.
- Do not expose arbitrary PHP, shell/process execution, SQL, unrestricted filesystem operations, generic HTTP proxying or secret export.
- High-risk production mutations remain staging-first or require explicit approval and rollback evidence.
