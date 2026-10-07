# Elementor MCP parity bridge

WordPress Connector does not copy Elementor MCP business logic. It reuses the WordPress Abilities that the installed Elementor runtime registers and explicitly exposes to clients.

## Why this route

Elementor's MCP implementation registers native `elementor/*` abilities through the WordPress Abilities API. Their execute callbacks retain Elementor's own schema handling, permission checks, Atomic/V4 gates, editor-sync/conflict guards and feature/license availability. Reimplementing those internals in WordPress Connector would create a second, drifting Elementor engine.

WordPress Connector therefore adds a guarded discovery/read bridge plus a bounded Elementor mutation route:

- discover with `wordpress.abilities`, optionally `{"namespace":"elementor"}`;
- preserve generic WordPress Ability reads only when the provider explicitly declares `readonly=true` and `destructive=false`; native WordPress permission checks still run and results remain bounded/redacted;
- expose delegated mutation only for `elementor/*` Abilities with explicit mutating annotations and MCP exposure whose provider object derives from Elementor's real MCP `Abstract_Ability`, whose native `execute_guarded` method/base class resolve inside active Elementor Core, whose concrete provider class resolves inside active Elementor Core/Pro, and whose provider ID exactly matches the registered Ability name;
- preview eligible Elementor mutations through `wordpress.ability.execute` with connector `dry_run=true`; this does not invoke provider code;
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
- Names, categories, annotations and global path constants are metadata, not mutation ownership proof. Mutation trust is derived from the active plugin files plus the concrete native provider/base-class/callback provenance described above.
- Generic read execution requires explicit `readonly=true` and `destructive=false`; mutation requires explicit `readonly=false` and an explicit boolean `destructive` plus native Elementor provenance.
- If `meta.mcp.public` exists, only `true` is discoverable/executable; `false` is an authoritative opt-out even when `show_in_rest=true`.
- `wordpress.ability.execute` is privileged, absent from the public GitHub-runtime allowlist, and forbidden inside `connector.batch` because delegated writes have no Connector compensation contract.
- Connector mutation locking and request-id idempotency still apply. Delegated Elementor mutations report `rollback_supported=false`; no rollback or stale-state guarantee is invented.
- A successful provider write whose result exceeds the traversal/size budget becomes a bounded terminal success with `result_omitted=true`. WordPress validation/permission failures before `wp_before_execute_ability` remain retryable. At that hook the connector first persists and verifies a durable `started` marker. Once the marker exists, a provider exception or `WP_Error` becomes a bounded terminal failure recorded against the request ID. If execution is interrupted before a final result is persisted, the leftover marker becomes an unknown-outcome replay barrier rather than allowing the provider to run twice.
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

The bridge is deliberately capability-driven: newly registered Elementor abilities can be discovered without a WordPress Connector release, but mutation execution additionally requires the native Elementor provider/base-class/callback provenance gate. Controlled CI covers WordPress 6.9 Abilities and WordPress 7.1.3 + Elementor Core 4.3.4 MCP, including generic read compatibility, native discovery/read, a disposable create-page mutation, exact readback, spoof rejection, output bounding and request-ID replay. Elementor Pro entitlement, Atomic/Loop specifics, ChatGPT-workspace authentication and frontend behavior still require target-runtime evidence.
