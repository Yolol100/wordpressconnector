#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compat="$root/tests/php-compat.php"
run_php() {
  php -d "auto_prepend_file=$compat" "$@"
}
# Local Docker has PHP only. The SHA-checked Windows runner must execute
# deferred Python tests on the host. CI never enables this alternate mode.
run_python() {
  if [[ "${WPCONNECTOR_LOCAL_DOCKER_SPLIT_PYTHON:-}" == "1" ]]; then
    printf 'LOCAL_PYTHON_DEFERRED %s\n' "$1"
  else
    python3 -B "$@"
  fi
}

find plugin/wordpressconnector tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l
run_php tests/policy-contract.php
run_php tests/policy-asset-symlink-contract.php
run_php tests/json-atomic-write-contract.php
run_php tests/fingerprint-runtime-contract.php
run_php tests/input-strict-boolean-contract.php
run_php tests/asset-private-permissions-contract.php
run_php tests/asset-base-symlink-contract.php
run_php tests/rest-asset-cleanup-contract.php
run_php tests/rest-asset-upload-error-contract.php
run_php tests/github-oidc-contract.php
run_php tests/oauth-resource-contract.php
run_php tests/oauth-unconfigured-contract.php
run_php tests/oauth-native-contract.php
run_php tests/release-owner-approval-contract.php
run_php tests/action-catalog.php
run_php tests/cli-helper-contract.php
run_php tests/abilities-catalog-contract.php
run_php tests/rest-discovery-contract.php
run_php tests/plugin-adapter-catalog-contract.php
run_php tests/plugin-audit-contract.php
run_php tests/public-plugin-audit-transport-contract.php
run_php tests/abilities-provider-provenance-contract.php
run_php tests/approved-plugin-abilities-contract.php
run_php tests/abilities-mutation-idempotency-contract.php
run_php tests/woocommerce-orders-read-contract.php
run_php tests/woocommerce-catalog-read-contract.php
run_php tests/woocommerce-product-factory-contract.php
run_php tests/woocommerce-settings-contract.php
run_php tests/woocommerce-operations-contract.php
run_php tests/woocommerce-variation-readback-contract.php
run_php tests/woocommerce-fulfillment-contract.php
run_php tests/user-password-reset-contract.php
run_php tests/custom-css-contract.php
run_php tests/public-custom-css-contract.php
run_php tests/code-snippets-contract.php
run_php tests/code-snippets-catalog-preset-contract.php
run_php tests/cache-maintenance-contract.php
run_php tests/cache-loopback-security-contract.php
run_php tests/elementor-inventory-contract.php
run_php tests/elementor-forms-contract.php
run_php tests/elementor-json-export-runtime.php
run_php tests/elementor-json-import-contract.php
run_php tests/elementor-bulk-import-contract.php
run_php tests/media-rename-contract.php
run_php tests/media-assignment-safety-contract.php
run_php tests/state-token-contract.php
run_php tests/read-batch-contract.php
run_php tests/mutation-batch-preflight-contract.php
run_php tests/diagnostics-contract.php
run_php tests/system-doctor-runtime-contract.php
run_php tests/connector-core-backup-runtime.php
run_php tests/plugin-control-contract.php
run_php tests/plugin-list-multisite-contract.php
run_php tests/joinchat-settings-contract.php
run_php tests/gtranslate-settings-contract.php
run_php tests/optimizer-settings-contract.php
run_php tests/yoast-site-representation-contract.php
run_php tests/yoast-template-settings-contract.php
run_php tests/public-yoast-template-runner-contract.php
run_php tests/public-yoast-snippet-contract.php
run_php tests/yoast-feature-settings-contract.php
run_php tests/yoast-native-abilities-contract.php
run_php tests/plugin-package-contract.php
run_php tests/connector-update-contract.php
# Keep new release-supply-chain regressions in the existing CI contract runner,
# even before protected .github/workflows/ci.yml governance changes are merged.
run_python tests/release-supply-chain-tests.py
run_python tests/wpcs-regression-gate-tests.py
run_php tests/connector-update-preview-identity-contract.php
run_php tests/release-protected-source-contract.php
run_php tests/native-update-contract.php
run_php tests/connector-self-update-activation-contract.php
run_php tests/direct-mcp-credential-admin-contract.php
run_php tests/direct-mcp-admin-runtime.php
run_python tests/plan-readonly-audit-tests.py
run_python tests/plan-fleet-release-tests.py
run_php tests/connection-readiness-contract.php
run_php tests/mcp-admin-connection-doctor-contract.php
run_php tests/admin-ui-contract.php
if command -v node >/dev/null 2>&1; then
  node --check plugin/wordpressconnector/assets/connection-doctor.js
  node --check plugin/wordpressconnector/assets/admin-ui.js
  node tests/mcp-admin-connection-doctor.js
  node tests/admin-ui-navigation.js
fi
run_php tests/strict-input-contract.php
run_php tests/payload-boolean-contract.php
run_php tests/security-boundary-contract.php
run_php tests/runtime-preflight-contract.php
run_php tests/local-docker-contract-runner-contract.php
run_php tests/filesystem-control-contract.php
run_php tests/plugin-directory-file-lifecycle-contract.php
run_php tests/theme-lifecycle-readback-contract.php
run_php tests/repository-hygiene.php
run_php tests/rest-transport-contract.php
run_php tests/mcp-transport-contract.php
run_php tests/site-editor-contract.php
run_php tests/request-workflow-contract.php
run_php tests/execute-workflow-contract.php
run_php tests/public-runtime-contract.php
run_php tests/public-plugin-package-contract.php
run_php tests/public-theme-package-contract.php
run_php tests/public-broad-actions-contract.php
run_php tests/public-request-server-boundary-contract.php
run_php tests/public-post-read-contract.php
run_php tests/public-post-update-target-contract.php
run_php tests/core-post-field-id-contract.php
run_php tests/core-revision-identity-contract.php
run_php tests/system-user-id-contract.php
run_php tests/system-multisite-id-contract.php
run_php tests/core-term-assignment-contract.php
run_php tests/core-term-target-identity-contract.php
run_php tests/core-menu-identity-contract.php
run_php tests/core-comment-identity-contract.php
run_php tests/public-post-literal-replace-contract.php
run_php tests/public-site-identity-contract.php
run_php tests/public-provider-settings-contract.php
run_php tests/public-elementor-runtime-contract.php
run_php tests/public-elementor-target-boundary-contract.php
run_php tests/public-header-link-policy-contract.php
run_php tests/public-acf-schema-contract.php
run_php tests/public-acf-update-boundary-contract.php
run_php tests/portfolio-stats-unpublished-contract.php
run_php tests/portfolio-stats-public-runtime-contract.php
run_php tests/portfolio-stats-public-transport-contract.php
run_php tests/portfolio-case-text-public-runtime-contract.php
run_php tests/public-validator-trusted-workspace-contract.php
run_php tests/public-receipt-self-contained-contract.php
run_php tests/acf-schema-parent-contract.php
run_php tests/acf-complex-schema-contract.php
run_php tests/acf-complex-schema-runtime.php
run_php tests/workflow-security-contract.php
run_php tests/mutation-lock-contract.php
run_php tests/durable-persistence-contract.php
run_php tests/audit-hardening-contract.php
run_php tests/single-plugin-contract.php
if command -v pwsh >/dev/null 2>&1; then
  pwsh -NoProfile -NonInteractive -File tests/local-docker-runner-preflight.ps1
fi
