<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class Runner
{
    private Registry $registry;
    private SnapshotStore $snapshots;
    private ProcessedStore $processed;

    public function __construct(Registry $registry, SnapshotStore $snapshots, ProcessedStore $processed)
    {
        $this->registry = $registry;
        $this->snapshots = $snapshots;
        $this->processed = $processed;
        $this->registry->register('connector.actions', array($this, 'actions'), array('description' => 'List registered actions and security metadata.'));
        $this->registry->register('connector.batch', array($this, 'batch'), array('mutation' => true, 'description' => 'Execute up to 25 connector operations with compensation on failure.'));
        $this->registry->register('connector.read_batch', array($this, 'readBatch'), array('description' => 'Read up to 25 supported actions with one permission-checked request and no mutations.'));
        $this->registry->register('connector.rollback', array($this, 'rollback'), array('mutation' => true, 'privileged' => true, 'description' => 'Execute a stored rollback snapshot by request_id.'));
        $this->registry->register('connector.cleanup', array($this, 'cleanup'), array('mutation' => true, 'privileged' => true, 'description' => 'Delete expired idempotency and rollback state.'));
    }

    public function run(Request $request, array $context = array()): array
    {
        $lockToken = '';
        try {
            $descriptor = $this->registry->descriptor($request->action());
            if (Policy::publicRepositoryContext()) {
                $descriptor = $this->publicDescriptor($request->action(), $request->payload(), $descriptor, $request->dryRun(), $request->expectedFingerprint());
            }
            Policy::assertActionAllowed($descriptor, $request->dryRun(), $request->confirm());
            $requestFingerprint = Fingerprint::make(array(
                'action' => $request->action(),
                'payload' => $request->payload(),
                'expected_fingerprint' => $request->expectedFingerprint(),
                'expected_state_token' => $request->expectedStateToken(),
            ));
            if (! empty($descriptor['mutation']) && ! $request->dryRun()) {
                $lockToken = $this->processed->acquireMutationLock();
                if ('' === $lockToken) {
                    throw new RuntimeException('Another connector mutation is already in progress. Retry this request.');
                }
                $existing = $this->processed->get($request->id());
                if ($existing) {
                    if (! isset($existing['fingerprint']) || ! hash_equals((string) $existing['fingerprint'], $requestFingerprint)) {
                        throw new RuntimeException('request_id was already used for a different mutation.');
                    }
                    if (array_key_exists('ok', $existing) && false === $existing['ok']) {
                        return Result::failure(
                            $request,
                            isset($existing['terminal_error']) && is_string($existing['terminal_error'])
                                ? $existing['terminal_error']
                                : 'Completed mutation ended with a terminal execution failure.',
                            array(
                                'request_fingerprint' => $requestFingerprint,
                                'idempotent_replay' => true,
                                'original_result_hash' => $existing['result_hash'] ?? null,
                                'terminal_mutation' => true,
                            )
                        );
                    }
                    return Result::success($request, array('idempotent_replay' => true, 'original_result_hash' => $existing['result_hash'] ?? null), array('request_fingerprint' => $requestFingerprint));
                }
            }
            $context['dry_run'] = $request->dryRun();
            $context['confirm'] = $request->confirm();
            $context['request_id'] = $request->id();
            $context['registry'] = $this->registry;
            $data = $this->executeWithStateGuards($request->action(), $request->payload(), $context, $request->expectedFingerprint(), $request->expectedStateToken());
            if (isset($data['fingerprint']) && is_string($data['fingerprint']) && preg_match('/^[a-f0-9]{64}\z/', $data['fingerprint'])) {
                $data['state_token'] = Fingerprint::siteTokenFromFingerprint($data['fingerprint']);
            }
            if (isset($data['_current_fingerprint']) && is_string($data['_current_fingerprint']) && preg_match('/^[a-f0-9]{64}\z/', $data['_current_fingerprint'])) {
                $data['current_state_token'] = Fingerprint::siteTokenFromFingerprint($data['_current_fingerprint']);
            }
            $rollback = isset($data['_rollback']) && is_array($data['_rollback']) ? $data['_rollback'] : null;
            $terminalError = isset($data['_terminal_error']) && is_string($data['_terminal_error'])
                ? trim($data['_terminal_error'])
                : '';
            $terminalContext = array();
            foreach (array('name', 'execution_may_have_started', 'provider_error_code') as $key) {
                if (array_key_exists($key, $data)) {
                    $terminalContext[$key] = $data[$key];
                }
            }
            unset($data['_rollback'], $data['_current_fingerprint'], $data['_terminal_error']);
            if ($rollback && ! $request->dryRun() && ! empty($descriptor['mutation'])) {
                $this->snapshots->put($request->id(), $rollback, array(
                    'source_action' => $request->action(),
                    'public_repository_mode' => Policy::publicRepositoryContext(),
                ));
                $data['rollback_request_id'] = $request->id();
            }
            if ('' !== $terminalError) {
                $result = Result::failure($request, $terminalError, array(
                    'request_fingerprint' => $requestFingerprint,
                    'terminal_mutation' => true,
                    'terminal_context' => $terminalContext,
                ));
                if (! $request->dryRun() && ! empty($descriptor['mutation'])) {
                    $this->processed->put(
                        $request->id(),
                        $requestFingerprint,
                        $request->action(),
                        Fingerprint::make($result),
                        false,
                        $terminalError
                    );
                }
                return $result;
            }

            $result = Result::success($request, $data, array('request_fingerprint' => $requestFingerprint));
            if (! $request->dryRun() && ! empty($descriptor['mutation'])) {
                $this->processed->put($request->id(), $requestFingerprint, $request->action(), Fingerprint::make($result), true);
            }
            return $result;
        } catch (Throwable $error) {
            return Result::failure($request, $error->getMessage(), array('exception' => get_class($error)));
        } finally {
            if ('' !== $lockToken) {
                $this->processed->releaseMutationLock($lockToken);
            }
        }
    }

    public function actions(array $payload, array $context): array
    {
        return array('actions' => $this->registry->catalog());
    }

    /**
     * Single-roundtrip fast path for independent reads. Preflight every leaf
     * before executing any to prevent a later unsafe action from partly running.
     * Mutations, sensitive exports and nested actions are never eligible.
     */
    public function readBatch(array $payload, array $context): array
    {
        $operations = isset($payload['operations']) && is_array($payload['operations']) ? $payload['operations'] : array();
        if (!$operations || count($operations) > 25 || array_keys($payload) !== array('operations')) {
            throw new RuntimeException('connector.read_batch requires 1-25 operations.');
        }

        $allowed = array();
        foreach ($operations as $index => $operation) {
            if (! is_array($operation) || ! isset($operation['action']) || ! is_string($operation['action'])
                || ! isset($operation['payload']) || ! is_array($operation['payload'])
                || array_diff(array_keys($operation), array('action', 'payload'))) {
                throw new RuntimeException('Read batch operation at index ' . $index . ' is invalid.');
            }
            $action = $operation['action'];
            if (in_array($action, array('connector.read_batch', 'connector.batch', 'connector.rollback', 'connector.cleanup'), true)
                || 'wordpress.ability.execute' === $action) {
                throw new RuntimeException('Nested and delegated actions are not allowed in connector.read_batch.');
            }
            $descriptor = $this->registry->descriptor($action);
            if (! empty($descriptor['mutation']) || ! empty($descriptor['sensitive']) || ! empty($descriptor['system_update'])) {
                throw new RuntimeException('Mutating, sensitive and system-update actions are not allowed in connector.read_batch.');
            }
            if (Policy::publicRepositoryContext()) {
                $descriptor = $this->publicDescriptor($action, $operation['payload'], $descriptor, true, null);
            }
            Policy::assertActionAllowed($descriptor, true, false);
            $allowed[] = array('action' => $action, 'payload' => $operation['payload']);
        }

        $results = array();
        foreach ($allowed as $operation) {
            $result = $this->registry->execute($operation['action'], $operation['payload'], array_merge($context, array(
                'dry_run' => true,
                'confirm' => false,
            )));
            unset($result['_rollback'], $result['_current_fingerprint']);
            $results[] = array('action' => $operation['action'], 'result' => $result);
        }
        return array('operations' => $results);
    }

    public function batch(array $payload, array $context): array
    {
        $operations = isset($payload['operations']) && is_array($payload['operations']) ? $payload['operations'] : array();
        if (! $operations || count($operations) > 25) {
            throw new RuntimeException('connector.batch requires 1-25 operations.');
        }
        $results = array();
        $rollbacks = array();
        try {
            foreach ($operations as $index => $operation) {
                if (! is_array($operation) || empty($operation['action']) || ! is_string($operation['action'])) {
                    throw new RuntimeException('Batch operation at index ' . $index . ' is invalid.');
                }
                $action = $operation['action'];
                if (in_array($action, array('connector.batch', 'connector.read_batch', 'connector.rollback', 'connector.cleanup'), true)) {
                    throw new RuntimeException('Nested batch, rollback or cleanup operations are not allowed.');
                }
                if ('wordpress.ability.execute' === $action) {
                    throw new RuntimeException('Delegated Ability mutations are not allowed inside connector.batch because they do not provide connector rollback guarantees.');
                }
                $operationPayload = isset($operation['payload']) && is_array($operation['payload']) ? $operation['payload'] : array();
                $descriptor = $this->registry->descriptor($action);
                if (Policy::publicRepositoryContext()) {
                    $descriptor = $this->publicDescriptor($action, $operationPayload, $descriptor, ! empty($context['dry_run']), isset($operation['expected_fingerprint']) && is_string($operation['expected_fingerprint']) ? $operation['expected_fingerprint'] : null);
                }
                Policy::assertActionAllowed($descriptor, ! empty($context['dry_run']), ! empty($context['confirm']));
                $expected = isset($operation['expected_fingerprint']) && is_string($operation['expected_fingerprint']) ? $operation['expected_fingerprint'] : null;
                $expectedStateToken = isset($operation['expected_state_token']) && is_string($operation['expected_state_token']) ? $operation['expected_state_token'] : null;
                $result = $this->executeWithStateGuards($action, $operationPayload, $context, $expected, $expectedStateToken);
                if (isset($result['_rollback']) && is_array($result['_rollback'])) {
                    array_unshift($rollbacks, $result['_rollback']);
                }
                unset($result['_rollback'], $result['_current_fingerprint']);
                $results[] = array('action' => $action, 'result' => $result);
            }
        } catch (Throwable $error) {
            $compensation = array();
            if (empty($context['dry_run'])) {
                foreach ($rollbacks as $rollback) {
                    try {
                        $compensation[] = array('ok' => true, 'action' => $rollback['action'], 'result' => $this->registry->execute((string) $rollback['action'], (array) $rollback['payload'], array_merge($context, array('rollback_mode' => true, 'dry_run' => false))));
                    } catch (Throwable $rollbackError) {
                        $compensation[] = array('ok' => false, 'action' => $rollback['action'] ?? null, 'error' => $rollbackError->getMessage());
                    }
                }
            }
            throw new RuntimeException('Batch failed at operation ' . count($results) . ': ' . $error->getMessage() . ($compensation ? ' Compensation attempted: ' . wp_json_encode($compensation) : ''), 0, $error);
        }
        $output = array('operations' => $results);
        if ($rollbacks) {
            $output['_rollback'] = array('action' => 'connector.batch', 'payload' => array('operations' => array_map(static function (array $rollback): array {
                return array('action' => $rollback['action'], 'payload' => $rollback['payload']);
            }, $rollbacks)));
        }
        return $output;
    }

    public function rollback(array $payload, array $context): array
    {
        $requestId = isset($payload['request_id']) ? (string) $payload['request_id'] : '';
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $requestId)) {
            throw new RuntimeException('rollback requires a valid payload.request_id.');
        }
        $record = $this->snapshots->getRecord($requestId);
        if (! $record || empty($record['rollback']) || ! is_array($record['rollback'])) {
            throw new RuntimeException('Rollback snapshot was not found.');
        }
        $rollback = $record['rollback'];
        if (empty($rollback['action']) || ! isset($rollback['payload']) || ! is_array($rollback['payload'])) {
            throw new RuntimeException('Rollback snapshot was not found.');
        }
        if (Policy::publicRepositoryContext()) {
            $this->assertPublicRollbackRecord($record);
            $fingerprint = $this->publicRollbackFingerprint($rollback, $context);
            if (! preg_match('/^[a-f0-9]{64}\z/', $fingerprint)) {
                throw new RuntimeException('Public rollback target cannot enforce stale-state protection.');
            }
            if (! empty($context['dry_run'])) {
                return array(
                    'would_execute_action' => (string) $rollback['action'],
                    'source_action' => (string) ($record['source_action'] ?? ''),
                    '_current_fingerprint' => $fingerprint,
                );
            }
        } elseif (! empty($context['dry_run'])) {
            return array('would_execute' => $rollback);
        }
        if (in_array($rollback['action'], array('connector.rollback', 'connector.cleanup'), true)) {
            throw new RuntimeException('Recursive rollback is not allowed.');
        }
        $descriptor = $this->registry->descriptor((string) $rollback['action']);
        if (! Policy::publicRepositoryContext()) {
            Policy::assertActionAllowed($descriptor, false, true);
        }
        $result = $this->registry->execute((string) $rollback['action'], $rollback['payload'], array_merge($context, array('rollback_mode' => true, 'expected_fingerprint' => null)));
        unset($result['_rollback'], $result['_current_fingerprint']);
        return array('restored_request_id' => $requestId, 'restored_with' => $rollback['action'], 'result' => $result);
    }

    public function cleanup(array $payload, array $context): array
    {
        $days = isset($payload['older_than_days']) ? max(1, min(365, (int) $payload['older_than_days'])) : 30;
        if (! empty($context['dry_run'])) {
            return array('would_delete_state_older_than_days' => $days);
        }
        $seconds = $days * DAY_IN_SECONDS;
        return array('snapshots_deleted' => $this->snapshots->cleanup($seconds), 'processed_deleted' => $this->processed->cleanup($seconds));
    }

    private function publicDescriptor(string $action, array $payload, array $descriptor, bool $dryRun, ?string $expectedFingerprint): array
    {
        if ('acf.update' === $action) {
            $this->assertPublicAcfUpdatePayload($payload);
            $descriptor['privileged'] = false;
            return $descriptor;
        }
        if ('acf.portfolio_stats_update' === $action) {
            $this->assertPublicPortfolioStatsPayload($payload);
            if (! $dryRun && (null === $expectedFingerprint || ! preg_match('/^[a-f0-9]{64}\z/', $expectedFingerprint))) {
                throw new RuntimeException('Confirmed public portfolio stats update requires expected_fingerprint from the preceding dry-run.');
            }
            $descriptor['privileged'] = false;
            $descriptor['public_repository_safe'] = false;
            return $descriptor;
        }
        if ('portfolio.case_text_update' === $action) {
            $this->assertPublicPortfolioCaseTextPayload($payload);
            if (! $dryRun && (null === $expectedFingerprint || ! preg_match('/^[a-f0-9]{64}\z/', $expectedFingerprint))) {
                throw new RuntimeException('Confirmed public portfolio case text update requires expected_fingerprint from the preceding dry-run.');
            }
            $descriptor['privileged'] = false;
            $descriptor['public_repository_safe'] = false;
            return $descriptor;
        }
        if ('custom_css.inspect' === $action) {
            $this->assertPublicCustomCssPayload($payload, false);
            $descriptor['privileged'] = false;
            return $descriptor;
        }
        if ('custom_css.patch' === $action) {
            $this->assertPublicCustomCssPayload($payload, true);
            if (! $dryRun && (null === $expectedFingerprint || ! preg_match('/^[a-f0-9]{64}\\z/', $expectedFingerprint))) {
                throw new RuntimeException('Confirmed public custom_css.patch requires expected_fingerprint from the preceding dry-run.');
            }
            $descriptor['privileged'] = false;
            $descriptor['public_repository_safe'] = false;
            return $descriptor;
        }
        if ('maintenance.cache_flush' === $action) {
            $this->assertPublicCacheFlushPayload($payload);
            if (! $dryRun && (null === $expectedFingerprint || ! preg_match('/^[a-f0-9]{64}\\z/', $expectedFingerprint))) {
                throw new RuntimeException('Confirmed public cache flush requires expected_fingerprint from the preceding dry-run.');
            }
            $descriptor['privileged'] = false;
            $descriptor['public_repository_safe'] = false;
            return $descriptor;
        }
        if ('code_snippets.patch' === $action) {
            $this->assertPublicCodeSnippetsPatchPayload($payload);
            if (! $dryRun && (null === $expectedFingerprint || ! preg_match('/^[a-f0-9]{64}\\z/', $expectedFingerprint))) {
                throw new RuntimeException('Confirmed public Code Snippets patch requires expected_fingerprint from the preceding dry-run.');
            }
            $descriptor['privileged'] = false;
            $descriptor['public_repository_safe'] = false;
            return $descriptor;
        }
        if ('plugin.install_package' === $action) {
            $this->assertPublicPluginInstallPayload($payload);
            if (! $dryRun && (null === $expectedFingerprint || ! preg_match('/^[a-f0-9]{64}\\z/', $expectedFingerprint))) {
                throw new RuntimeException('Confirmed public plugin package install requires expected_fingerprint from the preceding dry-run.');
            }
            $descriptor['public_repository_safe'] = true;
            return $descriptor;
        }
        if ('connector.rollback' === $action) {
            $requestId = isset($payload['request_id']) ? (string) $payload['request_id'] : '';
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $requestId)) {
                throw new RuntimeException('rollback requires a valid payload.request_id.');
            }
            $record = $this->snapshots->getRecord($requestId);
            if (! $record) {
                throw new RuntimeException('Rollback snapshot was not found.');
            }
            $this->assertPublicRollbackRecord($record);
            if (! $dryRun && (null === $expectedFingerprint || ! preg_match('/^[a-f0-9]{64}\z/', $expectedFingerprint))) {
                throw new RuntimeException('Confirmed public rollback requires expected_fingerprint from the preceding dry-run.');
            }
            $descriptor['privileged'] = false;
            return $descriptor;
        }
        return $descriptor;
    }

    private function assertPublicCustomCssPayload(array $payload, bool $patch): void
    {
        $allowed = $patch
            ? array('stylesheet', 'patch_id', 'operation', 'css')
            : array('stylesheet');
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                throw new RuntimeException('Public Additional CSS request contains unsupported payload key: ' . (string) $key);
            }
        }

        $stylesheet = isset($payload['stylesheet']) ? sanitize_text_field((string) $payload['stylesheet']) : (string) get_stylesheet();
        if ('' === $stylesheet || ! hash_equals((string) get_stylesheet(), $stylesheet)) {
            throw new RuntimeException('Public Additional CSS actions may target only the active theme stylesheet.');
        }

        if (! $patch) {
            return;
        }

        $patchId = isset($payload['patch_id']) && is_string($payload['patch_id']) ? $payload['patch_id'] : '';
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}\\z/', $patchId)) {
            throw new RuntimeException('Public custom_css.patch requires a safe 3-64 character patch_id.');
        }

        $operation = isset($payload['operation']) && is_string($payload['operation']) ? strtolower($payload['operation']) : '';
        if (! in_array($operation, array('upsert', 'remove'), true)) {
            throw new RuntimeException('Public custom_css.patch operation must be upsert or remove.');
        }

        if ('remove' === $operation) {
            if (array_key_exists('css', $payload)) {
                throw new RuntimeException('Public custom_css.patch remove must not include css.');
            }
            return;
        }

        $css = isset($payload['css']) && is_string($payload['css']) ? $payload['css'] : '';
        if ('' === trim($css) || strlen($css) > 65536) {
            throw new RuntimeException('Public custom_css.patch css must be non-empty and at most 64 KiB.');
        }
        if (preg_match('/[\\x00-\\x08\\x0B\\x0E-\\x1F\\x7F]/', $css)) {
            throw new RuntimeException('Public custom_css.patch css contains unsupported control characters.');
        }
        if (false !== stripos($css, '</style') || false !== stripos($css, 'wpconnector:')) {
            throw new RuntimeException('Public custom_css.patch css contains a forbidden style boundary or connector marker.');
        }
    }

    private function assertPublicCustomCssRollbackPayload(array $payload): void
    {
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, array('stylesheet', 'css'), true)) {
                throw new RuntimeException('Public Additional CSS rollback contains unsupported payload key: ' . (string) $key);
            }
        }
        $stylesheet = isset($payload['stylesheet']) ? sanitize_text_field((string) $payload['stylesheet']) : '';
        if ('' === $stylesheet || ! hash_equals((string) get_stylesheet(), $stylesheet)) {
            throw new RuntimeException('Public Additional CSS rollback may target only the active theme stylesheet.');
        }
        if (! array_key_exists('css', $payload) || ! is_string($payload['css']) || strlen($payload['css']) > 524288) {
            throw new RuntimeException('Public Additional CSS rollback css is invalid.');
        }
        if (false !== stripos($payload['css'], '</style')) {
            throw new RuntimeException('Public Additional CSS rollback contains a forbidden style boundary.');
        }
    }

    private function assertPublicPluginInstallPayload(array $payload): void
    {
        $allowed = array('source_path', 'sha256', 'expected_plugin', 'overwrite', 'activate', 'network_wide');
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                throw new RuntimeException('Public plugin package install contains unsupported payload key: ' . (string) $key);
            }
        }

        $sourcePath = isset($payload['source_path']) && is_string($payload['source_path'])
            ? str_replace('\\', '/', $payload['source_path'])
            : '';
        if (! preg_match('#^plugin-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\\.zip\\z#', $sourcePath)) {
            throw new RuntimeException('Public plugin package install requires source_path plugin-packages/<safe-name>.zip.');
        }

        $sha256 = isset($payload['sha256']) && is_string($payload['sha256'])
            ? strtolower($payload['sha256'])
            : '';
        if (! preg_match('/^[a-f0-9]{64}\\z/', $sha256)) {
            throw new RuntimeException('Public plugin package install requires an exact lowercase SHA-256 checksum.');
        }

        $expectedPlugin = isset($payload['expected_plugin']) && is_string($payload['expected_plugin'])
            ? str_replace('\\', '/', $payload['expected_plugin'])
            : '';
        if (! preg_match('/^[A-Za-z0-9._-]+\\/[A-Za-z0-9._-]+\\.php\\z/', $expectedPlugin)) {
            throw new RuntimeException('Public plugin package install requires an exact expected_plugin file.');
        }

        if (! array_key_exists('overwrite', $payload) || ! is_bool($payload['overwrite'])) {
            throw new RuntimeException('Public plugin package install requires an explicit boolean overwrite value.');
        }
        if (true === $payload['overwrite']
            && ! hash_equals('webactueel-mailbox-bridge/webactueel-mailbox-bridge.php', $expectedPlugin)) {
            throw new RuntimeException('Public plugin package overwrite is restricted to the Webactueel Mailbox Bridge.');
        }
        if (! array_key_exists('network_wide', $payload) || false !== $payload['network_wide']) {
            throw new RuntimeException('Public plugin package install requires network_wide=false.');
        }
        if (! array_key_exists('activate', $payload) || ! is_bool($payload['activate'])) {
            throw new RuntimeException('Public plugin package install requires an explicit boolean activate value.');
        }
    }

    private function assertPublicCacheFlushPayload(array $payload): string
    {
        foreach (array_keys($payload) as $key) {
            if ('layer' !== (string) $key) {
                throw new RuntimeException('Public cache flush contains unsupported payload key: ' . (string) $key);
            }
        }

        $layer = isset($payload['layer']) && is_string($payload['layer'])
            ? sanitize_key($payload['layer'])
            : '';

        if (! in_array($layer, array('elementor', 'wp_rocket', 'asset_cleanup'), true)) {
            throw new RuntimeException('Public cache flush requires layer: elementor, wp_rocket or asset_cleanup.');
        }

        $preview = $this->registry->execute('maintenance.cache_flush', array('layer' => $layer), array(
            'dry_run' => true,
            'confirm' => true,
            'public_validation' => true,
        ));

        $fingerprint = isset($preview['_current_fingerprint']) ? (string) $preview['_current_fingerprint'] : '';
        if (empty($preview['available']) || ! preg_match('/^[a-f0-9]{64}\\z/', $fingerprint)) {
            throw new RuntimeException('Public cache flush validation did not produce an available guarded layer.');
        }

        return $layer;
    }

    private function assertPublicCodeSnippetsPatchPayload(array $payload): int
    {
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, array('snippet_id', 'match_code_contains', 'replacements'), true)) {
                throw new RuntimeException('Public Code Snippets patch contains unsupported payload key: ' . (string) $key);
            }
        }

        $snippetId = isset($payload['snippet_id']) ? (int) $payload['snippet_id'] : 0;
        $marker = isset($payload['match_code_contains']) && is_string($payload['match_code_contains'])
            ? $payload['match_code_contains']
            : '';

        if (($snippetId > 0 && '' !== $marker) || ($snippetId <= 0 && '' === $marker)) {
            throw new RuntimeException('Public Code Snippets patch requires exactly one of snippet_id or match_code_contains.');
        }
        if ($snippetId <= 0 && (strlen($marker) > 240 || preg_match('/[\\x00-\\x1F\\x7F]/', $marker))) {
            throw new RuntimeException('Public Code Snippets marker must be a printable string up to 240 bytes.');
        }

        $replacements = isset($payload['replacements']) && is_array($payload['replacements'])
            ? array_values($payload['replacements'])
            : array();
        if (! $replacements || count($replacements) > 4) {
            throw new RuntimeException('Public Code Snippets patch requires 1-4 exact replacements.');
        }

        foreach ($replacements as $index => $replacement) {
            if (! is_array($replacement)) {
                throw new RuntimeException('Public Code Snippets replacement ' . $index . ' must be an object.');
            }
            foreach (array_keys($replacement) as $key) {
                if (! in_array((string) $key, array('find', 'replace'), true)) {
                    throw new RuntimeException('Public Code Snippets replacement contains unsupported key: ' . (string) $key);
                }
            }
            $find = isset($replacement['find']) && is_string($replacement['find']) ? $replacement['find'] : '';
            $replace = isset($replacement['replace']) && is_string($replacement['replace']) ? $replacement['replace'] : '';
            if ('' === $find || strlen($find) > 131072 || strlen($replace) > 131072) {
                throw new RuntimeException('Public Code Snippets replacement exceeds the bounded size limit.');
            }
            if (false !== strpos($find, "\0") || false !== strpos($replace, "\0")) {
                throw new RuntimeException('Public Code Snippets replacement may not contain NUL bytes.');
            }
        }

        $preview = $this->registry->execute('code_snippets.patch', $payload, array(
            'dry_run' => true,
            'confirm' => true,
            'public_validation' => true,
        ));
        $targetId = isset($preview['snippet']['id']) ? (int) $preview['snippet']['id'] : 0;
        $fingerprint = isset($preview['_current_fingerprint']) ? (string) $preview['_current_fingerprint'] : '';
        if ($targetId <= 0 || ! preg_match('/^[a-f0-9]{64}\\z/', $fingerprint)) {
            throw new RuntimeException('Public Code Snippets validation did not produce a guarded target fingerprint.');
        }

        return $targetId;
    }

    private function assertPublicPortfolioCaseTextPayload(array $payload): int
    {
        $preview = $this->registry->execute('portfolio.case_text_update', $payload, array('dry_run' => true, 'confirm' => true, 'public_validation' => true));
        $postId = isset($preview['post_id']) ? (int) $preview['post_id'] : 0;
        $fingerprint = isset($preview['_current_fingerprint']) ? (string) $preview['_current_fingerprint'] : '';
        if ($postId <= 0 || ! preg_match('/^[a-f0-9]{64}\z/', $fingerprint)) {
            throw new RuntimeException('Portfolio case text public validation did not produce a guarded target fingerprint.');
        }
        return $postId;
    }

    private function assertPublicPortfolioStatsPayload(array $payload): int
    {
        $preview = $this->registry->execute('acf.portfolio_stats_update', $payload, array('dry_run' => true, 'confirm' => true, 'public_validation' => true));
        $postId = isset($preview['post_id']) ? (int) $preview['post_id'] : 0;
        $fingerprint = isset($preview['_current_fingerprint']) ? (string) $preview['_current_fingerprint'] : '';
        if ($postId <= 0 || ! preg_match('/^[a-f0-9]{64}\z/', $fingerprint)) {
            throw new RuntimeException('Portfolio stats public validation did not produce a guarded target fingerprint.');
        }
        return $postId;
    }

    private function assertPublicAcfUpdatePayload(array $payload): int
    {
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, array('post_id', 'target', 'fields'), true)) {
                throw new RuntimeException('Public ACF update contains unsupported payload key: ' . (string) $key);
            }
        }
        if (isset($payload['post_id']) && array_key_exists('target', $payload)) {
            throw new RuntimeException('Public ACF update must use either post_id or target, not both.');
        }
        if (isset($payload['post_id'])) {
            if (! is_int($payload['post_id']) || $payload['post_id'] <= 0) {
                throw new RuntimeException('Public ACF update post_id must be a positive integer.');
            }
            $postId = $payload['post_id'];
        } elseif (array_key_exists('target', $payload) && is_int($payload['target']) && $payload['target'] > 0) {
            $postId = $payload['target'];
        } else {
            throw new RuntimeException('Public ACF update requires a positive integer post target.');
        }
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('Public ACF update target post was not found.');
        }
        Policy::assertPostReadable($post);
        if (! function_exists('current_user_can') || ! current_user_can('edit_post', $postId)) {
            throw new RuntimeException('Current user lacks permission to edit the public ACF target post.');
        }
        foreach (array('acf_get_field_groups', 'acf_get_field', 'get_field', 'update_field') as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException('ACF public update API is unavailable: ' . $function);
            }
        }
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (! $fields || count($fields) > 16 || array_keys($fields) === range(0, count($fields) - 1)) {
            throw new RuntimeException('Public ACF update requires a 1-16 field object.');
        }
        $allowedParents = array();
        foreach ((array) acf_get_field_groups(array('post_id' => $postId)) as $group) {
            if (! is_array($group)) {
                continue;
            }
            if (! empty($group['key'])) {
                $allowedParents[(string) $group['key']] = true;
            }
            if (! empty($group['ID'])) {
                $allowedParents[(string) $group['ID']] = true;
            }
        }
        if (! $allowedParents) {
            throw new RuntimeException('No ACF field group applies to the public target post.');
        }
        foreach ($fields as $fieldKey => $value) {
            $fieldKey = (string) $fieldKey;
            if (! preg_match('/^field_[A-Za-z0-9_-]{6,80}\z/', $fieldKey)) {
                throw new RuntimeException('Public ACF update requires field keys, not field names.');
            }
            Policy::assertKeyAllowed($fieldKey);
            if (! is_string($value) || strlen($value) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new RuntimeException('Public ACF values must be text strings up to 1000 bytes without control characters.');
            }
            $field = acf_get_field($fieldKey);
            $fieldType = is_array($field) ? (string) ($field['type'] ?? '') : '';
            if (! is_array($field) || ! in_array($fieldType, array('text', 'textarea', 'wysiwyg'), true)) {
                throw new RuntimeException('Public ACF field is missing or is not an allowed textual field: ' . $fieldKey);
            }
            if (in_array($fieldType, array('textarea', 'wysiwyg'), true) && (false !== strpos($value, '<') || false !== strpos($value, '>'))) {
                throw new RuntimeException('Public multiline ACF values must be plain text without HTML markup: ' . $fieldKey);
            }
            $name = (string) ($field['name'] ?? '');
            $parent = (string) ($field['parent'] ?? '');
            if ('' === $name || ! isset($allowedParents[$parent])) {
                throw new RuntimeException('Public ACF field does not belong to a field group for the target post: ' . $fieldKey);
            }
            Policy::assertKeyAllowed($name);
        }
        return $postId;
    }

    private function assertPublicRollbackRecord(array $record): void
    {
        if (empty($record['public_repository_mode'])) {
            throw new RuntimeException('Rollback snapshot was not created by the guarded public runtime.');
        }
        $sourceAction = (string) ($record['source_action'] ?? '');
        $rollback = isset($record['rollback']) && is_array($record['rollback']) ? $record['rollback'] : array();
        $rollbackAction = (string) ($rollback['action'] ?? '');
        $allowed = array(
            'acf.update' => array('acf.update'),
            'acf.portfolio_stats_update' => array('acf.portfolio_stats_update', 'acf.update'),
            'portfolio.case_text_update' => array('portfolio.case_text_update'),
            'post.update' => array('post.update'),
            'elementor.patch_element' => array('elementor.replace_document'),
            'acf.schema.ensure_text_fields' => array('acf.schema.remove_text_fields'),
            'connector.batch' => array('connector.batch'),
            'code_snippets.patch' => array('code_snippets.restore_code'),
            'custom_css.patch' => array('custom_css.update'),
        );
        if (! isset($allowed[$sourceAction]) || ! in_array($rollbackAction, $allowed[$sourceAction], true)) {
            throw new RuntimeException('Rollback snapshot is not eligible for the guarded public rollback route.');
        }
        if ('acf.portfolio_stats_update' === $sourceAction) {
            $this->assertPublicPortfolioStatsPayload((array) ($rollback['payload'] ?? array()));
        } elseif ('code_snippets.patch' === $sourceAction) {
            $rollbackPayload = (array) ($rollback['payload'] ?? array());
            $snippetId = isset($rollbackPayload['snippet_id']) ? (int) $rollbackPayload['snippet_id'] : 0;
            $code = isset($rollbackPayload['code']) && is_string($rollbackPayload['code']) ? $rollbackPayload['code'] : null;
            $expected = isset($rollbackPayload['expected_code_fingerprint']) ? (string) $rollbackPayload['expected_code_fingerprint'] : '';
            if ($snippetId <= 0 || null === $code || strlen($code) > 1048576 || ! preg_match('/^[a-f0-9]{64}\\z/', $expected)) {
                throw new RuntimeException('Public Code Snippets rollback snapshot is invalid.');
            }
        } elseif ('custom_css.patch' === $sourceAction) {
            $this->assertPublicCustomCssRollbackPayload((array) ($rollback['payload'] ?? array()));
        } elseif ('portfolio.case_text_update' === $sourceAction) {
            $this->assertPublicPortfolioCaseTextPayload((array) ($rollback['payload'] ?? array()));
        } elseif ('acf.update' === $rollbackAction) {
            $this->assertPublicAcfUpdatePayload((array) ($rollback['payload'] ?? array()));
        } elseif ('post.update' === $rollbackAction) {
            $this->assertPublicRollbackPostPayload((array) ($rollback['payload'] ?? array()));
        } elseif ('elementor.replace_document' === $rollbackAction) {
            $this->assertPublicRollbackPostId((array) ($rollback['payload'] ?? array()));
        } elseif ('acf.schema.remove_text_fields' === $rollbackAction) {
            $this->assertPublicRollbackPostId((array) ($rollback['payload'] ?? array()), 'post_id');
        } elseif ('connector.batch' === $rollbackAction) {
            $operations = isset($rollback['payload']['operations']) && is_array($rollback['payload']['operations']) ? array_values($rollback['payload']['operations']) : array();
            if (! $operations || count($operations) > 25) {
                throw new RuntimeException('Public batch rollback snapshot is invalid.');
            }
            foreach ($operations as $operation) {
                if (! is_array($operation) || empty($operation['action']) || ! isset($operation['payload']) || ! is_array($operation['payload'])) {
                    throw new RuntimeException('Public batch rollback operation is invalid.');
                }
                if ('acf.update' === $operation['action']) {
                    $this->assertPublicAcfUpdatePayload($operation['payload']);
                } elseif ('acf.portfolio_stats_update' === $operation['action']) {
                    $this->assertPublicPortfolioStatsPayload($operation['payload']);
                } elseif ('post.update' === $operation['action']) {
                    $this->assertPublicRollbackPostPayload($operation['payload']);
                } else {
                    throw new RuntimeException('Public batch rollback contains a non-public restore action.');
                }
            }
        }
    }

    private function publicRollbackFingerprint(array $rollback, array $context): string
    {
        $action = (string) $rollback['action'];
        $payload = (array) $rollback['payload'];
        if ('connector.batch' === $action) {
            $fingerprints = array();
            foreach ((array) ($payload['operations'] ?? array()) as $operation) {
                $preview = $this->registry->execute((string) $operation['action'], (array) $operation['payload'], array_merge($context, array('dry_run' => true, 'confirm' => true, 'rollback_mode' => true)));
                $current = isset($preview['_current_fingerprint']) ? (string) $preview['_current_fingerprint'] : '';
                if (! preg_match('/^[a-f0-9]{64}\z/', $current)) {
                    throw new RuntimeException('Public batch rollback operation cannot enforce stale-state protection.');
                }
                $fingerprints[] = $current;
            }
            return Fingerprint::make($fingerprints);
        }
        $preview = $this->registry->execute($action, $payload, array_merge($context, array('dry_run' => true, 'confirm' => true, 'rollback_mode' => true)));
        return isset($preview['_current_fingerprint']) ? (string) $preview['_current_fingerprint'] : '';
    }

    private function assertPublicRollbackPostPayload(array $payload): void
    {
        $allowed = array('id', 'title', 'content', 'excerpt', 'status', 'slug', 'type', 'author', 'parent', 'menu_order', 'comment_status', 'ping_status');
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                throw new RuntimeException('Public post rollback contains unsupported field: ' . (string) $key);
            }
        }
        $this->assertPublicRollbackPostId($payload);
        if (isset($payload['status']) && 'publish' !== (string) $payload['status']) {
            throw new RuntimeException('Public post rollback may only restore publish status.');
        }
    }

    private function assertPublicRollbackPostId(array $payload, string $key = 'id'): void
    {
        $postId = isset($payload[$key]) ? (int) $payload[$key] : 0;
        if ($postId <= 0) {
            throw new RuntimeException('Public rollback requires a positive post id.');
        }
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('Public rollback target post was not found.');
        }
        Policy::assertPostReadable($post);
        if (! function_exists('current_user_can') || ! current_user_can('edit_post', $postId)) {
            throw new RuntimeException('Current user lacks permission to edit the public rollback target post.');
        }
    }

    private function executeWithStateGuards(string $action, array $payload, array $context, ?string $expectedFingerprint, ?string $expectedStateToken): array
    {
        if ((null !== $expectedFingerprint || null !== $expectedStateToken) && empty($context['dry_run'])) {
            $preview = $this->registry->execute($action, $payload, array_merge($context, array('dry_run' => true)));
            if (! isset($preview['_current_fingerprint']) || ! is_string($preview['_current_fingerprint'])) {
                throw new RuntimeException('Action cannot enforce stale-state protection: ' . $action);
            }
            $current = $preview['_current_fingerprint'];
            if (null !== $expectedFingerprint && ! hash_equals($expectedFingerprint, $current)) {
                throw new RuntimeException('Stale target: expected_fingerprint does not match current state.');
            }
            if (null !== $expectedStateToken && ! hash_equals($expectedStateToken, Fingerprint::siteTokenFromFingerprint($current))) {
                throw new RuntimeException('Stale target: expected_state_token does not match current site state.');
            }
        }
        return $this->registry->execute($action, $payload, $context);
    }
}
