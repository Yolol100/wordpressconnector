# Architecture and reference patterns

WordPress Connector combines established patterns rather than copying another project.

- **WP-CLI:** explicit command contracts, machine-readable output and deterministic exit status. Runtime execution stays inside WordPress rather than adding a public management endpoint.
- **Roots Bedrock:** configuration/secrets remain environment-owned rather than committed to the repository. Connector behavior is controlled with runner/site environment flags.
- **Mature WordPress deployment actions:** validate/preflight first, use a trusted source revision, deploy atomically enough to restore the previous plugin directory if activation fails, and separate deployment from business requests.
- **10up release hygiene:** keep runtime/generated files out of the permanent product source and make distributable plugin boundaries explicit.
- **Git Updater:** demonstrates the usefulness of Git-hosted WordPress lifecycle workflows, while this project deliberately avoids fetching arbitrary remote executable code from a runtime request.
- **GitHub self-hosted runner guidance:** persistent runners are treated as privileged infrastructure; untrusted/fork PRs never get a runner assignment and public repositories are blocked by default.

## State model

A request has one stable `request_id`, normalized payload and fingerprint. Mutating requests are idempotent. Optional `expected_fingerprint` prevents stale writes. A mutation may register a rollback snapshot; batches compensate completed operations in reverse order when a later operation fails.

## Runtime ownership

GitHub owns request transport and audit history. WordPress remains the source of truth for content/runtime state. The connector does not mirror the WordPress database into GitHub.
