<?php

declare(strict_types=1);

if (! defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (! defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

$GLOBALS['durable_options'] = array();
$GLOBALS['durable_fail_add'] = '';
$GLOBALS['durable_fail_update'] = '';
$GLOBALS['durable_fail_read'] = '';
$GLOBALS['durable_mutations'] = 0;
$GLOBALS['durable_assertions'] = 0;
$GLOBALS['durable_admin_allowed'] = true;
$GLOBALS['durable_fail_scan'] = false;

function current_user_can($capability): bool
{
    return 'manage_options' === $capability && $GLOBALS['durable_admin_allowed'];
}

function durableAssert(bool $condition, string $message): void
{
    ++$GLOBALS['durable_assertions'];
    if (! $condition) throw new RuntimeException($message);
}

function durableThrows(callable $callback, string $fragment): void
{
    ++$GLOBALS['durable_assertions'];
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (false !== strpos($error->getMessage(), $fragment)) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Missing expected exception: ' . $fragment);
}

function get_option($name, $default = false)
{
    if ('' !== $GLOBALS['durable_fail_read'] && 0 === strpos((string) $name, $GLOBALS['durable_fail_read'])) {
        return $default;
    }
    return array_key_exists((string) $name, $GLOBALS['durable_options'])
        ? $GLOBALS['durable_options'][(string) $name] : $default;
}
function update_option($name, $value, $autoload = false): bool
{
    if ('' !== $GLOBALS['durable_fail_update'] && 0 === strpos((string) $name, $GLOBALS['durable_fail_update'])) {
        return false;
    }
    $previous = get_option($name, null);
    $GLOBALS['durable_options'][(string) $name] = $value;
    return $previous !== $value;
}
function add_option($name, $value, $deprecated = '', $autoload = false): bool
{
    if ('' !== $GLOBALS['durable_fail_add'] && 0 === strpos((string) $name, $GLOBALS['durable_fail_add'])) {
        return false;
    }
    if (array_key_exists((string) $name, $GLOBALS['durable_options'])) return false;
    $GLOBALS['durable_options'][(string) $name] = $value;
    return true;
}
function delete_option($name): bool
{
    if (! array_key_exists((string) $name, $GLOBALS['durable_options'])) return false;
    unset($GLOBALS['durable_options'][(string) $name]);
    return true;
}
function wp_json_encode($value) { return json_encode($value); }
function wp_cache_delete($key, $group = ''): bool { return true; }
function maybe_unserialize($value) { return is_string($value) ? @unserialize($value) : $value; }

final class DurableTestDb
{
    public string $options = 'wp_options';
    public function update($table, $data, $where, $formats = null, $whereFormats = null): int
    {
        $name = $where['option_name'];
        if (get_option($name, null) !== $where['option_value']) return 0;
        $GLOBALS['durable_options'][$name] = $data['option_value'];
        return 1;
    }
    public function delete($table, $where, $formats = null): int
    {
        $name = $where['option_name'];
        if (get_option($name, null) !== $where['option_value']) return 0;
        unset($GLOBALS['durable_options'][$name]);
        return 1;
    }
    public function esc_like($value) { return $value; }
    public function prepare($query, ...$args) { return $query; }
    public function get_results($query)
    {
        if ($GLOBALS['durable_fail_scan']) return null;
        $results = array();
        foreach ($GLOBALS['durable_options'] as $name => $value) {
            if (0 !== strpos($name, 'wpconnector_processed_')
                && 0 !== strpos($name, 'wpconnector_snapshot_')) continue;
            $results[] = (object) array('option_name' => $name, 'option_value' => serialize($value));
        }
        return $results;
    }
}
$GLOBALS['wpdb'] = new DurableTestDb();

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes';
foreach (array(
    '/Security/Policy.php',
    '/Support/Json.php',
    '/Support/Fingerprint.php',
    '/Runtime/Registry.php',
    '/Runtime/Request.php',
    '/Runtime/Result.php',
    '/Runtime/SnapshotStore.php',
    '/Runtime/ProcessedStore.php',
    '/Runtime/Runner.php',
) as $filename) require_once $root . $filename;

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Runtime\ProcessedStore;
use Webactueel\WordPressConnector\Runtime\SnapshotStore;
use Webactueel\WordPressConnector\Security\Policy;

Policy::setPublicRepositoryContext(false);
$proc = new ProcessedStore();
$snap = new SnapshotStore();
$recordId = 'store-contract-0001';
$proc->begin($recordId, 'f1', 'audit.mutate');
durableAssert(($proc->get($recordId)['state'] ?? null) === 'pending', 'First claim was not durable.');
durableThrows(static function () use ($proc, $recordId): void {
    $proc->begin($recordId, 'f1', 'audit.mutate');
}, 'could not be reserved');
$proc->put($recordId, 'f1', 'audit.mutate', 'result-one');
durableAssert(($proc->get($recordId)['state'] ?? null) === 'completed', 'Claim was not finalized.');
durableAssert(($proc->get($recordId)['result_hash'] ?? null) === 'result-one', 'Result checksum was not stored.');

$GLOBALS['durable_fail_add'] = 'wpconnector_processed_';
durableThrows(static function () use ($proc): void {
    $proc->begin('store-contract-0002', 'f2', 'audit.mutate');
}, 'could not be reserved');
durableAssert(null === $proc->get('store-contract-0002'), 'Failed claim created state.');
$GLOBALS['durable_fail_add'] = '';

$proc->begin('store-contract-0003', 'f3', 'audit.mutate');
$GLOBALS['durable_fail_update'] = 'wpconnector_processed_';
durableThrows(static function () use ($proc): void {
    $proc->put('store-contract-0003', 'f3', 'audit.mutate', 'result-three');
}, 'did not persist');
durableAssert(($proc->get('store-contract-0003')['state'] ?? null) === 'pending', 'Failed result storage removed pending guard.');
$GLOBALS['durable_fail_update'] = '';
durableThrows(static function () use ($proc): void {
    $proc->put('store-contract-0003', 'wrong', 'audit.mutate', 'result-three');
}, 'original pending reservation');

$GLOBALS['durable_fail_read'] = 'wpconnector_processed_';
durableThrows(static function () use ($proc): void {
    $proc->begin('store-contract-0004', 'f4', 'audit.mutate');
}, 'could not be reserved');
$GLOBALS['durable_fail_read'] = '';
durableAssert(($proc->get('store-contract-0004')['state'] ?? null) === 'pending', 'Unconfirmed claim readback did not retain the database guard.');

$snap->put('snapshot-contract-01', array('action' => 'audit.restore', 'payload' => array()), array('source_action' => 'audit.mutate'));
durableAssert(($snap->getRecord('snapshot-contract-01')['source_action'] ?? null) === 'audit.mutate', 'Rollback record not verified.');
$GLOBALS['durable_fail_update'] = 'wpconnector_snapshot_';
durableThrows(static function () use ($snap): void {
    $snap->put('snapshot-contract-02', array('action' => 'audit.restore', 'payload' => array()));
}, 'did not persist');
durableAssert(null === $snap->getRecord('snapshot-contract-02'), 'Failed snapshot write appeared successful.');
$GLOBALS['durable_fail_update'] = '';
$GLOBALS['durable_fail_read'] = 'wpconnector_snapshot_';
durableThrows(static function () use ($snap): void {
    $snap->put('snapshot-contract-03', array('action' => 'audit.restore', 'payload' => array()));
}, 'did not persist');
$GLOBALS['durable_fail_read'] = '';

$registry = new Registry();
$registry->register('audit.mutate', static function (array $payload, array $context): array {
    ++$GLOBALS['durable_mutations'];
    return array('stored' => true,
        '_rollback' => array('action' => 'audit.restore', 'payload' => array('test' => true)));
}, array('mutation' => true));
$runner = new Runner($registry, $snap, $proc);
$make = static function (string $id, array $payload = array()): Request {
    return Request::fromArray(array(
        'version' => 1, 'request_id' => $id, 'action' => 'audit.mutate',
        'payload' => $payload, 'dry_run' => false, 'confirm' => true,
    ));
};
$okRequest = $make('runner-contract-01');
$first = $runner->run($okRequest);
durableAssert(($first['ok'] ?? false) === true, 'Expected first successful mutation.');
durableAssert(($first['data']['rollback_request_id'] ?? '') === 'runner-contract-01', 'Rollback proof missing after verified write.');
$again = $runner->run($okRequest);
durableAssert(($again['data']['idempotent_replay'] ?? false) === true, 'Completed mutation not idempotent.');
durableAssert($GLOBALS['durable_mutations'] === 1, 'Idempotent replay re-executed an action.');
$changed = $runner->run($make('runner-contract-01', array('changed' => true)));
durableAssert(($changed['ok'] ?? true) === false, 'Conflicting request ID was accepted.');

$GLOBALS['durable_fail_add'] = 'wpconnector_processed_';
$before = $GLOBALS['durable_mutations'];
$notReserved = $runner->run($make('runner-contract-02'));
durableAssert(($notReserved['ok'] ?? true) === false, 'Failed reservation allowed a mutation.');
durableAssert($GLOBALS['durable_mutations'] === $before, 'Action executed before persistence claim.');
$GLOBALS['durable_fail_add'] = '';

$GLOBALS['durable_fail_update'] = 'wpconnector_snapshot_';
$before = $GLOBALS['durable_mutations'];
$missingSnapshot = $runner->run($make('runner-contract-03'));
durableAssert(($missingSnapshot['ok'] ?? true) === false, 'Missing snapshot was misreported as success.');
durableAssert($GLOBALS['durable_mutations'] === $before + 1, 'Snapshot failure fixture did not reach provider.');
durableAssert(($proc->get('runner-contract-03')['state'] ?? null) === 'pending', 'Snapshot failure failed to reserve unknown outcome.');
$GLOBALS['durable_fail_update'] = '';
$blocked = $runner->run($make('runner-contract-03'));
durableAssert(($blocked['ok'] ?? true) === false, 'Unknown snapshot outcome allowed replay.');
durableAssert($GLOBALS['durable_mutations'] === $before + 1, 'Pending state caused duplicate mutation.');

$GLOBALS['durable_fail_update'] = 'wpconnector_processed_';
$before = $GLOBALS['durable_mutations'];
$missingResult = $runner->run($make('runner-contract-04'));
durableAssert(($missingResult['ok'] ?? true) === false, 'Missing result marker was misreported as success.');
durableAssert($GLOBALS['durable_mutations'] === $before + 1, 'Result marker failure fixture did not execute provider.');
$GLOBALS['durable_fail_update'] = '';
$repeat = $runner->run($make('runner-contract-04'));
durableAssert(($repeat['ok'] ?? true) === false, 'Pending marker allowed automatic retry.');
durableAssert($GLOBALS['durable_mutations'] === $before + 1, 'Result marker failure caused duplicate mutation.');

$GLOBALS['durable_options']['wpconnector_processed_' . hash('sha256', 'legacy-contract-01')] = array(
    'created_at' => time(), 'fingerprint' => hash('sha256', '{}'), 'action' => 'audit.mutate',
    'ok' => true, 'result_hash' => 'legacy',
);
$proc->begin('cleanup-pending-001', 'f6', 'audit.mutate');
$pendingKey = 'wpconnector_processed_' . hash('sha256', 'cleanup-pending-001');
$GLOBALS['durable_options'][$pendingKey]['created_at'] = time() - 90 * DAY_IN_SECONDS;
$deleted = $proc->cleanup(30 * DAY_IN_SECONDS);
durableAssert($deleted >= 0, 'Cleanup returned an invalid number.');
durableAssert(($proc->get('cleanup-pending-001')['state'] ?? null) === 'pending', 'Unknown outcome was removed by expiry cleanup.');

// A lock that exceeds the TTL is NOT safe to steal while its original
// request still has a durable pending/unknown outcome.
$oldLock = $proc->acquireMutationLock('lock-longrunning-01');
durableAssert('' !== $oldLock, 'Could not acquire original mutation lock.');
$proc->begin('lock-longrunning-01', 'lock-fingerprint', 'audit.mutate');
$lockName = 'wpconnector_mutation_lock';
$lockState = json_decode((string) get_option($lockName, ''), true);
$lockState['created_at'] = time() - 601;
update_option($lockName, wp_json_encode($lockState));
durableAssert('' === $proc->acquireMutationLock('lock-new-request-02'),
    'Long-running pending mutation allowed takeover after lock TTL.');
$proc->put('lock-longrunning-01', 'lock-fingerprint', 'audit.mutate', 'completed-result');
$newLock = $proc->acquireMutationLock('lock-new-request-02');
durableAssert('' !== $newLock && $newLock !== $oldLock,
    'Verified completed owner did not allow compare-and-swap stale-lock recovery.');
$proc->releaseMutationLock($oldLock);
durableAssert('' !== get_option($lockName, ''),
    'Old lock token removed the newer lock.');
$proc->releaseMutationLock($newLock);
durableAssert('' === get_option($lockName, ''),
    'The replacement lock was not released.');

// Locks without an independently completed owner must not be stolen,
// even after their timeout. This protects legacy and corrupted state.
$GLOBALS['durable_options'][$lockName] = wp_json_encode(array(
    'token' => 'legacy-lock', 'created_at' => time() - 601,
));
durableAssert('' === $proc->acquireMutationLock('lock-new-request-03'),
    'An unverifiable legacy lock was stolen.');
unset($GLOBALS['durable_options'][$lockName]);

// Recovery inspection is private, administrator-only, read-only and
// never returns stored payloads, rollback instructions, hashes or tokens.
$statusRequest = static function (array $payload): Request {
    return Request::fromArray(array(
        'version' => 1,
        'request_id' => 'status-inspection-0001',
        'action' => 'connector.mutation_status',
        'payload' => $payload,
        'dry_run' => true,
        'confirm' => false,
    ));
};
$mutationCountBeforeInspection = $GLOBALS['durable_mutations'];
$observed = $runner->run($statusRequest(array('request_id' => 'runner-contract-01')));
durableAssert(($observed['ok'] ?? false) === true
    && ($observed['data']['record_state'] ?? null) === 'completed'
    && ($observed['data']['outcome'] ?? null) === 'succeeded'
    && ($observed['data']['rollback_snapshot_recorded'] ?? false) === true
    && ($observed['data']['safe_to_retry_automatically'] ?? true) === false
    && ($observed['data']['manual_reconciliation_required'] ?? true) === false,
    'Recorded successful mutation cannot be inspected safely.');
$pendingStatus = $runner->run($statusRequest(array('request_id' => 'runner-contract-03')));
durableAssert(($pendingStatus['data']['record_state'] ?? null) === 'pending'
    && ($pendingStatus['data']['outcome'] ?? null) === 'unknown'
    && ($pendingStatus['data']['manual_reconciliation_required'] ?? false) === true
    && ($pendingStatus['data']['safe_to_retry_automatically'] ?? true) === false,
    'Uncertain mutation was misreported as safe.');
$missingStatus = $runner->run($statusRequest(array('request_id' => 'status-missing-0001')));
durableAssert(($missingStatus['data']['record_state'] ?? null) === 'not_verified'
    && ($missingStatus['data']['outcome'] ?? null) === 'unknown'
    && ($missingStatus['data']['rollback_snapshot_recorded'] ?? true) === false,
    'Missing mutation record was misreported as executed or safe.');
$legacyStatus = $runner->run($statusRequest(array('request_id' => 'legacy-contract-01')));
durableAssert(($legacyStatus['data']['record_state'] ?? null) === 'legacy_terminal_unverified'
    && ($legacyStatus['data']['outcome'] ?? null) === 'unknown',
    'Legacy mutation record was treated as fully verified.');
$corruptId = 'status-corrupt-0001';
$GLOBALS['durable_options']['wpconnector_processed_' . hash('sha256', $corruptId)] = array(
    'created_at' => time(), 'state' => 'corrupt',
    'action' => '../../private-secret', 'result_hash' => 'NEVER-EXPOSE-THIS',
    'terminal_error' => 'MY-SECRET-TERMINAL-ERROR',
    'payload' => array('password' => 'NEVER-EXPOSE-THIS'),
);
$corruptStatus = $runner->run($statusRequest(array('request_id' => $corruptId)));
$rendered = json_encode($corruptStatus);
durableAssert(($corruptStatus['data']['record_state'] ?? null) === 'unverifiable'
    && array_key_exists('recorded_action', $corruptStatus['data'] ?? array())
    && $corruptStatus['data']['recorded_action'] === null
    && ! str_contains((string) $rendered, 'NEVER-EXPOSE-THIS')
    && ! str_contains((string) $rendered, 'MY-SECRET-TERMINAL-ERROR'),
    'Corrupt recovery state leaked private data or was misclassified.');
$invalidStatus = $runner->run($statusRequest(array('request_id' => $corruptId, 'unexpected' => true)));
durableAssert(($invalidStatus['ok'] ?? true) === false, 'Unexpected status request fields were accepted.');
$badStatusId = $runner->run($statusRequest(array('request_id' => '../wp-config.php')));
durableAssert(($badStatusId['ok'] ?? true) === false, 'Invalid status request identifier was accepted.');
$GLOBALS['durable_admin_allowed'] = false;
$unauthorizedStatus = $runner->run($statusRequest(array('request_id' => 'runner-contract-01')));
durableAssert(($unauthorizedStatus['ok'] ?? true) === false,
    'Unprivileged user could inspect private mutation state.');
$GLOBALS['durable_admin_allowed'] = true;
Policy::setPublicRepositoryContext(true);
$publicStatus = $runner->run($statusRequest(array('request_id' => 'runner-contract-01')));
durableAssert(($publicStatus['ok'] ?? true) === false, 'Public GitHub request accessed private recovery state.');
Policy::setPublicRepositoryContext(false);

$statusLockToken = $proc->acquireMutationLock('runner-contract-03');
durableAssert('' !== $statusLockToken, 'Could not set up locked status test.');
$lockedOwner = $runner->run($statusRequest(array('request_id' => 'runner-contract-03')));
$lockedOther = $runner->run($statusRequest(array('request_id' => 'runner-contract-01')));
durableAssert(($lockedOwner['data']['mutation_lock'] ?? null) === 'held_for_request'
    && ($lockedOther['data']['mutation_lock'] ?? null) === 'held_for_another_request',
    'Private inspection did not distinguish own and another request lock.');
$proc->releaseMutationLock($statusLockToken);
durableAssert($GLOBALS['durable_mutations'] === $mutationCountBeforeInspection,
    'A recovery inspection unexpectedly executed a mutation.');

// Cleanup is conservative: old pending, corrupt, and unpaired snapshots are
// retained. Only snapshots tied to a verified completed result can expire.
$cutoffTime = time() - 90 * DAY_IN_SECONDS;
foreach (array('cleanup-pending-001', 'cleanup-orphan-001', 'cleanup-completed-001', 'cleanup-malformed-001') as $id) {
    $snap->put($id, array('action' => 'audit.restore', 'payload' => array('sensitive' => 'not-output')));
    $snapKey = 'wpconnector_snapshot_' . hash('sha256', $id);
    $GLOBALS['durable_options'][$snapKey]['created_at'] = $cutoffTime;
}
$proc->begin('cleanup-completed-001', 'cleanup-fp', 'audit.mutate');
$proc->put('cleanup-completed-001', 'cleanup-fp', 'audit.mutate', 'valid-final-result');
$doneKey = 'wpconnector_processed_' . hash('sha256', 'cleanup-completed-001');
$GLOBALS['durable_options'][$doneKey]['created_at'] = $cutoffTime;
$malformedKey = 'wpconnector_processed_' . hash('sha256', 'cleanup-malformed-001');
$GLOBALS['durable_options'][$malformedKey] = array(
    'created_at' => $cutoffTime, 'state' => 'broken', 'ok' => true, 'result_hash' => 'unverified',
);
$snapshotCount = $snap->cleanup(30 * DAY_IN_SECONDS);
durableAssert($snapshotCount === 1
    && $snap->getRecord('cleanup-pending-001') !== null
    && $snap->getRecord('cleanup-orphan-001') !== null
    && $snap->getRecord('cleanup-malformed-001') !== null
    && $snap->getRecord('cleanup-completed-001') === null,
    'Snapshot cleanup discarded unverifiable recovery evidence.');
$processedCount = $proc->cleanup(30 * DAY_IN_SECONDS);
durableAssert($processedCount >= 1
    && null === $proc->get('cleanup-completed-001')
    && is_array($proc->get('cleanup-malformed-001'))
    && is_array($proc->get('cleanup-pending-001')),
    'Processed cleanup discarded an uncertain outcome or failed to expire a terminal record.');

$GLOBALS['durable_fail_scan'] = true;
durableThrows(static function () use ($proc): void {
    $proc->cleanup(30 * DAY_IN_SECONDS);
}, 'could not inspect');
durableThrows(static function () use ($snap): void {
    $snap->cleanup(30 * DAY_IN_SECONDS);
}, 'could not inspect');
$GLOBALS['durable_fail_scan'] = false;

echo 'durable persistence, recovery inspection, privacy and cleanup contract OK (' . $GLOBALS['durable_assertions'] . " assertions)\n";

