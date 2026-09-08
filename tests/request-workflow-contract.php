<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/wordpress-request.yml');

$required = array(
    'pull_request:',
    "- 'requests/**'",
    "- 'assets/inbox/**'",
    "if: github.actor != 'github-actions[bot]'",
    'github.event.pull_request.head.repo.full_name',
    'WPCONNECTOR_TRUSTED_REQUEST_ACTOR',
    'github.event.pull_request.user.login',
    'github.repository_owner',
    'github.event.repository.private',
    "public_mode='true'",
    'validate-public-request.php',
    'receipts/*.json',
    'Full results are forbidden on public runtime request branches.',
    'runs-on: ubuntu-latest',
    'contents: read',
    'actions: write',
    'dispatch_trusted_execute:',
    'wordpress-execute.yml/dispatches',
    "'ref': 'main'",
    "'pr_number': os.environ['PR_NUMBER']",
    "'expected_head_sha': os.environ['EXPECTED_HEAD_SHA']",
    'Validate request PR without production credentials',
    'Exactly one requests/*.json file is required.',
    'Only regular non-executable request files are allowed:',
    '"$mode" != \'100644\'',
);
foreach ($required as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "Missing request workflow guard: {$needle}\n");
        exit(1);
    }
}

$forbidden = array(
    'pull_request_target',
    'runs-on: [self-hosted, wordpressconnector]',
    'WP_CONNECTOR_WORDPRESS_PATH',
    'WPCONNECTOR_ALLOW_PUBLIC_SELF_HOSTED',
    "- 'results/**'",
    'WPCONNECTOR_SITE_URL',
    'WPCONNECTOR_REST_USERNAME',
    'WPCONNECTOR_REST_APPLICATION_PASSWORD',
    'secrets.',
    'webactueel-wordpress-connector/v1',
    'Remote WordPress execution requires a private repository.',
    '100755',
);
foreach ($forbidden as $needle) {
    if (strpos($workflow, $needle) !== false) {
        fwrite(STDERR, "Forbidden credential or unsafe pattern in request workflow: {$needle}\n");
        exit(1);
    }
}

echo "request workflow public/private dispatch contract OK\n";
