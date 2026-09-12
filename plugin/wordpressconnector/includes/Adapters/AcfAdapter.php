<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
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
            'public_repository_safe' => true,
            'capability' => 'manage_options',
            'description' => 'List active ACF field groups and fields for discovery.',
        ));
        $registry->register('acf.schema.ensure_text_fields', array($this, 'ensureTextFields'), array(
            'mutation' => true,
            'privileged' => true,
            'public_repository_safe' => true,
            'capability' => 'manage_options',
            'description' => 'Create a bounded set of new ACF text fields in an existing field group without altering existing fields.',
        ));
        $registry->register('acf.schema.remove_text_fields', array($this, 'removeTextFields'), array(
            'mutation' => true,
            'privileged' => true,
            'public_repository_safe' => true,
            'capability' => 'manage_options',
            'description' => 'Remove exact ACF text fields created by a prior bounded schema ensure operation.',
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
        $this->assertAcfSchema();
        $args = array();
        if (isset($payload['post_id'])) {
            $postId = (int) $payload['post_id'];
            if ($postId <= 0) {
                throw new RuntimeException('acf.field_groups post_id must be a positive integer.');
            }
            $post = get_post($postId);
            if (! $post instanceof \WP_Post) {
                throw new RuntimeException('ACF field-group target post was not found.');
            }
            if (Policy::publicRepositoryContext()) {
                Policy::assertPostReadable($post);
            }
            $args['post_id'] = $postId;
        } elseif (Policy::publicRepositoryContext()) {
            throw new RuntimeException('Public-repository ACF field-group discovery requires payload.post_id.');
        }

        $groups = acf_get_field_groups($args);
        $result = array();
        foreach ((array) $groups as $group) {
            $fields = acf_get_fields($group);
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

    public function ensureTextFields(array $payload, array $context): array
    {
        $this->assertAcfSchema();
        $postId = isset($payload['post_id']) ? (int) $payload['post_id'] : 0;
        $groupKey = isset($payload['group_key']) ? (string) $payload['group_key'] : '';
        $requested = isset($payload['fields']) && is_array($payload['fields']) ? array_values($payload['fields']) : array();
        if ($postId <= 0 || ! preg_match('/^group_[A-Za-z0-9_-]{6,80}\z/', $groupKey)) {
            throw new RuntimeException('acf.schema.ensure_text_fields requires a valid post_id and group_key.');
        }
        if (! $requested || count($requested) > 12) {
            throw new RuntimeException('acf.schema.ensure_text_fields requires 1-12 text field definitions.');
        }
        $this->assertGroupAppliesToPost($groupKey, $postId);

        $normalized = $this->normalizeRequestedTextFields($requested);
        $existingFields = $this->groupFields($groupKey);
        $existingByKey = array();
        $existingByName = array();
        foreach ($existingFields as $field) {
            if (! empty($field['key'])) {
                $existingByKey[(string) $field['key']] = $field;
            }
            if (! empty($field['name'])) {
                $existingByName[(string) $field['name']] = $field;
            }
        }

        $before = array();
        $wouldCreate = array();
        foreach ($normalized as $definition) {
            $key = $definition['key'];
            $name = $definition['name'];
            $byKey = $existingByKey[$key] ?? null;
            $byName = $existingByName[$name] ?? null;
            if (is_array($byKey)) {
                $summary = $this->fieldSummary($byKey);
                if (! $this->fieldMatchesDefinition($summary, $definition, $groupKey)) {
                    throw new RuntimeException('Existing ACF field key conflicts with requested text field: ' . $key);
                }
                if (is_array($byName) && (string) ($byName['key'] ?? '') !== $key) {
                    throw new RuntimeException('Existing ACF field name belongs to a different field: ' . $name);
                }
                $before[$key] = $summary;
                continue;
            }
            if (is_array($byName)) {
                throw new RuntimeException('Existing ACF field name conflicts with requested text field: ' . $name);
            }
            $before[$key] = null;
            $wouldCreate[] = $definition;
        }

        $state = array('post_id' => $postId, 'group_key' => $groupKey, 'fields' => $before);
        $result = array(
            'post_id' => $postId,
            'group_key' => $groupKey,
            'before' => $before,
            'requested' => $normalized,
            'would_create' => array_values(array_map(static function (array $field): string { return $field['key']; }, $wouldCreate)),
            '_current_fingerprint' => Fingerprint::make($state),
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }

        $created = array();
        foreach ($wouldCreate as $definition) {
            $field = array(
                'key' => $definition['key'],
                'label' => $definition['label'],
                'name' => $definition['name'],
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array('width' => '', 'class' => '', 'id' => ''),
                'default_value' => '',
                'maxlength' => '',
                'placeholder' => '',
                'prepend' => '',
                'append' => '',
                'parent' => $groupKey,
                'menu_order' => 0,
            );
            $saved = acf_update_field($field);
            if (! is_array($saved)) {
                throw new RuntimeException('ACF field creation failed for: ' . $definition['key']);
            }
            $readback = acf_get_field($definition['key']);
            if (! is_array($readback) || ! $this->fieldMatchesDefinition($this->fieldSummary($readback), $definition, $groupKey)) {
                throw new RuntimeException('ACF field creation readback failed for: ' . $definition['key']);
            }
            $created[] = $definition;
        }

        $after = array();
        foreach ($normalized as $definition) {
            $readback = acf_get_field($definition['key']);
            $after[$definition['key']] = is_array($readback) ? $this->fieldSummary($readback) : null;
        }
        $result['after'] = $after;
        $result['created'] = array_values(array_map(static function (array $field): string { return $field['key']; }, $created));
        if ($created) {
            $result['_rollback'] = array(
                'action' => 'acf.schema.remove_text_fields',
                'payload' => array('post_id' => $postId, 'group_key' => $groupKey, 'fields' => $created),
            );
        }
        return $result;
    }

    public function removeTextFields(array $payload, array $context): array
    {
        $this->assertAcfSchema();
        $postId = isset($payload['post_id']) ? (int) $payload['post_id'] : 0;
        $groupKey = isset($payload['group_key']) ? (string) $payload['group_key'] : '';
        $requested = isset($payload['fields']) && is_array($payload['fields']) ? array_values($payload['fields']) : array();
        if ($postId <= 0 || ! preg_match('/^group_[A-Za-z0-9_-]{6,80}\z/', $groupKey) || ! $requested || count($requested) > 12) {
            throw new RuntimeException('acf.schema.remove_text_fields requires a valid post_id, group_key and 1-12 field definitions.');
        }
        $this->assertGroupAppliesToPost($groupKey, $postId);
        $normalized = $this->normalizeRequestedTextFields($requested);

        $before = array();
        foreach ($normalized as $definition) {
            $existing = acf_get_field($definition['key']);
            if (! is_array($existing)) {
                $before[$definition['key']] = null;
                continue;
            }
            $summary = $this->fieldSummary($existing);
            if (! $this->fieldMatchesDefinition($summary, $definition, $groupKey)) {
                throw new RuntimeException('Refusing to remove an ACF field that no longer matches the rollback definition: ' . $definition['key']);
            }
            $before[$definition['key']] = $summary;
        }
        $state = array('post_id' => $postId, 'group_key' => $groupKey, 'fields' => $before);
        $result = array(
            'post_id' => $postId,
            'group_key' => $groupKey,
            'before' => $before,
            '_current_fingerprint' => Fingerprint::make($state),
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }

        $removed = array();
        foreach ($normalized as $definition) {
            $existing = acf_get_field($definition['key']);
            if (! is_array($existing)) {
                continue;
            }
            $id = isset($existing['ID']) ? (int) $existing['ID'] : 0;
            if ($id <= 0 || false === acf_delete_field($id)) {
                throw new RuntimeException('ACF field removal failed for: ' . $definition['key']);
            }
            $removed[] = $definition['key'];
        }
        $result['removed'] = $removed;
        return $result;
    }

    private function assertAcf(): void
    {
        if (! function_exists('get_fields') || ! function_exists('update_field')) {
            throw new RuntimeException('ACF is not active.');
        }
    }

    private function assertAcfSchema(): void
    {
        $this->assertAcf();
        foreach (array('acf_get_field_groups', 'acf_get_fields', 'acf_get_field_group', 'acf_get_field', 'acf_update_field', 'acf_delete_field') as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException('ACF schema API is unavailable: ' . $function);
            }
        }
    }

    private function assertGroupAppliesToPost(string $groupKey, int $postId): void
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('ACF schema target post was not found.');
        }
        if (Policy::publicRepositoryContext()) {
            Policy::assertPostReadable($post);
        }
        $group = acf_get_field_group($groupKey);
        if (! is_array($group) || empty($group['active'])) {
            throw new RuntimeException('ACF field group is missing or inactive: ' . $groupKey);
        }
        $applies = false;
        foreach ((array) acf_get_field_groups(array('post_id' => $postId)) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['key'] ?? '') === $groupKey) {
                $applies = true;
                break;
            }
        }
        if (! $applies) {
            throw new RuntimeException('ACF field group does not apply to the target post.');
        }
    }

    private function groupFields(string $groupKey): array
    {
        $group = acf_get_field_group($groupKey);
        if (! is_array($group)) {
            throw new RuntimeException('ACF field group was not found: ' . $groupKey);
        }
        $fields = acf_get_fields($group);
        return is_array($fields) ? $fields : array();
    }

    private function normalizeRequestedTextFields(array $requested): array
    {
        $normalized = array();
        $keys = array();
        $names = array();
        foreach ($requested as $index => $field) {
            if (! is_array($field)) {
                throw new RuntimeException('ACF schema field at index ' . $index . ' must be an object.');
            }
            foreach (array_keys($field) as $key) {
                if (! in_array((string) $key, array('key', 'name', 'label'), true)) {
                    throw new RuntimeException('Unknown ACF schema field property: ' . (string) $key);
                }
            }
            $key = isset($field['key']) ? (string) $field['key'] : '';
            $name = isset($field['name']) ? (string) $field['name'] : '';
            $label = isset($field['label']) ? trim((string) $field['label']) : '';
            if (! preg_match('/^field_[A-Za-z0-9_-]{6,80}\z/', $key)) {
                throw new RuntimeException('Invalid ACF field key: ' . $key);
            }
            if (! preg_match('/^[a-z][a-z0-9_]{2,63}\z/', $name)) {
                throw new RuntimeException('Invalid ACF field name: ' . $name);
            }
            if ('' === $label || strlen($label) > 80 || preg_match('/[\x00-\x1F\x7F]/', $label)) {
                throw new RuntimeException('Invalid ACF field label for: ' . $key);
            }
            if (isset($keys[$key]) || isset($names[$name])) {
                throw new RuntimeException('Duplicate ACF field key or name in schema request.');
            }
            Policy::assertKeyAllowed($key);
            Policy::assertKeyAllowed($name);
            $keys[$key] = true;
            $names[$name] = true;
            $normalized[] = array('key' => $key, 'name' => $name, 'label' => $label, 'type' => 'text');
        }
        return $normalized;
    }

    private function fieldSummary(array $field): array
    {
        return array(
            'key' => isset($field['key']) ? (string) $field['key'] : '',
            'name' => isset($field['name']) ? (string) $field['name'] : '',
            'label' => isset($field['label']) ? (string) $field['label'] : '',
            'type' => isset($field['type']) ? (string) $field['type'] : '',
            'parent' => isset($field['parent']) ? (string) $field['parent'] : '',
            'required' => ! empty($field['required']),
        );
    }

    private function fieldMatchesDefinition(array $summary, array $definition, string $groupKey): bool
    {
        return (string) ($summary['key'] ?? '') === (string) $definition['key']
            && (string) ($summary['name'] ?? '') === (string) $definition['name']
            && (string) ($summary['label'] ?? '') === (string) $definition['label']
            && 'text' === (string) ($summary['type'] ?? '')
            && $groupKey === (string) ($summary['parent'] ?? '')
            && empty($summary['required']);
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
