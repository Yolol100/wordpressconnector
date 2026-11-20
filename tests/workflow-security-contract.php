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

// PR-controlled event fields must be passed via step env, never interpolated into shell source.
foreach (array(
    'PR_HEAD_REPO: ${{ github.event.pull_request.head.repo.full_name }}',
    'PR_REQUEST_ACTOR: ${{ github.event.pull_request.user.login }}',
    'if [[ "${PR_HEAD_REPO}" != "${GITHUB_REPOSITORY}" ]]; then',
    'request_actor="${PR_REQUEST_ACTOR}"',
) as $safeRequestMarker) {
    if (false === strpos($request, $safeRequestMarker)) {
        fwrite(STDERR, "Request workflow lost its safe untrusted-event boundary: {$safeRequestMarker}.\n");
        exit(1);
    }
}
// Reject attacker-controlled PR expressions anywhere inside a Bash run block,
// including nested expressions, not just known unsafe assignment templates.
$hasUntrustedPullRequestInline = static function (string $workflow): bool {
    $runIndent = null;
    foreach (preg_split('/\R/', $workflow) as $line) {
        $indent = strlen($line) - strlen(ltrim($line, " \t"));
        if (null !== $runIndent && '' !== trim($line) && $indent <= $runIndent) {
            $runIndent = null;
        }
        $runSource = null;
        if (preg_match('/^([ \t]*)(?:-[ \t]+)?(?:run|"run"|\x27run\x27)[ \t]*:[ \t]*(.*)$/', $line, $matches)) {
            $runIndent = strlen($matches[1]);
            $runSource = $matches[2];
        } elseif (null !== $runIndent) {
            $runSource = $line;
        }
        if (null === $runSource) { continue; }
        // GitHub Actions decodes YAML hex/Unicode escapes before evaluating templates.
        // This contract fails closed rather than trying to interpret encoded Bash.
        if (preg_match('/\\\\(?:x[0-9a-f]{2}|u[0-9a-f]{4}|U[0-9a-f]{8})/i', $runSource)) {
            return true;
        }
        if (! preg_match('/\{\{/', $runSource)) { continue; }
        // The protected Bash workflow only needs these four simple typed GitHub expressions.
        // Fail closed on all other template syntax, including format(), fromJSON(),
        // bracket access, nested/quoted braces and YAML continuation lines.
        $withoutAllowed = preg_replace(
            '/\$\{\{\s*(?:github\.repository_owner|github\.event\.repository\.private|github\.event\.pull_request\.(?:base|head)\.sha)\s*\}\}/i',
            '',
            $runSource
        );
        if (! is_string($withoutAllowed) || preg_match('/\{\{/', $withoutAllowed)) {
            return true;
        }
    }
    return false;
};
if ($hasUntrustedPullRequestInline($request)) {
    fwrite(STDERR, "PR event metadata was interpolated into Bash source.\n");
    exit(1);
}
foreach (array(
    'request_actor="${{ github.event.pull_request.user.login }}"',
    'request_actor="${{ github.event.pull_request.title }}"',
    'request_actor="${{ fromJSON(toJSON(github.event.pull_request.head.ref)) }}"',
    'request_actor="${{ github.event.pull_request[\'title\'] }}"',
    'request_actor="${{ github.event[\'pull_request\'][\'title\'] }}"',
    'request_actor="${{ github[\'event\'][\'pull_request\'][\'title\'] }}"',
    'request_actor="${{ github.event.pull_request[inputs.property] }}"',
    'request_actor="${{ format(\'{0}\', github.event.pull_request.title) }}"',
    'request_actor="${{ fromJSON(toJSON(github.event)).pull_request.title }}"',
) as $unsafeInlineExpression) {
    $tampered = str_replace('request_actor="${PR_REQUEST_ACTOR}"', $unsafeInlineExpression, $request);
    if ($tampered === $request || ! $hasUntrustedPullRequestInline($tampered)) {
        fwrite(STDERR, "Request workflow contract failed to detect an injected PR expression.\n");
        exit(1);
    }
}
// Negative fixtures must be valid one-line run steps, not only multiline Bash blocks.
$inlineRunInsertion = '      - name: Dispatch trusted zero-config WordPress executor';
foreach (array(
    '        run: echo "${{ github.event.pull_request.title }}"',
    '        run: "echo \u0024{{ github.event.pull_request.title }}"',
    '        run: "echo \x24{{ github.event.pull_request.title }}"',
    '        run: "echo \U00000024{{ github.event.pull_request.title }}"',
    '        run: "echo \u0024\u007B\u007B github.event.pull_request.title }}"',
    '        "run": echo "${{ github.event.pull_request.title }}"',
    '        \'run\': echo "${{ github.event.pull_request.title }}"',
    '        run: echo "${{ github.event.pull_request[\'title\'] }}"',
    '        run: echo "${{ github.event[\'pull_request\'][\'title\'] }}"',
    '        run: echo "${{ github[\'event\'][\'pull_request\'][\'title\'] }}"',
    '        run: echo "${{ github.event.pull_request[\'head\'][\'sha\'] }}"',
    '        run: echo "${{ format(\'{0}\', github.event.pull_request.title) }}"',
    '        run: echo "${{ fromJSON(toJSON(github.event)).pull_request.title }}"',
    '        run: echo ready' . "\n" . '          "${{ github.event.pull_request.title }}"',
) as $unsafeOneLineRun) {
    $tampered = str_replace($inlineRunInsertion, "      - name: injected inline run\n" . $unsafeOneLineRun . "\n" . $inlineRunInsertion, $request);
    if ($tampered === $request || ! $hasUntrustedPullRequestInline($tampered)) {
        fwrite(STDERR, "Request workflow contract missed an unsafe single-line run.\n");
        exit(1);
    }
}
foreach (array(
    '      - run: echo "${{ github.event.pull_request.title }}"',
    '      - "run": echo "${{ github.event.pull_request.title }}"',
) as $unsafeSequenceRun) {
    $tampered = str_replace($inlineRunInsertion, $unsafeSequenceRun . "\n" . $inlineRunInsertion, $request);
    if ($tampered === $request || ! $hasUntrustedPullRequestInline($tampered)) {
        fwrite(STDERR, "Request workflow contract missed an unsafe sequence-item run.\n");
        exit(1);
    }
}
foreach (array(
    '        run: echo "${{ github.event.pull_request.base.sha }}"',
    '        run: echo "${{ github.event.pull_request.head.sha }}"',
    '        run: echo "${{ github.repository_owner }}"',
    '        run: echo "${{ github.event.repository.private }}"',
) as $safeOneLineRun) {
    $tampered = str_replace($inlineRunInsertion, "      - name: trusted SHA inline run\n" . $safeOneLineRun . "\n" . $inlineRunInsertion, $request);
    if ($tampered === $request || $hasUntrustedPullRequestInline($tampered)) {
        fwrite(STDERR, "Request workflow contract rejected a pinned trusted expression.\n");
        exit(1);
    }
}
if (strpos($request, "if: github.actor != 'github-actions[bot]'") === false) { fwrite(STDERR, "Request workflow lacks bot-feedback-loop suppression.\n"); exit(1); }
if (strpos($request, '100755') !== false || strpos($request, "!= '100644'") === false) { fwrite(STDERR, "Credential-free request guard must accept only regular non-executable 100644 request files.\n"); exit(1); }
foreach (array('id-token: write','ACTIONS_ID_TOKEN_REQUEST_URL','X-Webactueel-GitHub-OIDC') as $needle) {
    if (strpos($execute,$needle)===false) { fwrite(STDERR,"Trusted executor lost zero-config OIDC boundary: {$needle}.\n"); exit(1); }
}
foreach (array('secrets.','WPCONNECTOR_REST_APPLICATION_PASSWORD','--user ') as $needle) {
    if (strpos($execute,$needle)!==false) { fwrite(STDERR,"Trusted zero-config executor contains forbidden long-lived credential pattern: {$needle}.\n"); exit(1); }
}
echo "workflow zero-config security contract OK\n";
