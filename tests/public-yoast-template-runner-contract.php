<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Runner.php';

use Webactueel\WordPressConnector\Runtime\Runner;

// This test calls the ACTUAL runtime publicDescriptor gate, not only the
// GitHub request validator or the Yoast adapter. A mismatch between those
// layers caused the production 1.19.2 product-template dry-run failure.
$reflector = new ReflectionClass(Runner::class);
$runner = $reflector->newInstanceWithoutConstructor();
$method = $reflector->getMethod('publicDescriptor');
$method->setAccessible(true);

$descriptor = array(
    'name' => 'yoast.templates.update',
    'mutation' => true,
    'privileged' => true,
    'public_repository_safe' => false,
);
$invoke = static function (
    string $action, array $payload, bool $dryRun = true, ?string $fingerprint = null
) use ($method, $runner, $descriptor): array {
    $metadata = $descriptor;
    $metadata['name'] = $action;
    return $method->invoke($runner, $action, $payload, $metadata, $dryRun, $fingerprint);
};
$expectRejected = static function (
    array $payload, bool $dryRun = true, ?string $fingerprint = null
) use ($invoke): void {
    try {
        $invoke('yoast.templates.update', $payload, $dryRun, $fingerprint);
    } catch (RuntimeException $error) {
        return;
    }
    throw new RuntimeException('Unsafe public Yoast templates request was accepted.');
};
$allowed = array(
    'category_title' => '%%term_title%% %%page%% %%sep%% Byderma',
    'product_category_title' => '%%term_title%% %%page%% %%sep%% Byderma',
    'product_title' => '%%title%% %%sep%% Byderma',
    'product_description' => '%%excerpt%%',
);
foreach ($allowed as $key => $value) {
    $response = $invoke('yoast.templates.update', array('fields' => array($key => $value)));
    if ($response['privileged'] !== false ||
        $response['public_repository_safe'] !== false ||
        $response['mutation'] !== true) {
        throw new RuntimeException('The public Yoast runtime descriptor privilege guard drifted.');
    }
}
$invoke('yoast.templates.update', array('fields' => $allowed));
$invoke('yoast.templates.update', array('fields' => $allowed), false, str_repeat('a', 64));
$read = $invoke('yoast.templates.inspect', array());
if ($read['privileged'] !== false) {
    throw new RuntimeException('The public Yoast read descriptor did not remain scoped.');
}

$expectRejected(array('fields' => array()));
$expectRejected(array('fields' => array('page_title' => '%%title%% %%sep%% Byderma')));
$expectRejected(array('fields' => array('canonical' => 'https://example.test/')));
$expectRejected(array('fields' => array('noindex' => true)));
$expectRejected(array('fields' => array('product_title' => 'Missing product title token')));
$expectRejected(array('fields' => array('product_description' => 'Missing excerpt token')));
$expectRejected(array('fields' => array('product_description' => '%%excerpt_only%%')));
$expectRejected(array('fields' => array('product_title' => "%%title%%\nByderma")));
$expectRejected(array('fields' => array('product_title' => '%%title%% <script>')));
$expectRejected(array('fields' => array('product_title' => '%%title%%' . str_repeat('A', 155))));
$expectRejected(array('fields' => array_merge($allowed, array('post_title' => '%%title%%'))));
$expectRejected(array('fields' => array('product_title' => '%%title%% %%sep%% Byderma')), false);
$expectRejected(array('fields' => array('product_title' => '%%title%% %%sep%% Byderma')), false, 'invalid');
try {
    $invoke('yoast.templates.inspect', array('fields' => $allowed));
    throw new RuntimeException('Public Yoast inspection accepted writable payload.');
} catch (RuntimeException $error) {
    if ('Public Yoast inspection accepted writable payload.' === $error->getMessage()) {
        throw $error;
    }
}
echo "Public runtime Yoast category/product title and meta template boundary OK\n";
