<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class AcfAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('acf.get', array($this, 'get'), array(
            'privileged' => true,
            'description' => 'Read ACF values for a post, term, user or options target.',
        ));
        $registry->register('acf.update', array($this, 'update'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Update one or more ACF fields through update_field().',
        ));
        $registry->register('acf.delete', array($this, 'delete'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Delete one or more ACF field values through delete_field().',
        ));
        $registry->register('acf.field_groups', array($this, 'fieldGroups'), array(
            'privileged' => true,
            'description' => 'List active ACF field groups and fields for discovery.',
        ));
    }

    public function get(array $payload): array
    {
        $this->assertAcf();
        $target = $this->target($payload);
        $fields = get_fields($target, false);
        if (! is_array($fields)) {
            $fields = array();
        }

        if (isset($payload['fields']) && is_array($payload['fields'])) {
            $selected = array();
            foreach ($payload['fields'] as $field) {
                $key = (string) $field;
                $selected[$key] = get_field($key, $target, false);
            }
            $fields = $selected;
        }

        return array(
            'target' => $target,
            'fields' => $fields,
            'fingerprint' => Fingerprint::make($fields),
        );
    }

    public function update(array $payload, array $context): array
    {
        $this->assertAcf();
        $target = $this->target($payload);
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (! $fields) {
            throw new RuntimeException('acf.update requires payload.fields.');
        }

        $before = array();
        foreach ($fields as $field => $value) {
            $before[(string) $field] = get_field((string) $field, $target, false);
        }

        $result = array(
            'target' => $target,
            'before' => $before,
            'after' => $fields,
            '_current_fingerprint' => Fingerprint::make($before),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        foreach ($fields as $field => $value) {
            if (false === update_field((string) $field, $value, $target)) {
                $current = get_field((string) $field, $target, false);
                if ($current !== $value) {
                    throw new RuntimeException('ACF update failed for field: ' . (string) $field);
                }
            }
        }

        $result['after'] = array();
        foreach ($fields as $field => $value) {
            $result['after'][(string) $field] = get_field((string) $field, $target, false);
        }
        $result['_rollback'] = array('action' => 'acf.update', 'payload' => array('target' => $target, 'fields' => $before));
        return $result;
    }

    public function delete(array $payload, array $context): array
    {
        $this->assertAcf();
        $target = $this->target($payload);
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? array_values($payload['fields']) : array();
        if (! $fields) {
            throw new RuntimeException('acf.delete requires payload.fields.');
        }

        $before = array();
        foreach ($fields as $field) {
            $before[(string) $field] = get_field((string) $field, $target, false);
        }
        $result = array('target' => $target, 'before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) {
            return $result;
        }

        foreach ($fields as $field) {
            delete_field((string) $field, $target);
        }
        $result['_rollback'] = array('action' => 'acf.update', 'payload' => array('target' => $target, 'fields' => $before));
        return $result;
    }

    public function fieldGroups(array $payload): array
    {
        $this->assertAcf();
        $args = array();
        if (isset($payload['post_id'])) {
            $args['post_id'] = (int) $payload['post_id'];
        }
        $groups = function_exists('acf_get_field_groups') ? acf_get_field_groups($args) : array();
        $result = array();
        foreach ((array) $groups as $group) {
            $fields = function_exists('acf_get_fields') ? acf_get_fields($group) : array();
            $result[] = array(
                'key' => $group['key'] ?? null,
                'title' => $group['title'] ?? null,
                'fields' => array_map(static function (array $field): array {
                    return array(
                        'key' => $field['key'] ?? null,
                        'name' => $field['name'] ?? null,
                        'label' => $field['label'] ?? null,
                        'type' => $field['type'] ?? null,
                        'required' => ! empty($field['required']),
                    );
                }, is_array($fields) ? $fields : array()),
            );
        }
        return array('field_groups' => $result);
    }

    private function assertAcf(): void
    {
        if (! function_exists('get_fields') || ! function_exists('update_field')) {
            throw new RuntimeException('ACF is not active.');
        }
    }

    private function target(array $payload)
    {
        if (array_key_exists('target', $payload)) {
            $target = $payload['target'];
            if (is_int($target) || is_string($target)) {
                return $target;
            }
        }
        if (isset($payload['post_id'])) {
            return (int) $payload['post_id'];
        }
        throw new RuntimeException('ACF target is required. Use payload.target or payload.post_id.');
    }
}
