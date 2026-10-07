# Security

## Trust boundary

The GitHub runtime is zero-config but not unauthenticated. `wordpress-zero-config-execute.yml` requests short-lived GitHub Actions OIDC JWTs using `id-token: write`. WordPress verifies:

- issuer `https://token.actions.githubusercontent.com`;
- RS256 signature against GitHub JWKS;
- audience equal to this site's connector REST namespace;
- repository `Yolol100/wordpressconnector`;
- immutable repository ID `1341990468`;
- immutable owner ID `22932777`;
- ref `refs/heads/main`;
- exact `wordpress-zero-config-execute.yml` workflow ref;
- `workflow_dispatch` event;
- GitHub-hosted runner;
- validity window and one-time `jti` replay protection.

No WordPress password, Application Password or long-lived GitHub-to-WordPress secret is stored for this path.

## GitHub request trust

The credential-free request workflow accepts only same-repository requests from the repository owner or optional explicitly configured trusted actor. The trusted executor repeats that actor validation, validates the latest request commit author, exact base/head repositories and main base ref, file modes and paths, then re-reads the exact PR head immediately before execution.

Request assets are bounded to 10 files and 25 MiB total. Public request branches may contain only data safe to disclose.

## WordPress authorization

After OIDC verification, the connector establishes the WordPress execution context using an administrator on the current site. Existing action-specific `current_user_can()` checks remain authoritative. Direct authenticated WordPress REST clients remain supported.

## Execution safety

Persistent checkbox gates are removed from normal setup. Safety is enforced at the request and semantic-action layers instead:

- real mutations require `confirm=true`;
- sensitive actions require `confirm=true`;
- sensitive actions are blocked in public-repository mode;
- privileged actions in public mode require `public_repository_safe`;
- action-specific WordPress capabilities are enforced;
- stale-state fingerprints/tokens remain enforced;
- mutation locks and idempotency remain enforced;
- supported writes retain readback/rollback checks;
- filesystem scope and secret-file restrictions remain unchanged.

`WPCONNECTOR_ALLOW_*` constants/environment variables remain optional server-side emergency overrides. They default to enabled for zero-config normal operation and can be explicitly set false by an operator to stop a class of actions.

## MCP transport

The native MCP route reuses the existing REST authorization callback. It does not introduce anonymous access, a second credential store or a second semantic action implementation.

- HTTPS and WordPress `manage_options` remain required through the existing authorization boundary.
- GitHub OIDC remains available only under the same exact JWT claim verification used by REST.
- Modern MCP envelope metadata and standard method/name headers are checked before tool execution.
- The MCP facade exposes three bounded tools and routes execution through `Runtime\\Request` and `Runner`.
- Dry-run remains the default. A non-dry-run MCP call requires both `confirm=true` and an explicit stable connector `request_id`.
- Existing capability checks, sensitive/public-mode policy, stale-state guards, mutation locks, idempotency, readback, rollback and redaction remain authoritative.
- `wordpress.ability.execute` is a privileged mutation action and is intentionally excluded from the public GitHub runtime allowlist.
- Delegated mutation is restricted to `elementor/*` Abilities whose category is also `elementor`; arbitrary third-party mutating Abilities cannot use this generic write path. Eligible Elementor mutations must explicitly declare `readonly=false` and a boolean `destructive` annotation. If the provider publishes `mcp.public`, execution also requires that flag to be true.
- Ability dry-run is metadata-only and never calls the provider execute callback. Confirmed execution still passes through Connector confirmation/idempotency, then WordPress Ability input validation, permission checks and provider-specific guards.
- Delegated Elementor Ability mutations do not receive a fabricated rollback or stale-state guarantee. Their response reports `rollback_supported=false`. If the provider completed a mutation but its returned value exceeds the connector output/redaction budget, the connector returns a bounded terminal success with `result_omitted=true` so the request is recorded as processed before a client retry can occur.
- MCP adds no arbitrary shell, SQL, PHP eval, unrestricted filesystem write or generic HTTP proxy.
- Hosted account/site registries, OAuth brokerage, billing/quotas and background-job orchestration are outside this plugin's trust boundary.

## Public presence endpoint

`/presence` is intentionally minimal and HTTPS-only. It exposes only that WordPress Connector is present, zero-config capable and expects GitHub OIDC. It does not expose users, site settings, secrets or content.

## Public repository mode

A public GitHub repository is not a private transport. Full WordPress results and sensitive data must not be committed to public request branches or emitted into public logs. Public runtime requests retain the explicit action allowlist and sanitized receipt builder. Additional CSS public access is limited to the active theme: inspection receipts expose only hashes/byte counts, managed `custom_css.patch` writes require `edit_css`, dry-run fingerprint protection, exact readback and rollback, and full `custom_css.update` remains unavailable through public GitHub transport.

`plugin.install_package` is allowed in public GitHub runtime only for publishable packages under the guarded package contract: exact SHA-256 and plugin identity, `network_wide=false`, explicit activation intent, and a preceding dry-run fingerprint before confirmation. New installs require `overwrite=false`; `overwrite=true` is restricted to the exact public Mailbox Bridge identity `webactueel-mailbox-bridge/webactueel-mailbox-bridge.php`. Private/confidential package bytes remain excluded from public GitHub runtime.

## Token handling

OIDC tokens are requested only inside the trusted executor. Authenticated asset calls and final execution use short-lived tokens; tokens are not committed, printed or persisted. JWT replay is rejected by WordPress. HTTPS verification remains enabled.

## Discovery boundary

The connector does not publish an anonymous global list of installations. `/presence` identifies only a domain that is already known. Discovering arbitrary unknown installed sites requires a separate authenticated registry/pairing service and must not be emulated with crawling, anonymous callbacks or credential leakage.
