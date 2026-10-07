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
        $registry->register('acf.schema.ensure_fields', array($this, 'ensureFields'), array(
            'mutation' => true,
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Create bounded complex ACF fields in an existing field group without altering existing fields.',
        ));
        $registry->register('acf.schema.remove_fields', array($this, 'removeFields'), array(
            'mutation' => true,
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Remove exact complex ACF fields created by a prior bounded schema ensure operation.',
        ));
        $registry->register('acf.schema.create_field_group', array($this, 'createFieldGroup'), array(
            'mutation' => true,
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Create a new bounded ACF field group with nested fields and location rules.',
        ));
        $registry->register('acf.schema.delete_field_group', array($this, 'deleteFieldGroup'), array(
            'mutation' => true,
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Delete an exact connector-created ACF field group only when its schema still matches.',
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
        $groupId = $this->groupId($groupKey);

        $normalized = $this->normalizeRequestedTextFields($requested);
        $existingFields = $this->groupFields($groupKey);
        $existingByKey = array();
        $existingByName = array();
        $nextMenuOrder = 0;
        foreach ($existingFields as $field) {
            if (! empty($field['key'])) {
                $existingByKey[(string) $field['key']] = $field;
            }
            if (! empty($field['name'])) {
                $existingByName[(string) $field['name']] = $field;
            }
            $nextMenuOrder = max($nextMenuOrder, ((int) ($field['menu_order'] ?? -1)) + 1);
        }

        $before = array();
        $wouldCreate = array();
        $recoverable = array();
        foreach ($normalized as $definition) {
            $key = $definition['key'];
            $name = $definition['name'];
            $byKey = $existingByKey[$key] ?? null;
            $byName = $existingByName[$name] ?? null;
            if (is_array($byKey)) {
                $summary = $this->fieldSummary($byKey);
                if (! $this->fieldMatchesDefinition($summary, $definition, $groupId)) {
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

            $global = acf_get_field($key);
            if (is_array($global)) {
                $summary = $this->fieldSummary($global);
                $globalId = isset($global['ID']) ? (int) $global['ID'] : 0;
                if ($globalId <= 0
                    || 0 !== (int) ($summary['parent'] ?? 0)
                    || (string) ($summary['name'] ?? '') !== $name
                    || 'text' !== (string) ($summary['type'] ?? '')
                    || ! empty($summary['required'])) {
                    throw new RuntimeException('Existing global ACF field conflicts with requested text field: ' . $key);
                }
                $before[$key] = $summary;
                $recoverable[$key] = $globalId;
                $wouldCreate[] = $definition;
                continue;
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
                'parent' => $groupId,
                'menu_order' => $nextMenuOrder++,
            );
            if (isset($recoverable[$definition['key']])) {
                $field['ID'] = (int) $recoverable[$definition['key']];
            }
            $saved = acf_update_field($field);
            if (! is_array($saved)) {
                throw new RuntimeException('ACF field creation failed for: ' . $definition['key']);
            }
            $readback = acf_get_field($definition['key']);
            if (! is_array($readback) || ! $this->fieldMatchesDefinition($this->fieldSummary($readback), $definition, $groupId)) {
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
        $groupId = $this->groupId($groupKey);
        $normalized = $this->normalizeRequestedTextFields($requested);

        $before = array();
        foreach ($normalized as $definition) {
            $existing = acf_get_field($definition['key']);
            if (! is_array($existing)) {
                $before[$definition['key']] = null;
                continue;
            }
            $summary = $this->fieldSummary($existing);
            if (! $this->fieldMatchesDefinition($summary, $definition, $groupId)) {
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

    public function ensureFields(array $payload, array $context): array
    {
        $this->assertAcfComplexSchema();
        $postId = isset($payload['post_id']) ? (int) $payload['post_id'] : 0;
        $groupKey = isset($payload['group_key']) ? (string) $payload['group_key'] : '';
        $requested = isset($payload['fields']) && is_array($payload['fields']) ? array_values($payload['fields']) : array();
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, array('post_id', 'group_key', 'fields'), true)) {
                throw new RuntimeException('Unknown ACF complex schema payload property: ' . (string) $key);
            }
        }
        if ($postId <= 0 || ! preg_match('/^group_[A-Za-z0-9_-]{6,80}\z/', $groupKey)) {
            throw new RuntimeException('acf.schema.ensure_fields requires a valid post_id and group_key.');
        }
        if (! $requested || count($requested) > 12) {
            throw new RuntimeException('acf.schema.ensure_fields requires 1-12 top-level field definitions.');
        }
        $this->assertGroupAppliesToPost($groupKey, $postId);
        $groupId = $this->groupId($groupKey);
        $total = 0;
        $keys = array();
        $normalized = $this->normalizeSchemaFields($requested, 0, $total, $keys);
        $existingFields = $this->groupFields($groupKey);
        $existingByKey = array();
        $existingByName = array();
        $nextMenuOrder = 0;
        foreach ($existingFields as $field) {
            if (! empty($field['key'])) {
                $existingByKey[(string) $field['key']] = $field;
            }
            if (! empty($field['name'])) {
                $existingByName[(string) $field['name']] = $field;
            }
            $nextMenuOrder = max($nextMenuOrder, ((int) ($field['menu_order'] ?? -1)) + 1);
        }

        $before = array();
        $wouldCreate = array();
        foreach ($normalized as $definition) {
            $key = $definition['key'];
            $name = $definition['name'];
            $byKey = $existingByKey[$key] ?? null;
            $byName = $existingByName[$name] ?? null;
            if (is_array($byKey)) {
                if ((int) ($byKey['parent'] ?? 0) !== $groupId) {
                    throw new RuntimeException('Existing ACF field key belongs to a different parent: ' . $key);
                }
                $tree = $this->hydrateFieldTree($byKey);
                if (! $this->schemaContains($definition, $tree)) {
                    throw new RuntimeException('Existing ACF field key conflicts with requested complex field: ' . $key);
                }
                if (is_array($byName) && (string) ($byName['key'] ?? '') !== $key) {
                    throw new RuntimeException('Existing ACF field name belongs to a different field: ' . $name);
                }
                $before[$key] = $tree;
                continue;
            }
            if (is_array($byName)) {
                throw new RuntimeException('Existing ACF field name conflicts with requested complex field: ' . $name);
            }
            $this->assertFieldTreeKeysAvailable($definition);
            $before[$key] = null;
            $wouldCreate[] = $definition;
        }

        $state = array('post_id' => $postId, 'group_key' => $groupKey, 'fields' => $before);
        $result = array(
            'post_id' => $postId,
            'group_key' => $groupKey,
            'requested' => $normalized,
            'before' => $before,
            'would_create' => array_values(array_map(static function (array $field): string { return $field['key']; }, $wouldCreate)),
            '_current_fingerprint' => Fingerprint::make($state),
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }

        $created = array();
        try {
            foreach ($wouldCreate as $definition) {
                $this->createFieldTree($definition, $groupId, $nextMenuOrder++);
                $created[] = $definition;
            }
            $after = array();
            foreach ($normalized as $definition) {
                $field = acf_get_field($definition['key']);
                if (! is_array($field)) {
                    throw new RuntimeException('ACF complex field readback failed for: ' . $definition['key']);
                }
                $tree = $this->hydrateFieldTree($field);
                if (! $this->schemaContains($definition, $tree)) {
                    throw new RuntimeException('ACF complex field readback did not match requested schema: ' . $definition['key']);
                }
                $after[$definition['key']] = $tree;
            }
        } catch (\Throwable $error) {
            foreach (array_reverse($created) as $definition) {
                $this->deleteFieldTreeByKey((string) $definition['key']);
            }
            throw $error;
        }

        $result['after'] = $after;
        $result['created'] = array_values(array_map(static function (array $field): string { return $field['key']; }, $created));
        if ($created) {
            $result['_rollback'] = array(
                'action' => 'acf.schema.remove_fields',
                'payload' => array(
                    'post_id' => $postId,
                    'group_key' => $groupKey,
                    'fields' => $created,
                ),
            );
        }
        return $result;
    }

    public function removeFields(array $payload, array $context): array
    {
        $this->assertAcfComplexSchema();
        $postId = isset($payload['post_id']) ? (int) $payload['post_id'] : 0;
        $groupKey = isset($payload['group_key']) ? (string) $payload['group_key'] : '';
        $requested = isset($payload['fields']) && is_array($payload['fields']) ? array_values($payload['fields']) : array();
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, array('post_id', 'group_key', 'fields'), true)) {
                throw new RuntimeException('Unknown ACF complex schema rollback property: ' . (string) $key);
            }
        }
        if ($postId <= 0 || ! preg_match('/^group_[A-Za-z0-9_-]{6,80}\z/', $groupKey) || ! $requested || count($requested) > 12) {
            throw new RuntimeException('acf.schema.remove_fields requires a valid post_id, group_key and 1-12 field definitions.');
        }
        $this->assertGroupAppliesToPost($groupKey, $postId);
        $groupId = $this->groupId($groupKey);
        $total = 0;
        $keys = array();
        $normalized = $this->normalizeSchemaFields($requested, 0, $total, $keys);

        $before = array();
        foreach ($normalized as $definition) {
            $field = acf_get_field($definition['key']);
            if (! is_array($field)) {
                $before[$definition['key']] = null;
                continue;
            }
            if ((int) ($field['parent'] ?? 0) !== $groupId) {
                throw new RuntimeException('Refusing to remove an ACF field that moved to another parent: ' . $definition['key']);
            }
            $tree = $this->hydrateFieldTree($field);
            if (! $this->schemaContains($definition, $tree)) {
                throw new RuntimeException('Refusing to remove an ACF field that no longer matches the rollback schema: ' . $definition['key']);
            }
            $before[$definition['key']] = $tree;
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
            if (! is_array(acf_get_field($definition['key']))) {
                continue;
            }
            $this->deleteFieldTreeByKey($definition['key']);
            if (is_array(acf_get_field($definition['key']))) {
                throw new RuntimeException('ACF complex field removal readback failed for: ' . $definition['key']);
            }
            $removed[] = $definition['key'];
        }
        $result['removed'] = $removed;
        if ($removed) {
            $result['_rollback'] = array(
                'action' => 'acf.schema.ensure_fields',
                'payload' => array(
                    'post_id' => $postId,
                    'group_key' => $groupKey,
                    'fields' => $normalized,
                ),
            );
        }
        return $result;
    }

    public function createFieldGroup(array $payload, array $context): array
    {
        $this->assertAcfComplexSchema();
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, array('group', 'fields'), true)) {
                throw new RuntimeException('Unknown ACF field-group schema payload property: ' . (string) $key);
            }
        }
        $groupInput = isset($payload['group']) && is_array($payload['group']) ? $payload['group'] : array();
        $fieldsInput = isset($payload['fields']) && is_array($payload['fields']) ? array_values($payload['fields']) : array();
        $group = $this->normalizeFieldGroup($groupInput);
        if (! $fieldsInput || count($fieldsInput) > 20) {
            throw new RuntimeException('acf.schema.create_field_group requires 1-20 top-level fields.');
        }
        $total = 0;
        $keys = array();
        $fields = $this->normalizeSchemaFields($fieldsInput, 0, $total, $keys);
        $groupKey = $group['key'];
        if (is_array(acf_get_field_group($groupKey))) {
            throw new RuntimeException('Refusing to overwrite an existing ACF field group: ' . $groupKey);
        }
        foreach ($fields as $definition) {
            $this->assertFieldTreeKeysAvailable($definition);
        }

        $state = array('group_key' => $groupKey, 'current' => null);
        $schema = array('group' => $group, 'fields' => $fields);
        $result = array(
            'group_key' => $groupKey,
            'requested_schema' => $schema,
            'would_create' => true,
            '_current_fingerprint' => Fingerprint::make($state),
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }

        $import = $group;
        $import['fields'] = $fields;
        try {
            $saved = acf_import_field_group($import);
            if (! is_array($saved) || (int) ($saved['ID'] ?? 0) <= 0) {
                throw new RuntimeException('ACF field group creation failed: ' . $groupKey);
            }
            $after = $this->fieldGroupSnapshot($groupKey);
            if (! is_array($after) || ! $this->schemaContains($schema, $after)) {
                throw new RuntimeException('ACF field group creation readback did not match the requested schema: ' . $groupKey);
            }
        } catch (\Throwable $error) {
            if (is_array(acf_get_field_group($groupKey))) {
                acf_delete_field_group($groupKey);
            }
            throw $error;
        }

        $result['after'] = $after;
        $result['created'] = true;
        $result['_rollback'] = array(
            'action' => 'acf.schema.delete_field_group',
            'payload' => array(
                'group_key' => $groupKey,
                'expected_schema' => $schema,
            ),
        );
        return $result;
    }

    public function deleteFieldGroup(array $payload, array $context): array
    {
        $this->assertAcfComplexSchema();
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, array('group_key', 'expected_schema'), true)) {
                throw new RuntimeException('Unknown ACF field-group deletion payload property: ' . (string) $key);
            }
        }
        $groupKey = isset($payload['group_key']) ? (string) $payload['group_key'] : '';
        $expected = isset($payload['expected_schema']) && is_array($payload['expected_schema']) ? $payload['expected_schema'] : array();
        if (! preg_match('/^group_[A-Za-z0-9_-]{6,80}\z/', $groupKey)
            || ! isset($expected['group'], $expected['fields'])
            || ! is_array($expected['group'])
            || ! is_array($expected['fields'])) {
            throw new RuntimeException('acf.schema.delete_field_group requires group_key and expected_schema.');
        }
        $group = $this->normalizeFieldGroup($expected['group']);
        if ($group['key'] !== $groupKey) {
            throw new RuntimeException('ACF field-group deletion schema key does not match group_key.');
        }
        $total = 0;
        $keys = array();
        $fields = $this->normalizeSchemaFields(array_values($expected['fields']), 0, $total, $keys);
        $normalizedExpected = array('group' => $group, 'fields' => $fields);
        $current = $this->fieldGroupSnapshot($groupKey);
        if (is_array($current) && ! $this->schemaContains($normalizedExpected, $current)) {
            throw new RuntimeException('Refusing to delete an ACF field group that no longer matches the expected schema: ' . $groupKey);
        }

        $result = array(
            'group_key' => $groupKey,
            'before' => $current,
            '_current_fingerprint' => Fingerprint::make(array('group_key' => $groupKey, 'current' => $current)),
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }
        if (! is_array($current)) {
            $result['deleted'] = false;
            return $result;
        }
        if (! acf_delete_field_group($groupKey) || is_array(acf_get_field_group($groupKey))) {
            throw new RuntimeException('ACF field group deletion readback failed: ' . $groupKey);
        }
        $result['deleted'] = true;
        $result['_rollback'] = array(
            'action' => 'acf.schema.create_field_group',
            'payload' => $normalizedExpected,
        );
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

    private function groupId(string $groupKey): int
    {
        $group = acf_get_field_group($groupKey);
        $groupId = is_array($group) && isset($group['ID']) ? (int) $group['ID'] : 0;
        if ($groupId <= 0) {
            throw new RuntimeException('ACF field group has no persistent database ID: ' . $groupKey);
        }
        return $groupId;
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

    private function fieldMatchesDefinition(array $summary, array $definition, int $groupId): bool
    {
        return (string) ($summary['key'] ?? '') === (string) $definition['key']
            && (string) ($summary['name'] ?? '') === (string) $definition['name']
            && (string) ($summary['label'] ?? '') === (string) $definition['label']
            && 'text' === (string) ($summary['type'] ?? '')
            && (string) $groupId === (string) ($summary['parent'] ?? '')
            && empty($summary['required']);
    }

    private function assertAcfComplexSchema(): void
    {
        $this->assertAcfSchema();
        foreach (array(
            'acf_get_field_type',
            'acf_import_field_group',
            'acf_delete_field_group',
            'acf_get_location_rule_types',
            'acf_get_location_rule_operators',
        ) as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException('ACF complex schema API is unavailable: ' . $function);
            }
        }
    }

    private function normalizeFieldGroup(array $group): array
    {
        $allowed = array(
            'key', 'title', 'location', 'description', 'position', 'style',
            'label_placement', 'instruction_placement', 'hide_on_screen',
            'active', 'show_in_rest',
        );
        foreach (array_keys($group) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                throw new RuntimeException('Unknown ACF field-group property: ' . (string) $key);
            }
        }
        $key = isset($group['key']) ? (string) $group['key'] : '';
        $title = isset($group['title']) ? trim((string) $group['title']) : '';
        if (! preg_match('/^group_[A-Za-z0-9_-]{6,80}\z/', $key)) {
            throw new RuntimeException('Invalid ACF field-group key: ' . $key);
        }
        Policy::assertKeyAllowed($key);
        if ('' === $title || strlen($title) > 120 || $this->hasInvalidControl($title, false)) {
            throw new RuntimeException('Invalid ACF field-group title.');
        }
        $location = isset($group['location']) && is_array($group['location'])
            ? $this->normalizeLocationRules($group['location'])
            : array();
        if (! $location) {
            throw new RuntimeException('ACF field-group location requires at least one rule group.');
        }

        $normalized = array(
            'key' => $key,
            'title' => $title,
            'location' => $location,
            'position' => isset($group['position']) ? (string) $group['position'] : 'normal',
            'style' => isset($group['style']) ? (string) $group['style'] : 'default',
            'label_placement' => isset($group['label_placement']) ? (string) $group['label_placement'] : 'top',
            'instruction_placement' => isset($group['instruction_placement']) ? (string) $group['instruction_placement'] : 'label',
            'active' => array_key_exists('active', $group) ? $this->requireBoolean($group['active'], 'group.active') : true,
            'show_in_rest' => array_key_exists('show_in_rest', $group) ? $this->requireBoolean($group['show_in_rest'], 'group.show_in_rest') : false,
        );
        if (! in_array($normalized['position'], array('normal', 'side', 'acf_after_title'), true)) {
            throw new RuntimeException('Invalid ACF field-group position.');
        }
        if (! in_array($normalized['style'], array('default', 'seamless'), true)) {
            throw new RuntimeException('Invalid ACF field-group style.');
        }
        if (! in_array($normalized['label_placement'], array('top', 'left'), true)) {
            throw new RuntimeException('Invalid ACF field-group label placement.');
        }
        if (! in_array($normalized['instruction_placement'], array('label', 'field'), true)) {
            throw new RuntimeException('Invalid ACF field-group instruction placement.');
        }
        if (isset($group['description'])) {
            $description = (string) $group['description'];
            if (strlen($description) > 1000 || $this->hasInvalidControl($description, true)) {
                throw new RuntimeException('Invalid ACF field-group description.');
            }
            $normalized['description'] = $description;
        }
        if (isset($group['hide_on_screen'])) {
            if (! is_array($group['hide_on_screen']) || count($group['hide_on_screen']) > 20) {
                throw new RuntimeException('Invalid ACF field-group hide_on_screen list.');
            }
            $allowedHidden = array(
                'permalink', 'the_content', 'excerpt', 'custom_fields', 'discussion',
                'comments', 'revisions', 'slug', 'author', 'format', 'page_attributes',
                'featured_image', 'categories', 'tags', 'send-trackbacks',
            );
            $hidden = array_values(array_unique(array_map('strval', $group['hide_on_screen'])));
            foreach ($hidden as $item) {
                if (! in_array($item, $allowedHidden, true)) {
                    throw new RuntimeException('Invalid ACF hide_on_screen item: ' . $item);
                }
            }
            $normalized['hide_on_screen'] = $hidden;
        }
        return $normalized;
    }

    private function normalizeLocationRules(array $location): array
    {
        if (! $location || count($location) > 8) {
            throw new RuntimeException('ACF field-group location requires 1-8 OR groups.');
        }
        $types = array();
        foreach ((array) acf_get_location_rule_types() as $category) {
            if (! is_array($category)) {
                continue;
            }
            foreach ($category as $param => $label) {
                $types[(string) $param] = true;
            }
        }
        $normalized = array();
        foreach (array_values($location) as $groupIndex => $rules) {
            if (! is_array($rules) || ! $rules || count($rules) > 8) {
                throw new RuntimeException('ACF location group at index ' . $groupIndex . ' requires 1-8 rules.');
            }
            $normalizedRules = array();
            foreach (array_values($rules) as $ruleIndex => $rule) {
                if (! is_array($rule)) {
                    throw new RuntimeException('ACF location rule must be an object.');
                }
                foreach (array_keys($rule) as $key) {
                    if (! in_array((string) $key, array('param', 'operator', 'value'), true)) {
                        throw new RuntimeException('Unknown ACF location rule property: ' . (string) $key);
                    }
                }
                $param = isset($rule['param']) ? (string) $rule['param'] : '';
                $operator = isset($rule['operator']) ? (string) $rule['operator'] : '';
                $value = isset($rule['value']) ? (string) $rule['value'] : '';
                if (! isset($types[$param])) {
                    throw new RuntimeException('Unsupported ACF location parameter: ' . $param);
                }
                $operators = (array) acf_get_location_rule_operators(array('param' => $param));
                if (! isset($operators[$operator])) {
                    throw new RuntimeException('Unsupported ACF location operator for ' . $param . ': ' . $operator);
                }
                if ('' === $value || strlen($value) > 200 || $this->hasInvalidControl($value, false)) {
                    throw new RuntimeException('Invalid ACF location value at group ' . $groupIndex . ', rule ' . $ruleIndex . '.');
                }
                Policy::assertKeyAllowed($param);
                $normalizedRules[] = array('param' => $param, 'operator' => $operator, 'value' => $value);
            }
            $normalized[] = $normalizedRules;
        }
        return $normalized;
    }

    private function normalizeSchemaFields(array $requested, int $depth, int &$total, array &$keys): array
    {
        if ($depth > 3) {
            throw new RuntimeException('ACF nested field depth exceeds the maximum of 3.');
        }
        if (! $requested || count($requested) > 20) {
            throw new RuntimeException('Each ACF field level requires 1-20 definitions.');
        }
        $normalized = array();
        $names = array();
        foreach (array_values($requested) as $index => $field) {
            if (! is_array($field)) {
                throw new RuntimeException('ACF schema field at index ' . $index . ' must be an object.');
            }
            $total++;
            if ($total > 50) {
                throw new RuntimeException('ACF schema request exceeds the maximum of 50 total fields.');
            }
            $definition = $this->normalizeSchemaField($field, $depth, $total, $keys);
            if (isset($names[$definition['name']])) {
                throw new RuntimeException('Duplicate ACF field name at the same schema level: ' . $definition['name']);
            }
            $names[$definition['name']] = true;
            $normalized[] = $definition;
        }
        return $normalized;
    }

    private function normalizeSchemaField(array $field, int $depth, int &$total, array &$keys): array
    {
        $type = isset($field['type']) ? (string) $field['type'] : '';
        $typeProperties = array(
            'text' => array('default_value', 'placeholder', 'prepend', 'append', 'maxlength'),
            'textarea' => array('default_value', 'placeholder', 'maxlength', 'rows', 'new_lines'),
            'number' => array('default_value', 'placeholder', 'prepend', 'append', 'min', 'max', 'step'),
            'email' => array('default_value', 'placeholder', 'prepend', 'append'),
            'url' => array('default_value', 'placeholder'),
            'image' => array('return_format', 'preview_size', 'library', 'min_width', 'min_height', 'min_size', 'max_width', 'max_height', 'max_size', 'mime_types'),
            'relationship' => array('post_type', 'taxonomy', 'min', 'max', 'filters', 'elements', 'return_format'),
            'group' => array('sub_fields', 'layout'),
            'repeater' => array('sub_fields', 'min', 'max', 'layout', 'button_label', 'collapsed', 'rows_per_page'),
        );
        if (! isset($typeProperties[$type])) {
            throw new RuntimeException('Unsupported ACF complex schema field type: ' . $type);
        }
        if (! acf_get_field_type($type)) {
            throw new RuntimeException('ACF field type is unavailable in the installed runtime: ' . $type);
        }

        $allowed = array_merge(
            array('key', 'name', 'label', 'type', 'instructions', 'required', 'conditional_logic', 'wrapper'),
            $typeProperties[$type]
        );
        foreach (array_keys($field) as $property) {
            if (! in_array((string) $property, $allowed, true)) {
                throw new RuntimeException('Unknown ACF ' . $type . ' field property: ' . (string) $property);
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
        if ('' === $label || strlen($label) > 120 || $this->hasInvalidControl($label, false)) {
            throw new RuntimeException('Invalid ACF field label for: ' . $key);
        }
        Policy::assertKeyAllowed($key);
        Policy::assertKeyAllowed($name);
        if (isset($keys[$key])) {
            throw new RuntimeException('Duplicate ACF field key in schema request: ' . $key);
        }
        $keys[$key] = true;

        $normalized = array(
            'key' => $key,
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'instructions' => isset($field['instructions']) ? $this->boundedText($field['instructions'], 2000, true, 'instructions') : '',
            'required' => array_key_exists('required', $field) ? $this->requireBoolean($field['required'], $key . '.required') : false,
            'conditional_logic' => array_key_exists('conditional_logic', $field)
                ? $this->normalizeConditionalLogic($field['conditional_logic'])
                : false,
            'wrapper' => isset($field['wrapper']) ? $this->normalizeWrapper($field['wrapper']) : array('width' => '', 'class' => '', 'id' => ''),
        );

        if (in_array($type, array('text', 'textarea', 'email', 'url', 'number'), true)) {
            foreach (array('default_value', 'placeholder', 'prepend', 'append') as $property) {
                if (! array_key_exists($property, $field)) {
                    continue;
                }
                if ('number' === $type && 'default_value' === $property && is_numeric($field[$property])) {
                    $normalized[$property] = $field[$property] + 0;
                } else {
                    $normalized[$property] = $this->boundedText($field[$property], 2000, true, $key . '.' . $property);
                }
            }
        }
        if (array_key_exists('maxlength', $field)) {
            $normalized['maxlength'] = $this->boundedInteger($field['maxlength'], 1, 100000, $key . '.maxlength');
        }
        if ('textarea' === $type) {
            if (array_key_exists('rows', $field)) {
                $normalized['rows'] = $this->boundedInteger($field['rows'], 1, 100, $key . '.rows');
            }
            if (array_key_exists('new_lines', $field)) {
                $newLines = (string) $field['new_lines'];
                if (! in_array($newLines, array('', 'wpautop', 'br'), true)) {
                    throw new RuntimeException('Invalid textarea new_lines setting for: ' . $key);
                }
                $normalized['new_lines'] = $newLines;
            }
        }
        if ('number' === $type) {
            foreach (array('min', 'max', 'step') as $property) {
                if (array_key_exists($property, $field)) {
                    $normalized[$property] = $this->boundedNumber($field[$property], -1000000000, 1000000000, $key . '.' . $property);
                }
            }
        }
        if ('image' === $type) {
            if (array_key_exists('return_format', $field)) {
                $normalized['return_format'] = $this->enumValue($field['return_format'], array('array', 'url', 'id'), $key . '.return_format');
            }
            if (array_key_exists('preview_size', $field)) {
                $preview = (string) $field['preview_size'];
                if (! preg_match('/^[A-Za-z0-9_-]{1,80}\z/', $preview)) {
                    throw new RuntimeException('Invalid image preview_size for: ' . $key);
                }
                $normalized['preview_size'] = $preview;
            }
            if (array_key_exists('library', $field)) {
                $normalized['library'] = $this->enumValue($field['library'], array('all', 'uploadedTo'), $key . '.library');
            }
            foreach (array('min_width', 'min_height', 'max_width', 'max_height') as $property) {
                if (array_key_exists($property, $field)) {
                    $normalized[$property] = $this->boundedInteger($field[$property], 0, 100000, $key . '.' . $property);
                }
            }
            foreach (array('min_size', 'max_size') as $property) {
                if (array_key_exists($property, $field)) {
                    $normalized[$property] = $this->boundedNumber($field[$property], 0, 10000, $key . '.' . $property);
                }
            }
            if (array_key_exists('mime_types', $field)) {
                $mime = $this->boundedText($field['mime_types'], 250, false, $key . '.mime_types');
                if ('' !== $mime && ! preg_match('/^[A-Za-z0-9.+_\/,\s-]+\z/', $mime)) {
                    throw new RuntimeException('Invalid image mime_types for: ' . $key);
                }
                $normalized['mime_types'] = $mime;
            }
        }
        if ('relationship' === $type) {
            if (array_key_exists('post_type', $field)) {
                $normalized['post_type'] = $this->normalizeKeyList($field['post_type'], 30, $key . '.post_type');
            }
            if (array_key_exists('taxonomy', $field)) {
                $normalized['taxonomy'] = $this->normalizeBoundedStringList($field['taxonomy'], 50, 120, $key . '.taxonomy');
            }
            foreach (array('min', 'max') as $property) {
                if (array_key_exists($property, $field)) {
                    $normalized[$property] = $this->boundedInteger($field[$property], 0, 1000, $key . '.' . $property);
                }
            }
            if (array_key_exists('filters', $field)) {
                if (! is_array($field['filters']) || count($field['filters']) > 3) {
                    throw new RuntimeException('Invalid relationship filters for: ' . $key);
                }
                $filters = array_values(array_unique(array_map('strval', $field['filters'])));
                foreach ($filters as $filter) {
                    if (! in_array($filter, array('search', 'post_type', 'taxonomy'), true)) {
                        throw new RuntimeException('Invalid relationship filter for: ' . $key);
                    }
                }
                $normalized['filters'] = $filters;
            }
            if (array_key_exists('elements', $field)) {
                $normalized['elements'] = $this->normalizeKeyList($field['elements'], 10, $key . '.elements');
            }
            if (array_key_exists('return_format', $field)) {
                $normalized['return_format'] = $this->enumValue($field['return_format'], array('object', 'id'), $key . '.return_format');
            }
        }
        if (in_array($type, array('group', 'repeater'), true)) {
            $subFields = isset($field['sub_fields']) && is_array($field['sub_fields']) ? array_values($field['sub_fields']) : array();
            if (! $subFields) {
                throw new RuntimeException('ACF ' . $type . ' field requires sub_fields: ' . $key);
            }
            $normalized['sub_fields'] = $this->normalizeSchemaFields($subFields, $depth + 1, $total, $keys);
            $layouts = 'group' === $type ? array('block', 'table', 'row') : array('table', 'block', 'row');
            if (array_key_exists('layout', $field)) {
                $normalized['layout'] = $this->enumValue($field['layout'], $layouts, $key . '.layout');
            }
        }
        if ('repeater' === $type) {
            foreach (array('min', 'max') as $property) {
                if (array_key_exists($property, $field)) {
                    $normalized[$property] = $this->boundedInteger($field[$property], 0, 1000, $key . '.' . $property);
                }
            }
            if (array_key_exists('button_label', $field)) {
                $normalized['button_label'] = $this->boundedText($field['button_label'], 80, false, $key . '.button_label');
            }
            if (array_key_exists('rows_per_page', $field)) {
                $normalized['rows_per_page'] = $this->boundedInteger($field['rows_per_page'], 1, 200, $key . '.rows_per_page');
            }
            if (array_key_exists('collapsed', $field)) {
                $collapsed = (string) $field['collapsed'];
                if ('' !== $collapsed && ! preg_match('/^field_[A-Za-z0-9_-]{6,80}\z/', $collapsed)) {
                    throw new RuntimeException('Invalid repeater collapsed field key for: ' . $key);
                }
                if ('' !== $collapsed) {
                    $directKeys = array_map(static function (array $subField): string { return (string) $subField['key']; }, $normalized['sub_fields']);
                    if (! in_array($collapsed, $directKeys, true)) {
                        throw new RuntimeException('Repeater collapsed field must reference a direct sub field: ' . $key);
                    }
                }
                $normalized['collapsed'] = $collapsed;
            }
        }
        return $normalized;
    }

    private function normalizeWrapper($wrapper): array
    {
        if (! is_array($wrapper)) {
            throw new RuntimeException('ACF field wrapper must be an object.');
        }
        foreach (array_keys($wrapper) as $key) {
            if (! in_array((string) $key, array('width', 'class', 'id'), true)) {
                throw new RuntimeException('Unknown ACF wrapper property: ' . (string) $key);
            }
        }
        $width = isset($wrapper['width']) ? (string) $wrapper['width'] : '';
        $class = isset($wrapper['class']) ? (string) $wrapper['class'] : '';
        $id = isset($wrapper['id']) ? (string) $wrapper['id'] : '';
        if ('' !== $width && (! ctype_digit($width) || (int) $width < 1 || (int) $width > 100)) {
            throw new RuntimeException('ACF wrapper width must be between 1 and 100.');
        }
        if (strlen($class) > 200 || $this->hasInvalidControl($class, false)) {
            throw new RuntimeException('Invalid ACF wrapper class.');
        }
        if ('' !== $id && ! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}\z/', $id)) {
            throw new RuntimeException('Invalid ACF wrapper id.');
        }
        return array('width' => $width, 'class' => $class, 'id' => $id);
    }

    private function normalizeConditionalLogic($logic)
    {
        if (false === $logic || 0 === $logic || '0' === $logic || null === $logic) {
            return false;
        }
        if (! is_array($logic) || ! $logic || count($logic) > 8) {
            throw new RuntimeException('ACF conditional_logic must be false or 1-8 rule groups.');
        }
        $normalized = array();
        foreach (array_values($logic) as $rules) {
            if (! is_array($rules) || ! $rules || count($rules) > 8) {
                throw new RuntimeException('Each ACF conditional_logic group requires 1-8 rules.');
            }
            $group = array();
            foreach (array_values($rules) as $rule) {
                if (! is_array($rule)) {
                    throw new RuntimeException('ACF conditional_logic rule must be an object.');
                }
                foreach (array_keys($rule) as $key) {
                    if (! in_array((string) $key, array('field', 'operator', 'value'), true)) {
                        throw new RuntimeException('Unknown ACF conditional_logic property: ' . (string) $key);
                    }
                }
                $field = isset($rule['field']) ? (string) $rule['field'] : '';
                $operator = isset($rule['operator']) ? (string) $rule['operator'] : '';
                $value = isset($rule['value']) ? (string) $rule['value'] : '';
                if (! preg_match('/^field_[A-Za-z0-9_-]{6,80}\z/', $field) || ! in_array($operator, array('==', '!='), true)) {
                    throw new RuntimeException('Invalid ACF conditional_logic rule.');
                }
                if (strlen($value) > 500 || $this->hasInvalidControl($value, true)) {
                    throw new RuntimeException('Invalid ACF conditional_logic value.');
                }
                $group[] = array('field' => $field, 'operator' => $operator, 'value' => $value);
            }
            $normalized[] = $group;
        }
        return $normalized;
    }

    private function createFieldTree(array $definition, int $parentId, int $menuOrder): void
    {
        $children = isset($definition['sub_fields']) && is_array($definition['sub_fields']) ? $definition['sub_fields'] : array();
        $field = $definition;
        unset($field['sub_fields']);
        $field['parent'] = $parentId;
        $field['menu_order'] = $menuOrder;
        $saved = acf_update_field($field);
        if (! is_array($saved)) {
            throw new RuntimeException('ACF complex field creation failed for: ' . $definition['key']);
        }
        $savedId = isset($saved['ID']) ? (int) $saved['ID'] : 0;
        if ($savedId <= 0) {
            $readback = acf_get_field($definition['key']);
            $savedId = is_array($readback) && isset($readback['ID']) ? (int) $readback['ID'] : 0;
        }
        if ($savedId <= 0) {
            throw new RuntimeException('ACF complex field creation returned no persistent ID: ' . $definition['key']);
        }
        foreach (array_values($children) as $index => $child) {
            $this->createFieldTree($child, $savedId, $index);
        }
    }

    private function deleteFieldTreeByKey(string $key): void
    {
        $field = acf_get_field($key);
        if (! is_array($field)) {
            return;
        }
        $children = acf_get_fields($field);
        if (is_array($children)) {
            foreach (array_reverse($children) as $child) {
                if (is_array($child) && ! empty($child['key'])) {
                    $this->deleteFieldTreeByKey((string) $child['key']);
                }
            }
        }
        $id = isset($field['ID']) ? (int) $field['ID'] : 0;
        if ($id <= 0 || false === acf_delete_field($id)) {
            throw new RuntimeException('ACF complex field deletion failed for: ' . $key);
        }
    }

    private function hydrateFieldTree(array $field): array
    {
        $tree = $field;
        $children = acf_get_fields($field);
        if (is_array($children) && $children) {
            $tree['sub_fields'] = array_values(array_map(function (array $child): array {
                return $this->hydrateFieldTree($child);
            }, $children));
        } else {
            unset($tree['sub_fields']);
        }
        return $tree;
    }

    private function fieldGroupSnapshot(string $groupKey): ?array
    {
        $group = acf_get_field_group($groupKey);
        if (! is_array($group)) {
            return null;
        }
        $fields = acf_get_fields($group);
        $trees = array();
        foreach (is_array($fields) ? $fields : array() as $field) {
            if (is_array($field)) {
                $trees[] = $this->hydrateFieldTree($field);
            }
        }
        return array('group' => $group, 'fields' => $trees);
    }

    private function assertFieldTreeKeysAvailable(array $definition): void
    {
        $key = (string) $definition['key'];
        if (is_array(acf_get_field($key))) {
            throw new RuntimeException('Existing global ACF field conflicts with requested schema field: ' . $key);
        }
        foreach (isset($definition['sub_fields']) && is_array($definition['sub_fields']) ? $definition['sub_fields'] : array() as $child) {
            $this->assertFieldTreeKeysAvailable($child);
        }
    }

    private function schemaContains($expected, $actual): bool
    {
        if (is_array($expected)) {
            if (! is_array($actual)) {
                return false;
            }
            $isList = array_values($expected) === $expected;
            if ($isList) {
                if (count($expected) !== count($actual)) {
                    return false;
                }
                $actualValues = array_values($actual);
                foreach ($expected as $index => $value) {
                    if (! $this->schemaContains($value, $actualValues[$index])) {
                        return false;
                    }
                }
                return true;
            }
            foreach ($expected as $key => $value) {
                if (! array_key_exists($key, $actual) || ! $this->schemaContains($value, $actual[$key])) {
                    return false;
                }
            }
            return true;
        }
        if (is_bool($expected)) {
            return (bool) $actual === $expected;
        }
        if (is_int($expected)) {
            return is_numeric($actual) && (int) $actual === $expected;
        }
        if (is_float($expected)) {
            return is_numeric($actual) && abs((float) $actual - $expected) < 0.0000001;
        }
        return $expected === $actual;
    }

    private function boundedText($value, int $maxLength, bool $allowNewlines, string $context): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new RuntimeException('ACF ' . $context . ' must be text.');
        }
        $text = (string) $value;
        if (strlen($text) > $maxLength || $this->hasInvalidControl($text, $allowNewlines)) {
            throw new RuntimeException('Invalid ACF text value for ' . $context . '.');
        }
        return $text;
    }

    private function hasInvalidControl(string $value, bool $allowNewlines): bool
    {
        $pattern = $allowNewlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        return (bool) preg_match($pattern, $value);
    }

    private function requireBoolean($value, string $context): bool
    {
        if (! is_bool($value)) {
            throw new RuntimeException('ACF ' . $context . ' must be boolean.');
        }
        return $value;
    }

    private function boundedInteger($value, int $min, int $max, string $context): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new RuntimeException('ACF ' . $context . ' must be an integer between ' . $min . ' and ' . $max . '.');
        }
        return $value;
    }

    private function boundedNumber($value, float $min, float $max, string $context)
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new RuntimeException('ACF ' . $context . ' must be numeric.');
        }
        $number = (float) $value;
        if ($number < $min || $number > $max) {
            throw new RuntimeException('ACF ' . $context . ' is outside the supported range.');
        }
        return $value;
    }

    private function enumValue($value, array $allowed, string $context): string
    {
        $value = (string) $value;
        if (! in_array($value, $allowed, true)) {
            throw new RuntimeException('Invalid ACF enum value for ' . $context . '.');
        }
        return $value;
    }

    private function normalizeKeyList($value, int $maxItems, string $context): array
    {
        if (! is_array($value) || count($value) > $maxItems) {
            throw new RuntimeException('Invalid ACF list for ' . $context . '.');
        }
        $result = array();
        foreach (array_values($value) as $item) {
            $item = (string) $item;
            if (! preg_match('/^[A-Za-z0-9_-]{1,80}\z/', $item)) {
                throw new RuntimeException('Invalid ACF key in ' . $context . '.');
            }
            Policy::assertKeyAllowed($item);
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    private function normalizeBoundedStringList($value, int $maxItems, int $maxLength, string $context): array
    {
        if (! is_array($value) || count($value) > $maxItems) {
            throw new RuntimeException('Invalid ACF list for ' . $context . '.');
        }
        $result = array();
        foreach (array_values($value) as $item) {
            if (! is_string($item) || '' === $item || strlen($item) > $maxLength || $this->hasInvalidControl($item, false)) {
                throw new RuntimeException('Invalid ACF string in ' . $context . '.');
            }
            Policy::assertKeyAllowed($item);
            $result[] = $item;
        }
        return array_values(array_unique($result));
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
