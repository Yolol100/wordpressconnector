<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/wordpress-execute.yml');
if ($workflow === false) {
    fwrite(STDERR, "Unable to read trusted WordPress execute workflow.\n");
    exit(1);
}

$required = array(
    'workflow_dispatch:',
    'pr_number:',
    'expected_head_sha:',
    'runs-on: ubuntu-latest',
    'cancel-in-progress: false',
    'github.event.repository.private',
    'WPCONNECTOR_TRUSTED_REQUEST_ACTOR',
    '/pulls/${PR_NUMBER}',
    '/commits/${head_sha}',
    'Latest request commit must be authored by the repository owner or configured trusted actor',
    'git_auth fetch --depth=1 origin main',
    'git branch trusted-main FETCH_HEAD',
    'git show "trusted-main:scripts/validate-request.php"',
    'Exactly one requests/*.json file is required.',
    'assets/inbox/*)',
    'results/*.json)',
    'Revalidate PR head immediately before execution',
    'Request branch moved after validation; refusing WordPress execution.',
    'WPCONNECTOR_SITE_URL',
    'secrets.WPCONNECTOR_REST_USERNAME',
    'secrets.WPCONNECTOR_REST_APPLICATION_PASSWORD',
    'webactueel-wordpress-connector/v1',
    'Verify authenticated HTTPS connector health',
    'Upload request assets over authenticated HTTPS',
    'Execute connector request over authenticated HTTPS',
    'Commit result to exact request branch',
    'Request branch moved after execution; refusing result write.',
);
foreach ($required as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "Missing trusted execute workflow guard: {$needle}\n");
        exit(1);
    }
}

$forbidden = array(
    'pull_request_target',
    'pull_request:',
    'runs-on: [self-hosted, wordpressconnector]',
    'WP_CONNECTOR_WORDPRESS_PATH',
    'WPCONNECTOR_ALLOW_PUBLIC_SELF_HOSTED',
    'runner_watchdog:',
    'cancel-in-progress: true',
);
foreach ($forbidden as $needle) {
    if (strpos($workflow, $needle) !== false) {
        fwrite(STDERR, "Forbidden legacy or untrusted trigger in trusted execute workflow: {$needle}\n");
        exit(1);
    }
}

if (substr_count($workflow, 'secrets.WPCONNECTOR_REST_APPLICATION_PASSWORD') < 3) {
    fwrite(STDERR, "Trusted execute workflow must scope the Application Password only to transport steps.\n");
    exit(1);
}

$preflightPosition = strpos($workflow, 'Revalidate request from trusted main workflow');
$firstSecretPosition = strpos($workflow, 'secrets.WPCONNECTOR_REST_APPLICATION_PASSWORD');
if (false === $preflightPosition || false === $firstSecretPosition || $firstSecretPosition < $preflightPosition) {
    fwrite(STDERR, "Production secrets must not precede trusted PR revalidation.\n");
    exit(1);
}

echo "trusted execute workflow contract OK\n";
