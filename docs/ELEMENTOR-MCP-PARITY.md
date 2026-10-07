# Elementor MCP parity bridge

WordPress Connector does not copy Elementor MCP business logic. It reuses the WordPress Abilities that the installed Elementor runtime registers and explicitly exposes to clients.

## Why this route

Elementor's MCP implementation registers native `elementor/*` abilities through the WordPress Abilities API. Their execute callbacks retain Elementor's own schema handling, permission checks, Atomic/V4 gates, editor-sync/conflict guards and feature/license availability. Reimplementing those internals in WordPress Connector would create a second, drifting Elementor engine.

WordPress Connector therefore adds a guarded generic mutation route:

- discover with `wordpress.abilities`, optionally `{"namespace":"elementor"}`;
- read explicitly read-only abilities through `wordpress.ability.read`;
- preview explicitly mutating abilities through `wordpress.ability.execute` with connector `dry_run=true`; this does not invoke provider code;
- execute with `dry_run=false`, `confirm=true` and a stable `request_id`;
- keep the provider's native input validation, permission callback and execution guards authoritative.

## Native Elementor surface

The exact surface is target/runtime dependent. Current Elementor Core source registers MCP abilities covering families such as:

- page/document creation, structure reads, settings and publishing;
- Atomic composition and element management;
- widget schemas and asset discovery;
- global classes, class ordering and default styles;
- global color/font/size variables;
- Components, component discovery and overridable props;
- interactions schemas/resources and style guidance;
- dynamic tags/resources;
- preview links and WordPress/Elementor best-practice resources.

Elementor Pro, add-ons or later releases may register additional abilities, including Loop/Theme Builder related capabilities. WordPress Connector exposes them only when the installed target actually registers them and marks them executable for clients.

## Safety contract

- The connector never treats discovery metadata as authorization.
- Read execution requires explicit `readonly=true` and `destructive=false`.
- Mutation execution requires explicit `readonly=false` and an explicit boolean `destructive`.
- If the provider publishes `meta.mcp.public`, `false` blocks connector execution even when `show_in_rest=true`.
- `wordpress.ability.execute` is privileged and is not in the public GitHub-runtime allowlist.
- Connector mutation locking and request-id idempotency still apply.
- Generic foreign Ability mutations report `rollback_supported=false`; no rollback or stale-state guarantee is invented.
- Output remains recursively redacted and bounded by the connector transport limits.

## MCP client sequence

Through the existing `wordpress_connector_execute` MCP tool:

1. Call action `wordpress.abilities` with payload `{"namespace":"elementor"}`.
2. Select an ability whose descriptor exposes `connector_action`.
3. For a mutation, call action `wordpress.ability.execute` with payload `{"name":"elementor/<ability>","input":{...}}` and leave `dry_run=true`.
4. Review the returned schema/annotations and target prerequisites.
5. Execute the same semantic action with `dry_run=false`, `confirm=true` and a stable `request_id`.
6. Perform target-specific readback/preview/QA. A successful Ability call is execution evidence, not frontend/release acceptance.

## Evidence boundary

The bridge is deliberately future-compatible: newly registered Elementor abilities can appear without a WordPress Connector release. That is capability discovery, not a blanket compatibility claim. Core/Pro entitlement, Atomic/V4 availability, Loop support and frontend behavior still require target-runtime evidence.
