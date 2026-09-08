# Repository Hygiene Policy

`main` contains only reusable connector source, tests, documentation, CI and the guarded private GitHub runtime transport.

The optional GitHub runtime transport may contain its reusable validator and workflows on `main`, but production request payloads, generated WordPress results, client exports, credentials, private runtime state and date-stamped debugging residue must never be merged into the implementation branch.

Runtime request PRs are temporary transport branches only. They may contain exactly the intended `requests/*.json`, bounded `assets/inbox/*` files and generated `results/*.json`. They must stay same-repository, private-repository, trusted-actor only and must be closed without merge after result collection.

WordPress credentials belong only in GitHub Actions Secrets. Site URLs belong in GitHub Actions Variables. Do not commit passwords, application passwords, tokens, salts, private customer/order/patient data or WordPress configuration.

The GitHub runtime is an authenticated HTTPS client for WordPress Connector, not a second WordPress implementation. WordPress remains the source of truth for content and runtime state, and all requests still pass connector authentication, semantic security gates, idempotency, mutation locks, stale-state guards, readback and rollback.

Completion requires source/static validation plus separate target-runtime proof for any claim about actual WordPress or Elementor mutation behavior.
