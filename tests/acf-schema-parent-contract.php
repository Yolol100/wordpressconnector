<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/AcfAdapter.php');
$structure = preg_replace('/\\s+/', '', $source);
if (! is_string($structure)) { throw new RuntimeException('Could not normalize ACF schema source.'); }

$required = array(
    '$groupId = $this->groupId($groupKey);',
    '\'parent\' => $groupId',
    'private function groupId(string $groupKey): int',
    'private function fieldMatchesDefinition(array $summary, array $definition, int $groupId): bool',
    '0 !== (int) ($summary[\'parent\'] ?? 0)',
    '$recoverable[$key] = $globalId;',
    '$field[\'ID\'] = (int) $recoverable[$definition[\'key\']];',
);

foreach ($required as $needle) {
    if (false === strpos($structure, preg_replace('/\\s+/', '', $needle))) {
        fwrite(STDERR, 'Missing ACF schema parent/orphan guard: ' . $needle . PHP_EOL);
        exit(1);
    }
}

if (false !== strpos($structure, preg_replace('/\\s+/', '', '\'parent\' => $groupKey'))) {
    fwrite(STDERR, "ACF schema writes must not persist a group key as field parent.\n");
    exit(1);
}

if (false !== strpos($structure, preg_replace('/\\s+/', '', 'private function fieldMatchesDefinition(array $summary, array $definition, string $groupKey): bool'))) {
    fwrite(STDERR, "ACF schema readback must compare the persistent numeric group parent.\n");
    exit(1);
}

echo "acf schema parent contract OK\n";
