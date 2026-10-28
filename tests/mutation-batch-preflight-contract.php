<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
foreach (array(
    'Support/Json.php',
    'Support/Fingerprint.php',
    'Security/Policy.php',
    'Runtime/Registry.php',
    'Runtime/Diagnostics.php',
    'Runtime/SnapshotStore.php',
    'Runtime/ProcessedStore.php',
    'Runtime/Runner.php',
) as $path) {
    require_once $root . $path;
}

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Runtime\SnapshotStore;
use Webactueel\WordPressConnector\Runtime\ProcessedStore;
use Webactueel\WordPressConnector\Security\Policy;

function current_user_can(string $capability): bool
{
    return 'manage_options' === $capability;
}

function wp_json_encode($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR);
}

Policy::setPublicRepositoryContext(false);
$registry = new Registry();
$writes = 0;
$rollbackState = 0;

$registry->register('test.first', static function (array $payload, array $context) use (&$writes): array {
    if (empty($context['dry_run'])) {
        ++$writes;
    }
    return array('updated' => true);
}, array('mutation' => true));
$registry->register('test.admin', static function (array $payload, array $context) use (&$writes): array {
    ++$writes;
    return array('deleted' => true);
}, array('mutation' => true, 'privileged' => true, 'capability' => 'delete_plugins'));
$registry->register('test.undo', static function () use (&$rollbackState): array {
    --$rollbackState;
    return array('restored' => true);
}, array('mutation' => true));
$registry->register('test.reversible', static function (array $payload, array $context) use (&$rollbackState): array {
    if (! empty($context['dry_run'])) {
        return array('would_update' => true);
    }
    ++$rollbackState;
    return array('updated' => true, '_rollback' => array('action' => 'test.undo', 'payload' => array()));
}, array('mutation' => true));
$registry->register('test.fail', static function (): array {
    throw new RuntimeException('Simulated provider failure');
}, array('mutation' => true));
$registry->register('wordpress.ability.execute_approved', static function () use (&$writes): array {
    ++$writes;
    return array('delegated' => true);
}, array('mutation' => true, 'privileged' => true, 'sensitive' => true));

$runner = new Runner($registry, new SnapshotStore(), new ProcessedStore());
$first = array('action' => 'test.first', 'payload' => array());
$run = static function (array $operations, bool $dryRun = false) use ($runner): array {
    return $runner->batch(array('operations' => $operations), array(
        'dry_run' => $dryRun, 'confirm' => ! $dryRun,
    ));
};
$rejectWithoutSideEffects = static function (array $operations, string $case) use ($run, &$writes): void {
    $before = $writes;
    $rejected = false;
    try {
        $run($operations);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    if (! $rejected || $writes !== $before) {
        throw new RuntimeException('Batch preflight must reject ' . $case . ' before any earlier mutation.');
    }
};

$rejectWithoutSideEffects(array($first, array('action' => 'test.admin', 'payload' => array())),
    'a later disallowed WordPress capability');
$rejectWithoutSideEffects(array($first, array('action' => 'test.not_registered', 'payload' => array())),
    'a later unregistered action');
$rejectWithoutSideEffects(array($first, array('action' => 'test.first', 'payload' => 'invalid')),
    'a later malformed payload');
$rejectWithoutSideEffects(array($first, array('action' => 'test.first', 'payload' => array(), 'unknown' => true)),
    'an unsupported leaf key');
$rejectWithoutSideEffects(array($first, array('action' => 'test.first', 'payload' => array(), 'expected_fingerprint' => 'invalid')),
    'an invalid later stale-state fingerprint');
$rejectWithoutSideEffects(array($first, array('action' => 'test.first', 'payload' => array(), 'expected_state_token' => 7)),
    'an invalid later state token');
$rejectWithoutSideEffects(array($first, array('action' => 'wordpress.ability.execute_approved', 'payload' => array())),
    'a delegated third-party Ability without compensation guarantees');

$validDryRun = $run(array($first, $first), true);
if (count($validDryRun['operations'] ?? array()) !== 2 || $writes !== 0) {
    throw new RuntimeException('Valid batch dry-run must remain side-effect free.');
}
$validRun = $run(array($first, $first));
if (count($validRun['operations'] ?? array()) !== 2 || $writes !== 2) {
    throw new RuntimeException('Valid confirmed batch actions must still execute.');
}

$failed = false;
try {
    $run(array(
        array('action' => 'test.reversible', 'payload' => array()),
        array('action' => 'test.fail', 'payload' => array()),
    ));
} catch (RuntimeException $error) {
    $failed = true;
}
if (! $failed || 0 !== $rollbackState) {
    throw new RuntimeException('Runtime failure must continue compensating an earlier reversible mutation.');
}

echo "Mutation batch: preflight-before-write, guarded delegated actions and compensation OK\n";
