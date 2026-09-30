<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/mailbox-private-bridge.yml');
if (! is_string($workflow) || '' === $workflow) {
    fwrite(STDERR, "Unable to read mailbox private workflow.\n");
    exit(1);
}

$required = array(
    'name: Mailbox Private Bridge',
    'issues:',
    'github.event.repository.private == true',
    'github.event.issue.user.login == github.repository_owner',
    "[mailbox-store]",
    "[mailbox-result]",
    "[mailbox-clear]",
    'group: mailbox-private-${{ fromJSON(github.event.issue.body).request_id }}',
    'id-token: write',
    'issues: write',
    'GH_REPO: ${{ github.repository }}',
    'https://andrewbaeten.nl',
    'webactueel-mailbox-bridge/v1',
    'X-Webactueel-GitHub-OIDC',
    'ACTIONS_ID_TOKEN_REQUEST_URL',
    'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a',
    'retention-days: 1',
    'isinstance(ttl, bool)',
    'not isinstance(ttl, int)',
    'gh issue edit "$ISSUE_NUMBER" --body',
    'unset token',
    'rm -rf "$RUNNER_TEMP/mailbox-private"',
);

foreach ($required as $needle) {
    if (false === strpos($workflow, $needle)) {
        fwrite(STDERR, "Missing private mailbox transport boundary: {$needle}\n");
        exit(1);
    }
}

$forbidden = array(
    'actions/upload-artifact@v',
    'oidc-token.txt',
    'cat "$RUNNER_TEMP/mailbox-private/store.json"',
    'cat "$RUNNER_TEMP/mailbox-private/result.json"',
    'tee "$RUNNER_TEMP/mailbox-private',
    'OUTREACH_MAIL_PASSWORD',
    'OUTREACH_SMTP_PASSWORD',
    'OUTREACH_SMTP_SEND_ENABLED',
    'allowed = {',
    'confirm_send=true',
    'destructive action requires confirm=true',
);

foreach ($forbidden as $needle) {
    if (false !== strpos($workflow, $needle)) {
        fwrite(STDERR, "Forbidden private mailbox transport pattern: {$needle}\n");
        exit(1);
    }
}

if (substr_count($workflow, 'ACTIONS_ID_TOKEN_REQUEST_URL') !== 3) {
    fwrite(STDERR, "Each mutually exclusive mailbox transport operation must mint its own in-memory OIDC token.\n");
    exit(1);
}

echo "mailbox private workflow contract OK\n";
