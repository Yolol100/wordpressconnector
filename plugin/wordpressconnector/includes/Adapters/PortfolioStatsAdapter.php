<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class PortfolioStatsAdapter
{
    private const ALLOWED_FIELD_NAMES = array(
        'portfolio_stat_1_value',
        'portfolio_stat_1_label',
        'portfolio_stat_2_value',
        'portfolio_stat_2_label',
        'portfolio_stat_3_value',
        'portfolio_stat_3_label',
        'portfolio_stat_4_value',
        'portfolio_stat_4_label',
    );

    private const ALLOWED_POST_STATUSES = array('publish', 'draft', 'pending', 'private', 'future');

    public function register(Registry $registry): void
    {
        $registry->register('acf.portfolio_stats_update', array($this, 'update'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Update only the eight existing portfolio-stat ACF text fields on one standard WordPress post, including unpublished targets.',
        ));
        $registry->register('portfolio.case_text_update', array($this, 'updateCaseText'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Update only portfolio intro content plus description_1 and description_2 on one standard WordPress post, including unpublished targets.',
        ));
    }


    public function updateCaseText(array $payload, array $context): array
    {
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, array('post_id', 'content', 'description_1', 'description_2'), true)) {
                throw new RuntimeException('Portfolio case text update contains unsupported payload key: ' . (string) $key);
            }
        }
        if (! isset($payload['post_id']) || ! is_int($payload['post_id']) || $payload['post_id'] <= 0) {
            throw new RuntimeException('Portfolio case text update requires a positive integer post_id.');
        }
        $postId = $payload['post_id'];
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('Portfolio case text target post was not found.');
        }
        if ('post' !== (string) $post->post_type || ! in_array((string) $post->post_status, self::ALLOWED_POST_STATUSES, true)) {
            throw new RuntimeException('Portfolio case text target must be a normal WordPress post in an allowed content status.');
        }
        if (! function_exists('current_user_can') || ! current_user_can('edit_post', $postId)) {
            throw new RuntimeException('Current user lacks permission to edit the portfolio case text target.');
        }

        $requested = array();
        if (array_key_exists('content', $payload)) {
            $value = $payload['content'];
            if (! is_string($value) || strlen($value) > 12000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new RuntimeException('Portfolio case content must be text up to 12000 bytes without control characters.');
            }
            $requested['content'] = $value;
        }

        $fieldMap = array(
            'description_1' => 'field_69deb8ddbcd8d',
            'description_2' => 'field_69deb8e4bcd8e',
        );
        $descriptionKeys = array_intersect(array_keys($fieldMap), array_keys($payload));
        if ($descriptionKeys) {
            foreach (array('acf_get_field_groups', 'acf_get_field', 'get_field', 'update_field') as $function) {
                if (! function_exists($function)) {
                    throw new RuntimeException('Portfolio case text ACF API is unavailable: ' . $function);
                }
            }
            $allowedParents = array();
            foreach ((array) acf_get_field_groups(array('post_id' => $postId)) as $group) {
                if (! is_array($group)) continue;
                if (! empty($group['key'])) $allowedParents[(string) $group['key']] = true;
                if (! empty($group['ID'])) $allowedParents[(string) $group['ID']] = true;
            }
            if (! $allowedParents) {
                throw new RuntimeException('No ACF field group applies to the portfolio case text target.');
            }
            foreach ($descriptionKeys as $name) {
                $value = $payload[$name];
                if (! is_string($value) || strlen($value) > 5000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) || false !== strpos($value, '<') || false !== strpos($value, '>')) {
                    throw new RuntimeException('Portfolio case descriptions must be plain text up to 5000 bytes without HTML or control characters.');
                }
                $fieldKey = $fieldMap[$name];
                $field = acf_get_field($fieldKey);
                if (! is_array($field) || (string) ($field['name'] ?? '') !== $name || ! in_array((string) ($field['type'] ?? ''), array('text','textarea','wysiwyg'), true) || ! isset($allowedParents[(string) ($field['parent'] ?? '')])) {
                    throw new RuntimeException('Portfolio case description field is missing or outside the target field group: ' . $name);
                }
                $requested[$name] = $value;
            }
        }
        if (! $requested) {
            throw new RuntimeException('Portfolio case text update requires content and/or description fields.');
        }

        $before = array();
        foreach ($requested as $name => $value) {
            if ('content' === $name) {
                $before[$name] = (string) $post->post_content;
            } else {
                $before[$name] = get_field($fieldMap[$name], $postId, false);
            }
        }
        $result = array('post_id' => $postId, 'before' => $before, 'after' => $requested, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;

        try {
            if (array_key_exists('content', $requested)) {
                $updated = wp_update_post(array('ID' => $postId, 'post_content' => $requested['content']), true);
                if (is_wp_error($updated)) throw new RuntimeException('Portfolio case content update failed: ' . $updated->get_error_message());
            }
            foreach ($descriptionKeys as $name) {
                $fieldKey = $fieldMap[$name];
                if (false === update_field($fieldKey, $requested[$name], $postId) && get_field($fieldKey, $postId, false) !== $requested[$name]) {
                    throw new RuntimeException('Portfolio case ACF update failed for: ' . $name);
                }
            }
            $afterPost = get_post($postId);
            $after = array();
            foreach ($requested as $name => $value) {
                $actual = 'content' === $name ? ($afterPost instanceof \WP_Post ? (string) $afterPost->post_content : null) : get_field($fieldMap[$name], $postId, false);
                if ($actual !== $value) throw new RuntimeException('Portfolio case readback mismatch for: ' . $name);
                $after[$name] = $actual;
            }
            $result['after'] = $after;
        } catch (Throwable $error) {
            $failures = array();
            if (array_key_exists('content', $before)) {
                $restore = wp_update_post(array('ID' => $postId, 'post_content' => $before['content']), true);
                $restoredPost = get_post($postId);
                if (is_wp_error($restore) || ! $restoredPost instanceof \WP_Post || (string) $restoredPost->post_content !== $before['content']) $failures[] = 'content';
            }
            foreach ($descriptionKeys as $name) {
                $fieldKey = $fieldMap[$name];
                update_field($fieldKey, $before[$name], $postId);
                if (get_field($fieldKey, $postId, false) !== $before[$name]) $failures[] = $name;
            }
            if ($failures) throw new RuntimeException('Portfolio case compensation failed for: ' . implode(', ', $failures) . '. Original error: ' . $error->getMessage(), 0, $error);
            throw $error;
        }

        $rollbackPayload = array('post_id' => $postId);
        foreach ($before as $name => $value) $rollbackPayload[$name] = $value;
        $result['_rollback'] = array('action' => 'portfolio.case_text_update', 'payload' => $rollbackPayload);
        return $result;
    }


    public function update(array $payload, array $context): array
    {
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, array('post_id', 'fields'), true)) {
                throw new RuntimeException('Portfolio stats update contains unsupported payload key: ' . (string) $key);
            }
        }

        if (! isset($payload['post_id']) || ! is_int($payload['post_id']) || $payload['post_id'] <= 0) {
            throw new RuntimeException('Portfolio stats update requires a positive integer post_id.');
        }
        $postId = $payload['post_id'];
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('Portfolio stats target post was not found.');
        }
        if ('post' !== (string) $post->post_type) {
            throw new RuntimeException('Portfolio stats public exception is limited to the standard WordPress post type.');
        }
        if (! in_array((string) $post->post_status, self::ALLOWED_POST_STATUSES, true)) {
            throw new RuntimeException('Portfolio stats target has an unsupported post status.');
        }
        if (! function_exists('current_user_can') || ! current_user_can('edit_post', $postId)) {
            throw new RuntimeException('Current user lacks permission to edit the portfolio stats target post.');
        }

        foreach (array('acf_get_field_groups', 'acf_get_field', 'get_field', 'update_field') as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException('Portfolio stats ACF API is unavailable: ' . $function);
            }
        }

        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (! $fields || count($fields) > 8 || array_keys($fields) === range(0, count($fields) - 1)) {
            throw new RuntimeException('Portfolio stats update requires a 1-8 field object.');
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
            throw new RuntimeException('No ACF field group applies to the portfolio stats target post.');
        }

        $before = array();
        $seenNames = array();
        foreach ($fields as $fieldKey => $value) {
            $fieldKey = (string) $fieldKey;
            if (! preg_match('/^field_[A-Za-z0-9_-]{6,80}\z/', $fieldKey)) {
                throw new RuntimeException('Portfolio stats update requires ACF field keys, not field names.');
            }
            Policy::assertKeyAllowed($fieldKey);
            if (! is_string($value) || strlen($value) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new RuntimeException('Portfolio stats values must be text strings up to 1000 bytes without control characters.');
            }
            $field = acf_get_field($fieldKey);
            if (! is_array($field) || 'text' !== (string) ($field['type'] ?? '')) {
                throw new RuntimeException('Portfolio stats field is missing or is not a text field: ' . $fieldKey);
            }
            $name = (string) ($field['name'] ?? '');
            $parent = (string) ($field['parent'] ?? '');
            if (! in_array($name, self::ALLOWED_FIELD_NAMES, true)) {
                throw new RuntimeException('Field is outside the fixed portfolio stats allowlist: ' . $fieldKey);
            }
            if (isset($seenNames[$name])) {
                throw new RuntimeException('Duplicate portfolio stats field name in one request: ' . $name);
            }
            if (! isset($allowedParents[$parent])) {
                throw new RuntimeException('Portfolio stats field does not belong to a field group for the target post: ' . $fieldKey);
            }
            Policy::assertKeyAllowed($name);
            $seenNames[$name] = true;
            $before[$fieldKey] = get_field($fieldKey, $postId, false);
        }

        $result = array(
            'post_id' => $postId,
            'before' => $before,
            'after' => $fields,
            '_current_fingerprint' => Fingerprint::make($before),
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }

        try {
            foreach ($fields as $fieldKey => $value) {
                if (false === update_field((string) $fieldKey, $value, $postId)) {
                    $current = get_field((string) $fieldKey, $postId, false);
                    if ($current !== $value) {
                        throw new RuntimeException('Portfolio stats ACF update failed for field: ' . (string) $fieldKey);
                    }
                }
            }

            $after = array();
            foreach ($fields as $fieldKey => $value) {
                $actual = get_field((string) $fieldKey, $postId, false);
                if ($actual !== $value) {
                    throw new RuntimeException('Portfolio stats readback mismatch for field: ' . (string) $fieldKey);
                }
                $after[(string) $fieldKey] = $actual;
            }
            $result['after'] = $after;
        } catch (Throwable $error) {
            $restoreFailures = array();
            foreach ($before as $fieldKey => $oldValue) {
                update_field((string) $fieldKey, $oldValue, $postId);
                $restored = get_field((string) $fieldKey, $postId, false);
                if ($restored !== $oldValue) {
                    $restoreFailures[] = (string) $fieldKey;
                }
            }
            if ($restoreFailures) {
                throw new RuntimeException(
                    'Portfolio stats compensation readback failed for: ' . implode(', ', $restoreFailures) . '. Original error: ' . $error->getMessage(),
                    0,
                    $error
                );
            }
            throw $error;
        }

        $result['_rollback'] = array(
            'action' => 'acf.portfolio_stats_update',
            'payload' => array('post_id' => $postId, 'fields' => $before),
        );
        return $result;
    }
}
