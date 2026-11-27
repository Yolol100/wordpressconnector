<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/class-acfadapter.php');
$source .= (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/trait-acfadapteractions.php');
$sourceCompact = preg_replace('/\\s+/', '', $source);
if (! is_string($sourceCompact)) { throw new RuntimeException('Could not normalize ACF schema source.'); }

$required = array(
    '$group_id = $this->group_id($group_key);',
    "'parent' => \$group_id",
    'private function group_id(string $group_key): int',
    'private function field_matches_definition(array $summary, array $definition, int $group_id): bool',
    "0 !== (int) (\$summary['parent'] ?? 0)",
    "\$recoverable[\$key] = \$global_id;",
    "\$field['ID'] = (int) \$recoverable[\$definition['key']];",
);

foreach ($required as $needle) {
    if (false === strpos($sourceCompact, preg_replace('/\\s+/', '', $needle))) {
        fwrite(STDERR, 'Missing ACF schema parent/orphan guard: ' . $needle . PHP_EOL);
        exit(1);
    }
}

if (false !== strpos($sourceCompact, "'parent'=>\$group_key")) {
    fwrite(STDERR, "ACF schema writes must not persist a group key as field parent.\n");
    exit(1);
}

if (false !== strpos($sourceCompact, 'privatefunctionfield_matches_definition(array$summary,array$definition,string$group_key):bool')) {
    fwrite(STDERR, "ACF schema readback must compare the persistent numeric group parent.\n");
    exit(1);
}

echo "acf schema parent contract OK\n";
