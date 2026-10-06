<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
$receiptBuilder = $root . '/scripts/build-public-receipt.php';
$policyPath = $root . '/plugin/wordpressconnector/includes/Security/Policy.php';
$runnerPath = $root . '/plugin/wordpressconnector/includes/Runtime/Runner.php';
$adapterPath = $root . '/plugin/wordpressconnector/includes/Adapters/CustomCssAdapter.php';

$tmp = sys_get_temp_dir() . '/wpconnector-public-css-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    fwrite(STDERR, "Could not create temporary directory.\n");
    exit(1);
}

$write = static function (string $name, array $data) use ($tmp): string {
    $path = $tmp . '/' . $name;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    return $path;
};
$run = static function (string $script, array $args): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);
    $output = array();
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    return array($status, implode("\n", $output));
};

$inspect = array(
    'version' => 1,
    'request_id' => 'public-css-inspect-0001',
    'action' => 'custom_css.inspect',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array(),
);
list($status) = $run($validator, array($write('inspect.json', $inspect)));
if (0 !== $status) {
    fwrite(STDERR, "Public custom_css.inspect was rejected.\n");
    exit(1);
}

$patch = array(
    'version' => 1,
    'request_id' => 'public-css-patch-0002',
    'action' => 'custom_css.patch',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array(
        'patch_id' => 'adminbar-site-icon',
        'operation' => 'upsert',
        'css' => '#wpadminbar img.site-icon{width:20px!important;height:20px!important;}',
    ),
);
list($status) = $run($validator, array($write('patch-dry.json', $patch)));
if (0 !== $status) {
    fwrite(STDERR, "Public custom_css.patch dry-run was rejected.\n");
    exit(1);
}

$confirmed = $patch;
$confirmed['request_id'] = 'public-css-patch-0003';
$confirmed['dry_run'] = false;
$confirmed['confirm'] = true;
$confirmed['expected_fingerprint'] = str_repeat('a', 64);
list($status) = $run($validator, array($write('patch-live.json', $confirmed)));
if (0 !== $status) {
    fwrite(STDERR, "Confirmed public custom_css.patch was rejected.\n");
    exit(1);
}

$withoutFingerprint = $confirmed;
$withoutFingerprint['request_id'] = 'public-css-patch-0004';
unset($withoutFingerprint['expected_fingerprint']);
list($status) = $run($validator, array($write('patch-no-fingerprint.json', $withoutFingerprint)));
if (0 === $status) {
    fwrite(STDERR, "Confirmed public custom_css.patch passed without fingerprint.\n");
    exit(1);
}

$fullReplace = $patch;
$fullReplace['request_id'] = 'public-css-update-0005';
$fullReplace['action'] = 'custom_css.update';
$fullReplace['payload'] = array('css' => 'body{display:block;}');
list($status) = $run($validator, array($write('full-update.json', $fullReplace)));
if (0 === $status) {
    fwrite(STDERR, "Full custom_css.update unexpectedly passed public transport.\n");
    exit(1);
}

foreach (array(
    '</style><script>alert(1)</script>' => 'style boundary',
    '/* wpconnector:forged */ body{}' => 'managed marker',
) as $css => $label) {
    $bad = $patch;
    $bad['request_id'] = 'public-css-bad-' . substr(hash('sha256', $label), 0, 12);
    $bad['payload']['css'] = $css;
    list($status) = $run($validator, array($write('bad-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.json', $bad)));
    if (0 === $status) {
        fwrite(STDERR, "Public custom_css.patch accepted forbidden {$label}.\n");
        exit(1);
    }
}

$removeWithCss = $patch;
$removeWithCss['request_id'] = 'public-css-remove-0006';
$removeWithCss['payload']['operation'] = 'remove';
list($status) = $run($validator, array($write('remove-with-css.json', $removeWithCss)));
if (0 === $status) {
    fwrite(STDERR, "Public custom_css.patch remove accepted a css payload.\n");
    exit(1);
}

$secretCss = 'body{outline:1px solid red}';
$inspectResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $inspect['request_id'],
    'action' => 'custom_css.inspect',
    'dry_run' => true,
    'completed_at_gmt' => '2026-10-06T20:00:00+00:00',
    'data' => array(
        'custom_css' => array('stylesheet' => 'hello-elementor', 'css' => $secretCss, 'post_id' => 99),
        'fingerprint' => str_repeat('b', 64),
    ),
);
$inspectResultPath = $write('inspect-result.json', $inspectResult);
$inspectReceiptPath = $tmp . '/inspect-receipt.json';
list($status) = $run($receiptBuilder, array($write('inspect-request.json', $inspect), $inspectResultPath, $inspectReceiptPath));
$inspectReceiptRaw = (string) file_get_contents($inspectReceiptPath);
$inspectReceipt = json_decode($inspectReceiptRaw, true, 512, JSON_THROW_ON_ERROR);
if (0 !== $status || str_contains($inspectReceiptRaw, $secretCss)) {
    fwrite(STDERR, "Public custom_css.inspect receipt leaked CSS content.\n");
    exit(1);
}
if (($inspectReceipt['css_sha256'] ?? '') !== hash('sha256', $secretCss) || ($inspectReceipt['css_bytes'] ?? -1) !== strlen($secretCss)) {
    fwrite(STDERR, "Public custom_css.inspect receipt is missing safe CSS evidence.\n");
    exit(1);
}

$patchResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $confirmed['request_id'],
    'action' => 'custom_css.patch',
    'dry_run' => false,
    'completed_at_gmt' => '2026-10-06T20:00:01+00:00',
    'data' => array(
        'patch_id' => 'adminbar-site-icon',
        'operation' => 'upsert',
        'changed' => true,
        'before' => array('stylesheet' => 'hello-elementor', 'css' => 'old-private-css', 'post_id' => 99),
        'after' => array('stylesheet' => 'hello-elementor', 'css' => 'new-private-css', 'post_id' => 99),
        'before_css_bytes' => 15,
        'after_css_bytes' => 15,
        'before_css_sha256' => hash('sha256', 'old-private-css'),
        'after_css_sha256' => hash('sha256', 'new-private-css'),
        'readback_verified' => true,
        'rollback_request_id' => $confirmed['request_id'],
    ),
);
$patchReceiptPath = $tmp . '/patch-receipt.json';
list($status) = $run($receiptBuilder, array($write('patch-request.json', $confirmed), $write('patch-result.json', $patchResult), $patchReceiptPath));
$patchReceiptRaw = (string) file_get_contents($patchReceiptPath);
$patchReceipt = json_decode($patchReceiptRaw, true, 512, JSON_THROW_ON_ERROR);
if (0 !== $status || str_contains($patchReceiptRaw, 'old-private-css') || str_contains($patchReceiptRaw, 'new-private-css')) {
    fwrite(STDERR, "Public custom_css.patch receipt leaked CSS content.\n");
    exit(1);
}
if (true !== ($patchReceipt['readback_verified'] ?? null) || empty($patchReceipt['rollback_available'])) {
    fwrite(STDERR, "Public custom_css.patch receipt did not retain readback/rollback evidence.\n");
    exit(1);
}

$policy = (string) file_get_contents($policyPath);
$runner = (string) file_get_contents($runnerPath);
$adapter = (string) file_get_contents($adapterPath);
foreach (array("'custom_css.inspect'", "'custom_css.patch'") as $needle) {
    if (! str_contains($policy, $needle)) {
        fwrite(STDERR, "Public policy is missing {$needle}.\n");
        exit(1);
    }
}
if (preg_match("/PUBLIC_REPOSITORY_ACTIONS[\\s\\S]*?'custom_css\.update'/", $policy)) {
    fwrite(STDERR, "Full custom_css.update must remain off the public action allowlist.\n");
    exit(1);
}
foreach (array(
    "if ('custom_css.inspect' === " . '$action' . ")",
    "if ('custom_css.patch' === " . '$action' . ")",
    "'custom_css.patch' => array('custom_css.update')",
) as $needle) {
    if (! str_contains($runner, $needle)) {
        fwrite(STDERR, "Runner is missing public CSS guard marker: {$needle}\n");
        exit(1);
    }
}
foreach (array(
    "register('custom_css.patch'",
    "'capability' => 'edit_css'",
    'wp_update_custom_css_post(',
    "'_rollback'",
) as $needle) {
    if (! str_contains($adapter, $needle)) {
        fwrite(STDERR, "Custom CSS adapter is missing marker: {$needle}\n");
        exit(1);
    }
}

echo "public custom css contract OK\n";
