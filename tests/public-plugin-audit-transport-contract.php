<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$validator = $root . '/scripts/validate-public-request.php';
$builder = $root . '/scripts/build-public-receipt.php';
$tmp = sys_get_temp_dir() . '/wpc-public-plugin-audit-' . bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
    throw new RuntimeException('Cannot create isolated plugin audit transport fixture.');
}
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: array() as $path) @unlink($path);
    @rmdir($tmp);
});
$store = static function (string $name, array $value) use ($tmp): string {
    $path = $tmp . '/' . $name . '.json';
    file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    return $path;
};
$run = static function (string $script, array $arguments): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($arguments as $arg) $command .= ' ' . escapeshellarg($arg);
    $output = array();
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return array($exitCode, implode("\n", $output));
};
$req = array('version'=>1,'site_url'=>'https://isohulk.example.test/',
    'request_id'=>'plugin-audit-transport-0001','action'=>'plugin.audit.inspect',
    'dry_run'=>true,'confirm'=>false,'payload'=>array());
list($exit, $log) = $run($validator, array($store('request', $req)));
if ($exit !== 0) throw new RuntimeException('Safe read-only audit request rejected: ' . $log);
$bad = array();
$x = $req; $x['dry_run'] = false; $x['confirm'] = true; $bad[] = $x;
$x = $req; $x['payload'] = array('plugin'=>'woocommerce'); $bad[] = $x;
$x = $req; $x['payload'] = 'not-an-object'; $bad[] = $x;
$x = $req; $x['expected_fingerprint'] = str_repeat('a', 64); $bad[] = $x;
$x = $req; $x['expected_state_token'] = 'private-token'; $bad[] = $x;
$x = $req; $x['site_url'] = 'http://localhost'; $bad[] = $x;
$x = $req; $x['extra_field'] = 'secret-value'; $bad[] = $x;
foreach ($bad as $index => $case) {
    list($exit) = $run($validator, array($store('bad-' . $index, $case)));
    if ($exit === 0) throw new RuntimeException('Unsafe public plugin audit request passed case ' . $index);
}

$info = array(
    'installed_count' => 3,'active_count'=>2,'inactive_count'=>1,
    'unmodeled_count'=>1,'provider_checks_count'=>1,
    'plugins'=>array(
        array('id'=>'woocommerce','active'=>true,'coverage'=>'partial',
            'checks'=>array('guest_checkout'=>'enabled','stock_management'=>'enabled','cart_page'=>'missing')),
        array('id'=>'wp_mail_smtp','active'=>true,'coverage'=>'restricted','checks'=>array()),
        array('id'=>'unmodeled_1','active'=>false,'coverage'=>'unmodeled','checks'=>array()),
    ),
    'image_lazyload_overlap'=>'unverified','checkout_qa'=>'not_run',
    'smtp_delivery_qa'=>'not_run','translation_browser_qa'=>'not_run',
    'private_settings_exported'=>false,
);
$result = array('version'=>1,'ok'=>true,'request_id'=>$req['request_id'],
    'action'=>'plugin.audit.inspect','dry_run'=>true,'data'=>$info);
$runBuilder = static function (string $name, array $result) use ($store,$run,$builder,$req): array {
    $outPath = $store('output-' . $name, array());
    list($exit, $log) = $run($builder, array($store('request-' . $name,$req),
        $store('result-' . $name,$result),$outPath));
    $receipt = $exit === 0 ? json_decode((string) file_get_contents($outPath),true,512,JSON_THROW_ON_ERROR) : array();
    return array($exit,$log,$receipt);
};
list($exit, $log, $receipt) = $runBuilder('safe', $result);
if ($exit !== 0 || ($receipt['installed_count'] ?? null) !== 3
    || ($receipt['plugins'][0]['checks']['guest_checkout'] ?? null) !== 'enabled'
    || ($receipt['private_settings_exported'] ?? null) !== false) {
    throw new RuntimeException('Safe public plugin audit receipt failed: ' . $log);
}
$receiptJson = json_encode($receipt,JSON_THROW_ON_ERROR);
foreach (array('password','secret','PRIVATE-NAME','internal.domain') as $needle) {
    if (stripos($receiptJson,$needle) !== false) {
        throw new RuntimeException('Public plugin audit receipt leaked sensitive content.');
    }
}
$variants = $result;
$variants['data']['plugins'][] = $variants['data']['plugins'][0];
$variants['data']['installed_count'] = 4;
$variants['data']['active_count'] = 3;
$variants['data']['provider_checks_count'] = 2;
list($exit, $log, $variantReceipt) = $runBuilder('repeated-registered-id', $variants);
if ($exit !== 0 || ($variantReceipt['installed_count'] ?? null) !== 4
    || count($variantReceipt['plugins'] ?? array()) !== 4) {
    throw new RuntimeException('Multiple installations sharing a static adapter ID were lost: ' . $log);
}
foreach (array(
    'nested_secret' => static function (array &$x): void {
        $x['data']['plugins'][1]['checks']['smtp_password'] = 'DO-NOT-LEAK';
    },
    'unexpected_field' => static function (array &$x): void {
        $x['data']['plugins'][0]['raw_options'] = array('secret'=>'DO-NOT-LEAK');
    },
    'arbitrary_provider_name' => static function (array &$x): void {
        $x['data']['plugins'][2]['id'] = 'PRIVATE-NAME';
    },
    'wrong_count' => static function (array &$x): void {
        $x['data']['active_count'] = 100;
    },
    'unsupported_value' => static function (array &$x): void {
        $x['data']['plugins'][0]['checks']['guest_checkout'] = 'unsafe-string';
    },
    'fictitious_qa' => static function (array &$x): void {
        $x['data']['checkout_qa'] = 'passed';
    },
    'private_export' => static function (array &$x): void {
        $x['data']['private_settings_exported'] = true;
    },
) as $name => $mutate) {
    $modified = $result;
    $mutate($modified);
    list($exit) = $runBuilder($name, $modified);
    if ($exit === 0) {
        throw new RuntimeException('Unsafe public audit projection accepted ' . $name);
    }
}
echo "Public all-plugin request boundary and secret-free receipt contract OK\n";
