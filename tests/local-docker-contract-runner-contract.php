<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$script = (string) file_get_contents($root . '/scripts/local-docker-contracts.ps1');
$runner = (string) file_get_contents($root . '/scripts/run-contracts.sh');
if ('' === $script || '' === $runner) {
    throw new RuntimeException('Missing canonical Windows Docker QA sources.');
}
$required = array(
    'ValidatePattern(',
    'Assert-VerifiedSource',
    "Assert-VerifiedSource -Phase 'preflight'",
    "Assert-VerifiedSource -Phase 'postflight'",
    'ls-files --others --exclude-standard',
    'ls-files --others --ignored --exclude-standard',
    'Untracked PHP source input detected',
    'headAfter',
    'headStable',
    'repository_head_after_sha',
    'repository_head_unchanged',
    "imageId -notmatch '^sha256:",
    "'--network', 'none'",
    "'--read-only'",
    "'--cap-drop', 'ALL'",
    'log_sha256',
    'no_production_site_or_write_validation',
);
foreach ($required as $fragment) {
    if (false === strpos($script, $fragment)) {
        throw new RuntimeException('Windows Docker QA safety fragment missing: ' . $fragment);
    }
}
if (false === strpos($runner, 'tests/local-docker-contract-runner-contract.php') ||
    false === strpos($runner, 'tests/local-docker-runner-preflight.ps1')) {
    throw new RuntimeException('Windows Docker QA safety tests are not in canonical CI runner.');
}
echo "Windows Docker source, SHA, untracked PHP, image and execution-boundary contracts OK\n";
