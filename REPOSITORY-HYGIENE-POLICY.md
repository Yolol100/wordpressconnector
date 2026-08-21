# Repository Hygiene Policy

`main` contains only generic connector implementation, schemas, documentation, examples and reusable workflow infrastructure. It must not retain client domains, production request payloads, generated target results, credentials, private exports or date-stamped debugging residue.

Runtime WordPress work uses short-lived request branches/PRs. Such PRs contain only one `requests/*.json`, optional `assets/inbox/**`, and generated `results/*.json`; close them without merge after evidence is collected unless a human explicitly wants to preserve a generic non-sensitive fixture.

Do not commit secrets, credentials, private customer/order/patient/medical data, runner registration tokens or WordPress configuration. Production self-hosted runners should use a private repository.

Implementation completion requires static validation, no target-specific residue and separate reporting of package/static proof versus actual WordPress runtime proof.
