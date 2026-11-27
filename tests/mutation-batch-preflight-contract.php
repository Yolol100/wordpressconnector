<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wordpress-escape-fixture.php';

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
foreach (array(
    'Support/Json.php',
    'Support/class-fingerprint.php',
    'Security/Policy.php',
    'Runtime/class-registry.php',
    'Runtime/class-diagnostics.php',
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
    return 'manage_options' === $capability
        || ('delete_plugins' === $capability && ! empty($GLOBALS['test_allow_delete']));
}

function wp_json_encode($value, int $flags = 0)
{
    if (!empty($GLOBALS['test_fail_compensation_encoding']) && $flags !== 0) {
        if ($GLOBALS['test_fail_compensation_encoding'] === 'throw') {
            throw new RuntimeException('Synthetic encoder failure');
        }
        return false;
    }
    return json_encode($value, $flags | JSON_THROW_ON_ERROR);
}

Policy::setPublicRepositoryContext(false);
$registry = new Registry();
$writes = 0;
$rollbackState = 0;
$GLOBALS['test_allow_delete'] = false;

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
    return array('restored' => true, 'detail' => '<b>restored & ready</b>', 'nested' => array('api_key' => 'fixture-secret'));
}, array('mutation' => true));
$registry->register('test.reversible', static function (array $payload, array $context) use (&$rollbackState): array {
    if (! empty($context['dry_run'])) {
        return array('would_update' => true);
    }
    ++$rollbackState;
    return array('updated' => true, '_rollback' => array('action' => 'test.undo', 'payload' => array()));
}, array('mutation' => true));
$registry->register('test.fail', static function (): array {
    throw new RuntimeException('Simulated provider failure <unsafe>');
}, array('mutation' => true));
$registry->register('wordpress.ability.execute_approved', static function () use (&$writes): array {
    ++$writes;
    return array('delegated' => true);
}, array('mutation' => true, 'privileged' => true, 'sensitive' => true));

$registry->register('test.revoke_permission', static function (array $payload, array $context): array {
    $GLOBALS['test_allow_delete'] = false;
    return array('revoked' => true, '_rollback' => array(
        'action' => 'test.restore_permission', 'payload' => array(),
    ));
}, array('mutation' => true));
$registry->register('test.restore_permission', static function (): array {
    $GLOBALS['test_allow_delete'] = true;
    return array('restored' => true);
}, array('mutation' => true));

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

foreach (array('expected_fingerprint', 'expected_state_token') as $guard) {
    $bad = $first;
    $bad[$guard] = 'invalid';
    try {
        $run(array($first, $bad));
        throw new LogicException('Malformed guard accepted.');
    } catch (RuntimeException $error) {
        if ('Invalid ' . $guard . ' at batch index 1.' !== $error->getMessage() || 0 !== $writes) {
            throw new RuntimeException('Incorrect batch guard diagnostic or unexpected write.');
        }
    }
}
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
    $prefix = 'Batch failed at operation 1: Simulated provider failure &lt;unsafe&gt; Compensation attempted: ';
    $message = $error->getMessage();
    $previous = $error->getPrevious();
    if (get_class($error) !== RuntimeException::class || !($previous instanceof RuntimeException)
        || $previous->getMessage() !== 'Simulated provider failure <unsafe>' || strpos($message, $prefix) !== 0
        || strpos($message, 'fixture-secret') !== false || strpos($message, '<b>') !== false) {
        throw new LogicException('Batch diagnostic lost its cause or exposed raw markup/secret keys.');
    }
    $receipt = json_decode(substr($message, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
    if (($receipt[0]['result']['detail'] ?? null) !== '<b>restored & ready</b>'
        || ($receipt[0]['result']['nested']['api_key'] ?? null) !== '[redacted]'
        || ($receipt[0]['ok'] ?? false) !== true) {
        throw new LogicException('Compensation diagnostic no longer contains valid structured JSON.');
    }
    $failed = true;
}
if (! $failed || 0 !== $rollbackState) {
    throw new RuntimeException('Runtime failure must continue compensating an earlier reversible mutation.');
}


foreach (array('false', 'throw') as $encodingFault) {
    $GLOBALS['test_fail_compensation_encoding'] = $encodingFault;
    $caught = null;
    try {
        $run(array(
            array('action' => 'test.reversible', 'payload' => array()),
            array('action' => 'test.fail', 'payload' => array()),
        ));
    } catch (RuntimeException $error) {
        $caught = $error;
    } finally {
        unset($GLOBALS['test_fail_compensation_encoding']);
    }
    if (!$caught || !($caught->getPrevious() instanceof RuntimeException)
        || $caught->getPrevious()->getMessage() !== 'Simulated provider failure <unsafe>'
        || strpos($caught->getMessage(), 'Compensation attempted: [unavailable]') === false
        || 0 !== $rollbackState) {
        throw new LogicException('Encoding fault replaced the provider failure or skipped compensation.');
    }
}

// An earlier leaf may revoke a capability after preflight. Check again at
// execution time, then compensate the earlier reversible change.
$GLOBALS['test_allow_delete'] = true;
$before = $writes;
$blockedAfterRevocation = false;
try {
    $run(array(
        array('action' => 'test.revoke_permission', 'payload' => array()),
        array('action' => 'test.admin', 'payload' => array()),
    ));
} catch (RuntimeException $error) {
    $blockedAfterRevocation = true;
}
if (! $blockedAfterRevocation || $writes !== $before
    || $GLOBALS['test_allow_delete'] !== true) {
    throw new RuntimeException('Batch must recheck capabilities immediately before execution and roll back a revoked permission.');
}

echo "Mutation batch: preflight-before-write, fresh permission checks and compensation OK\n";
