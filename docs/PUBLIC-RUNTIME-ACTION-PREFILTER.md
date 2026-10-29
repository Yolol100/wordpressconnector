# Public runtime action prefilter

The public GitHub request transport accepts **only** the explicitly allowed action names in `scripts/validate-public-request.php`. A request with `confirm=true` does not make an unlisted action available. Every supported action also has its own input-shape, dry-run, confirmation, target and fingerprint requirements; private-only operations must use an independently authorized private or direct transport.

The WordPress runtime remains the final authorization boundary: signed OIDC identity and site pairing, the `Security/Policy.php` public action allowlist, WordPress capabilities, privileged and sensitive-action policies, mutation locks, idempotency, stale-state guards, rollback and secret-safe public receipts. Public mode blocks sensitive actions and never exposes full result bodies.

Changes to public action availability require an explicit, reviewed update to both the transport validator and the WordPress runtime policy, with positive and negative contract tests. The transport is a dispatcher, not an authorization bypass.
