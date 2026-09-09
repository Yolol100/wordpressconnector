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
    'WPCONNECTOR_TRUSTED_REQUEST_ACTOR',
    '/pulls/${PR_NUMBER}',
    '/commits/${head_sha}',
    'Latest request commit must be authored by the repository owner or configured trusted actor.',
    'git branch trusted-main FETCH_HEAD',
    'validate-request.php',
    'validate-public-request.php',
    'build-public-receipt.php',
    'Exactly one request JSON is required.',
    'Asset limit exceeded (max 10 files / 25 MiB total).',
    'assets/inbox/*',
    'git show "request-head:$path" > "$root/assets/inbox/$relative"',
    'site_url',
    '/presence',
    'Upload request assets with short-lived GitHub OIDC',
    '--form-string "request_id=${REQUEST_ID}"',
    '--form-string "asset_path=${relative}"',
    'ACTIONS_ID_TOKEN_REQUEST_URL',
    'ACTIONS_ID_TOKEN_REQUEST_TOKEN',
    'X-Webactueel-GitHub-OIDC',
    'Revalidate request head before execution',
    'Request branch moved after validation; refusing execution.',
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
$revalidation = strpos($workflow, 'Revalidate request head before execution');
$execute = strpos($workflow, 'Execute with short-lived GitHub OIDC');
if (false === $preflight || false === $revalidation || false === $execute || $revalidation < $preflight || $execute < $revalidation) {
    fwrite(STDERR, "Trusted preflight and exact head revalidation must precede OIDC execution.\n");
    exit(1);
}
if (substr_count($workflow, 'ACTIONS_ID_TOKEN_REQUEST_TOKEN') < 2) {
    fwrite(STDERR, "OIDC must cover both request-scoped asset transport and final execution without persistent credentials.\n");
    exit(1);
}
echo "zero-config execute workflow contract OK\n";
