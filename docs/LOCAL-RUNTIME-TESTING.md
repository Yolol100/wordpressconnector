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

The configuration mounts only `plugin/wordpressconnector` into a disposable WordPress 7.1.3 / PHP 8.3 environment. The GitHub workflow checks WordPress 7.1.3, PHP 8.3, a real disposable database query and three expected REST routes. Four unauthenticated HTTP route probes must return 403. With HTTPS simulated **inside the isolated WP-CLI test only**, public `/presence` must return 200 while `/health`, `/assets`, and `/execute` require authentication (401). Simulated HTTPS is not a real network/TLS transport test.

The isolated Docker check runs for changes in relevant paths on pull requests, again after those changes land on `main`, or with manual `workflow_dispatch`. Other file-only changes do not trigger this expensive runtime check. The existing main-branch Connector CI and release workflow remain separate; green Docker CI is not production deployment evidence.

## Reproduce contract QA on an authorized Windows PC

Use the existing Docker image and **exact clean Git commit**; this is optional, additional controlled-runtime evidence. It is not a second release runner or an alternative to the existing GitHub-hosted `wp-env` smoke test.

In PowerShell, from the checked-out repository root (with Git and a running Docker Engine):

```powershell
# Check out the same commit that GitHub CI tested before running this command.
$sha = (git rev-parse HEAD).Trim()
docker image inspect wordpress:php8.3-apache
.\scripts\local-docker-contracts.ps1 -ExpectedSha $sha
```

The image must already exist locally; the script never pulls. It requires clean tracked files, checks the full 40-character SHA, runs `scripts/run-contracts.sh` inside the **pre-existing** image with no network, a read-only repository bind mount, a read-only container root, dropped capabilities and a bounded temporary filesystem. A unique container is cleaned up. Only that uniquely named container may be removed; never delete shared Docker volumes, projects or other containers. No site URL, secrets, database, local WordPress installation, live plugin activation or admin credentials are required.

Results and a compact `receipt.json` are stored outside the repository under the user's temporary `wordpressconnector-qa/<run-id>/` directory (or the explicit `-EvidenceDirectory`), with exact Git SHA, image ID, Docker version, status, warning count, log name and SHA-256. Fail on non-zero exit, PHP warnings or tracked source changes. A wrong SHA must fail before starting Docker. Inspect the raw log when a failure occurs. These local logs are not automatically uploaded to public GitHub.

**Proof boundary:** this runner executes PHP contract fixtures under Docker, **not** a bootstrapped WordPress site. Use the existing GitHub `docker-runtime-smoke.yml` / `wp-env` for real disposable WordPress + database + plugin activation. A green result cannot establish a target site's OAuth/OIDC, WAF, file permissions, administration, deployment or tested restore. For a real installation or update, use separately authorized staging, an independent backup/restore test and readback; a blocked setup remains `not_run`, never `PASS`.

## Evidence and limitations

- In addition to bootstrap, REST-route and anonymous HTTP/HTTPS denial, the Docker smoke now stages a verified ZIP outside Core's cleaned `wp-content/upgrade` scratch directory and installs a **disposable verified ZIP** through the real WordPress plugin upgrader, verifies `register_activation_hook()` execution, deactivates/reactivates the installed plugin, creates and deletes a confirmed browser-active HTML asset, and cleans up the temporary plugin. This is isolated Docker WordPress/MySQL proof, **not** live hosting, managed WAF, multisite or production deployment proof.
- The real presence endpoint is **HTTPS-only**. Do not weaken that check or claim the local HTTP test proves authenticated HTTPS access.
- This does **not** prove production WordPress connectivity, GitHub OIDC signatures, Elementor/WooCommerce integration, production writes, deployment, rollback, or release compatibility across all declared PHP and WordPress versions.
- No production credentials, contact data, site URL, or request payloads are needed. Test failures block this new runtime gate but must not silently change existing trusted executor permissions.
- Keep independent PHP-version, static contract, package integrity, security and deployment checks. Docker is not a replacement for them.
- Prefer the existing `@wordpress/env` tool over writing a custom application Dockerfile or creating a separate Docker repository.
