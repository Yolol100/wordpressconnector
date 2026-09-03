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

        $this->registry->register('connector.actions', array($this, 'actions'), array(
            'description' => 'List registered actions and security metadata.',
        ));
        $this->registry->register('connector.batch', array($this, 'batch'), array(
            'mutation' => true,
            'description' => 'Execute up to 25 connector operations with compensation on failure.',
        ));
        $this->registry->register('connector.rollback', array($this, 'rollback'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Execute a stored rollback snapshot by request_id.',
        ));
        $this->registry->register('connector.cleanup', array($this, 'cleanup'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Delete expired idempotency and rollback state.',
        ));
    }

    public function run(Request $request, array $context = array()): array
    {
        $lockToken = '';

        try {
            $descriptor = $this->registry->descriptor($request->action());
            Policy::assertActionAllowed($descriptor, $request->dryRun(), $request->confirm());

            $requestFingerprint = Fingerprint::make(array(
                'action' => $request->action(),
                'payload' => $request->payload(),
                'expected_fingerprint' => $request->expectedFingerprint(),
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
                    return Result::success($request, array(
                        'idempotent_replay' => true,
                        'original_result_hash' => $existing['result_hash'] ?? null,
                    ), array('request_fingerprint' => $requestFingerprint));
                }
            }

            $context['dry_run'] = $request->dryRun();
            $context['confirm'] = $request->confirm();
            $context['request_id'] = $request->id();
            $context['registry'] = $this->registry;

            $data = $this->executeWithFingerprintGuard(
                $request->action(),
                $request->payload(),
                $context,
                $request->expectedFingerprint()
            );

            $rollback = isset($data['_rollback']) && is_array($data['_rollback']) ? $data['_rollback'] : null;
            unset($data['_rollback'], $data['_current_fingerprint']);

            if ($rollback && ! $request->dryRun() && ! empty($descriptor['mutation'])) {
                $this->snapshots->put($request->id(), $rollback);
                $data['rollback_request_id'] = $request->id();
            }

            $result = Result::success($request, $data, array('request_fingerprint' => $requestFingerprint));

            if (! $request->dryRun() && ! empty($descriptor['mutation'])) {
                $this->processed->put($request->id(), $requestFingerprint, $request->action(), Fingerprint::make($result));
            }

            return $result;
        } catch (Throwable $error) {
            return Result::failure($request, $error->getMessage(), array(
                'exception' => get_class($error),
            ));
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
                if (in_array($action, array('connector.batch', 'connector.rollback', 'connector.cleanup'), true)) {
                    throw new RuntimeException('Nested batch, rollback or cleanup operations are not allowed.');
                }

                $descriptor = $this->registry->descriptor($action);
                Policy::assertActionAllowed($descriptor, ! empty($context['dry_run']), ! empty($context['confirm']));

                $operationPayload = isset($operation['payload']) && is_array($operation['payload']) ? $operation['payload'] : array();
                $expected = isset($operation['expected_fingerprint']) && is_string($operation['expected_fingerprint'])
                    ? $operation['expected_fingerprint']
                    : null;

                $result = $this->executeWithFingerprintGuard($action, $operationPayload, $context, $expected);
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
                        $compensation[] = array(
                            'ok' => true,
                            'action' => $rollback['action'],
                            'result' => $this->registry->execute((string) $rollback['action'], (array) $rollback['payload'], array_merge($context, array(
                                'rollback_mode' => true,
                                'dry_run' => false,
                            ))),
                        );
                    } catch (Throwable $rollbackError) {
                        $compensation[] = array(
                            'ok' => false,
                            'action' => $rollback['action'] ?? null,
                            'error' => $rollbackError->getMessage(),
                        );
                    }
                }
            }

            throw new RuntimeException(
                'Batch failed at operation ' . count($results) . ': ' . $error->getMessage() .
                ($compensation ? ' Compensation attempted: ' . wp_json_encode($compensation) : ''),
                0,
                $error
            );
        }

        $output = array('operations' => $results);
        if ($rollbacks) {
            $output['_rollback'] = array(
                'action' => 'connector.batch',
                'payload' => array('operations' => array_map(static function (array $rollback): array {
                    return array('action' => $rollback['action'], 'payload' => $rollback['payload']);
                }, $rollbacks)),
            );
        }

        return $output;
    }

    public function rollback(array $payload, array $context): array
    {
        $requestId = isset($payload['request_id']) ? (string) $payload['request_id'] : '';
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}$/', $requestId)) {
            throw new RuntimeException('rollback requires a valid payload.request_id.');
        }

        $rollback = $this->snapshots->get($requestId);
        if (! $rollback || empty($rollback['action']) || ! isset($rollback['payload']) || ! is_array($rollback['payload'])) {
            throw new RuntimeException('Rollback snapshot was not found.');
        }

        if (! empty($context['dry_run'])) {
            return array('would_execute' => $rollback);
        }

        if (in_array($rollback['action'], array('connector.rollback', 'connector.cleanup'), true)) {
            throw new RuntimeException('Recursive rollback is not allowed.');
        }

        $descriptor = $this->registry->descriptor((string) $rollback['action']);
        Policy::assertActionAllowed($descriptor, false, true);
        $result = $this->registry->execute((string) $rollback['action'], $rollback['payload'], array_merge($context, array(
            'rollback_mode' => true,
            'expected_fingerprint' => null,
        )));
        unset($result['_rollback'], $result['_current_fingerprint']);

        return array(
            'restored_request_id' => $requestId,
            'restored_with' => $rollback['action'],
            'result' => $result,
        );
    }

    public function cleanup(array $payload, array $context): array
    {
        $days = isset($payload['older_than_days']) ? max(1, min(365, (int) $payload['older_than_days'])) : 30;
        if (! empty($context['dry_run'])) {
            return array('would_delete_state_older_than_days' => $days);
        }

        $seconds = $days * DAY_IN_SECONDS;
        return array(
            'snapshots_deleted' => $this->snapshots->cleanup($seconds),
            'processed_deleted' => $this->processed->cleanup($seconds),
        );
    }

    private function executeWithFingerprintGuard(string $action, array $payload, array $context, ?string $expected): array
    {
        if (null !== $expected && empty($context['dry_run'])) {
            $preview = $this->registry->execute($action, $payload, array_merge($context, array('dry_run' => true)));
            if (! isset($preview['_current_fingerprint']) || ! is_string($preview['_current_fingerprint'])) {
                throw new RuntimeException('Action cannot enforce expected_fingerprint: ' . $action);
            }
            if (! hash_equals($expected, $preview['_current_fingerprint'])) {
                throw new RuntimeException('Stale target: expected_fingerprint does not match current state.');
            }
        }

        return $this->registry->execute($action, $payload, $context);
    }
}
