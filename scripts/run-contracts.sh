#!/usr/bin/env bash
set -euo pipefail

find plugin/wordpressconnector tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/policy-contract.php
php tests/action-catalog.php
php tests/custom-css-contract.php
php tests/elementor-inventory-contract.php
php tests/elementor-forms-contract.php
php tests/elementor-json-export-runtime.php
php tests/elementor-json-import-contract.php
php tests/state-token-contract.php
php tests/plugin-control-contract.php
php tests/plugin-package-contract.php
php tests/connector-update-contract.php
php tests/strict-input-contract.php
php tests/payload-boolean-contract.php
php tests/security-boundary-contract.php
php tests/runtime-preflight-contract.php
php tests/filesystem-control-contract.php
php tests/repository-hygiene.php
php tests/rest-transport-contract.php
php tests/request-workflow-contract.php
php tests/execute-workflow-contract.php
php tests/public-runtime-contract.php
php tests/workflow-security-contract.php
php tests/mutation-lock-contract.php
php tests/audit-hardening-contract.php
php tests/single-plugin-contract.php
