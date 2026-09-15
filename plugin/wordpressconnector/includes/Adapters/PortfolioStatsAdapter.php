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
            'public_repository_safe' => true,
            'description' => 'Update only the eight existing portfolio-stat ACF text fields on one standard WordPress post, including unpublished targets.',
        ));
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
        $resolved = array();
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
            $resolved[$fieldKey] = $field;
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
            foreach ($before as $fieldKey => $oldValue) {
                update_field((string) $fieldKey, $oldValue, $postId);
            }
            throw $error;
        }

        $result['_rollback'] = array(
            'action' => 'acf.update',
            'payload' => array('post_id' => $postId, 'fields' => $before),
        );
        return $result;
    }
}
