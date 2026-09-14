<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
$tmp = sys_get_temp_dir() . '/wpconnector-public-broad-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    fwrite(STDERR, "Could not create temporary directory.\n");
    exit(1);
}

$write = static function (string $name, array $request) use ($tmp): string {
    $path = $tmp . '/' . $name;
    file_put_contents($path, json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    return $path;
};

$run = static function (string $path) use ($validator): array {
    $output = array();
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($validator) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    return array($status, implode("\n", $output));
};

$base = array(
    'version' => 1,
    'request_id' => 'public-broad-2001',
    'action' => 'media.list',
    'dry_run' => true,
    'confirm' => true,
    'payload' => array('per_page' => 10, 'page' => 1),
);

foreach (array(
    'media.list' => array('per_page' => 10, 'page' => 1),
    'media.get' => array('id' => 123),
    'media.update' => array('id' => 123, 'alt' => 'SEO alt text'),
    'plugin.update' => array('plugin' => 'example/example.php'),
    'filesystem.inspect' => array(),
) as $action => $payload) {
    $request = $base;
    $request['request_id'] = 'public-broad-' . substr(hash('sha256', $action), 0, 12);
    $request['action'] = $action;
    $request['payload'] = $payload;
    list($status, $output) = $run($write(str_replace('.', '-', $action) . '.json', $request));
    if (0 !== $status) {
        fwrite(STDERR, "Confirmed broad public action was rejected: {$action}: {$output}\n");
        exit(1);
    }
}

$unconfirmed = $base;
$unconfirmed['request_id'] = 'public-broad-3001';
$unconfirmed['confirm'] = false;
list($status) = $run($write('unconfirmed.json', $unconfirmed));
if (0 === $status) {
    fwrite(STDERR, "Unconfirmed broad public action unexpectedly passed.\n");
    exit(1);
}

$secret = $base;
$secret['request_id'] = 'public-broad-3002';
$secret['action'] = 'plugin.update';
$secret['payload'] = array('api_key' => 'must-not-pass');
list($status) = $run($write('secret.json', $secret));
if (0 === $status) {
    fwrite(STDERR, "Secret-like public payload unexpectedly passed.\n");
    exit(1);
}

$batch = $base;
$batch['request_id'] = 'public-broad-3003';
$batch['action'] = 'connector.batch';
$batch['payload'] = array('operations' => array(
    array('action' => 'media.update', 'payload' => array('id' => 123, 'alt' => 'SEO alt text')),
));
list($status, $output) = $run($write('batch.json', $batch));
if (0 !== $status) {
    fwrite(STDERR, "Confirmed broad batch action was rejected: {$output}\n");
    exit(1);
}

echo "public broad actions contract OK\n";
