# Setup

## 1. Repository

Keep the GitHub repository **private**. The remote WordPress execution job uses production WordPress credentials and refuses public-repository execution.

No self-hosted GitHub Actions runner or VPS is required for the default transport. GitHub provisions an `ubuntu-latest` VM automatically for each request job.

## 2. Install the WordPress Connector plugin

Install `plugin/wordpressconnector` in `wp-content/plugins/wordpressconnector` and activate it.

Version 1.1.0 registers:

- authenticated HTTPS REST transport under `/wp-json/webactueel-wordpress-connector/v1/`;
- the same semantic action registry used by the local WP-CLI command;
- `Settings -> WordPress Connector` for execution gates;
- optional local `wp wordpress-connector ...` commands when WP-CLI is available.

The REST route is not a remote shell. It executes only registered connector actions.

## 3. WordPress security configuration

Open `Settings -> WordPress Connector` as an administrator.

Recommended initial state:

| Gate | Initial value | Enable when |
| --- | --- | --- |
| HTTPS REST transport | on | required for GitHub-hosted execution |
| confirmed writes | off | after read-only and dry-run verification |
| privileged actions | off | needed for post meta/options/users and similar administration actions |
| sensitive actions | off | only for an explicitly approved private sensitive-data workflow |
| system updates | off | only for approved plugin/theme/core lifecycle operations |

All REST endpoints additionally require:

- HTTPS;
- an authenticated WordPress user;
- `manage_options` capability.

## 4. Create a dedicated Application Password

Use a dedicated WordPress administrator/service account where practical.

In WordPress, open the user profile and create an Application Password named for this integration, for example `GitHub WordPress Connector`.

Store the generated password immediately. WordPress shows it only once. Do not use the account's normal login password in GitHub.

## 5. Configure GitHub

Under `Settings -> Secrets and variables -> Actions` configure:

### Variable

| Name | Value |
| --- | --- |
| `WPCONNECTOR_SITE_URL` | canonical site URL including `https://`, for example `https://example.com` |

### Secrets

| Name | Value |
| --- | --- |
| `WPCONNECTOR_REST_USERNAME` | WordPress username for the dedicated connector account |
| `WPCONNECTOR_REST_APPLICATION_PASSWORD` | the generated WordPress Application Password |

Optional variable:

| Name | Purpose |
| --- | --- |
| `WPCONNECTOR_TRUSTED_REQUEST_ACTOR` | exact GitHub App bot login allowed to submit same-repository request PRs; leave unset when only the repository owner submits requests |

The trusted actor allowance never bypasses same-repository origin, private-repository enforcement, request schema validation, path restrictions or WordPress authentication/authorization.

## 6. How a request runs

Create a branch from `main`, add exactly one JSON request under `requests/`, optionally add request assets under `assets/inbox/`, and open a PR.

The workflow performs:

1. GitHub-hosted guard job validates actor, repository visibility, changed paths, request count, request size, asset limits and JSON schema.
2. GitHub automatically provisions a fresh `ubuntu-latest` runner for `execute`.
3. The runner authenticates to `/health` over HTTPS with the Application Password.
4. Optional assets are uploaded to a request-scoped temporary WordPress directory.
5. The exact validated request JSON is sent to `/execute`.
6. WordPress runs the existing policy, dry-run, confirmation, fingerprint, idempotency and rollback logic.
7. The result JSON is committed to the request branch under `results/`.
8. Result-only commits do not retrigger the workflow.
9. GitHub destroys the temporary hosted runner after the job.

## 7. Limits

- one `requests/*.json` per runtime PR;
- request JSON max 256 KiB;
- max 10 assets / 25 MiB total per request;
- max 20 MiB per individual uploaded asset;
- REST requires HTTPS and `manage_options`;
- no generic shell, arbitrary SQL, arbitrary filesystem path, eval or HTTP-proxy action exists.

## 8. Acceptance sequence

1. Keep all mutation gates off and verify authenticated `/health`.
2. Run `connector.discover` read-only.
3. Run `system.doctor` read-only.
4. Read one published normal page.
5. Inspect one Gutenberg page.
6. Inspect one Elementor page/template.
7. Read one WooCommerce product and variation.
8. Read one ACF-backed item.
9. Dry-run one post, Elementor, WooCommerce and ACF mutation.
10. On staging, enable confirmed writes plus only the privileged gate required by the test and perform a disposable write + rollback.
11. Import one disposable image through `assets/inbox/`, update/assign it, then rollback/remove it.
12. Run the representative test set twice after the last code/config change.
13. Enable production write gates only after the target runtime passes.

## 9. Optional local WP-CLI recovery

If the hosting environment exposes WP-CLI, the plugin still supports:

```bash
wp --path=/path/to/wordpress wordpress-connector doctor
wp --path=/path/to/wordpress wordpress-connector actions
wp --path=/path/to/wordpress wordpress-connector run request.json --output=result.json --asset-root=assets/inbox
```

This is optional. The GitHub request workflow itself no longer requires a persistent runner, systemd, SSH or a VPS.
