<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
$receiptBuilder = $root . '/scripts/build-public-receipt.php';

if (! is_file($validator) || ! is_file($receiptBuilder)) {
    fwrite(STDERR, "Public runtime scripts are missing.\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/wpconnector-public-elementor-' . bin2hex(random_bytes(6));
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

$inspect = array(
    'version' => 1,
    'request_id' => 'public-elementor-inspect-0001',
    'action' => 'elementor.inspect',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('id' => 1216),
);
list($status) = $run($validator, array($writeJson('inspect.json', $inspect)));
if (0 !== $status) {
    fwrite(STDERR, "Safe public elementor.inspect was rejected.\n");
    exit(1);
}

$filteredInspect = $inspect;
$filteredInspect['request_id'] = 'public-elementor-inspect-0002';
$filteredInspect['payload']['element_ids'] = array('abc12345', 'def67890');
list($status) = $run($validator, array($writeJson('inspect-filtered.json', $filteredInspect)));
if (0 !== $status) {
    fwrite(STDERR, "Filtered public elementor.inspect was rejected.\n");
    exit(1);
}

$patch = array(
    'version' => 1,
    'request_id' => 'public-elementor-patch-0001',
    'action' => 'elementor.patch_element',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array(
        'id' => 1216,
        'element_id' => 'abc12345',
        'settings' => array('title' => 'Impact'),
    ),
);
list($status) = $run($validator, array($writeJson('patch-dry-run.json', $patch)));
if (0 !== $status) {
    fwrite(STDERR, "Safe public elementor.patch_element dry-run was rejected.\n");
    exit(1);
}

$confirmedPatch = $patch;
$confirmedPatch['request_id'] = 'public-elementor-patch-0002';
$confirmedPatch['dry_run'] = false;
$confirmedPatch['confirm'] = true;
$confirmedPatch['expected_fingerprint'] = str_repeat('a', 64);
list($status) = $run($validator, array($writeJson('patch-confirmed.json', $confirmedPatch)));
if (0 !== $status) {
    fwrite(STDERR, "Safe confirmed public elementor.patch_element was rejected.\n");
    exit(1);
}

$forbidden = array();

$case = $confirmedPatch;
$case['request_id'] = 'public-elementor-forbidden-0001';
unset($case['expected_fingerprint']);
$forbidden['confirmed patch without fingerprint'] = $case;

$case = $patch;
$case['request_id'] = 'public-elementor-forbidden-0002';
$case['payload']['replace'] = array('id' => 'abc12345', 'elType' => 'widget');
$forbidden['whole element replacement'] = $case;

$case = $patch;
$case['request_id'] = 'public-elementor-forbidden-0003';
$case['payload']['widgetType'] = 'html';
$forbidden['widget type mutation'] = $case;

$case = $patch;
$case['request_id'] = 'public-elementor-forbidden-0004';
$case['payload']['settings'] = array('api_key' => 'do-not-publish');
$forbidden['secret-like setting'] = $case;

$case = $inspect;
$case['request_id'] = 'public-elementor-forbidden-0005';
$case['dry_run'] = false;
$case['confirm'] = true;
$forbidden['non-dry-run inspect'] = $case;

$case = $patch;
$case['request_id'] = 'public-elementor-forbidden-0006';
$case['payload']['element_id'] = '../unsafe';
$forbidden['unsafe element id'] = $case;

$case = array(
    'version' => 1,
    'request_id' => 'public-elementor-forbidden-0007',
    'action' => 'connector.batch',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('operations' => array(array(
        'action' => 'elementor.patch_element',
        'payload' => array('id' => 1216, 'element_id' => 'abc12345', 'settings' => array('title' => 'No')),
    ))),
);
$forbidden['Elementor patch inside public batch'] = $case;

foreach ($forbidden as $label => $request) {
    list($status) = $run($validator, array($writeJson('forbidden-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.json', $request)));
    if (0 === $status) {
        fwrite(STDERR, "Forbidden public Elementor case passed: {$label}\n");
        exit(1);
    }
}

$inspectResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $inspect['request_id'],
    'action' => 'elementor.inspect',
    'dry_run' => true,
    'completed_at_gmt' => '2026-09-12T18:00:00+00:00',
    'data' => array(
        'fingerprint' => str_repeat('b', 64),
        'document' => array(
            'post_id' => 1216,
            'document_type' => 'single-post',
            'edit_mode' => 'builder',
            'template_type' => 'single-post',
            'elementor_version' => '4.2.3',
            'conditions' => array('private-condition-must-not-leak'),
            'data' => array(array(
                'id' => 'container1',
                'elType' => 'container',
                'settings' => array('api_key' => 'secret-must-not-leak'),
                'elements' => array(array(
                    'id' => 'abc12345',
                    'elType' => 'widget',
                    'widgetType' => 'heading',
                    'settings' => array(
                        'title' => 'Client',
                        '__dynamic__' => array('title' => '[elementor-tag name="field_example:client"]'),
                        'api_key' => 'secret-must-not-leak',
                    ),
                    'elements' => array(),
                )),
            )),
        ),
    ),
);
$inspectRequestPath = $writeJson('receipt-inspect-request.json', $inspect);
$inspectResultPath = $writeJson('receipt-inspect-result.json', $inspectResult);
$inspectReceiptPath = $tmp . '/receipt-inspect.json';
list($status) = $run($receiptBuilder, array($inspectRequestPath, $inspectResultPath, $inspectReceiptPath));
if (0 !== $status || ! is_file($inspectReceiptPath)) {
    fwrite(STDERR, "Could not build public Elementor inspect receipt.\n");
    exit(1);
}
$inspectReceiptRaw = (string) file_get_contents($inspectReceiptPath);
$inspectReceipt = json_decode($inspectReceiptRaw, true, 512, JSON_THROW_ON_ERROR);
if (($inspectReceipt['document_fingerprint'] ?? '') !== str_repeat('b', 64) || ($inspectReceipt['post_id'] ?? 0) !== 1216) {
    fwrite(STDERR, "Elementor inspect receipt is missing safe document identity.\n");
    exit(1);
}
$elements = isset($inspectReceipt['elements']) && is_array($inspectReceipt['elements']) ? $inspectReceipt['elements'] : array();
if (count($elements) < 2 || ($elements[1]['id'] ?? '') !== 'abc12345' || ($elements[1]['settings']['title'] ?? '') !== 'Client') {
    fwrite(STDERR, "Elementor inspect receipt did not expose the bounded element summary.\n");
    exit(1);
}
foreach (array('secret-must-not-leak', 'private-condition-must-not-leak', 'conditions') as $needle) {
    if (false !== strpos($inspectReceiptRaw, $needle)) {
        fwrite(STDERR, "Elementor inspect receipt leaked non-public document data.\n");
        exit(1);
    }
}

$patchResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $confirmedPatch['request_id'],
    'action' => 'elementor.patch_element',
    'dry_run' => false,
    'completed_at_gmt' => '2026-09-12T18:01:00+00:00',
    'data' => array(
        'post_id' => 1216,
        'element_id' => 'abc12345',
        'before_element' => array(
            'id' => 'abc12345',
            'elType' => 'widget',
            'widgetType' => 'heading',
            'settings' => array('title' => 'Client', 'private_note' => 'must-not-leak'),
        ),
        'after_element' => array(
            'id' => 'abc12345',
            'elType' => 'widget',
            'widgetType' => 'heading',
            'settings' => array('title' => 'Impact', 'private_note' => 'must-not-leak'),
        ),
        'rollback_request_id' => 'rollback-private-id',
    ),
);
$patchRequestPath = $writeJson('receipt-patch-request.json', $confirmedPatch);
$patchResultPath = $writeJson('receipt-patch-result.json', $patchResult);
$patchReceiptPath = $tmp . '/receipt-patch.json';
list($status) = $run($receiptBuilder, array($patchRequestPath, $patchResultPath, $patchReceiptPath));
if (0 !== $status || ! is_file($patchReceiptPath)) {
    fwrite(STDERR, "Could not build public Elementor patch receipt.\n");
    exit(1);
}
$patchReceiptRaw = (string) file_get_contents($patchReceiptPath);
$patchReceipt = json_decode($patchReceiptRaw, true, 512, JSON_THROW_ON_ERROR);
if (true !== ($patchReceipt['readback_verified'] ?? null) || empty($patchReceipt['rollback_available']) || ($patchReceipt['element_id'] ?? '') !== 'abc12345') {
    fwrite(STDERR, "Elementor patch receipt did not verify the bounded mutation.\n");
    exit(1);
}
foreach (array('before_fingerprint', 'after_fingerprint') as $key) {
    if (empty($patchReceipt[$key]) || ! preg_match('/^[a-f0-9]{64}$/D', (string) $patchReceipt[$key])) {
        fwrite(STDERR, "Elementor patch receipt is missing a safe fingerprint: {$key}\n");
        exit(1);
    }
}
foreach (array('Client', 'Impact', 'must-not-leak', 'rollback-private-id') as $needle) {
    if (false !== strpos($patchReceiptRaw, $needle)) {
        fwrite(STDERR, "Elementor patch receipt leaked raw mutation data.\n");
        exit(1);
    }
}

echo "Public Elementor runtime contract OK.\n";
