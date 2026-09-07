<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$forbidden = array(
    'bootstrap-manifest.json',
    '.github/workflows/bootstrap-materialize.yml',
    '.github/workflows/package-wordpressconnector-temp.yml',
    '.github/workflows/wordpress-request.yml',
    '.github/workflows/wordpress-execute.yml',
    'scripts/validate-request.php',
    'schemas/request.schema.json',
    'schemas/result.schema.json',
    'tests/request-workflow-contract.php',
    'tests/execute-workflow-contract.php',
);
foreach ($forbidden as $path) {
    if (file_exists($root . '/' . $path)) {
        fwrite(STDERR, "Forbidden legacy/temporary residue: {$path}\n");
        exit(1);
    }
}
foreach (array('requests', 'results', 'assets/inbox', 'examples') as $directory) {
    $path = $root . '/' . $directory;
    if (! is_dir($path)) { continue; }
    $items = array_values(array_filter(scandir($path) ?: array(), static function (string $item): bool { return ! in_array($item, array('.', '..'), true); }));
    if ($items) {
        fwrite(STDERR, "Legacy/runtime residue on default implementation tree: {$directory}\n");
        exit(1);
    }
}
echo "repository hygiene OK\n";
