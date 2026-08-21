# Repository Hygiene Policy

The default branch contains only generic connector implementation, schemas, documentation, examples and reusable workflow infrastructure. It must not retain client-specific domains, production request payloads, generated target results, credentials, tokens, private exports or temporary debugging residue.

Runtime WordPress work uses short-lived feature branches and request pull requests. Request PRs may contain only `requests/*.json`, required files under `assets/inbox/**`, and generated `results/*.json`. They are closed without merge after evidence has been collected unless a human explicitly wants to preserve a generic, non-sensitive fixture.

Secrets, credentials, private customer/patient/medical/order data and other sensitive production records must never be committed. Production self-hosted runners should be attached to a private repository. Connector implementation changes use normal feature branches and draft pull requests, independent of runtime request PRs.

Before merging implementation work: run static validation, inspect changed paths, confirm no target-specific residue exists, and keep runtime claims separate from package/static validation. A real WordPress/Elementor/WooCommerce/ACF integration remains `staging-first` until the target runtime acceptance tests succeed.
