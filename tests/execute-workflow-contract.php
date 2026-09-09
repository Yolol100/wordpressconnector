<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/wordpress-zero-config-execute.yml');
$required = array(
    'workflow_dispatch:',
    'pr_number:',
    'expected_head_sha:',
    'runs-on: ubuntu-latest',
    'cancel-in-progress: false',
    'id-token: write',
    'github.event.repository.private',
    'github.repository_owner',
    '/pulls/${PR_NUMBER}',
    'git branch trusted-main FETCH_HEAD',
    'validate-request.php',
    'validate-public-request.php',
    'build-public-receipt.php',
    'Exactly one request JSON is required.',
    'site_url',
    '/presence',
    'ACTIONS_ID_TOKEN_REQUEST_URL',
    'ACTIONS_ID_TOKEN_REQUEST_TOKEN',
    'X-Webactueel-GitHub-OIDC',
    'Execute with short-lived GitHub OIDC',
    'Persist sanitized public receipt',
    'Persist private result',
);
foreach ($required as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "Missing zero-config execute workflow guard: {$needle}\n");
        exit(1);
    }
}
$forbidden = array(
    'pull_request_target',
    'self-hosted',
    'WPCONNECTOR_SITE_URL',
    'WPCONNECTOR_REST_USERNAME',
    'WPCONNECTOR_REST_APPLICATION_PASSWORD',
    'secrets.',
    '--user ',
    'REST_APP_PASSWORD',
);
foreach ($forbidden as $needle) {
    if (strpos($workflow, $needle) !== false) {
        fwrite(STDERR, "Forbidden long-lived credential or unsafe pattern in zero-config executor: {$needle}\n");
        exit(1);
    }
}
$preflight = strpos($workflow, 'Validate trusted runtime request');
$oidc = strpos($workflow, 'ACTIONS_ID_TOKEN_REQUEST_TOKEN');
if (false === $preflight || false === $oidc || $oidc < $preflight) {
    fwrite(STDERR, "OIDC token access must happen only after trusted request validation.\n");
    exit(1);
}
echo "zero-config execute workflow contract OK\n";
