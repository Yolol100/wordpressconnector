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
    'post.trash' => array('id' => 4933),
    'plugin.update' => array('plugin' => 'example/example.php'),
    'filesystem.inspect' => array(),
) as $action => $payload) {
    $request = $base;
    $request['request_id'] = 'public-broad-' . substr(hash('sha256', $action), 0, 12);
    $request['action'] = $action;
    $request['payload'] = $payload;
    list($status) = $run($write(str_replace('.', '-', $action) . '.json', $request));
    if (0 === $status) {
        fwrite(STDERR, "Confirmed non-allowlisted public action unexpectedly passed: {$action}\n");
        exit(1);
    }
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
list($status) = $run($write('batch.json', $batch));
if (0 === $status) {
    fwrite(STDERR, "Confirmed broad batch action unexpectedly passed.\n");
    exit(1);
}

require_once $root . '/plugin/wordpressconnector/includes/Security/Policy.php';
\Webactueel\WordPressConnector\Security\Policy::setPublicRepositoryContext(true);

$unsafeDescriptor = array(
    'name' => 'post.trash',
    'mutation' => true,
    'privileged' => false,
    'sensitive' => false,
    'system_update' => false,
    'public_repository_safe' => false,
    'capability' => null,
);
$blocked = false;
try {
    \Webactueel\WordPressConnector\Security\Policy::assertActionAllowed($unsafeDescriptor, false, true);
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'not allowed in public-repository mode');
}
if (! $blocked) {
    fwrite(STDERR, "Server policy did not block non-allowlisted public post.trash.\n");
    exit(1);
}

$safeDescriptor = array(
    'name' => 'post.get',
    'mutation' => false,
    'privileged' => false,
    'sensitive' => false,
    'system_update' => false,
    'public_repository_safe' => false,
    'capability' => null,
);
try {
    \Webactueel\WordPressConnector\Security\Policy::assertActionAllowed($safeDescriptor, true, false);
} catch (RuntimeException $error) {
    fwrite(STDERR, 'Server policy rejected allowlisted public post.get: ' . $error->getMessage() . "\n");
    exit(1);
}

echo "public broad actions contract OK\n";
