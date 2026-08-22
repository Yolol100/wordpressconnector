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
    'runs-on: ubuntu-latest',
    'WPCONNECTOR_SITE_URL',
    'secrets.WPCONNECTOR_REST_USERNAME',
    'secrets.WPCONNECTOR_REST_APPLICATION_PASSWORD',
    'webactueel-wordpress-connector/v1',
    'Verify authenticated HTTPS connector health',
    'Upload request assets over authenticated HTTPS',
    'Execute connector request over authenticated HTTPS',
    'case "$SITE_URL" in',
    'https://*',
);
foreach ($required as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "Missing request workflow REST guard: {$needle}\n");
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
);
foreach ($forbidden as $needle) {
    if (strpos($workflow, $needle) !== false) {
        fwrite(STDERR, "Forbidden legacy or privileged workflow pattern: {$needle}\n");
        exit(1);
    }
}

echo "request workflow REST contract OK\n";
