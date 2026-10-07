<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$validatorSource = $root . '/scripts/validate-public-request.php';
$legacySource = $root . '/scripts/validate-public-request-legacy.php';

if (! is_file($validatorSource) || ! is_file($legacySource)) {
    fwrite(STDERR, "Public validator sources are missing.\n");
    exit(1);
}

$workflowPaths = array(
    '.github/workflows/wordpress-request.yml',
    '.github/workflows/wordpress-zero-config-execute.yml',
);
foreach ($workflowPaths as $relative) {
    $workflow = (string) file_get_contents($root . '/' . $relative);
    if (strpos($workflow, 'validate-public-request.php') === false) {
        fwrite(STDERR, "Trusted public validator is missing from {$relative}.\n");
        exit(1);
    }
    if (strpos($workflow, 'validate-public-request-legacy.php') === false) {
        fwrite(STDERR, "Trusted legacy validator dependency is not provisioned by {$relative}.\n");
        exit(1);
    }
}

foreach (glob($root . '/.github/workflows/*.yml') ?: array() as $path) {
    $workflow = (string) file_get_contents($path);
    if (strpos($workflow, 'validate-public-request.php') !== false && strpos($workflow, 'validate-public-request-legacy.php') === false) {
        fwrite(STDERR, 'Workflow copies the public validator without its dependency: ' . basename($path) . "\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/wpconnector-public-validator-workspace-' . bin2hex(random_bytes(6));
$scripts = $tmp . '/scripts';
if (! mkdir($scripts, 0700, true) && ! is_dir($scripts)) {
    fwrite(STDERR, "Could not create isolated validator workspace.\n");
    exit(1);
}
copy($validatorSource, $scripts . '/validate-public-request.php');
copy($legacySource, $scripts . '/validate-public-request-legacy.php');

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

$run = static function (array $request, string $name) use ($tmp, $scripts): array {
    $path = $tmp . '/' . $name . '.json';
    file_put_contents($path, json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    $output = array();
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scripts . '/validate-public-request.php') . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    return array($status, implode("\n", $output));
};

$base = static function (string $requestId, string $action, array $payload = array(), bool $dryRun = true, bool $confirm = false): array {
    return array(
        'version' => 1,
        'site_url' => 'https://example.com',
        'request_id' => $requestId,
        'action' => $action,
        'dry_run' => $dryRun,
        'confirm' => $confirm,
        'payload' => $payload,
    );
};

$assertAccepted = static function (array $request, string $name) use ($run): void {
    list($status, $output) = $run($request, $name);
    if (0 !== $status) {
        fwrite(STDERR, "Expected accepted request {$name}, got: {$output}\n");
        exit(1);
    }
};
$assertRejected = static function (array $request, string $name) use ($run): void {
    list($status, $output) = $run($request, $name);
    if (0 === $status) {
        fwrite(STDERR, "Expected rejected request {$name}, got success: {$output}\n");
        exit(1);
    }
};

$portfolioFields = array(
    'field_portfolio_stat_1_value' => '11.7.19',
    'field_portfolio_stat_1_label' => 'Stable Release',
    'field_portfolio_stat_2_value' => '11',
    'field_portfolio_stat_2_label' => 'Optimization Areas',
    'field_portfolio_stat_3_value' => 'WooCommerce',
    'field_portfolio_stat_3_label' => 'Store Safeguards',
    'field_portfolio_stat_4_value' => 'Safe preset',
    'field_portfolio_stat_4_label' => 'Staged Tuning',
);
$portfolioDry = $base('trusted-portfolio-dry-0001', 'acf.portfolio_stats_update', array('post_id' => 4090, 'fields' => $portfolioFields), true, false);
$assertAccepted($portfolioDry, 'portfolio-dry');

$portfolioLive = $portfolioDry;
$portfolioLive['request_id'] = 'trusted-portfolio-live-0001';
$portfolioLive['dry_run'] = false;
$portfolioLive['confirm'] = true;
$portfolioLive['expected_fingerprint'] = str_repeat('a', 64);
$assertAccepted($portfolioLive, 'portfolio-live');

$portfolioMissingConfirm = $portfolioLive;
$portfolioMissingConfirm['request_id'] = 'trusted-portfolio-bad-0001';
$portfolioMissingConfirm['confirm'] = false;
$assertRejected($portfolioMissingConfirm, 'portfolio-live-missing-confirm');

$portfolioMissingFingerprint = $portfolioLive;
$portfolioMissingFingerprint['request_id'] = 'trusted-portfolio-bad-0002';
unset($portfolioMissingFingerprint['expected_fingerprint']);
$assertRejected($portfolioMissingFingerprint, 'portfolio-live-missing-fingerprint');

$assertAccepted($base('trusted-update-check-0001', 'connector.update.check'), 'connector-update-check');
$assertAccepted($base('trusted-update-apply-dry-0001', 'connector.update.apply'), 'connector-update-apply-dry');
$updateLive = $base('trusted-update-apply-live-0001', 'connector.update.apply', array(), false, true);
$updateLive['expected_fingerprint'] = str_repeat('b', 64);
$assertAccepted($updateLive, 'connector-update-apply-live');

$assertAccepted($base('trusted-post-get-0001', 'post.get', array('id' => 4933)), 'post-get');
$assertAccepted($base('trusted-post-list-0001', 'post.list', array('post_type' => 'post', 'status' => 'publish', 'per_page' => 20, 'page' => 1)), 'post-list');
$assertAccepted($base('trusted-acf-update-0001', 'acf.update', array('post_id' => 4933, 'fields' => array('description_1' => 'Preview'))), 'acf-update');
$assertAccepted($base('trusted-acf-groups-0001', 'acf.field_groups', array('post_id' => 4933)), 'acf-field-groups');
$assertAccepted($base('trusted-elementor-inspect-0001', 'elementor.inspect', array('id' => 4933)), 'elementor-inspect');
$assertAccepted($base('trusted-elementor-patch-0001', 'elementor.patch_element', array('id' => 4933, 'element_id' => 'abc123', 'settings' => array('title' => 'Preview'))), 'elementor-patch-dry');
$assertAccepted($base('trusted-rollback-0001', 'connector.rollback', array('request_id' => 'source-request-0001')), 'connector-rollback-dry');

$assertRejected($base('trusted-unsafe-0001', 'plugin.install_package', array('source_path' => 'plugin-packages/private.zip')), 'unsafe-unconfirmed-action');
$assertRejected($base('trusted-unsafe-0002', 'post.get', array('id' => 4933), false, true), 'post-get-live');
$assertRejected($base('trusted-unsafe-0003', 'acf.update', array('post_id' => 4933, 'fields' => array('api_key' => 'no'))), 'secret-like-acf-key');
$stateToken = $base('trusted-unsafe-0004', 'post.get', array('id' => 4933));
$stateToken['expected_state_token'] = str_repeat('c', 64);
$assertRejected($stateToken, 'public-state-token');

echo "public validator trusted workspace contract OK\n";
