<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/CacheMaintenanceAdapter.php');
$validator = $root . '/scripts/validate-public-request.php';
$receiptBuilder = $root . '/scripts/build-public-receipt.php';

foreach (array(
    "maintenance.cache_capabilities",
    "maintenance.cache_flush",
    "elementor",
    "wp_rocket",
    "asset_cleanup",
    "rocket_clean_domain",
    "WpAssetCleanUp\\\\OptimiseAssets\\\\OptimizeCommon",
    "elementor/core/files/clear_cache",
    "rocket_after_clean_domain",
    "rocket_clean_cache_dir",
    "after_rocket_clean_cache_dir",
    "ensureAssetCleanupClass",
    "WPACU_PLUGIN_CLASSES_PATH",
    "spl_autoload_register",
    "clearCache",
    "clearAllCache",
    "wpacu_clear_cache_after",
    "wpacu_css_wpconnector_verify_",
    "_last_clear_cache",
    "readback_verified",
) as $needle) {
    if (false === strpos($adapter, $needle)) {
        fwrite(STDERR, "Cache maintenance adapter contract is missing: {$needle}\n");
        exit(1);
    }
}

foreach (array(
    "unlink(",
    "rmdir(",
    "delete_transient(",
    "DELETE FROM",
) as $forbiddenNeedle) {
    if (false !== strpos($adapter, $forbiddenNeedle)) {
        fwrite(STDERR, "Cache maintenance adapter contains forbidden direct Asset CleanUp cache mutation primitive: {$forbiddenNeedle}\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/wpconnector-cache-maintenance-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    fwrite(STDERR, "Could not create temporary directory.\n");
    exit(1);
}

$write = static function (string $name, array $data) use ($tmp): string {
    $path = $tmp . '/' . $name;
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

$capabilities = array(
    'version' => 1,
    'request_id' => 'cache-capabilities-001',
    'action' => 'maintenance.cache_capabilities',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array(),
);
list($status, $output) = $run($validator, array($write('capabilities.json', $capabilities)));
if (0 !== $status) {
    fwrite(STDERR, "Cache capabilities request was rejected: {$output}\n");
    exit(1);
}

$dry = array(
    'version' => 1,
    'request_id' => 'cache-flush-001',
    'action' => 'maintenance.cache_flush',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('layer' => 'elementor'),
);
list($status, $output) = $run($validator, array($write('dry.json', $dry)));
if (0 !== $status) {
    fwrite(STDERR, "Cache flush dry-run was rejected: {$output}\n");
    exit(1);
}

$live = $dry;
$live['request_id'] = 'cache-flush-002';
$live['dry_run'] = false;
$live['confirm'] = true;
list($status) = $run($validator, array($write('live-no-fingerprint.json', $live)));
if (0 === $status) {
    fwrite(STDERR, "Live cache flush passed without expected_fingerprint.\n");
    exit(1);
}

$live['expected_fingerprint'] = str_repeat('a', 64);
list($status, $output) = $run($validator, array($write('live.json', $live)));
if (0 !== $status) {
    fwrite(STDERR, "Guarded cache flush was rejected: {$output}\n");
    exit(1);
}

$bad = $dry;
$bad['request_id'] = 'cache-flush-003';
$bad['payload']['layer'] = 'everything';
list($status) = $run($validator, array($write('bad-layer.json', $bad)));
if (0 === $status) {
    fwrite(STDERR, "Unknown cache layer unexpectedly passed.\n");
    exit(1);
}

$bad = $dry;
$bad['request_id'] = 'cache-flush-004';
$bad['payload']['path'] = '/tmp';
list($status) = $run($validator, array($write('bad-key.json', $bad)));
if (0 === $status) {
    fwrite(STDERR, "Cache flush accepted an unsupported payload key.\n");
    exit(1);
}

$capRequestPath = $write('cap-receipt-request.json', $capabilities);
$capResultPath = $write('cap-receipt-result.json', array(
    'version' => 1,
    'ok' => true,
    'request_id' => $capabilities['request_id'],
    'action' => 'maintenance.cache_capabilities',
    'dry_run' => true,
    'data' => array(
        'layers' => array(
            'elementor' => array('provider' => 'Elementor', 'available' => true, 'version' => '3.30.0'),
            'wp_rocket' => array('provider' => 'WP Rocket', 'available' => true, 'version' => '3.19.0'),
            'asset_cleanup' => array('provider' => 'Asset CleanUp', 'available' => true, 'version' => '1.3.2.2'),
        ),
        'fingerprint' => str_repeat('1', 64),
        'secret' => 'must-not-leak',
    ),
));
$capReceiptPath = $tmp . '/cap-receipt.json';
list($status, $output) = $run($receiptBuilder, array($capRequestPath, $capResultPath, $capReceiptPath));
if (0 !== $status) {
    fwrite(STDERR, "Could not build cache capabilities receipt: {$output}\n");
    exit(1);
}
$raw = (string) file_get_contents($capReceiptPath);
if (false !== strpos($raw, 'must-not-leak') || false === strpos($raw, 'asset_cleanup')) {
    fwrite(STDERR, "Cache capabilities receipt is not safely bounded.\n");
    exit(1);
}

$liveResultPath = $write('live-result.json', array(
    'version' => 1,
    'ok' => true,
    'request_id' => $live['request_id'],
    'action' => 'maintenance.cache_flush',
    'dry_run' => false,
    'data' => array(
        'layer' => 'elementor',
        'provider' => 'Elementor',
        'version' => '3.30.0',
        'readback_verified' => true,
        'rollback_supported' => false,
        'rebuild_mode' => 'regenerate_on_next_page_request',
        'private_state' => 'must-not-leak',
    ),
));
$liveReceiptPath = $tmp . '/live-receipt.json';
list($status, $output) = $run($receiptBuilder, array($write('live-receipt-request.json', $live), $liveResultPath, $liveReceiptPath));
if (0 !== $status) {
    fwrite(STDERR, "Could not build cache flush receipt: {$output}\n");
    exit(1);
}
$receipt = json_decode((string) file_get_contents($liveReceiptPath), true, 512, JSON_THROW_ON_ERROR);
$raw = (string) file_get_contents($liveReceiptPath);
if (
    true !== ($receipt['readback_verified'] ?? null) ||
    'elementor' !== ($receipt['layer'] ?? '') ||
    '3.30.0' !== ($receipt['provider_version'] ?? '') ||
    1 !== ($receipt['version'] ?? null) ||
    false !== strpos($raw, 'must-not-leak')
) {
    fwrite(STDERR, "Cache flush receipt verification failed.\n");
    exit(1);
}

echo "cache maintenance contract OK\n";
