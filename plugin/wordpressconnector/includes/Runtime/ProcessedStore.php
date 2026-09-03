<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime;

final class ProcessedStore
{
    private const PREFIX = 'wpconnector_processed_';
    private const LOCK_KEY = 'wpconnector_mutation_lock';
    private const LOCK_TTL = 600;

    public function put(string $requestId, string $fingerprint, string $action, string $resultHash): void
    {
        update_option(self::key($requestId), array(
            'created_at' => time(),
            'fingerprint' => $fingerprint,
            'action' => $action,
            'result_hash' => $resultHash,
        ), false);
    }

    public function get(string $requestId): ?array
    {
        $value = get_option(self::key($requestId), null);
        return is_array($value) ? $value : null;
    }

    public function acquireMutationLock(): string
    {
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
        $value = wp_json_encode(array(
            'token' => $token,
            'created_at' => time(),
        ));
        if (! is_string($value)) {
            return '';
        }

        if (add_option(self::LOCK_KEY, $value, '', false)) {
            return $token;
        }

        $existing = get_option(self::LOCK_KEY, '');
        $data = is_string($existing) ? json_decode($existing, true) : null;
        if (
            ! is_string($existing)
            || ! is_array($data)
            || time() - (int) ($data['created_at'] ?? time()) <= self::LOCK_TTL
        ) {
            return '';
        }

        return $this->compareAndSwapLock($existing, $value) ? $token : '';
    }

    public function releaseMutationLock(string $token): void
    {
        $existing = get_option(self::LOCK_KEY, '');
        $data = is_string($existing) ? json_decode($existing, true) : null;
        if (! is_string($existing) || ! is_array($data) || ! hash_equals((string) ($data['token'] ?? ''), $token)) {
            return;
        }

        $this->deleteLockIfValue($existing);
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

    private function compareAndSwapLock(string $expected, string $replacement): bool
    {
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->options,
            array('option_value' => $replacement),
            array('option_name' => self::LOCK_KEY, 'option_value' => $expected),
            array('%s'),
            array('%s', '%s')
        );
        if (1 !== $updated) {
            return false;
        }

        wp_cache_delete(self::LOCK_KEY, 'options');
        return true;
    }

    private function deleteLockIfValue(string $expected): bool
    {
        global $wpdb;
        $deleted = $wpdb->delete(
            $wpdb->options,
            array('option_name' => self::LOCK_KEY, 'option_value' => $expected),
            array('%s', '%s')
        );
        if (1 !== $deleted) {
            return false;
        }

        wp_cache_delete(self::LOCK_KEY, 'options');
        return true;
    }

    private static function key(string $requestId): string
    {
        return self::PREFIX . hash('sha256', $requestId);
    }
}
