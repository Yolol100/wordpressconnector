<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
$receiptBuilder = $root . '/scripts/build-public-receipt.php';

$tmp = sys_get_temp_dir() . '/wpconnector-public-post-read-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    fwrite(STDERR, "Could not create temporary directory.\n");
    exit(1);
}
register_shutdown_function(static function () use ($tmp): void {
    $files = glob($tmp . '/*') ?: array();
    foreach ($files as $file) @unlink($file);
    @rmdir($tmp);
});

$write = static function (string $name, array $data) use ($tmp): string {
    $path = $tmp . '/' . $name;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    return $path;
};
$run = static function (string $script, array $args): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);
    $output = array(); $status = 0;
    exec($command . ' 2>&1', $output, $status);
    return array($status, implode("\n", $output));
};

$get = array(
    'version' => 1,
    'request_id' => 'public-post-get-001',
    'action' => 'post.get',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('id' => 4470),
);
list($status) = $run($validator, array($write('get.json', $get)));
if (0 !== $status) {
    fwrite(STDERR, "Bounded public post.get was rejected.\n");
    exit(1);
}

$list = $get;
$list['request_id'] = 'public-post-list-001';
$list['action'] = 'post.list';
$list['payload'] = array('post_type' => 'post', 'status' => 'publish', 'per_page' => 100, 'page' => 1, 'orderby' => 'ID', 'order' => 'ASC');
list($status) = $run($validator, array($write('list.json', $list)));
if (0 !== $status) {
    fwrite(STDERR, "Bounded public post.list was rejected.\n");
    exit(1);
}

$forbidden = array();
$case = $get; $case['request_id'] = 'public-post-get-bad-001'; $case['dry_run'] = false; $case['confirm'] = true;
$forbidden[] = $case;
$case = $get; $case['request_id'] = 'public-post-get-bad-002'; $case['payload']['context'] = 'edit';
$forbidden[] = $case;
$case = $list; $case['request_id'] = 'public-post-list-bad-001'; $case['payload']['status'] = 'draft';
$forbidden[] = $case;
$case = $list; $case['request_id'] = 'public-post-list-bad-002'; $case['payload']['per_page'] = 101;
$forbidden[] = $case;
$case = $list; $case['request_id'] = 'public-post-list-bad-003'; $case['payload']['meta_key'] = 'private';
$forbidden[] = $case;
foreach ($forbidden as $index => $request) {
    list($status) = $run($validator, array($write('forbidden-' . $index . '.json', $request)));
    if (0 === $status) {
        fwrite(STDERR, "Forbidden public post read request passed validation at index {$index}.\n");
        exit(1);
    }
}

$fingerprint = str_repeat('a', 64);
$getResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $get['request_id'],
    'action' => 'post.get',
    'dry_run' => true,
    'data' => array(
        'post' => array(
            'id' => 4470,
            'type' => 'post',
            'status' => 'publish',
            'title' => 'Ten Bruggen',
            'slug' => 'tenbrugge',
            'excerpt' => 'Publieke intro met <a href="https://tenbruggen.nl/">link</a>.',
            'content' => 'FULL CONTENT MUST NOT LEAK',
            'password_protected' => false,
            'permalink' => 'https://example.com/tenbrugge/',
        ),
        'fingerprint' => $fingerprint,
    ),
);
$getRequest = $write('get-receipt-request.json', $get);
$getResultPath = $write('get-receipt-result.json', $getResult);
$getReceiptPath = $tmp . '/get-receipt.json';
list($status) = $run($receiptBuilder, array($getRequest, $getResultPath, $getReceiptPath));
$getReceiptRaw = is_file($getReceiptPath) ? (string) file_get_contents($getReceiptPath) : '';
$getReceipt = $getReceiptRaw !== '' ? json_decode($getReceiptRaw, true, 512, JSON_THROW_ON_ERROR) : array();
if (0 !== $status || ($getReceipt['post_fingerprint'] ?? '') !== $fingerprint || ($getReceipt['post']['id'] ?? 0) !== 4470 || ! str_contains((string) ($getReceipt['post']['excerpt'] ?? ''), 'tenbruggen.nl')) {
    fwrite(STDERR, "Public post.get receipt is incomplete.\n");
    exit(1);
}
foreach (array('FULL CONTENT MUST NOT LEAK', 'password_protected') as $needle) {
    if (str_contains($getReceiptRaw, $needle)) {
        fwrite(STDERR, "Public post.get receipt leaked non-approved post data.\n");
        exit(1);
    }
}

$listResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $list['request_id'],
    'action' => 'post.list',
    'dry_run' => true,
    'data' => array(
        'items' => array(array(
            'id' => 4470,
            'type' => 'post',
            'status' => 'publish',
            'title' => 'Ten Bruggen',
            'slug' => 'tenbrugge',
            'excerpt' => 'LIST EXCERPT MUST NOT LEAK',
            'content' => 'LIST CONTENT MUST NOT LEAK',
            'permalink' => 'https://example.com/tenbrugge/',
        )),
        'page' => 1,
        'per_page' => 100,
        'total' => 1,
        'pages' => 1,
    ),
);
$listRequest = $write('list-receipt-request.json', $list);
$listResultPath = $write('list-receipt-result.json', $listResult);
$listReceiptPath = $tmp . '/list-receipt.json';
list($status) = $run($receiptBuilder, array($listRequest, $listResultPath, $listReceiptPath));
$listReceiptRaw = is_file($listReceiptPath) ? (string) file_get_contents($listReceiptPath) : '';
$listReceipt = $listReceiptRaw !== '' ? json_decode($listReceiptRaw, true, 512, JSON_THROW_ON_ERROR) : array();
if (0 !== $status || ($listReceipt['total'] ?? 0) !== 1 || ($listReceipt['items'][0]['id'] ?? 0) !== 4470 || ($listReceipt['items'][0]['slug'] ?? '') !== 'tenbrugge') {
    fwrite(STDERR, "Public post.list receipt is incomplete.\n");
    exit(1);
}
foreach (array('LIST EXCERPT MUST NOT LEAK', 'LIST CONTENT MUST NOT LEAK') as $needle) {
    if (str_contains($listReceiptRaw, $needle)) {
        fwrite(STDERR, "Public post.list receipt leaked non-approved post data.\n");
        exit(1);
    }
}

echo "public post read contract OK\n";
