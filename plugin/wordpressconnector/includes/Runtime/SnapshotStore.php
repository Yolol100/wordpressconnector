<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime;

final class SnapshotStore
{
    private const PREFIX = 'wpconnector_snapshot_';

    public function put(string $requestId, array $rollback, array $metadata = array()): void
    {
        $key = self::key($requestId);
        $record = array(
            'created_at' => time(),
            'rollback' => $rollback,
            'source_action' => isset($metadata['source_action']) ? (string) $metadata['source_action'] : '',
            'public_repository_mode' => ! empty($metadata['public_repository_mode']),
        );
        update_option($key, $record, false);
        if (get_option($key, null) !== $record) {
            throw new \RuntimeException('Rollback snapshot did not persist; the mutation outcome requires manual reconciliation.');
        }
    }

    public function get(string $requestId): ?array
    {
        $record = $this->getRecord($requestId);
        return is_array($record) && isset($record['rollback']) && is_array($record['rollback']) ? $record['rollback'] : null;
    }

    public function getRecord(string $requestId): ?array
    {
        $value = get_option(self::key($requestId), null);
        if (! is_array($value) || ! isset($value['rollback']) || ! is_array($value['rollback'])) {
            return null;
        }
        return array(
            'created_at' => isset($value['created_at']) ? (int) $value['created_at'] : 0,
            'rollback' => $value['rollback'],
            'source_action' => isset($value['source_action']) ? (string) $value['source_action'] : '',
            'public_repository_mode' => ! empty($value['public_repository_mode']),
        );
    }

    public function delete(string $requestId): void
    {
        delete_option(self::key($requestId));
    }

    public function cleanup(int $olderThanSeconds): int
    {
        global $wpdb;
        $like = $wpdb->esc_like(self::PREFIX) . '%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        $deleted = 0;
        if (! is_array($rows)) {
            throw new \RuntimeException('Rollback cleanup could not inspect WordPress options storage.');
        }
        foreach ($rows as $row) {
            $name = isset($row->option_name) && is_string($row->option_name) ? $row->option_name : '';
            if (1 !== preg_match('/^wpconnector_snapshot_[a-f0-9]{64}\z/', $name)) {
                continue;
            }
            $value = maybe_unserialize($row->option_value);
            if (! is_array($value) || ! is_int($value['created_at'] ?? null)
                || $value['created_at'] >= time() - $olderThanSeconds) {
                continue;
            }

            // A pending or missing result is NOT permission to discard the
            // only site-local compensation data for an uncertain write.
            $processed = get_option('wpconnector_processed_' . substr($name, strlen(self::PREFIX)), null);
            if (! is_array($processed)) {
                continue;
            }
            $completed = ($processed['state'] ?? null) === 'completed'
                || (! array_key_exists('state', $processed) && is_bool($processed['ok'] ?? null));
            if (! $completed || ! is_bool($processed['ok'] ?? null)
                || ! is_string($processed['result_hash'] ?? null)
                || '' === $processed['result_hash']) {
                continue;
            }
            if (delete_option($name)) {
                ++$deleted;
            }
        }
        return $deleted;
    }

    private static function key(string $requestId): string
    {
        return self::PREFIX . hash('sha256', $requestId);
    }
}
