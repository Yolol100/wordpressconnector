<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
$receiptBuilder = $root . '/scripts/build-public-receipt.php';

if (! is_file($validator) || ! is_file($receiptBuilder)) {
    fwrite(STDERR, "Public runtime scripts are missing.\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/wpconnector-public-runtime-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    fwrite(STDERR, "Could not create temporary directory.\n");
    exit(1);
}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (! is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: array() as $name) {
        if ('.' === $name || '..' === $name) {
            continue;
        }
        $removeTree($path . DIRECTORY_SEPARATOR . $name);
    }
    @rmdir($path);
};
register_shutdown_function(static function () use ($tmp, $removeTree): void {
    $removeTree($tmp);
});

$writeJson = static function (string $name, array $data) use ($tmp): string {
    $path = $tmp . '/' . $name;
    $directory = dirname($path);
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    return $path;
};

$run = static function (string $script, array $args): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $output = array();
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    return array($status, implode("\n", $output));
};

$base = array(
    'version' => 1,
    'request_id' => 'public-test-0001',
    'action' => 'post.update',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('id' => 4933, 'excerpt' => 'Public portfolio summary.'),
);

list($status) = $run($validator, array($writeJson('allowed-post.json', $base)));
if (0 !== $status) {
    fwrite(STDERR, "Allowed public post.update was rejected.\n");
    exit(1);
}

$acf = $base;
$acf['request_id'] = 'public-test-0002';
$acf['action'] = 'acf.update';
$acf['payload'] = array('post_id' => 4933, 'fields' => array('description_1' => 'Public portfolio problem.'));
list($status) = $run($validator, array($writeJson('allowed-acf.json', $acf)));
if (0 !== $status) {
    fwrite(STDERR, "Allowed public acf.update was rejected.\n");
    exit(1);
}

$batch = $base;
$batch['request_id'] = 'public-test-0003';
$batch['action'] = 'connector.batch';
$batch['payload'] = array('operations' => array(
    array('action' => 'post.update', 'payload' => array('id' => 4933, 'excerpt' => 'Public summary.')),
    array('action' => 'acf.update', 'payload' => array('post_id' => 4933, 'fields' => array('description_2' => 'Public solution.'))),
));
list($status) = $run($validator, array($writeJson('allowed-batch.json', $batch)));
if (0 !== $status) {
    fwrite(STDERR, "Allowed public connector.batch was rejected.\n");
    exit(1);
}

$updateCheck = $base;
$updateCheck['request_id'] = 'public-test-0004';
$updateCheck['action'] = 'connector.update.check';
$updateCheck['payload'] = array();
list($status) = $run($validator, array($writeJson('allowed-update-check.json', $updateCheck)));
if (0 !== $status) {
    fwrite(STDERR, "Allowed public connector.update.check was rejected.\n");
    exit(1);
}

$updateDryRun = $base;
$updateDryRun['request_id'] = 'public-test-0005';
$updateDryRun['action'] = 'connector.update.apply';
$updateDryRun['payload'] = array();
list($status) = $run($validator, array($writeJson('allowed-update-dry-run.json', $updateDryRun)));
if (0 !== $status) {
    fwrite(STDERR, "Allowed public connector.update.apply dry-run was rejected.\n");
    exit(1);
}

$updateConfirm = $updateDryRun;
$updateConfirm['request_id'] = 'public-test-0006';
$updateConfirm['dry_run'] = false;
$updateConfirm['confirm'] = true;
$updateConfirm['expected_fingerprint'] = str_repeat('c', 64);
list($status) = $run($validator, array($writeJson('allowed-update-confirm.json', $updateConfirm)));
if (0 !== $status) {
    fwrite(STDERR, "Allowed confirmed public connector.update.apply was rejected.\n");
    exit(1);
}

$forbiddenCases = array();

$case = $base;
$case['request_id'] = 'public-test-1001';
$case['action'] = 'plugin.update';
$case['payload'] = array('plugin' => 'example/example.php');
$forbiddenCases['system action'] = $case;

$case = $acf;
$case['request_id'] = 'public-test-1002';
$case['payload'] = array('target' => 'options', 'fields' => array('description_1' => 'No.'));
$forbiddenCases['non-post ACF target'] = $case;

$case = $acf;
$case['request_id'] = 'public-test-1003';
$case['payload'] = array('post_id' => 4933, 'fields' => array('api_key' => 'do-not-publish'));
$forbiddenCases['secret-like field'] = $case;

$case = $base;
$case['request_id'] = 'public-test-1004';
$case['expected_state_token'] = str_repeat('a', 64);
$forbiddenCases['site state token'] = $case;

$case = $batch;
$case['request_id'] = 'public-test-1005';
$case['payload']['operations'][] = array('action' => 'plugin.update', 'payload' => array('plugin' => 'example/example.php'));
$forbiddenCases['unsafe nested batch action'] = $case;

$case = $updateCheck;
$case['request_id'] = 'public-test-1006';
$case['payload'] = array('url' => 'https://example.com/plugin.zip');
$forbiddenCases['self-update payload override'] = $case;

$case = $updateConfirm;
$case['request_id'] = 'public-test-1007';
unset($case['expected_fingerprint']);
$forbiddenCases['confirmed self-update without fingerprint'] = $case;

$case = $updateDryRun;
$case['request_id'] = 'public-test-1008';
$case['action'] = 'plugin.install_package';
$case['payload'] = array('source_path' => 'plugin-packages/private.zip');
$forbiddenCases['private package through public runtime'] = $case;

foreach ($forbiddenCases as $label => $request) {
    list($status) = $run($validator, array($writeJson('forbidden-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.json', $request)));
    if (0 === $status) {
        fwrite(STDERR, "Forbidden public runtime case passed: {$label}\n");
        exit(1);
    }
}

$requestPath = $writeJson('receipt-request.json', $base);
$resultPath = $writeJson('receipt-result.json', array(
    'version' => 1,
    'ok' => true,
    'request_id' => $base['request_id'],
    'action' => 'post.update',
    'dry_run' => true,
    'completed_at_gmt' => '2026-09-08T15:00:00+00:00',
    'data' => array(
        'before' => array('id' => 4933, 'excerpt' => 'Old confidential-looking source text.'),
        'after' => array('id' => 4933, 'excerpt' => 'Public portfolio summary.'),
        'current_state_token' => str_repeat('b', 64),
        'rollback_request_id' => 'must-not-leak',
    ),
));
$receiptPath = $tmp . '/receipt.json';
list($status) = $run($receiptBuilder, array($requestPath, $resultPath, $receiptPath));
if (0 !== $status || ! is_file($receiptPath)) {
    fwrite(STDERR, "Could not build public receipt.\n");
    exit(1);
}
$receiptRaw = (string) file_get_contents($receiptPath);
$receipt = json_decode($receiptRaw, true, 512, JSON_THROW_ON_ERROR);
if (empty($receipt['ok']) || true !== ($receipt['readback_verified'] ?? null)) {
    fwrite(STDERR, "Public receipt did not verify the allowed result.\n");
    exit(1);
}
foreach (array('Old confidential-looking source text.', 'Public portfolio summary.', str_repeat('b', 64), 'must-not-leak') as $needle) {
    if (str_contains($receiptRaw, $needle)) {
        fwrite(STDERR, "Public receipt leaked response data.\n");
        exit(1);
    }
}
foreach (array('before_fingerprint', 'after_fingerprint') as $key) {
    if (empty($receipt[$key]) || ! preg_match('/^[a-f0-9]{64}$/D', (string) $receipt[$key])) {
        fwrite(STDERR, "Public receipt is missing a safe fingerprint: {$key}\n");
        exit(1);
    }
}

$runtimeRequest = $writeJson('runtime/request.json', $base);
$runtimeResult = $writeJson('runtime/results/result.json', array(
    'version' => 1,
    'ok' => true,
    'request_id' => $base['request_id'],
    'action' => 'post.update',
    'dry_run' => true,
    'data' => array(
        'before' => array('id' => 4933, 'excerpt' => 'Before.'),
        'after' => array('id' => 4933, 'excerpt' => 'Public portfolio summary.'),
    ),
));
$writeJson('runtime/health.json', array(
    'ok' => true,
    'transport' => 'rest',
    'version' => '1.12.2',
    'user_id' => 123,
    'gates' => array('writes' => true, 'privileged' => true, 'system_updates' => true),
));
$runtimeReceipt = $tmp . '/runtime/receipt.json';
list($status) = $run($receiptBuilder, array($runtimeRequest, $runtimeResult, $runtimeReceipt));
$runtimeReceiptRaw = (string) file_get_contents($runtimeReceipt);
$runtimeReceiptData = json_decode($runtimeReceiptRaw, true, 512, JSON_THROW_ON_ERROR);
if (0 !== $status || ($runtimeReceiptData['connector_version'] ?? '') !== '1.12.2') {
    fwrite(STDERR, "Public receipt did not expose the safe connector version.\n");
    exit(1);
}
foreach (array('user_id', 'gates', 'system_updates') as $needle) {
    if (str_contains($runtimeReceiptRaw, $needle)) {
        fwrite(STDERR, "Public receipt leaked health metadata beyond connector version.\n");
        exit(1);
    }
}

$updateRequestPath = $writeJson('update-request.json', $updateDryRun);
$updateResultPath = $writeJson('update-result.json', array(
    'version' => 1,
    'ok' => true,
    'request_id' => $updateDryRun['request_id'],
    'action' => 'connector.update.apply',
    'dry_run' => true,
    'data' => array(
        'would_update_connector' => array(
            'from_version' => '1.12.1',
            'to_version' => '1.12.2',
            'tag' => 'v1.12.2',
            'package_asset' => 'wordpressconnector.zip',
            'checksum_asset' => 'wordpressconnector.zip.sha256',
            'rollback_supported' => false,
        ),
        '_current_fingerprint' => str_repeat('d', 64),
    ),
));
$updateReceiptPath = $tmp . '/update-receipt.json';
list($status) = $run($receiptBuilder, array($updateRequestPath, $updateResultPath, $updateReceiptPath));
$updateReceipt = json_decode((string) file_get_contents($updateReceiptPath), true, 512, JSON_THROW_ON_ERROR);
if (0 !== $status || true !== ($updateReceipt['update_plan_verified'] ?? null) || ($updateReceipt['from_version'] ?? '') !== '1.12.1' || ($updateReceipt['to_version'] ?? '') !== '1.12.2' || ($updateReceipt['before_fingerprint'] ?? '') !== str_repeat('d', 64)) {
    fwrite(STDERR, "Public self-update dry-run receipt is incomplete.\n");
    exit(1);
}

$confirmedRequest = $updateConfirm;
$confirmedRequestPath = $writeJson('update-confirm-request.json', $confirmedRequest);
$confirmedResultPath = $writeJson('update-confirm-result.json', array(
    'version' => 1,
    'ok' => true,
    'request_id' => $confirmedRequest['request_id'],
    'action' => 'connector.update.apply',
    'dry_run' => false,
    'data' => array(
        'before' => array('plugin_file' => 'wordpressconnector/wordpressconnector.php', 'version' => '1.12.1'),
        'after' => array('plugin_file' => 'wordpressconnector/wordpressconnector.php', 'version' => '1.12.2'),
        'package_sha256' => str_repeat('e', 64),
        'release_tag' => 'v1.12.2',
        'rollback_supported' => false,
    ),
));
$confirmedReceiptPath = $tmp . '/update-confirm-receipt.json';
list($status) = $run($receiptBuilder, array($confirmedRequestPath, $confirmedResultPath, $confirmedReceiptPath));
$confirmedReceipt = json_decode((string) file_get_contents($confirmedReceiptPath), true, 512, JSON_THROW_ON_ERROR);
if (0 !== $status || true !== ($confirmedReceipt['readback_verified'] ?? null) || ($confirmedReceipt['to_version'] ?? '') !== '1.12.2' || ($confirmedReceipt['package_sha256'] ?? '') !== str_repeat('e', 64)) {
    fwrite(STDERR, "Public self-update confirmed receipt is incomplete.\n");
    exit(1);
}

$errorResult = $writeJson('error-result.json', array(
    'version' => 1,
    'ok' => false,
    'request_id' => $base['request_id'],
    'action' => 'post.update',
    'dry_run' => false,
    'completed_at_gmt' => '2026-09-08T15:00:00+00:00',
    'error' => 'Secret backend detail: do-not-publish',
));
$errorReceiptPath = $tmp . '/error-receipt.json';
list($status) = $run($receiptBuilder, array($requestPath, $errorResult, $errorReceiptPath));
if (0 !== $status) {
    fwrite(STDERR, "Could not build public error receipt.\n");
    exit(1);
}
$errorReceiptRaw = (string) file_get_contents($errorReceiptPath);
if (str_contains($errorReceiptRaw, 'do-not-publish') || ! str_contains($errorReceiptRaw, 'error_code')) {
    fwrite(STDERR, "Public error receipt leaked raw connector error data.\n");
    exit(1);
}

echo "public runtime contract OK\n";
