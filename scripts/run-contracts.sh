#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compat="$root/tests/php-compat.php"
run_php() {
  php -d "auto_prepend_file=$compat" "$@"
}

find plugin/wordpressconnector tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l
run_php tests/policy-contract.php
run_php tests/github-oidc-contract.php
run_php tests/action-catalog.php
run_php tests/custom-css-contract.php
run_php tests/elementor-inventory-contract.php
run_php tests/elementor-forms-contract.php
run_php tests/elementor-json-export-runtime.php
run_php tests/elementor-json-import-contract.php
run_php tests/state-token-contract.php
run_php tests/plugin-control-contract.php
run_php tests/plugin-package-contract.php
run_php tests/connector-update-contract.php
run_php tests/strict-input-contract.php
run_php tests/payload-boolean-contract.php
run_php tests/security-boundary-contract.php
run_php tests/runtime-preflight-contract.php
run_php tests/filesystem-control-contract.php
run_php tests/repository-hygiene.php
run_php tests/rest-transport-contract.php
run_php tests/request-workflow-contract.php
run_php tests/execute-workflow-contract.php
run_php tests/public-runtime-contract.php
run_php tests/public-batch-fingerprint-contract.php
run_php tests/workflow-security-contract.php
run_php tests/mutation-lock-contract.php
run_php tests/audit-hardening-contract.php
run_php tests/single-plugin-contract.php
