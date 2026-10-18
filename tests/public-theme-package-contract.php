<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
$tmp = sys_get_temp_dir() . '/wpconnector-public-theme-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    fwrite(STDERR, "Could not create public theme validator temp directory.\n");
    exit(1);
}
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: array() as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
});

$run = static function (array $request) use ($tmp, $validator): array {
    $path = $tmp . '/' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode($request, JSON_THROW_ON_ERROR));
    $output = array();
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($validator) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    @unlink($path);
    return array($status, implode("\n", $output));
};

$request = array(
    'version' => 1,
    'site_url' => 'https://example.test',
    'request_id' => 'public-theme-dry-001',
    'action' => 'theme.install_package',
    'payload' => array(
        'source_path' => 'theme-packages/isohulk-theme.zip',
        'sha256' => str_repeat('a', 64),
        'expected_stylesheet' => 'isohulk-theme',
        'overwrite' => false,
    ),
    'dry_run' => true,
    'confirm' => false,
);
list($status, $output) = $run($request);
if (0 !== $status) {
    fwrite(STDERR, 'Safe public new-theme dry-run was rejected: ' . $output . "\n");
    exit(1);
}

$confirmed = $request;
$confirmed['request_id'] = 'public-theme-live-001';
$confirmed['dry_run'] = false;
$confirmed['confirm'] = true;
$confirmed['expected_fingerprint'] = str_repeat('b', 64);
list($status, $output) = $run($confirmed);
if (0 !== $status) {
    fwrite(STDERR, 'Confirmed public new-theme request with fingerprint was rejected: ' . $output . "\n");
    exit(1);
}

$unsafe = $request;
$unsafe['payload']['overwrite'] = true;
list($status) = $run($unsafe);
if (0 === $status) {
    fwrite(STDERR, "Public theme replacement must remain blocked.\n");
    exit(1);
}

$unsafe = $request;
$unsafe['payload']['source_path'] = 'theme-packages/../unsafe.zip';
list($status) = $run($unsafe);
if (0 === $status) {
    fwrite(STDERR, "Unsafe public theme package path was accepted.\n");
    exit(1);
}

$unsafe = $request;
$unsafe['payload']['sha256'] = 'invalid';
list($status) = $run($unsafe);
if (0 === $status) {
    fwrite(STDERR, "Public theme package without an exact SHA-256 was accepted.\n");
    exit(1);
}

echo "public guarded new-theme validator contract OK\n";
