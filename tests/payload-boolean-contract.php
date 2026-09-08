<?php

declare(strict_types=1);

$root = dirname(__DIR__) . '/plugin/wordpressconnector';
$violations = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file->isFile() || 'php' !== strtolower((string) $file->getExtension())) {
        continue;
    }
    $path = $file->getPathname();
    $relative = str_replace(dirname(__DIR__) . '/', '', $path);
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (! is_array($lines)) {
        continue;
    }
    foreach ($lines as $index => $line) {
        $unsafe = preg_match('/!\s*empty\s*\(\s*\$payload\s*\[/', $line)
            || preg_match('/\(bool\)\s*\$payload\s*\[/', $line)
            || preg_match('/\(bool\)\s*\(\s*\$payload\s*\[/', $line);
        if ($unsafe) {
            $violations[] = $relative . ':' . ($index + 1) . ': ' . trim($line);
        }
    }
}

if ($violations) {
    fwrite(STDERR, "Direct payload boolean coercion is forbidden; use a strict boolean validator.\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    exit(1);
}

echo "payload boolean contract OK\n";
