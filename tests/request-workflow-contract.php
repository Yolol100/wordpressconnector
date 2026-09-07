<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/wordpress-request.yml');
if ($workflow === false) {
    fwrite(STDERR, "Unable to read WordPress request workflow.\n");
    exit(1);
}

$required = array(
    'github.event.pull_request.head.repo.full_name',
    'WPCONNECTOR_TRUSTED_REQUEST_ACTOR',
    'github.event.pull_request.user.login',
    'github.repository_owner',
    "github.actor != 'github-actions[bot]'",
    'runs-on: ubuntu-latest',
    'contents: read',
    'actions: write',
    'dispatch_trusted_execute:',
    'wordpress-execute.yml/dispatches',
    "'ref': 'main'",
    "'pr_number': os.environ['PR_NUMBER']",
    "'expected_head_sha': os.environ['EXPECTED_HEAD_SHA']",
    'Validate request PR without production credentials',
);
foreach ($required as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "Missing request workflow dispatch guard: {$needle}\n");
        exit(1);
    }
}

$forbidden = array(
    'pull_request_target',
    'runs-on: [self-hosted, wordpressconnector]',
    'runner_watchdog:',
    'WP_CONNECTOR_WORDPRESS_PATH',
    'WPCONNECTOR_ALLOW_PUBLIC_SELF_HOSTED',
    "- 'results/**'",
    'WPCONNECTOR_SITE_URL',
    'WPCONNECTOR_REST_USERNAME',
    'WPCONNECTOR_REST_APPLICATION_PASSWORD',
    'secrets.',
    'webactueel-wordpress-connector/v1',
);
foreach ($forbidden as $needle) {
    if (strpos($workflow, $needle) !== false) {
        fwrite(STDERR, "Forbidden credential or legacy pattern in untrusted request workflow: {$needle}\n");
        exit(1);
    }
}

echo "request workflow dispatch contract OK\n";
