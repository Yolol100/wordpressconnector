<?php

declare(strict_types=1);

if (! defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
foreach (array(
    'Support/Json.php',
    'Support/Fingerprint.php',
    'Security/Policy.php',
    'Runtime/Registry.php',
    'Runtime/Request.php',
    'Runtime/Result.php',
    'Runtime/Diagnostics.php',
    'Runtime/SnapshotStore.php',
    'Runtime/ProcessedStore.php',
    'Runtime/Runner.php',
    'Adapters/CustomCssAdapter.php',
    'Adapters/PortfolioStatsAdapter.php',
) as $path) {
    require_once $root . $path;
}

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Runtime\SnapshotStore;
use Webactueel\WordPressConnector\Runtime\ProcessedStore;
use Webactueel\WordPressConnector\Security\Policy;

Policy::setPublicRepositoryContext(false);
$registry = new Registry();
$reads = 0;
$writes = 0;
$registry->register('post.list', static function (array $payload, array $context) use (&$reads): array {
    ++$reads;
    return array('marker' => (string) ($payload['marker'] ?? ''), 'dry_run' => ! empty($context['dry_run']));
});
$registry->register('post.update', static function (array $payload, array $context) use (&$writes): array {
    ++$writes;
    return array('updated' => true);
}, array('mutation' => true));
$registry->register('private.secret', static function (): array {
    return array('secret' => 'never export');
}, array('sensitive' => true));
$registry->register('privileged.read', static function (): array {
    return array('admin' => true);
}, array('privileged' => true, 'capability' => 'manage_options'));
$runner = new Runner($registry, new SnapshotStore(), new ProcessedStore());
$descriptor = $registry->descriptor('connector.read_batch');
if ($descriptor['mutation'] || $descriptor['privileged'] || $descriptor['sensitive']) {
    throw new RuntimeException('Read batch cannot be a mutation or privileged shortcut.');
}
$seq = 0;
$run = static function (array $operations) use ($runner, &$seq): array {
    ++$seq;
    return $runner->run(Request::fromArray(array(
        'version' => 1,
        'request_id' => 'read-fast-path-' . $seq,
        'action' => 'connector.read_batch',
        'payload' => array('operations' => $operations),
        'dry_run' => false,
        'confirm' => false,
    )));
};
$good = $run(array(
    array('action' => 'post.list', 'payload' => array('marker' => 'first')),
    array('action' => 'post.list', 'payload' => array('marker' => 'second')),
));
if (! $good['ok'] || count($good['data']['operations']) !== 2
    || $good['data']['operations'][0]['result']['marker'] !== 'first'
    || $good['data']['operations'][1]['result']['dry_run'] !== true || $reads !== 2) {
    throw new RuntimeException('One-request readonly fast path failed.');
}
$start = $reads;
$reject = $run(array(
    array('action' => 'post.list', 'payload' => array('marker' => 'must-not-read')),
    array('action' => 'post.update', 'payload' => array('id' => 23)),
));
if ($reject['ok'] || $reads !== $start || $writes !== 0) {
    throw new RuntimeException('Mutation in read batch must be rejected before all other reads.');
}
foreach (array(
    array(array('action' => 'connector.read_batch', 'payload' => array('operations' => array()))),
    array(array('action' => 'private.secret', 'payload' => array())),
    array(array('action' => 'privileged.read', 'payload' => array())),
    array(array('action' => 'post.list', 'payload' => array(), 'dry_run' => false)),
    array(array('action' => 'post.list')),
) as $cases) {
    $result = $run($cases);
    if ($result['ok'] || $reads !== $start || $writes !== 0) {
        throw new RuntimeException('Unpermitted, nested or malformed read batch was accepted.');
    }
}

// Public-mode policy stays in force at both the wrapper and every leaf.
Policy::setPublicRepositoryContext(true);
$allowed = $run(array(array('action' => 'post.list', 'payload' => array('marker' => 'public'))));
if (! $allowed['ok'] || $reads !== $start + 1) {
    throw new RuntimeException('Allowlisted public read failed.');
}
$start = $reads;
$denied = false;
try {
    $blocked = $run(array(
        array('action' => 'post.list', 'payload' => array()),
        array('action' => 'privileged.read', 'payload' => array()),
    ));
    $denied = ! $blocked['ok'];
} catch (RuntimeException $error) {
    $denied = false !== strpos($error->getMessage(), 'Unsafe public read batch operation');
}
if (! $denied || $reads !== $start) {
    throw new RuntimeException('Public policy did not block read-batch leaf before any reads.');
}
Policy::setPublicRepositoryContext(false);
// Unknown field names and associative batch keys must not be reflected.
$diagnosticMarker = 'qa_opaque_diagnostic_marker_7e21';
$rejectWithoutReflection = static function (callable $call, string $expected) use ($diagnosticMarker): void {
    try {
        $call();
    } catch (RuntimeException $error) {
        if (false === strpos($error->getMessage(), $expected)
            || false !== strpos($error->getMessage(), $diagnosticMarker)) {
            throw new LogicException('Unsafe or unexpected runtime diagnostic.');
        }
        return;
    }
    throw new LogicException('Malformed payload unexpectedly accepted.');
};
foreach (array('readBatch', 'batch') as $method) {
    $rejectWithoutReflection(static function () use ($runner, $method, $diagnosticMarker): void {
        $runner->$method(array('operations' => array($diagnosticMarker => 'invalid')), array());
    }, 'at index 0');
}
if ($reads !== $start || $writes !== 0) {
    throw new LogicException('Invalid batch reached an execution handler.');
}
$guardCases = array(
    array('assert_public_custom_css_payload', array(array($diagnosticMarker => true), true)),
    array('assert_public_custom_css_rollback_payload', array(array($diagnosticMarker => true))),
    array('assert_public_plugin_install_payload', array(array($diagnosticMarker => true))),
    array('assert_public_cache_flush_payload', array(array($diagnosticMarker => true))),
    array('assert_public_code_snippets_patch_payload', array(array($diagnosticMarker => true))),
    array('assert_public_code_snippets_patch_payload', array(array('snippet_id' => 1, 'replacements' => array(array($diagnosticMarker => true))))),
    array('assert_public_acf_update_payload', array(array($diagnosticMarker => true))),
    array('assert_public_rollback_post_payload', array(array($diagnosticMarker => true))),
);
foreach ($guardCases as $case) {
    $method = new ReflectionMethod(Runner::class, $case[0]);
    if (PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }
    $rejectWithoutReflection(static function () use ($method, $runner, $case): void {
        $method->invokeArgs($runner, $case[1]);
    }, 'unsupported');
}
$css = new \Webactueel\WordPressConnector\Adapters\CustomCssAdapter();
$portfolio = new \Webactueel\WordPressConnector\Adapters\PortfolioStatsAdapter();
foreach (array(array($css, 'patch'), array($portfolio, 'updateCaseText'), array($portfolio, 'update')) as $call) {
    $rejectWithoutReflection(static function () use ($call, $diagnosticMarker): void {
        $call(array($diagnosticMarker => true), array());
    }, 'unsupported payload key');
}
echo "read batch preflight, public policy, bounded execution and mutation exclusion OK\n";
