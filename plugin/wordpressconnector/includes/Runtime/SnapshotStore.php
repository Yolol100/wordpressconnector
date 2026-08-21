<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime;

final class SnapshotStore
{
    private const PREFIX = 'wpconnector_snapshot_';

    public function put(string $requestId, array $rollback): void
    {
        update_option(self::key($requestId), array(
            'created_at' => time(),
            'rollback' => $rollback,
        ), false);
    }

    public function get(string $requestId): ?array
    {
        $value = get_option(self::key($requestId), null);
        if (! is_array($value) || ! isset($value['rollback']) || ! is_array($value['rollback'])) {
            return null;
        }
        return $value['rollback'];
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
        foreach ($rows as $row) {
            $value = maybe_unserialize($row->option_value);
            if (is_array($value) && isset($value['created_at']) && (int) $value['created_at'] < time() - $olderThanSeconds) {
                delete_option((string) $row->option_name);
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
