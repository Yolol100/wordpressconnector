<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$forbidden = array('bootstrap-manifest.json', '.github/workflows/bootstrap-materialize.yml');
foreach ($forbidden as $path) {
    if (file_exists($root . '/' . $path)) {
        fwrite(STDERR, "Forbidden bootstrap residue: {$path}\n");
        exit(1);
    }
}

foreach (array('requests', 'results', 'assets/inbox') as $directory) {
    $items = array_values(array_filter(scandir($root . '/' . $directory) ?: array(), static function (string $item): bool {
        return ! in_array($item, array('.', '..', '.gitkeep'), true);
    }));
    if ($items) {
        fwrite(STDERR, "Runtime residue on default implementation tree: {$directory}\n");
        exit(1);
    }
}

echo "repository hygiene OK\n";
