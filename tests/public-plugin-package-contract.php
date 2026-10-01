<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
$receiptBuilder = $root . '/scripts/build-public-receipt.php';
$runnerSource = (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Runtime/Runner.php');
$policySource = (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Security/Policy.php');

require_once $root . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once $root . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/Request.php';

use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Security\Policy;

$tmp = sys_get_temp_dir() . '/wpconnector-public-plugin-package-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    fwrite(STDERR, "Could not create public plugin package temp directory.\n");
    exit(1);
}
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: array() as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
});

$write = static function (string $name, array $data) use ($tmp): string {
    $path = $tmp . '/' . $name . '.json';
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

$payload = array(
    'source_path' => 'plugin-packages/webactueel-mailbox-bridge.zip',
    'sha256' => str_repeat('a', 64),
    'expected_plugin' => 'webactueel-mailbox-bridge/webactueel-mailbox-bridge.php',
    'overwrite' => false,
    'activate' => true,
    'network_wide' => false,
);
$dry = array(
    'version' => 1,
    'request_id' => 'public-plugin-dry-0001',
    'action' => 'plugin.install_package',
    'dry_run' => true,
    'confirm' => false,
    'payload' => $payload,
);

list($status, $output) = $run($validator, array($write('dry-request', $dry)));
if (0 !== $status) {
    fwrite(STDERR, 'Guarded public plugin dry-run was rejected: ' . $output . "\n");
    exit(1);
}

$live = $dry;
$live['request_id'] = 'public-plugin-live-0001';
$live['dry_run'] = false;
$live['confirm'] = true;

list($status) = $run($validator, array($write('live-no-fingerprint', $live)));
if (0 === $status) {
    fwrite(STDERR, "Confirmed public plugin install passed without dry-run fingerprint.\n");
    exit(1);
}

$live['expected_fingerprint'] = str_repeat('b', 64);
list($status, $output) = $run($validator, array($write('live-request', $live)));
if (0 !== $status) {
    fwrite(STDERR, 'Guarded confirmed public plugin install was rejected: ' . $output . "\n");
    exit(1);
}

$overwriteDry = $dry;
$overwriteDry['request_id'] = 'public-plugin-overwrite-dry-0001';
$overwriteDry['payload']['overwrite'] = true;
list($status, $output) = $run($validator, array($write('overwrite-dry-request', $overwriteDry)));
if (0 !== $status) {
    fwrite(STDERR, 'Guarded public Mailbox Bridge overwrite dry-run was rejected: ' . $output . "\n");
    exit(1);
}

$overwriteLive = $overwriteDry;
$overwriteLive['request_id'] = 'public-plugin-overwrite-live-0001';
$overwriteLive['dry_run'] = false;
$overwriteLive['confirm'] = true;
$overwriteLive['expected_fingerprint'] = str_repeat('d', 64);
list($status, $output) = $run($validator, array($write('overwrite-live-request', $overwriteLive)));
if (0 !== $status) {
    fwrite(STDERR, 'Guarded confirmed public Mailbox Bridge overwrite was rejected: ' . $output . "\n");
    exit(1);
}

$bad = array();
$case = $dry;
$case['request_id'] = 'public-plugin-bad-0001';
$case['payload']['overwrite'] = true;
$case['payload']['expected_plugin'] = 'other-plugin/other-plugin.php';
$bad['other-plugin-overwrite'] = $case;
$case = $dry;
$case['request_id'] = 'public-plugin-bad-0002';
$case['payload']['network_wide'] = true;
$bad['network-wide'] = $case;
$case = $dry;
$case['request_id'] = 'public-plugin-bad-0003';
unset($case['payload']['sha256']);
$bad['missing-sha'] = $case;
$case = $dry;
$case['request_id'] = 'public-plugin-bad-0004';
$case['payload']['unexpected'] = true;
$bad['extra-key'] = $case;
$case = $dry;
$case['request_id'] = 'public-plugin-bad-0005';
$case['confirm'] = true;
$bad['dry-confirm'] = $case;
$case = $dry;
$case['request_id'] = 'public-plugin-bad-0006';
$case['expected_state_token'] = str_repeat('c', 64);
$bad['state-token'] = $case;

foreach ($bad as $label => $request) {
    list($status) = $run($validator, array($write('bad-' . $label, $request)));
    if (0 === $status) {
        fwrite(STDERR, 'Unsafe public plugin package request passed: ' . $label . "\n");
        exit(1);
    }
}

Policy::setPublicRepositoryContext(true);
$serverLive = $live;
unset($serverLive['expected_fingerprint']);
try {
    Request::fromArray($serverLive);
    fwrite(STDERR, "Server request parser accepted confirmed public plugin install without fingerprint.\n");
    exit(1);
} catch (RuntimeException $error) {
    if (false === strpos($error->getMessage(), 'expected_fingerprint')) {
        fwrite(STDERR, 'Unexpected server fingerprint failure: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
$parsed = Request::fromArray($live);
if ($parsed->expectedFingerprint() !== str_repeat('b', 64)) {
    fwrite(STDERR, "Server request parser did not preserve plugin install fingerprint.\n");
    exit(1);
}
Policy::setPublicRepositoryContext(false);

foreach (array(
    "'plugin.install_package'",
    'assertPublicPluginInstallPayload',
    "Public plugin package overwrite is restricted to the Webactueel Mailbox Bridge.",
    "network_wide=false",
    "expected_fingerprint from the preceding dry-run",
    "public_repository_safe",
) as $needle) {
    if (false === strpos($runnerSource . "\n" . $policySource, $needle)) {
        fwrite(STDERR, 'Missing guarded public plugin package runtime fragment: ' . $needle . "\n");
        exit(1);
    }
}

$dryResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $dry['request_id'],
    'action' => 'plugin.install_package',
    'dry_run' => true,
    'data' => array(
        'would_install_package' => array(
            'source_path' => $payload['source_path'],
            'sha256' => $payload['sha256'],
            'bytes' => 12345,
            'plugin_file' => $payload['expected_plugin'],
            'plugin_name' => 'Sensitive Name Must Not Be Needed',
            'archive_entries' => 5,
            'uncompressed_bytes' => 67890,
            'overwrite' => false,
            'activate' => true,
            'network_wide' => false,
        ),
        'before' => array(
            'file' => $payload['expected_plugin'],
            'installed' => false,
            'version' => null,
            'active' => false,
            'network_active' => false,
        ),
        'current_state_token' => 'private-state-token-must-not-leak',
    ),
);
$dryReceiptPath = $tmp . '/dry-receipt.json';
list($status, $output) = $run($receiptBuilder, array($write('dry-receipt-request', $dry), $write('dry-result', $dryResult), $dryReceiptPath));
$dryReceipt = is_file($dryReceiptPath) ? json_decode((string) file_get_contents($dryReceiptPath), true, 512, JSON_THROW_ON_ERROR) : array();
$dryRaw = is_file($dryReceiptPath) ? (string) file_get_contents($dryReceiptPath) : '';
if (0 !== $status || true !== ($dryReceipt['package_verified'] ?? null) || ($dryReceipt['plugin_file'] ?? '') !== $payload['expected_plugin'] || ($dryReceipt['package_sha256'] ?? '') !== $payload['sha256']) {
    fwrite(STDERR, 'Public plugin dry-run receipt failed: ' . $output . "\n");
    exit(1);
}
foreach (array('private-state-token-must-not-leak', 'Sensitive Name Must Not Be Needed', 'uncompressed_bytes') as $needle) {
    if (false !== strpos($dryRaw, $needle)) {
        fwrite(STDERR, 'Public plugin dry-run receipt leaked private or unnecessary data: ' . $needle . "\n");
        exit(1);
    }
}

$liveResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $live['request_id'],
    'action' => 'plugin.install_package',
    'dry_run' => false,
    'data' => array(
        'operation' => 'installed',
        'package' => array(
            'source_path' => $payload['source_path'],
            'sha256' => $payload['sha256'],
            'bytes' => 12345,
            'plugin_file' => $payload['expected_plugin'],
            'overwrite' => false,
            'activate' => true,
            'network_wide' => false,
        ),
        'before' => array('file' => $payload['expected_plugin'], 'installed' => false, 'version' => null, 'active' => false, 'network_active' => false),
        'after' => array('file' => $payload['expected_plugin'], 'installed' => true, 'name' => 'Mailbox Bridge', 'version' => '0.1.0', 'active' => true, 'network_active' => false),
        'rollback_supported' => false,
    ),
);
$liveReceiptPath = $tmp . '/live-receipt.json';
list($status, $output) = $run($receiptBuilder, array($write('live-receipt-request', $live), $write('live-result', $liveResult), $liveReceiptPath));
$liveReceipt = is_file($liveReceiptPath) ? json_decode((string) file_get_contents($liveReceiptPath), true, 512, JSON_THROW_ON_ERROR) : array();
if (0 !== $status || true !== ($liveReceipt['readback_verified'] ?? null) || ($liveReceipt['operation'] ?? '') !== 'installed') {
    fwrite(STDERR, 'Public plugin live receipt failed: ' . $output . "\n");
    exit(1);
}

$overwriteDryResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $overwriteDry['request_id'],
    'action' => 'plugin.install_package',
    'dry_run' => true,
    'data' => array(
        'would_install_package' => array(
            'source_path' => $overwriteDry['payload']['source_path'],
            'sha256' => $overwriteDry['payload']['sha256'],
            'bytes' => 23456,
            'plugin_file' => $overwriteDry['payload']['expected_plugin'],
            'plugin_name' => 'Mailbox Bridge',
            'archive_entries' => 5,
            'uncompressed_bytes' => 70000,
            'overwrite' => true,
            'activate' => true,
            'network_wide' => false,
        ),
        'before' => array(
            'file' => $overwriteDry['payload']['expected_plugin'],
            'installed' => true,
            'name' => 'Mailbox Bridge',
            'version' => '0.1.0',
            'active' => true,
            'network_active' => false,
        ),
    ),
);
$overwriteDryReceiptPath = $tmp . '/overwrite-dry-receipt.json';
list($status, $output) = $run($receiptBuilder, array(
    $write('overwrite-dry-receipt-request', $overwriteDry),
    $write('overwrite-dry-result', $overwriteDryResult),
    $overwriteDryReceiptPath,
));
$overwriteDryReceipt = is_file($overwriteDryReceiptPath)
    ? json_decode((string) file_get_contents($overwriteDryReceiptPath), true, 512, JSON_THROW_ON_ERROR)
    : array();
if (0 !== $status || true !== ($overwriteDryReceipt['package_verified'] ?? null) || true !== ($overwriteDryReceipt['overwrite'] ?? null) || empty($overwriteDryReceipt['already_installed'])) {
    fwrite(STDERR, 'Public Mailbox Bridge overwrite dry-run receipt failed: ' . $output . "\n");
    exit(1);
}

$overwriteLiveResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $overwriteLive['request_id'],
    'action' => 'plugin.install_package',
    'dry_run' => false,
    'data' => array(
        'operation' => 'updated',
        'package' => array(
            'source_path' => $overwriteLive['payload']['source_path'],
            'sha256' => $overwriteLive['payload']['sha256'],
            'bytes' => 23456,
            'plugin_file' => $overwriteLive['payload']['expected_plugin'],
            'overwrite' => true,
            'activate' => true,
            'network_wide' => false,
        ),
        'before' => array(
            'file' => $overwriteLive['payload']['expected_plugin'],
            'installed' => true,
            'name' => 'Mailbox Bridge',
            'version' => '0.1.0',
            'active' => true,
            'network_active' => false,
        ),
        'after' => array(
            'file' => $overwriteLive['payload']['expected_plugin'],
            'installed' => true,
            'name' => 'Mailbox Bridge',
            'version' => '0.1.1',
            'active' => true,
            'network_active' => false,
        ),
        'rollback_supported' => false,
    ),
);
$overwriteLiveReceiptPath = $tmp . '/overwrite-live-receipt.json';
list($status, $output) = $run($receiptBuilder, array(
    $write('overwrite-live-receipt-request', $overwriteLive),
    $write('overwrite-live-result', $overwriteLiveResult),
    $overwriteLiveReceiptPath,
));
$overwriteLiveReceipt = is_file($overwriteLiveReceiptPath)
    ? json_decode((string) file_get_contents($overwriteLiveReceiptPath), true, 512, JSON_THROW_ON_ERROR)
    : array();
if (0 !== $status || true !== ($overwriteLiveReceipt['readback_verified'] ?? null) || true !== ($overwriteLiveReceipt['overwrite'] ?? null) || ($overwriteLiveReceipt['operation'] ?? '') !== 'updated') {
    fwrite(STDERR, 'Public Mailbox Bridge overwrite live receipt failed: ' . $output . "\n");
    exit(1);
}

echo "public guarded plugin package contract OK\n";
