<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/CodeSnippetsAdapter.php');
$validator = $root . '/scripts/validate-public-request.php';
$receiptBuilder = $root . '/scripts/build-public-receipt.php';

foreach (array(
    "register('code_snippets.patch'",
    "register('code_snippets.restore_code'",
    "match_code_contains",
    "substr_count",
    "update_snippet_fields",
    "expected_code_fingerprint",
    "readback_verified",
    "'_rollback'",
) as $needle) {
    if (false === strpos($adapter, $needle)) {
        fwrite(STDERR, "Code Snippets adapter contract is missing: {$needle}\n");
        exit(1);
    }
}

foreach (array('execute_snippet(', 'save_snippet(', 'delete_snippet(', 'create_item(') as $needle) {
    if (false !== strpos($adapter, $needle)) {
        fwrite(STDERR, "Code Snippets adapter exposes a forbidden broad primitive: {$needle}\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/wpconnector-code-snippets-' . bin2hex(random_bytes(6));
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

$base = array(
    'version' => 1,
    'request_id' => 'snippet-patch-contract-001',
    'action' => 'code_snippets.patch',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array(
        'match_code_contains' => 'wab_category_archive_filter',
        'replacements' => array(
            array(
                'find' => "'priority-order-v3-exact-plugin-post-ids'",
                'replace' => "'priority-order-v5-portfolio-strength'",
            ),
            array(
                'find' => 'function wab_caf_get_priority_cases( $term_slug ) { OLD }',
                'replace' => 'function wab_caf_get_priority_cases( $term_slug ) { NEW }',
            ),
        ),
    ),
);

list($status, $output) = $run($validator, array($write('dry.json', $base)));
if (0 !== $status) {
    fwrite(STDERR, "Valid Code Snippets dry-run was rejected: {$output}\n");
    exit(1);
}

$live = $base;
$live['request_id'] = 'snippet-patch-contract-002';
$live['dry_run'] = false;
$live['confirm'] = true;
list($status) = $run($validator, array($write('live-no-fingerprint.json', $live)));
if (0 === $status) {
    fwrite(STDERR, "Live Code Snippets patch passed without expected_fingerprint.\n");
    exit(1);
}

$live['expected_fingerprint'] = str_repeat('a', 64);
list($status, $output) = $run($validator, array($write('live.json', $live)));
if (0 !== $status) {
    fwrite(STDERR, "Guarded live Code Snippets patch was rejected: {$output}\n");
    exit(1);
}

$tooMany = $base;
$tooMany['request_id'] = 'snippet-patch-contract-003';
$tooMany['payload']['replacements'] = array_fill(0, 5, array('find' => 'a', 'replace' => 'b'));
list($status) = $run($validator, array($write('too-many.json', $tooMany)));
if (0 === $status) {
    fwrite(STDERR, "Code Snippets patch accepted more than four replacements.\n");
    exit(1);
}

$extra = $base;
$extra['request_id'] = 'snippet-patch-contract-004';
$extra['payload']['arbitrary_code'] = 'echo 1;';
list($status) = $run($validator, array($write('extra.json', $extra)));
if (0 === $status) {
    fwrite(STDERR, "Code Snippets patch accepted an unsupported payload key.\n");
    exit(1);
}

$restore = $base;
$restore['request_id'] = 'snippet-patch-contract-005';
$restore['action'] = 'code_snippets.restore_code';
$restore['payload'] = array(
    'snippet_id' => 1,
    'code' => 'echo 1;',
    'expected_code_fingerprint' => str_repeat('b', 64),
);
list($status) = $run($validator, array($write('direct-restore.json', $restore)));
if (0 === $status) {
    fwrite(STDERR, "Internal Code Snippets rollback action was directly exposed to public transport.\n");
    exit(1);
}

$receiptRequest = $write('receipt-request.json', $live);
$receiptResult = $write('receipt-result.json', array(
    'version' => 1,
    'ok' => true,
    'request_id' => $live['request_id'],
    'action' => 'code_snippets.patch',
    'dry_run' => false,
    'completed_at_gmt' => '2026-09-29T02:00:00+00:00',
    'data' => array(
        'snippet' => array(
            'id' => 77,
            'name' => 'Portfolio filter',
            'scope' => 'front-end',
            'type' => 'php',
            'priority' => 10,
            'active' => true,
        ),
        'replacement_count' => 2,
        'before_code_fingerprint' => str_repeat('1', 64),
        'after_code_fingerprint' => str_repeat('2', 64),
        'before_fingerprint' => str_repeat('3', 64),
        'after_fingerprint' => str_repeat('4', 64),
        'readback_verified' => true,
        'rollback_request_id' => $live['request_id'],
        'code' => 'SECRET SNIPPET SOURCE MUST NOT LEAK',
    ),
));
$receiptPath = $tmp . '/receipt.json';
list($status, $output) = $run($receiptBuilder, array($receiptRequest, $receiptResult, $receiptPath));
if (0 !== $status || ! is_file($receiptPath)) {
    fwrite(STDERR, "Could not build Code Snippets public receipt: {$output}\n");
    exit(1);
}

$raw = (string) file_get_contents($receiptPath);
$receipt = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

if (
    77 !== ($receipt['snippet_id'] ?? null) ||
    true !== ($receipt['readback_verified'] ?? null) ||
    true !== ($receipt['rollback_available'] ?? null) ||
    2 !== ($receipt['replacement_count'] ?? null)
) {
    fwrite(STDERR, "Code Snippets public receipt is missing verification metadata.\n");
    exit(1);
}

foreach (array(
    'SECRET SNIPPET SOURCE MUST NOT LEAK',
    'wab_category_archive_filter',
    'priority-order-v3-exact-plugin-post-ids',
    'function wab_caf_get_priority_cases',
) as $needle) {
    if (false !== strpos($raw, $needle)) {
        fwrite(STDERR, "Code Snippets public receipt leaked request/source content: {$needle}\n");
        exit(1);
    }
}

foreach (array('before_code_fingerprint','after_code_fingerprint','before_fingerprint','after_fingerprint') as $key) {
    if (empty($receipt[$key]) || ! preg_match('/^[a-f0-9]{64}$/D', (string) $receipt[$key])) {
        fwrite(STDERR, "Code Snippets public receipt is missing fingerprint: {$key}\n");
        exit(1);
    }
}

echo "code snippets contract OK\n";
