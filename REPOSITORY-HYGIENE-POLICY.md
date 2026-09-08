# Repository Hygiene Policy

`main` contains only reusable connector source, tests, documentation, CI and the guarded GitHub runtime transport.

The GitHub runtime transport may contain reusable validators and workflows on `main`, but production request payloads, generated full WordPress results, client exports, credentials, private runtime state and date-stamped debugging residue must never be merged into the implementation branch.

Runtime request PRs are temporary transport branches only. They must be same-repository and repository-owner/configured-trusted-actor controlled, and they must be closed without merge after result collection.

Private repositories may contain the intended `requests/*.json`, bounded `assets/inbox/*` files and generated full `results/*.json` on the temporary runtime branch.

Public repositories use a reduced public-safe contract. Public request branches may contain only non-sensitive request data intended to be publicly visible, bounded public assets and generated `receipts/*.json`. Full WordPress responses are never committed in public mode. Public receipts contain only request identity, success/failure category, readback status and deterministic state fingerprints; they never contain before/after content, state tokens, rollback payloads or raw connector errors.

Public runtime requests are limited to `post.update`, post-targeted `acf.update`, batches composed only from those two operations, and `connector.rollback`. Secret-like payload keys, non-post ACF targets, password-protected/non-published status changes and `expected_state_token` are rejected before WordPress credentials become available. Use `expected_fingerprint` for public stale-state protection.

WordPress credentials belong only in GitHub Actions Secrets. Site URLs belong in GitHub Actions Variables. Do not commit passwords, application passwords, tokens, salts, private customer/order/patient data or WordPress configuration. GitHub Actions history and logs for a public repository are public, so trusted workflows must never print full connector responses or secret-bearing error bodies.

The GitHub runtime is an authenticated HTTPS client for WordPress Connector, not a second WordPress implementation. WordPress remains the source of truth for content and runtime state, and all requests still pass connector authentication, semantic security gates, idempotency, mutation locks, stale-state guards, readback and rollback.

Completion requires source/static validation plus separate target-runtime proof for any claim about actual WordPress or Elementor mutation behavior.
