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

## Public presence endpoint

`/presence` is intentionally minimal and HTTPS-only. It exposes only that WordPress Connector is present, zero-config capable and expects GitHub OIDC. It does not expose users, site settings, secrets or content.

## Public repository mode

A public GitHub repository is not a private transport. Full WordPress results and sensitive data must not be committed to public request branches or emitted into public logs. Public runtime requests retain the explicit action allowlist and sanitized receipt builder.

`plugin.install_package` and private/custom package bytes remain excluded from public GitHub runtime.

## Token handling

OIDC tokens are requested only inside the trusted executor. Authenticated asset calls and final execution use short-lived tokens; tokens are not committed, printed or persisted. JWT replay is rejected by WordPress. HTTPS verification remains enabled.

## Discovery boundary

The connector does not publish an anonymous global list of installations. `/presence` identifies only a domain that is already known. Discovering arbitrary unknown installed sites requires a separate authenticated registry/pairing service and must not be emulated with crawling, anonymous callbacks or credential leakage.
