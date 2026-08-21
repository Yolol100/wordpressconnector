<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/wordpress-request.yml');
if ($workflow === false) {
    fwrite(STDERR, "Unable to read WordPress request workflow.\n");
    exit(1);
}

$required = array(
    "github.event.pull_request.head.repo.full_name",
    "WPCONNECTOR_TRUSTED_REQUEST_ACTOR",
    "github.event.pull_request.user.login",
    "github.repository_owner",
    "WPCONNECTOR_ALLOW_PUBLIC_SELF_HOSTED",
    "runs-on: [self-hosted, wordpressconnector]",
);
foreach ($required as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "Missing request workflow guard: {$needle}\n");
        exit(1);
    }
}

if (strpos($workflow, 'pull_request_target') !== false) {
    fwrite(STDERR, "pull_request_target is forbidden for the WordPress runtime workflow.\n");
    exit(1);
}

echo "request workflow contract OK\n";
