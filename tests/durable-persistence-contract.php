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
    public function get_results($query): array
    {
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

echo 'durable persistence, retry, rollback and cleanup contract OK (' . $GLOBALS['durable_assertions'] . " assertions)\n";
