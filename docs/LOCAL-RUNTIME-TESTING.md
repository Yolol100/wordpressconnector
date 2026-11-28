# Isolated local WordPress smoke test (Docker)

This repository retains its existing secretless contract CI and guarded GitHub OIDC workflow. Docker is only an **additional, isolated runtime test**: never a production connector, GitHub transport replacement, or release authorization.

## Prerequisites

- Docker Engine/Desktop running locally, or a Linux GitHub-hosted runner.
- Node.js 24.18.0.
- `npm install --global @wordpress/env@11.17.0`

## Run

From repository root:

```sh
wp-env start
wp-env run cli wp plugin is-active wordpressconnector
wp-env run cli wp eval 'if (!defined("WPCONNECTOR_VERSION")) { WP_CLI::error("Connector did not boot"); }'
wp-env destroy --force
```

The protected Docker workflow and `.wp-env.json` on `main` pin WordPress 7.1.5 / PHP 8.3. The promoted workflow checks WordPress 7.1.5, PHP 8.3, a real disposable database query and three expected REST routes. Four unauthenticated HTTP route probes must return 403. With HTTPS simulated **inside the isolated WP-CLI test only**, public `/presence` must return 200 while `/health`, `/assets`, and `/execute` require authentication (401). Simulated HTTPS is not a real network/TLS transport test.

The isolated Docker check runs for changes in relevant paths on pull requests, again after those changes land on `main`, or with manual `workflow_dispatch`. Other file-only changes do not trigger this expensive runtime check. The existing main-branch Connector CI and release workflow remain separate; green Docker CI is not production deployment evidence.

## Reproduce contract QA on an authorized Windows PC

Use the existing Docker image and **exact clean Git commit**; this is optional, additional controlled-runtime evidence. It is not a second release runner or an alternative to the existing GitHub-hosted `wp-env` smoke test.

In PowerShell, from the checked-out repository root (with Git, Python 3, Node.js and a running Docker Engine):

```powershell
# Check out the same commit that GitHub CI tested before running this command.
$sha = (git rev-parse HEAD).Trim()
docker image inspect wordpress:php8.3-apache
.\scripts\local-docker-contracts.ps1 -ExpectedSha $sha
```

The image must already exist locally; the script never pulls. It requires clean tracked files, checks the full 40-character SHA, runs `scripts/run-contracts.sh` inside the **pre-existing** image with no network, a read-only repository bind mount, a read-only container root, dropped capabilities and a bounded temporary filesystem. Because the WordPress PHP image has no Python, only the four Python tests are delegated to the SHA-checked host Python interpreter; JavaScript tests run with host Node.js. The runner rejects changes to the deferred list and fails if either host suite fails. GitHub CI still executes Python normally. A unique container is cleaned up. Only that uniquely named container may be removed; never delete shared Docker volumes, projects or other containers. No site URL, secrets, database, local WordPress installation, live plugin activation or admin credentials are required.

Results and a compact `receipt.json` are stored outside the repository under the user's temporary `wordpressconnector-qa/<run-id>/` directory (or the explicit `-EvidenceDirectory`), with exact Git SHA, image ID, Docker version, status, warning count, log name and SHA-256. Fail on non-zero exit, PHP warnings or tracked source changes. A wrong SHA, option-like Docker image, or ignored/untracked PHP input must fail before Docker starts. The script also rechecks the Git HEAD and PHP inputs after Docker finishes. The canonical `run-contracts.sh` exercises the PowerShell parser and negative scenarios whenever `pwsh` is available, and always runs the static contract test.

**Protected GitHub governance:** the mandatory WPCS no-regression audit and the promoted CI, Docker and release governance are already present on protected `main` (PRs #1392 and #1393). The trusted Required PR Gate remains enabled and must evaluate the exact current PR head. Ordinary implementation and documentation changes must not alter or bypass protected controls. Changes to this document trigger the existing Docker smoke; source changes alone do not authorize releasing or installing anything. Local logs are not automatically uploaded to public GitHub.

**Proof boundary:** this runner executes PHP contract fixtures under Docker, **not** a bootstrapped WordPress site. Use the existing GitHub `docker-runtime-smoke.yml` / `wp-env` for real disposable WordPress + database + plugin activation. A green result cannot establish a target site's OAuth/OIDC, WAF, file permissions, administration, deployment or tested restore. For a real installation or update, use separately authorized staging, an independent backup/restore test and readback; a blocked setup remains `not_run`, never `PASS`.

## Staging acceptance and restoration (mandatory before release)

Use an **owner-approved disposable staging clone**, never the customer's live site. Record exact URL, site UUID,
WordPress/PHP/WooCommerce/Elementor versions, plugin header version, database prefix, hosting filesystem
method, current OAuth state, active plugins and baseline file/tree hashes; do not put credentials in GitHub.

1. Make offsite copies of **both files and database** (and any required object-store/hosting configuration).
   Restore into a **separate disposable staging clone** before attempting any upgrade. Verify expected
   content/media counts, WP user roles, login, REST auth, cron, active plugin state and source ZIP SHA-256.
2. Ensure only the signed, post-merge, tested **immutable published** GitHub release ZIP is offered.
   Verify release tag, exact protected-main source ancestry/tree, ZIP bytes, sha256 asset,
   SPDX SBOM, GitHub attestation provenance, plugin headers and `Update URI`. Preserve original install.
3. Stage a normal **Plugins → Update Now** test and a separately approved OAuth-scoped release
   test, verifying both reject a checksum-matching ZIP whose source differs from protected main.
   Test interrupted download, corrupt archive, invalid SHA256, wrong plugin identity/version,
   WordPress Core backup failure, post-install version mismatch, lost activation and failed
   recovery. Compare prior plugin source hashes and WP REST presence **after** every failure.
   A PHP worker fatal/kill **cannot** be proven recoverable by the in-process rollback code;
   use the externally restored staging clone and record downtime and manual steps.
4. Test owner approval in a live HTTPS admin cookie session, nonce rejection, 10-minute expiry,
   exact release fingerprint, one-time atomic claim, double-click/replay, simultaneous requests
   and the request-ID unknown-outcome reconciliation. No automatic retry on an unknown write.
5. Configure a trusted external OAuth provider only with secrets stored server-side. Confirm
   authorization code + PKCE S256, provider metadata, exact resource audience, optional pinned
   authorization client ID (not introspection client), consent, refresh rotation, revocation,
   user role/capability changes, WAF 429, provider outage 503 and token redaction. Verify real
   ChatGPT workspace consent, read-only MCP canary and disconnect; do not test a real update
   until the owner approves the exact release in WordPress.
6. Re-run WordPress/PHP/WooCommerce HPOS/legacy/Elementor contracts and browser readback after
   restoring. Require two consecutive green identical-source runs. Preserve sanitized JSON
   receipts with exact Git SHA, release asset hashes, environment versions, expected/actual
   outcome and operator approval. **NO-GO** on any unverified restore, lost activation,
   drifted source, unexpected token access or stale version.

This staging runbook is a checklist, not evidence that any client site was tested.
The isolated PHP contract mocks and Docker smoke tests do not prove WordPress hosting parity.

## Evidence and limitations

- In addition to bootstrap, REST-route and anonymous HTTP/HTTPS denial, the Docker smoke now stages a verified ZIP outside Core's cleaned `wp-content/upgrade` scratch directory and installs a **disposable verified ZIP** through the real WordPress plugin upgrader, verifies `register_activation_hook()` execution, deactivates/reactivates the installed plugin, creates and deletes a confirmed browser-active HTML asset, and cleans up the temporary plugin. This is isolated Docker WordPress/MySQL proof, **not** live hosting, managed WAF, multisite or production deployment proof.
- The real presence endpoint is **HTTPS-only**. Do not weaken that check or claim the local HTTP test proves authenticated HTTPS access.
- This does **not** prove production WordPress connectivity, GitHub OIDC signatures, Elementor/WooCommerce integration, production writes, deployment, rollback, or release compatibility across all declared PHP and WordPress versions.
- No production credentials, contact data, site URL, or request payloads are needed. Test failures block this new runtime gate but must not silently change existing trusted executor permissions.
- Keep independent PHP-version, static contract, package integrity, security and deployment checks. Docker is not a replacement for them.
- Prefer the existing `@wordpress/env` tool over writing a custom application Dockerfile or creating a separate Docker repository.
