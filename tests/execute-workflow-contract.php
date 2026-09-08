<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/wordpress-execute.yml');

$required = array(
    'workflow_dispatch:',
    'pr_number:',
    'expected_head_sha:',
    'runs-on: ubuntu-latest',
    'cancel-in-progress: false',
    'github.event.repository.private',
    "public_mode='true'",
    'WPCONNECTOR_TRUSTED_REQUEST_ACTOR',
    '/pulls/${PR_NUMBER}',
    '/commits/${head_sha}',
    'Latest request commit must be authored by the repository owner or configured trusted actor',
    'git_auth fetch --depth=1 origin main',
    'git branch trusted-main FETCH_HEAD',
    'git show "trusted-main:scripts/validate-request.php"',
    'validate-public-request.php',
    'build-public-receipt.php',
    'Exactly one requests/*.json file is required.',
    'Full results are forbidden on public runtime request branches.',
    'Only regular non-executable request files are allowed:',
    '"$mode" != \'100644\'',
    'Revalidate PR head immediately before execution',
    'Request branch moved after validation; refusing WordPress execution.',
    'WPCONNECTOR_SITE_URL',
    'secrets.WPCONNECTOR_REST_USERNAME',
    'secrets.WPCONNECTOR_REST_APPLICATION_PASSWORD',
    'webactueel-wordpress-connector/v1',
    'Verify authenticated HTTPS connector health',
    'Upload request assets over authenticated HTTPS',
    'Execute connector request over authenticated HTTPS',
    'Commit full result to private request branch',
    "steps.preflight.outputs.public_mode != 'true'",
    'Commit sanitized receipt to public request branch',
    "steps.preflight.outputs.public_mode == 'true'",
    'receipts/${REQUEST_ID}.json',
    'Request branch moved after execution; refusing public receipt write.',
    'Validate connector result without exposing response data',
    'Public readback verification failed.',
);
foreach ($required as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "Missing trusted execute workflow guard: {$needle}\n");
        exit(1);
    }
}

$forbidden = array(
    'pull_request_target',
    'runs-on: [self-hosted, wordpressconnector]',
    'WP_CONNECTOR_WORDPRESS_PATH',
    'WPCONNECTOR_ALLOW_PUBLIC_SELF_HOSTED',
    'runner_watchdog:',
    'cancel-in-progress: true',
    'Remote WordPress execution requires a private repository.',
    '$body["message"]',
    '100755',
);
foreach ($forbidden as $needle) {
    if (strpos($workflow, $needle) !== false) {
        fwrite(STDERR, "Forbidden legacy or public-leak pattern in trusted execute workflow: {$needle}\n");
        exit(1);
    }
}

if (substr_count($workflow, 'secrets.WPCONNECTOR_REST_APPLICATION_PASSWORD') < 3) {
    fwrite(STDERR, "Application Password must be scoped only to trusted transport steps.\n");
    exit(1);
}

$preflightPosition = strpos($workflow, 'Revalidate request from trusted main workflow');
$firstSecretPosition = strpos($workflow, 'secrets.WPCONNECTOR_REST_APPLICATION_PASSWORD');
if (false === $preflightPosition || false === $firstSecretPosition || $firstSecretPosition < $preflightPosition) {
    fwrite(STDERR, "Production secrets must not precede trusted PR revalidation.\n");
    exit(1);
}

echo "trusted execute workflow public/private contract OK\n";
