<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wordpress-escape-fixture.php';

$root = dirname(__DIR__);
require_once $root . '/plugin/wordpressconnector/includes/Support/class-json.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/class-request.php';
require_once $root . '/plugin/wordpressconnector/includes/REST/class-assetstore.php';
require_once $root . '/plugin/wordpressconnector/includes/REST/class-controller.php';
require_once $root . '/plugin/wordpressconnector/includes/Security/class-policy.php';
require_once $root . '/plugin/wordpressconnector/includes/Adapters/class-pluginpackageadapter.php';

use Webactueel\WordPressConnector\Adapters\PluginPackageAdapter;
use Webactueel\WordPressConnector\REST\AssetStore;
use Webactueel\WordPressConnector\REST\Controller;
use Webactueel\WordPressConnector\Runtime\Request;

$expectRuntimeException = static function (callable $callback, string $label): void {
    try {
        $callback();
    } catch (\RuntimeException $error) {
        return;
    }
    fwrite(STDERR, "Expected RuntimeException for {$label}.\n");
    exit(1);
};

$baseRequest = array(
    'version' => 1,
    'request_id' => 'request-1234',
    'action' => 'connector.actions',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array(),
);

$valid = Request::fromArray($baseRequest + array(
    'expected_fingerprint' => str_repeat('a', 64),
    'expected_state_token' => str_repeat('b', 64),
));
if ('request-1234' !== $valid->id() || 'connector.actions' !== $valid->action() || ! $valid->dryRun() || $valid->confirm()) {
    fwrite(STDERR, "Valid strict request envelope was not preserved.\n");
    exit(1);
}

$invalidEnvelopes = array(
    'missing version' => array_diff_key($baseRequest, array('version' => true)),
    'string version' => array_merge($baseRequest, array('version' => '1')),
    'unknown top-level key' => array_merge($baseRequest, array('unexpected' => true)),
    'missing payload' => array_diff_key($baseRequest, array('payload' => true)),
    'string dry_run false' => array_merge($baseRequest, array('dry_run' => 'false')),
    'integer dry_run zero' => array_merge($baseRequest, array('dry_run' => 0)),
    'string confirm false' => array_merge($baseRequest, array('confirm' => 'false')),
    'integer confirm one' => array_merge($baseRequest, array('confirm' => 1)),
    'integer request id' => array_merge($baseRequest, array('request_id' => 12345678)),
    'integer action' => array_merge($baseRequest, array('action' => 12345678)),
);
foreach ($invalidEnvelopes as $label => $request) {
    $expectRuntimeException(static function () use ($request): void {
        Request::fromArray($request);
    }, $label);
}

foreach (array("request-1234\n", "request-1234\r", "request-1234\t", "request-1234\0") as $value) {
    $expectRuntimeException(static function () use ($baseRequest, $value): void {
        Request::fromArray(array_merge($baseRequest, array('request_id' => $value)));
    }, 'request_id control suffix');
}
foreach (array("connector.actions\n", "connector.actions\r", "connector.actions\t", "connector.actions\0") as $value) {
    $expectRuntimeException(static function () use ($baseRequest, $value): void {
        Request::fromArray(array_merge($baseRequest, array('action' => $value)));
    }, 'action control suffix');
}
foreach (array("\n", "\r", "\t", "\0") as $suffix) {
    $expectRuntimeException(static function () use ($baseRequest, $suffix): void {
        Request::fromArray(array_merge($baseRequest, array('expected_fingerprint' => str_repeat('a', 64) . $suffix)));
    }, 'fingerprint control suffix');
}

$controller = (new ReflectionClass(Controller::class))->newInstanceWithoutConstructor();
if (! $controller->validateRequestId('request-1234')) {
    fwrite(STDERR, "Controller rejected a valid request_id.\n");
    exit(1);
}
if (! $controller->validateAssetPath('plugin-packages/acme.zip')) {
    fwrite(STDERR, "Controller rejected a valid asset path.\n");
    exit(1);
}
foreach (array("request-1234\n", "request-1234\r", "request-1234\t", "request-1234\0") as $value) {
    if ($controller->validateRequestId($value)) {
        fwrite(STDERR, "Controller accepted a request_id control suffix.\n");
        exit(1);
    }
}
foreach (array("plugin-packages/acme.zip\n", "plugin-packages/acme.zip\r", "plugin-packages/acme.zip\t", "plugin-packages/acme.zip\0") as $value) {
    if ($controller->validateAssetPath($value)) {
        fwrite(STDERR, "Controller accepted an asset_path control suffix.\n");
        exit(1);
    }
}

$assetStore = new AssetStore();
$normalize = new ReflectionMethod(AssetStore::class, 'normalize_relative_path');
$assertRequestId = new ReflectionMethod(AssetStore::class, 'validated_request_id');
// PHP < 8.1 requires explicit accessibility for invoking non-public methods in tests.
$normalize->setAccessible(true);
$assertRequestId->setAccessible(true);
if ('request-1234' !== $assertRequestId->invoke($assetStore, 'request-1234')) {
    throw new RuntimeException('AssetStore did not preserve a valid request id exactly.');
}
if ('plugin-packages/acme.zip' !== $normalize->invoke($assetStore, 'plugin-packages/acme.zip')) {
    fwrite(STDERR, "AssetStore did not preserve a valid asset path exactly.\n");
    exit(1);
}
foreach (array("plugin-packages/acme.zip\n", "plugin-packages/acme.zip\r", "plugin-packages/acme.zip\t", "plugin-packages/acme.zip\0") as $value) {
    $expectRuntimeException(static function () use ($normalize, $assetStore, $value): void {
        $normalize->invoke($assetStore, $value);
    }, 'AssetStore asset_path control suffix');
}
foreach (array("request-1234\n", "request-1234\r", "request-1234\t", "request-1234\0") as $value) {
    $expectRuntimeException(static function () use ($assertRequestId, $assetStore, $value): void {
        $assertRequestId->invoke($assetStore, $value);
    }, 'AssetStore request_id control suffix');
}

$adapter = new PluginPackageAdapter();
foreach (array("plugin-packages/acme.zip\n", "plugin-packages/acme.zip\r", "plugin-packages/acme.zip\t", "plugin-packages/acme.zip\0") as $value) {
    $expectRuntimeException(static function () use ($adapter, $value): void {
        $adapter->installPackage(array('source_path' => $value), array('asset_root' => sys_get_temp_dir()));
    }, 'plugin package source_path control suffix');
}

$adapterSource = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/class-pluginpackageadapter.php');
$adapterSource .= (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/trait-pluginpackageadapteractions.php');
$assetSource = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/class-assetstore.php');
$assetSourceImplementation = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/trait-assetstoremethods.php');
if (!is_string($assetSource) || !is_string($assetSourceImplementation)) { throw new RuntimeException('Missing canonical assetstore source.'); }
$assetSource .= $assetSourceImplementation;
$requestSource = file_get_contents($root . '/plugin/wordpressconnector/includes/Runtime/class-request.php');
$controllerSource = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/class-controller.php');
$controllerImplementation = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/trait-restcontrollermethods.php');
if (!is_string($controllerSource) || !is_string($controllerImplementation)) { throw new RuntimeException('Missing canonical REST validator source.'); }
$controllerSource .= $controllerImplementation;
foreach (array('adapter' => $adapterSource, 'asset store' => $assetSource, 'request' => $requestSource, 'controller' => $controllerSource) as $name => $source) {
    if (false === $source || strpos($source, '\\z') === false) {
        fwrite(STDERR, "Strict absolute-end validation is missing from {$name}.\n");
        exit(1);
    }
}
if (false !== strpos((string) $assetSource, "trim(\$path)")) {
    fwrite(STDERR, "AssetStore must reject identity whitespace instead of trimming it.\n");
    exit(1);
}

echo "strict input contract OK\n";
