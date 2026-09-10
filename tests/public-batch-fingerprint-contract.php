<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';

if (! is_file($validator)) {
    fwrite(STDERR, "Public request validator is missing.\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'wpconnector-batch-fingerprint-');
if (false === $tmp) {
    fwrite(STDERR, "Could not create temporary request file.\n");
    exit(1);
}
register_shutdown_function(static function () use ($tmp): void {
    @unlink($tmp);
});

$run = static function (array $request) use ($validator, $tmp): int {
    file_put_contents($tmp, json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    $output = array();
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($validator) . ' ' . escapeshellarg($tmp) . ' 2>&1', $output, $status);
    return $status;
};

$operations = array(
    array(
        'action' => 'acf.update',
        'payload' => array(
            'post_id' => 4933,
            'fields' => array('description_1' => 'Problem text.'),
        ),
    ),
    array(
        'action' => 'acf.update',
        'payload' => array(
            'post_id' => 4934,
            'fields' => array('description_2' => 'Solution text.'),
        ),
    ),
);

$dryRun = array(
    'version' => 1,
    'request_id' => 'batch-fingerprint-dry-run',
    'action' => 'connector.batch',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('operations' => $operations),
);

if (0 !== $run($dryRun)) {
    fwrite(STDERR, "Dry-run batch without fingerprints should remain valid.\n");
    exit(1);
}

$confirmedWithout = $dryRun;
$confirmedWithout['request_id'] = 'batch-fingerprint-confirmed-missing';
$confirmedWithout['dry_run'] = false;
$confirmedWithout['confirm'] = true;
if (0 === $run($confirmedWithout)) {
    fwrite(STDERR, "Confirmed batch without fingerprints was accepted.\n");
    exit(1);
}

$confirmedPartial = $confirmedWithout;
$confirmedPartial['request_id'] = 'batch-fingerprint-confirmed-partial';
$confirmedPartial['payload']['operations'][0]['expected_fingerprint'] = str_repeat('a', 64);
if (0 === $run($confirmedPartial)) {
    fwrite(STDERR, "Confirmed batch with a missing nested fingerprint was accepted.\n");
    exit(1);
}

$confirmedValid = $confirmedWithout;
$confirmedValid['request_id'] = 'batch-fingerprint-confirmed-valid';
$confirmedValid['payload']['operations'][0]['expected_fingerprint'] = str_repeat('a', 64);
$confirmedValid['payload']['operations'][1]['expected_fingerprint'] = str_repeat('b', 64);
if (0 !== $run($confirmedValid)) {
    fwrite(STDERR, "Confirmed batch with valid nested fingerprints was rejected.\n");
    exit(1);
}

echo "public batch fingerprint contract OK\n";
