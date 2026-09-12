<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
$receiptBuilder = $root . '/scripts/build-public-receipt.php';
$adapterPath = $root . '/plugin/wordpressconnector/includes/Adapters/AcfAdapter.php';

foreach (array($validator, $receiptBuilder, $adapterPath) as $required) {
    if (! is_file($required)) {
        fwrite(STDERR, "Required ACF public-runtime file is missing: {$required}\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/wpconnector-public-acf-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    fwrite(STDERR, "Could not create temporary directory.\n");
    exit(1);
}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (! is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: array() as $name) {
        if ('.' === $name || '..' === $name) continue;
        $removeTree($path . DIRECTORY_SEPARATOR . $name);
    }
    @rmdir($path);
};
register_shutdown_function(static function () use ($tmp, $removeTree): void { $removeTree($tmp); });

$writeJson = static function (string $name, array $data) use ($tmp): string {
    $path = $tmp . '/' . $name;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
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

$fields = array(
    array('key' => 'field_webactueel_portfolio_stat_1', 'name' => 'portfolio_stat_1', 'label' => 'Portfolio stat 1'),
    array('key' => 'field_webactueel_portfolio_stat_2', 'name' => 'portfolio_stat_2', 'label' => 'Portfolio stat 2'),
    array('key' => 'field_webactueel_portfolio_stat_3', 'name' => 'portfolio_stat_3', 'label' => 'Portfolio stat 3'),
    array('key' => 'field_webactueel_portfolio_stat_4', 'name' => 'portfolio_stat_4', 'label' => 'Portfolio stat 4'),
);

$fieldGroups = array(
    'version' => 1,
    'request_id' => 'public-acf-groups-0001',
    'action' => 'acf.field_groups',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('post_id' => 4470),
);
list($status) = $run($validator, array($writeJson('field-groups.json', $fieldGroups)));
if (0 !== $status) {
    fwrite(STDERR, "Safe public acf.field_groups was rejected.\n");
    exit(1);
}

$ensure = array(
    'version' => 1,
    'request_id' => 'public-acf-schema-0001',
    'action' => 'acf.schema.ensure_text_fields',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('post_id' => 4470, 'group_key' => 'group_portfolio123', 'fields' => $fields),
);
list($status) = $run($validator, array($writeJson('ensure-dry-run.json', $ensure)));
if (0 !== $status) {
    fwrite(STDERR, "Safe public ACF schema dry-run was rejected.\n");
    exit(1);
}

$confirmed = $ensure;
$confirmed['request_id'] = 'public-acf-schema-0002';
$confirmed['dry_run'] = false;
$confirmed['confirm'] = true;
$confirmed['expected_fingerprint'] = str_repeat('a', 64);
list($status) = $run($validator, array($writeJson('ensure-confirmed.json', $confirmed)));
if (0 !== $status) {
    fwrite(STDERR, "Safe confirmed public ACF schema request was rejected.\n");
    exit(1);
}

$forbidden = array();
$case = $confirmed; unset($case['expected_fingerprint']); $case['request_id'] = 'public-acf-forbidden-0001';
$forbidden['confirmed without fingerprint'] = $case;
$case = $ensure; $case['request_id'] = 'public-acf-forbidden-0002'; $case['payload']['fields'][0]['type'] = 'textarea';
$forbidden['field type override'] = $case;
$case = $ensure; $case['request_id'] = 'public-acf-forbidden-0003'; $case['payload']['group_key'] = '../unsafe';
$forbidden['unsafe group key'] = $case;
$case = $fieldGroups; $case['request_id'] = 'public-acf-forbidden-0004'; $case['dry_run'] = false; $case['confirm'] = true;
$forbidden['non-dry field group discovery'] = $case;
$case = $ensure; $case['request_id'] = 'public-acf-forbidden-0005'; $case['payload']['fields'][0]['name'] = 'api_key';
$forbidden['secret-like field name'] = $case;
$case = array(
    'version' => 1,
    'request_id' => 'public-acf-forbidden-0006',
    'action' => 'connector.batch',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('operations' => array(array('action' => 'acf.schema.ensure_text_fields', 'payload' => $ensure['payload']))),
);
$forbidden['schema mutation inside batch'] = $case;

foreach ($forbidden as $label => $request) {
    list($status) = $run($validator, array($writeJson('forbidden-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.json', $request)));
    if (0 === $status) {
        fwrite(STDERR, "Forbidden ACF public-runtime case passed: {$label}\n");
        exit(1);
    }
}

$groupResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $fieldGroups['request_id'],
    'action' => 'acf.field_groups',
    'dry_run' => true,
    'data' => array('field_groups' => array(array(
        'key' => 'group_portfolio123',
        'title' => 'Portfolio',
        'fields' => array(
            array('key' => 'field_existing_client', 'name' => 'client', 'label' => 'Client', 'type' => 'text', 'required' => false),
            array('key' => 'field_existing_secret', 'name' => 'private_note', 'label' => 'Private Note', 'type' => 'text', 'required' => false, 'value' => 'must-not-leak'),
        ),
    ))),
);
$groupReceipt = $tmp . '/group-receipt.json';
list($status) = $run($receiptBuilder, array($writeJson('group-request.json', $fieldGroups), $writeJson('group-result.json', $groupResult), $groupReceipt));
$groupRaw = is_file($groupReceipt) ? (string) file_get_contents($groupReceipt) : '';
$groupData = $groupRaw !== '' ? json_decode($groupRaw, true, 512, JSON_THROW_ON_ERROR) : array();
if (0 !== $status || ($groupData['field_groups'][0]['key'] ?? '') !== 'group_portfolio123' || empty($groupData['schema_fingerprint'])) {
    fwrite(STDERR, "Public ACF field-group receipt is incomplete.\n");
    exit(1);
}
if (false !== strpos($groupRaw, 'must-not-leak') || false !== strpos($groupRaw, '"value"')) {
    fwrite(STDERR, "Public ACF field-group receipt leaked field values.\n");
    exit(1);
}

$before = array();
$after = array();
foreach ($fields as $definition) {
    $before[$definition['key']] = null;
    $after[$definition['key']] = array(
        'key' => $definition['key'],
        'name' => $definition['name'],
        'label' => $definition['label'],
        'type' => 'text',
        'parent' => 'group_portfolio123',
        'required' => false,
    );
}
$dryResult = array(
    'version' => 1,
    'ok' => true,
    'request_id' => $ensure['request_id'],
    'action' => 'acf.schema.ensure_text_fields',
    'dry_run' => true,
    'data' => array(
        'post_id' => 4470,
        'group_key' => 'group_portfolio123',
        'before' => $before,
        'requested' => array_map(static function (array $field): array { return $field + array('type' => 'text'); }, $fields),
        'would_create' => array_column($fields, 'key'),
        '_current_fingerprint' => str_repeat('b', 64),
    ),
);
$dryReceipt = $tmp . '/schema-dry-receipt.json';
list($status) = $run($receiptBuilder, array($writeJson('schema-dry-request.json', $ensure), $writeJson('schema-dry-result.json', $dryResult), $dryReceipt));
$dryData = is_file($dryReceipt) ? json_decode((string) file_get_contents($dryReceipt), true, 512, JSON_THROW_ON_ERROR) : array();
if (0 !== $status || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($dryData['schema_fingerprint'] ?? '')) || count($dryData['would_create'] ?? array()) !== 4) {
    fwrite(STDERR, "Public ACF schema dry-run receipt is incomplete.\n");
    exit(1);
}

$confirmedResult = $dryResult;
$confirmedResult['request_id'] = $confirmed['request_id'];
$confirmedResult['dry_run'] = false;
$confirmedResult['data']['after'] = $after;
$confirmedResult['data']['created'] = array_column($fields, 'key');
$confirmedResult['data']['rollback_request_id'] = 'must-not-leak';
$confirmedReceipt = $tmp . '/schema-confirm-receipt.json';
list($status) = $run($receiptBuilder, array($writeJson('schema-confirm-request.json', $confirmed), $writeJson('schema-confirm-result.json', $confirmedResult), $confirmedReceipt));
$confirmedRaw = is_file($confirmedReceipt) ? (string) file_get_contents($confirmedReceipt) : '';
$confirmedData = $confirmedRaw !== '' ? json_decode($confirmedRaw, true, 512, JSON_THROW_ON_ERROR) : array();
if (0 !== $status || true !== ($confirmedData['readback_verified'] ?? null) || empty($confirmedData['rollback_available']) || count($confirmedData['created'] ?? array()) !== 4) {
    fwrite(STDERR, "Public ACF schema confirmed receipt did not verify mutation/rollback.\n");
    exit(1);
}
if (false !== strpos($confirmedRaw, 'must-not-leak')) {
    fwrite(STDERR, "Public ACF schema receipt leaked rollback identity.\n");
    exit(1);
}

$adapter = (string) file_get_contents($adapterPath);
foreach (array("acf.schema.ensure_text_fields", "acf.schema.remove_text_fields", "'_rollback'") as $needle) {
    if (false === strpos($adapter, $needle)) {
        fwrite(STDERR, "ACF adapter is missing required bounded schema/rollback contract token: {$needle}\n");
        exit(1);
    }
}
if (false !== strpos($adapter, 'acf_update_field_group(')) {
    fwrite(STDERR, "Bounded ACF schema path must not rewrite field-group definitions.\n");
    exit(1);
}

echo "Public ACF schema runtime contract OK.\n";
