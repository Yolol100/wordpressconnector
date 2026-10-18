<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/scripts/build-public-receipt.php';
if (! is_file($source)) {
    fwrite(STDERR, "Public receipt builder source is missing.\n");
    exit(1);
}
if (is_file($root . '/scripts/build-public-receipt-legacy.php')) {
    fwrite(STDERR, "Superseded public receipt builder is still present; canonical builder is self-contained.\n");
    exit(1);
}
$sourceContents = (string) file_get_contents($source);
if (strpos($sourceContents, 'build-public-receipt-legacy.php') !== false) {
    fwrite(STDERR, "Public receipt builder still depends on the legacy helper.\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/wpconnector-public-receipt-workspace-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    fwrite(STDERR, "Could not create isolated receipt workspace.\n");
    exit(1);
}
copy($source, $tmp . '/build-public-receipt.php');
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: array() as $file) @unlink($file);
    @rmdir($tmp);
});

$write = static function (string $name, array $data) use ($tmp): string {
    $path = $tmp . '/' . $name . '.json';
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    return $path;
};
$run = static function (string $requestPath, string $resultPath, string $receiptName) use ($tmp): array {
    $receiptPath = $tmp . '/' . $receiptName . '.json';
    $output = array(); $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/build-public-receipt.php') . ' ' . escapeshellarg($requestPath) . ' ' . escapeshellarg($resultPath) . ' ' . escapeshellarg($receiptPath) . ' 2>&1', $output, $status);
    $receipt = is_file($receiptPath) ? json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR) : array();
    return array($status, implode("\n", $output), $receipt);
};

$checkRequest = array('version'=>1,'request_id'=>'receipt-update-check-0001','action'=>'connector.update.check','dry_run'=>true,'confirm'=>false,'payload'=>array());
$checkResult = array('version'=>1,'ok'=>true,'request_id'=>$checkRequest['request_id'],'action'=>'connector.update.check','dry_run'=>true,'data'=>array('update'=>array('current_version'=>'1.14.3','latest_version'=>'1.14.7','update_available'=>true)));
list($status, $output, $receipt) = $run($write('check-request', $checkRequest), $write('check-result', $checkResult), 'check-receipt');
if (0 !== $status || ($receipt['current_version'] ?? '') !== '1.14.3' || ($receipt['latest_version'] ?? '') !== '1.14.7' || true !== ($receipt['update_available'] ?? null)) {
    fwrite(STDERR, 'Self-contained update-check receipt failed: ' . $output . "\n"); exit(1);
}

$applyRequest = array('version'=>1,'request_id'=>'receipt-update-apply-0001','action'=>'connector.update.apply','dry_run'=>true,'confirm'=>false,'payload'=>array());
$applyResult = array('version'=>1,'ok'=>true,'request_id'=>$applyRequest['request_id'],'action'=>'connector.update.apply','dry_run'=>true,'data'=>array('would_update_connector'=>array('from_version'=>'1.14.3','to_version'=>'1.14.7','tag'=>'v1.14.7','package_asset'=>'wordpressconnector.zip','checksum_asset'=>'wordpressconnector.zip.sha256','rollback_supported'=>false)));
list($status, $output, $receipt) = $run($write('apply-request', $applyRequest), $write('apply-result', $applyResult), 'apply-receipt');
if (0 !== $status || true !== ($receipt['update_plan_verified'] ?? null) || ! preg_match('/^[a-f0-9]{64}\z/', (string) ($receipt['before_fingerprint'] ?? ''))) {
    fwrite(STDERR, 'Self-contained update dry-run receipt failed: ' . $output . "\n"); exit(1);
}

$fields = array(
    'field_portfolio_stat_1_value'=>'3','field_portfolio_stat_1_label'=>'Practice Areas',
    'field_portfolio_stat_2_value'=>'Direct','field_portfolio_stat_2_label'=>'Fixed Contact',
    'field_portfolio_stat_3_value'=>'Mobile-first','field_portfolio_stat_3_label'=>'Legal Navigation',
    'field_portfolio_stat_4_value'=>'WordPress','field_portfolio_stat_4_label'=>'Service Website',
);
$portfolioRequest = array('version'=>1,'request_id'=>'receipt-portfolio-0001','action'=>'acf.portfolio_stats_update','dry_run'=>true,'confirm'=>false,'payload'=>array('post_id'=>5104,'fields'=>$fields));
$before = $fields; $before['field_portfolio_stat_1_value'] = '2';
$portfolioResult = array('version'=>1,'ok'=>true,'request_id'=>$portfolioRequest['request_id'],'action'=>'acf.portfolio_stats_update','dry_run'=>true,'data'=>array('post_id'=>5104,'before'=>$before,'after'=>$fields));
list($status, $output, $receipt) = $run($write('portfolio-request', $portfolioRequest), $write('portfolio-result', $portfolioResult), 'portfolio-receipt');
if (0 !== $status || 5104 !== ($receipt['post_id'] ?? 0) || ! preg_match('/^[a-f0-9]{64}\z/', (string) ($receipt['before_fingerprint'] ?? '')) || ! preg_match('/^[a-f0-9]{64}\z/', (string) ($receipt['after_fingerprint'] ?? ''))) {
    fwrite(STDERR, 'Self-contained portfolio receipt failed: ' . $output . "\n"); exit(1);
}

$raw = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
foreach (array('password','secret','token') as $needle) {
    if (stripos($raw, $needle) !== false) {
        fwrite(STDERR, "Public receipt contains secret-like material: {$needle}.\n"); exit(1);
    }
}

$rollbackTargetId = 'receipt-rollback-original-0001';
$previewRequest = array(
    'version'=>1, 'request_id'=>'receipt-rollback-preview-0001',
    'action'=>'connector.rollback', 'dry_run'=>true, 'confirm'=>false,
    'payload'=>array('request_id'=>$rollbackTargetId),
);
$previewResult = array(
    'version'=>1, 'ok'=>true, 'request_id'=>$previewRequest['request_id'],
    'action'=>'connector.rollback', 'dry_run'=>true,
    'data'=>array('would_execute_action'=>'site.identity.restore',
        'source_action'=>'site.identity.update',
        'current_state_token'=>'SECRET_MUST_NOT_LEAK'),
);
list($status, $output, $previewReceipt) = $run(
    $write('rollback-preview-request', $previewRequest),
    $write('rollback-preview-result', $previewResult),
    'rollback-preview-receipt'
);
if (0 !== $status || true !== ($previewReceipt['rollback_preflight_verified'] ?? null)
    || array_key_exists('rollback_executed', $previewReceipt)
    || false !== strpos(json_encode($previewReceipt), 'SECRET_MUST_NOT_LEAK')) {
    throw new RuntimeException('Rollback preview falsely claimed an executed rollback or leaked private state.');
}

$confirmedRequest = $previewRequest;
$confirmedRequest['request_id'] = 'receipt-rollback-confirmed-0001';
$confirmedRequest['dry_run'] = false;
$confirmedRequest['confirm'] = true;
$confirmedRequest['expected_fingerprint'] = str_repeat('a', 64);
$confirmedResult = array(
    'version'=>1, 'ok'=>true, 'request_id'=>$confirmedRequest['request_id'],
    'action'=>'connector.rollback', 'dry_run'=>false,
    'data'=>array('restored_request_id'=>$rollbackTargetId,
        'restored_with'=>'site.identity.restore',
        'result'=>array('restored'=>true,'secret'=>'SECRET_MUST_NOT_LEAK')),
);
list($status, $output, $confirmedReceipt) = $run(
    $write('rollback-confirmed-request', $confirmedRequest),
    $write('rollback-confirmed-result', $confirmedResult),
    'rollback-confirmed-receipt'
);
if (0 !== $status || true !== ($confirmedReceipt['rollback_executed'] ?? null)
    || array_key_exists('rollback_preflight_verified', $confirmedReceipt)
    || false !== strpos(json_encode($confirmedReceipt), 'SECRET_MUST_NOT_LEAK')) {
    throw new RuntimeException('Verified rollback receipt did not distinguish execution from preview.');
}

$mismatch = $confirmedResult;
$mismatch['data']['restored_request_id'] = 'receipt-rollback-different-0002';
list($status, $output, $mismatchReceipt) = $run(
    $write('rollback-mismatch-request', $confirmedRequest),
    $write('rollback-mismatch-result', $mismatch),
    'rollback-mismatch-receipt'
);
if (0 !== $status || false !== ($mismatchReceipt['rollback_executed'] ?? null)) {
    throw new RuntimeException('Public receipt incorrectly trusted an unrelated rollback target.');
}

$missingPreview = $previewResult;
$missingPreview['data'] = array();
list($status, $output, $missingReceipt) = $run(
    $write('rollback-missing-request', $previewRequest),
    $write('rollback-missing-result', $missingPreview),
    'rollback-missing-receipt'
);
if (0 !== $status || false !== ($missingReceipt['rollback_preflight_verified'] ?? null)
    || array_key_exists('rollback_executed', $missingReceipt)) {
    throw new RuntimeException('Unverified rollback preview must fail closed.');
}

echo "public receipt self-contained workspace contract OK\n";
