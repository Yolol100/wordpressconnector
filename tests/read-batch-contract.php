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
echo "read batch preflight, public policy, bounded execution and mutation exclusion OK\n";
