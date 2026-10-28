# One-pass WordPress Connector read-only audit

This is a bounded, repeatable **read-only** audit plan, not blanket WordPress administration or a 10/10 certification.

## Procedure

1. Verify exact HTTPS site URL, plugin presence, GitHub repository, main SHA, Actions availability and site-specific OIDC approval. Presence is not authorization.
2. Generate isolated request JSON files: python3 scripts/plan-readonly-audit.py --site https://example.com --prefix audit-20261028 --output /tmp/connector-audit-example. Add --elementor-id N only for a known published Elementor page; use --acf-post-id N only with a valid positive WordPress post ID (required by the public ACF validator). Page inventory is limited to 25 sanitized items per receipt; select additional pages with --page N. The planner only generates requests and does not execute GitHub jobs.
3. For EACH JSON file, create a separate short-lived branch from main, commit exactly one requests/*.json file, and open a same-repository PR. Never combine generated requests in one PR.
4. Keep the PR OPEN until both request validation and trusted executor finish and a sanitized receipts/<request_id>.json is committed to the branch. A green request workflow is not site success. Require matching request_id/action and ok=true.
5. If an action returns permission_gate, record it as a real denied permission. Inspect each action separately. Do not bypass WordPress capabilities or public GitHub policy. Private connector.setup.inspect requires a separately authenticated admin path.
6. Distinguish identity, plugins, pages, Elementor, ACF, cache, connector updates and transport. Empty Elementor elements means only that the selected document has no elements; it does not prove Elementor editing works.
7. Close the temporary PR without merging it. Delete temporary branches using an authorized branch deletion tool when available. Never commit site requests, receipts, secrets or client run state to main.

## Release gates

- For any live write: exact target, owner approval, dry-run, stale-state fingerprint, verified backup/restore, rollback, readback and independent QA.
- Public GitHub is intentionally restricted to sanitized receipts. Do not widen its allowlist to hide a permission denial.
- Unknown sites require an explicit domain or an authorized site registry; GitHub cannot discover all plugin installations.
- Retry only transient reads with backoff. Do not blindly retry mutations after ambiguous outcomes.
- A 10/10 claim requires CI, PHP compatibility, runtime matrix, security and supply-chain checks plus independent target-specific acceptance.

## Known historical issues

| Symptom | Safe response |
| --- | --- |
| Trusted executor rejects a closed PR | Keep PR open through terminal receipt, then close; do not weaken executor check |
| Mixed connector.read_batch returns permission_gate | Run separately allowlisted reads; privileged leaf cannot bypass permission checks |
| Presence succeeds, OIDC fails | Administrator checks site-bound pairing, canonical URL and trust claims |
| Elementor inspect has zero elements | Choose a known Elementor-built page; never claim editor functionality tested |
| PR validation succeeds without receipt | Trusted executor is not proven; check dispatch and terminal result |
