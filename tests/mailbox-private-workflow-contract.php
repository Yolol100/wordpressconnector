<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/mailbox-private-bridge.yml');
if (! is_string($workflow) || '' === $workflow) {
    fwrite(STDERR, "Unable to read mailbox private workflow.\n");
    exit(1);
}

$required = array(
    "name: Mailbox Private Bridge",
    "issues:",
    "github.event.issue.user.login == github.repository_owner",
    "[mailbox-store]",
    "[mailbox-result]",
    "[mailbox-clear]",
    "id-token: write",
    "issues: write",
    "https://andrewbaeten.nl",
    "webactueel-mailbox-bridge/v1",
    "X-Webactueel-GitHub-OIDC",
    "actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a",
    "retention-days: 1",
    "confirm_send=true",
    "destructive action requires confirm=true",
    'rm -rf "$RUNNER_TEMP/mailbox-private"',
);

foreach ($required as $needle) {
    if (false === strpos($workflow, $needle)) {
        fwrite(STDERR, "Missing private mailbox workflow boundary: {$needle}\n");
        exit(1);
    }
}

$forbidden = array(
    "actions/upload-artifact@v",
    "cat \"$RUNNER_TEMP/mailbox-private/store.json\"",
    "cat \"$RUNNER_TEMP/mailbox-private/result.json\"",
    "tee \"$RUNNER_TEMP/mailbox-private",
    "OUTREACH_MAIL_PASSWORD",
    "OUTREACH_SMTP_PASSWORD",
    "OUTREACH_SMTP_SEND_ENABLED",
);

foreach ($forbidden as $needle) {
    if (false !== strpos($workflow, $needle)) {
        fwrite(STDERR, "Forbidden private mailbox workflow pattern: {$needle}\n");
        exit(1);
    }
}

if (substr_count($workflow, "ACTIONS_ID_TOKEN_REQUEST_URL") !== 1) {
    fwrite(STDERR, "Private mailbox workflow must mint exactly one OIDC token per run.\n");
    exit(1);
}

echo "mailbox private workflow contract OK\n";
