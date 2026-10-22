<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflowDir = $root . '/.github/workflows';
$files = glob($workflowDir . '/*.{yml,yaml}', GLOB_BRACE) ?: array();
if (! $files) { fwrite(STDERR, "No GitHub Actions workflows found.\n"); exit(1); }
foreach ($files as $file) {
    $relative = str_replace($root . '/', '', $file);
    $source = (string) file_get_contents($file);
    if (strpos($source, 'pull_request_target') !== false && basename($file) !== 'required-pr-gate.yml') {
        fwrite(STDERR, "Forbidden pull_request_target in {$relative}.\n");
        exit(1);
    }
    foreach (preg_split('/\R/', $source) as $line) {
        if (! preg_match('/^\s*uses:\s*([^\s#]+)(?:\s+#.*)?$/', $line, $matches)) { continue; }
        $uses = trim($matches[1]);
        if (strpos($uses, './') === 0 || strpos($uses, 'docker://') === 0) { continue; }
        if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[a-f0-9]{40}$/D', $uses)) { fwrite(STDERR, "External action is not pinned to a full commit SHA in {$relative}: {$uses}\n"); exit(1); }
    }
}
// This is the ONLY allowed pull_request_target workflow. It is safe solely
// while every executable byte is from the trusted base, never the PR head.
$trustedGate = (string) file_get_contents($workflowDir . '/required-pr-gate.yml');
$trustedGateIsSafe = static function (string $source): bool {
    foreach (array(
        "on:\n  pull_request_target:\n",
        'name: Required PR Gate',
        'ref: ${{ github.event.pull_request.base.sha }}',
        'persist-credentials: false',
        'contents: read',
        'actions: read',
        'pull-requests: read',
        'GITHUB_TOKEN: ${{ github.token }}',
        'EXPECTED_HEAD_SHA: ${{ github.event.pull_request.head.sha }}',
        'PR_NUMBER: ${{ github.event.pull_request.number }}',
    ) as $required) {
        if (false === strpos($source, $required)) {
            return false;
        }
    }
    if (1 !== substr_count($source, 'pull_request_target:')
        || 1 !== substr_count($source, 'uses:')
        || 1 !== substr_count($source, 'ref: ${{ github.event.pull_request.base.sha }}')
        || false !== strpos($source, 'secrets.')
        || preg_match('/(?m)^\s*(contents|actions|pull-requests|id-token|checks|statuses):\s*write\s*$/', $source)) {
        return false;
    }
    if (! preg_match_all('/(?m)^\s*run:\s*([^\r\n]+)$/', $source, $runs)) {
        return false;
    }
    return $runs[1] === array(
        'python3 -B tests/required-pr-gate-tests.py',
        'python3 -B scripts/verify-required-pr-gate.py',
    );
};
if (! $trustedGateIsSafe($trustedGate)) {
    fwrite(STDERR, "Trusted required PR gate lost its base-only read-only execution contract.\n");
    exit(1);
}
foreach (array(
    str_replace('ref: ${{ github.event.pull_request.base.sha }}', 'ref: ${{ github.event.pull_request.head.sha }}', $trustedGate),
    str_replace('contents: read', 'contents: write', $trustedGate),
    str_replace('persist-credentials: false', 'persist-credentials: true', $trustedGate),
    str_replace('run: python3 -B tests/required-pr-gate-tests.py', 'run: bash attacker.sh', $trustedGate),
) as $unsafeVariant) {
    if ($trustedGateIsSafe($unsafeVariant)) {
        fwrite(STDERR, "Trusted required PR gate accepted an unsafe mutation.\n");
        exit(1);
    }
}

$request = (string) file_get_contents($workflowDir . '/wordpress-request.yml');
$execute = (string) file_get_contents($workflowDir . '/wordpress-zero-config-execute.yml');
if (strpos($request, "if: github.actor != 'github-actions[bot]'") === false) { fwrite(STDERR, "Request workflow lacks bot-feedback-loop suppression.\n"); exit(1); }
if (strpos($request, '100755') !== false || strpos($request, "!= '100644'") === false) { fwrite(STDERR, "Credential-free request guard must accept only regular non-executable 100644 request files.\n"); exit(1); }
foreach (array('id-token: write','ACTIONS_ID_TOKEN_REQUEST_URL','X-Webactueel-GitHub-OIDC') as $needle) {
    if (strpos($execute,$needle)===false) { fwrite(STDERR,"Trusted executor lost zero-config OIDC boundary: {$needle}.\n"); exit(1); }
}
foreach (array('secrets.','WPCONNECTOR_REST_APPLICATION_PASSWORD','--user ') as $needle) {
    if (strpos($execute,$needle)!==false) { fwrite(STDERR,"Trusted zero-config executor contains forbidden long-lived credential pattern: {$needle}.\n"); exit(1); }
}
echo "workflow zero-config security contract OK\n";
