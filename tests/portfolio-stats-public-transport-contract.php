<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
if (! is_file($validator)) {
    fwrite(STDERR, "Public request validator is missing.\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/wpconnector-portfolio-transport-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: array() as $path) {
        @unlink($path);
    }
    @rmdir($tmp);
});

$run = static function (array $request, string $name) use ($tmp, $validator): array {
    $path = $tmp . '/' . $name . '.json';
    file_put_contents($path, json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $output = array();
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($validator) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    return array($status, implode("\n", $output));
};

$fields = array(
    'field_portfolio_stat_1_value' => '11.7.19',
    'field_portfolio_stat_1_label' => 'Stable Release',
    'field_portfolio_stat_2_value' => '11',
    'field_portfolio_stat_2_label' => 'Optimization Areas',
    'field_portfolio_stat_3_value' => 'WooCommerce',
    'field_portfolio_stat_3_label' => 'Store Safeguards',
    'field_portfolio_stat_4_value' => 'Safe preset',
    'field_portfolio_stat_4_label' => 'Staged Tuning',
);
$dry = array(
    'version' => 1,
    'request_id' => 'portfolio-transport-dry-0001',
    'action' => 'acf.portfolio_stats_update',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('post_id' => 4090, 'fields' => $fields),
);
list($status, $output) = $run($dry, 'dry');
if (0 !== $status) {
    fwrite(STDERR, "Bounded portfolio dry-run was rejected by public transport: {$output}\n");
    exit(1);
}

$live = $dry;
$live['request_id'] = 'portfolio-transport-live-0001';
$live['dry_run'] = false;
$live['confirm'] = true;
$live['expected_fingerprint'] = str_repeat('a', 64);
list($status, $output) = $run($live, 'live');
if (0 !== $status) {
    fwrite(STDERR, "Fingerprint-gated portfolio live request was rejected: {$output}\n");
    exit(1);
}

$bad = $live;
$bad['request_id'] = 'portfolio-transport-bad-0001';
unset($bad['expected_fingerprint']);
list($status) = $run($bad, 'missing-fingerprint');
if (0 === $status) {
    fwrite(STDERR, "Portfolio live request without expected_fingerprint passed public transport.\n");
    exit(1);
}

$bad = $dry;
$bad['request_id'] = 'portfolio-transport-bad-0002';
$bad['payload']['fields'] = array('field_other_text' => 'no');
list($status) = $run($bad, 'wrong-field');
if (0 === $status) {
    fwrite(STDERR, "Portfolio field outside fixed allowlist passed public transport.\n");
    exit(1);
}

$bad = $dry;
$bad['request_id'] = 'portfolio-transport-bad-0003';
$bad['payload']['post_id'] = '4090';
list($status) = $run($bad, 'string-post-id');
if (0 === $status) {
    fwrite(STDERR, "String portfolio post_id passed public transport.\n");
    exit(1);
}

$bad = $dry;
$bad['request_id'] = 'portfolio-transport-bad-0004';
$bad['payload']['unexpected'] = true;
list($status) = $run($bad, 'unknown-payload-key');
if (0 === $status) {
    fwrite(STDERR, "Unknown portfolio payload key passed public transport.\n");
    exit(1);
}

$bad = $dry;
$bad['request_id'] = 'portfolio-transport-bad-0005';
$bad['expected_state_token'] = str_repeat('b', 64);
list($status) = $run($bad, 'state-token');
if (0 === $status) {
    fwrite(STDERR, "Public portfolio request exposed expected_state_token.\n");
    exit(1);
}

echo "Portfolio stats public transport contract OK.\n";
