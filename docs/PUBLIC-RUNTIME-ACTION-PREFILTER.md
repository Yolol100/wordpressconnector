# Public runtime action prefilter

The public GitHub request transport accepts the historically explicit public-safe action set without an extra confirmation flag. Other syntactically valid connector actions may pass the transport prefilter only when the request contains `confirm=true`.

This change does not make every WordPress action unrestricted. The site runtime remains authoritative: OIDC identity binding, WordPress capability checks, sensitive-action blocking in public-repository context, privileged-action policy, mutation confirmation, idempotency, state guards, secret-like input rejection, public result sanitization, and rollback rules remain in force.

The purpose of the broader prefilter is to stop duplicating the entire connector action catalog in the GitHub transport layer while keeping the WordPress runtime as the final security boundary.
