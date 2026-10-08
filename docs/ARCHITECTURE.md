# Architecture

## Canonical paths

### 1. Direct HTTPS REST

Approved WordPress-authenticated client -> HTTPS REST -> strict request parser -> policy/capability checks -> semantic adapter -> readback/result.

### 2. Zero-config GitHub runtime

ChatGPT/GitHub request branch -> `wordpress-request.yml` credential-free guard -> trusted `wordpress-zero-config-execute.yml` on `main` -> GitHub Actions OIDC -> WordPress HTTPS REST -> semantic connector runtime -> private full result or sanitized public receipt.

The untrusted request PR never receives production credentials. The trusted executor has no persistent WordPress credential either; it obtains short-lived OIDC tokens from GitHub for the exact WordPress audience.

### 3. Native MCP

Approved authenticated MCP client -> WordPress REST `/mcp` -> MCP envelope validation -> existing WordPress authorization -> bounded MCP tool facade -> the same semantic `Runtime\\Request` and `Runner` -> policy/capability checks -> adapter -> readback/result.

MCP is transport only. It does not register a parallel WordPress action model, does not expose arbitrary REST/SQL/shell/filesystem primitives and does not weaken the existing mutation contract. The bounded tool facade first exposes discovery/action metadata, then routes requested semantic actions through the canonical Runner. Confirmed writes require an explicit stable connector `request_id` so client retries remain inside the existing idempotency model.

## Fast read and failure diagnostics

Use the existing authenticated HTTPS REST/MCP path when an approved client already has access; it avoids launching a GitHub runner. Use `connector.read_batch` for 1-25 permitted, non-sensitive read actions in one request: all leaf permissions are checked before any read, and mutations, sensitive exports, delegated abilities and nested batches are disallowed. The zero-config GitHub OIDC workflow stays the safe fallback, never a second authorization model.

The runner returns deterministic, non-sensitive `meta.diagnostic` codes and safe recovery steps on error without copying request bodies or exception strings into diagnostic metadata. The GitHub executor fails when WordPress replies with HTTP 200 but a semantic `ok:false`, after persisting the allowed receipt. HTTP 401/403 and invalid routes fail fast; retry is reserved for transient read failures. For uncertain mutation outcomes, reconcile the original request ID before retrying.

## Connector self-update recovery

The release digest and ZIP identity checks are unchanged. Replacing an installed plugin now uses WordPress Core's temporary-backup upgrade hooks, not the plain overwrite-install path. Core can restore the previous version on installation failure; a readback-version mismatch attempts Core restoration only while that backup exists and verifies the old version. This is **not** guaranteed recovery from a fatal error discovered on a later request. Confirmed production self-updates explicitly require `payload.restore_verified=true` to attest that an external site backup **and its restore** have been tested, in addition to the existing authorization and fingerprint gates. Production self-updates still require a verified external site backup and restore plan, and the response accurately reports `rollback_supported=false` for general post-success rollback.

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
