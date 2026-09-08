<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflowDir = $root . '/.github/workflows';
$files = glob($workflowDir . '/*.{yml,yaml}', GLOB_BRACE) ?: array();
if (! $files) {
    fwrite(STDERR, "No GitHub Actions workflows found.\n");
    exit(1);
}

foreach ($files as $file) {
    $relative = str_replace($root . '/', '', $file);
    $source = (string) file_get_contents($file);
    if (strpos($source, 'pull_request_target') !== false) {
        fwrite(STDERR, "Forbidden pull_request_target in {$relative}.\n");
        exit(1);
    }
    foreach (preg_split('/\R/', $source) as $line) {
        if (! preg_match('/^\s*uses:\s*([^\s#]+)(?:\s+#.*)?$/', $line, $matches)) {
            continue;
        }
        $uses = trim($matches[1]);
        if (strpos($uses, './') === 0 || strpos($uses, 'docker://') === 0) {
            continue;
        }
        if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[a-f0-9]{40}$/D', $uses)) {
            fwrite(STDERR, "External action is not pinned to a full commit SHA in {$relative}: {$uses}\n");
            exit(1);
        }
    }
}

$request = (string) file_get_contents($workflowDir . '/wordpress-request.yml');
$execute = (string) file_get_contents($workflowDir . '/wordpress-execute.yml');
if (strpos($request, "if: github.actor != 'github-actions[bot]'") === false) {
    fwrite(STDERR, "Request workflow lacks bot-feedback-loop suppression.\n");
    exit(1);
}
if (strpos($request, '100755') !== false || strpos($request, "!= '100644'") === false) {
    fwrite(STDERR, "Credential-free request guard must accept only regular non-executable 100644 request files.\n");
    exit(1);
}
foreach (array('100644', '100755', 'Symlinks/submodules/special file modes are not allowed') as $needle) {
    if (strpos($execute, $needle) === false) {
        fwrite(STDERR, "Trusted executor lost its data-only file-mode/special-file boundary: {$needle}.\n");
        exit(1);
    }
}

echo "workflow security contract OK\n";
