# Architecture

## Canonical paths

### 1. Direct HTTPS REST

Approved WordPress-authenticated client -> HTTPS REST -> strict request parser -> policy/capability checks -> semantic adapter -> readback/result.

### 2. Zero-config GitHub runtime

ChatGPT/GitHub request branch -> `wordpress-request.yml` credential-free guard -> trusted `wordpress-zero-config-execute.yml` on `main` -> GitHub Actions OIDC -> WordPress HTTPS REST -> semantic connector runtime -> private full result or sanitized public receipt.

The untrusted request PR never receives production credentials. The trusted executor has no persistent WordPress credential either; it obtains short-lived OIDC tokens from GitHub for the exact WordPress audience.

## Target selection

`site_url` is part of the temporary GitHub request envelope. It is validated as canonical HTTPS and removed before the semantic request is passed to `Runtime\Request`.

This removes the old repository-variable dependency while keeping target selection explicit and reviewable.

## WordPress authentication adapter

`Security\GitHubOidc` verifies GitHub's JWT signature and fixed claims. On success it sets a WordPress administrator execution context; normal action-level capability checks then apply. Invalid issuer, signature, audience, repository, owner, workflow, ref, event, runner, time window or replay fails closed.

## Request assets

Request branches may include bounded `assets/inbox/*` data. The trusted executor validates file modes, file count and total size before extraction. Each authenticated asset request uses short-lived GitHub OIDC, followed by a fresh authenticated token for final `/execute`. Public branches may contain only assets already safe to disclose; private/custom plugin packages remain excluded from public mode.

## Request trust split

The request workflow and trusted executor both validate same-repository origin and the allowed actor. The executor also validates the latest request commit author and exact head SHA, then re-reads the PR head immediately before WordPress execution. Result/receipt writeback checks the unchanged request branch again.

## Public/private split

Repository visibility comes from the verified GitHub OIDC claim and is propagated into `Security\Policy`.

- public: sensitive actions blocked; privileged actions only when marked public-safe; sanitized receipt only;
- private: authenticated capabilities and request-level safeguards govern the broader action catalog; full results may be written to the private request branch.

## Discovery

The `/presence` route gives deterministic recognition for a known domain. There is intentionally no anonymous global site registry inside this plugin. Enumerating unknown installations requires a separate authenticated registry/pairing component because neither GitHub nor WordPress can securely infer an unknown domain from plugin activation alone.

## State and mutation safety

The semantic connector runtime remains unchanged in principle: strict request IDs, dry-run, `confirm=true` for real mutations, capability checks, idempotency, mutation locking, stale-state fingerprints/tokens, exact readback and rollback where supported. GitHub OIDC changes transport authentication, not the semantic action contract.
