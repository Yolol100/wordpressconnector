<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\SnapshotStore;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class PublicContentAdapter
{
    private Registry $registry;
    private SnapshotStore $snapshots;

    public function __construct(SnapshotStore $snapshots)
    {
        $this->snapshots = $snapshots;
    }

    public function register(Registry $registry): void
    {
        $this->registry = $registry;
        $registry->register('post.public_update', array($this, 'postUpdate'), array(
            'mutation' => true,
            'description' => 'Update public post title/content/excerpt through the guarded public runtime with rollback wrapping.',
        ));
        $registry->register('acf.post_update_text_fields', array($this, 'acfPostUpdateTextFields'), array(
            'mutation' => true,
            'description' => 'Update bounded ACF text fields on one public post with exact readback and rollback.',
        ));
        $registry->register('acf.post_restore_text_fields', array($this, 'acfPostRestoreTextFields'), array(
            'mutation' => true,
            'description' => 'Internal rollback action for public ACF text-field updates.',
        ));
        $registry->register('elementor.public_patch_element', array($this, 'elementorPatchElement'), array(
            'mutation' => true,
            'description' => 'Patch one Elementor element through the guarded public runtime with rollback wrapping.',
        ));
        $registry->register('connector.public_restore', array($this, 'publicRestore'), array(
            'mutation' => true,
            'description' => 'Internal restore action for guarded public mutations.',
        ));
        $registry->register('connector.rollback_public', array($this, 'rollbackPublic'), array(
            'mutation' => true,
            'description' => 'Rollback a guarded public mutation by request_id after dry-run fingerprint confirmation.',
        ));
    }

    public function postUpdate(array $payload, array $context): array
    {
        $this->assertPublicRepositoryMode();
        $this->assertOnlyKeys($payload, array('id', 'title', 'content', 'excerpt'), 'post.public_update payload');
        $postId = isset($payload['id']) ? (int) $payload['id'] : 0;
        $this->publicEditablePost($postId);
        foreach (array('title', 'content', 'excerpt') as $field) {
            if (array_key_exists($field, $payload) && ! is_string($payload[$field])) {
                throw new RuntimeException('post.public_update ' . $field . ' must be a string.');
            }
        }
        $result = $this->executeInner('post.update', $payload, $context);
        return $this->wrapRollback('post.public_update', $result);
    }

    public function acfPostUpdateTextFields(array $payload, array $context): array
    {
        $this->assertPublicRepositoryMode();
        $this->assertAcf();
        $this->assertOnlyKeys($payload, array('post_id', 'fields'), 'acf.post_update_text_fields payload');
        $postId = isset($payload['post_id']) ? (int) $payload['post_id'] : 0;
        $this->publicEditablePost($postId);
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (! $fields || count($fields) > 16 || array_keys($fields) === range(0, count($fields) - 1)) {
            throw new RuntimeException('acf.post_update_text_fields requires a 1-16 field object.');
        }

        $definitions = $this->acfTextDefinitionsForPost($postId, array_keys($fields));
        $before = array();
        $rollbackFields = array();
        foreach ($fields as $fieldKey => $value) {
            $fieldKey = (string) $fieldKey;
            if (! is_string($value) || strlen($value) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new RuntimeException('ACF public text values must be strings <= 1000 bytes without control characters.');
            }
            $definition = $definitions[$fieldKey];
            $name = (string) $definition['name'];
            $exists = function_exists('metadata_exists') ? metadata_exists('post', $postId, $name) : true;
            $before[$fieldKey] = get_field($fieldKey, $postId, false);
            $rollbackFields[] = array('key' => $fieldKey, 'exists' => (bool) $exists, 'value' => $before[$fieldKey]);
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
                $fieldKey = (string) $fieldKey;
                if (false === update_field($fieldKey, $value, $postId) && get_field($fieldKey, $postId, false) !== $value) {
                    throw new RuntimeException('ACF public text update failed for field: ' . $fieldKey);
                }
            }
            $result['after'] = array();
            foreach ($fields as $fieldKey => $value) {
                $fieldKey = (string) $fieldKey;
                $actual = get_field($fieldKey, $postId, false);
                if ($actual !== $value) {
                    throw new RuntimeException('ACF public text readback failed for field: ' . $fieldKey);
                }
                $result['after'][$fieldKey] = $actual;
            }
        } catch (Throwable $error) {
            $this->restoreAcfFields($postId, $rollbackFields);
            throw $error;
        }

        $result['_rollback'] = array(
            'action' => 'connector.public_restore',
            'payload' => array(
                'source_action' => 'acf.post_update_text_fields',
                'restore_action' => 'acf.post_restore_text_fields',
                'restore_payload' => array('post_id' => $postId, 'fields' => $rollbackFields),
            ),
        );
        return $result;
    }

    public function acfPostRestoreTextFields(array $payload, array $context): array
    {
        $this->assertPublicRepositoryMode();
        if (empty($context['rollback_mode'])) {
            throw new RuntimeException('acf.post_restore_text_fields is rollback-only.');
        }
        $this->assertAcf();
        $this->assertOnlyKeys($payload, array('post_id', 'fields'), 'acf.post_restore_text_fields payload');
        $postId = isset($payload['post_id']) ? (int) $payload['post_id'] : 0;
        $this->publicEditablePost($postId);
        $entries = isset($payload['fields']) && is_array($payload['fields']) ? array_values($payload['fields']) : array();
        if (! $entries || count($entries) > 16) {
            throw new RuntimeException('acf.post_restore_text_fields requires 1-16 rollback entries.');
        }
        $keys = array();
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['key']) || ! array_key_exists('exists', $entry) || ! array_key_exists('value', $entry)) {
                throw new RuntimeException('Invalid ACF public rollback entry.');
            }
            $this->assertOnlyKeys($entry, array('key', 'exists', 'value'), 'ACF public rollback entry');
            $keys[] = (string) $entry['key'];
        }
        $this->acfTextDefinitionsForPost($postId, $keys);

        $current = array();
        $desired = array();
        foreach ($entries as $entry) {
            $key = (string) $entry['key'];
            $current[$key] = get_field($key, $postId, false);
            $desired[$key] = ! empty($entry['exists']) ? $entry['value'] : null;
        }
        $result = array(
            'post_id' => $postId,
            'before' => $current,
            'after' => $desired,
            '_current_fingerprint' => Fingerprint::make($current),
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }

        $this->restoreAcfFields($postId, $entries);
        $result['after'] = array();
        foreach ($entries as $entry) {
            $key = (string) $entry['key'];
            if (empty($entry['exists'])) {
                $definition = acf_get_field($key);
                $name = is_array($definition) ? (string) ($definition['name'] ?? '') : '';
                $exists = $name !== '' && function_exists('metadata_exists') ? metadata_exists('post', $postId, $name) : false;
                if ($exists) {
                    throw new RuntimeException('ACF public rollback readback failed for field: ' . $key);
                }
                $result['after'][$key] = null;
                continue;
            }
            $actual = get_field($key, $postId, false);
            if ($actual !== $entry['value']) {
                throw new RuntimeException('ACF public rollback readback failed for field: ' . $key);
            }
            $result['after'][$key] = $actual;
        }
        return $result;
    }

    public function elementorPatchElement(array $payload, array $context): array
    {
        $this->assertPublicRepositoryMode();
        $this->assertOnlyKeys($payload, array('id', 'element_id', 'settings'), 'elementor.public_patch_element payload');
        $postId = isset($payload['id']) ? (int) $payload['id'] : 0;
        $this->publicEditablePost($postId);
        $elementId = isset($payload['element_id']) ? (string) $payload['element_id'] : '';
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $elementId)) {
            throw new RuntimeException('elementor.public_patch_element requires a valid element_id.');
        }
        $settings = isset($payload['settings']) && is_array($payload['settings']) ? $payload['settings'] : array();
        if (! $settings || array_keys($settings) === range(0, count($settings) - 1)) {
            throw new RuntimeException('elementor.public_patch_element requires a non-empty settings object.');
        }
        $this->assertSafeSettingTree($settings);
        $result = $this->executeInner('elementor.patch_element', $payload, $context);
        return $this->wrapRollback('elementor.public_patch_element', $result);
    }

    public function publicRestore(array $payload, array $context): array
    {
        $this->assertPublicRepositoryMode();
        if (empty($context['rollback_mode'])) {
            throw new RuntimeException('connector.public_restore is rollback-only.');
        }
        $restore = $this->validatedRestoreEnvelope($payload);
        $descriptor = $this->registry->descriptor($restore['restore_action']);
        Policy::assertActionAllowed($descriptor, ! empty($context['dry_run']), true);
        $result = $this->registry->execute($restore['restore_action'], $restore['restore_payload'], array_merge($context, array('confirm' => true, 'rollback_mode' => true)));
        unset($result['_rollback']);
        return $result;
    }

    public function rollbackPublic(array $payload, array $context): array
    {
        $this->assertPublicRepositoryMode();
        $this->assertOnlyKeys($payload, array('request_id'), 'connector.rollback_public payload');
        $requestId = isset($payload['request_id']) ? (string) $payload['request_id'] : '';
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $requestId)) {
            throw new RuntimeException('connector.rollback_public requires a valid payload.request_id.');
        }
        $rollback = $this->snapshots->get($requestId);
        if (! is_array($rollback) || empty($rollback['action']) || ! isset($rollback['payload']) || ! is_array($rollback['payload'])) {
            throw new RuntimeException('Public rollback snapshot was not found.');
        }

        if ('connector.public_restore' === (string) $rollback['action']) {
            $this->validatedRestoreEnvelope((array) $rollback['payload']);
            $preview = $this->registry->execute('connector.public_restore', (array) $rollback['payload'], array_merge($context, array('dry_run' => true, 'confirm' => true, 'rollback_mode' => true)));
            $fingerprint = isset($preview['_current_fingerprint']) ? (string) $preview['_current_fingerprint'] : '';
        } elseif ('connector.batch' === (string) $rollback['action']) {
            $fingerprint = $this->previewPublicBatchRollback((array) $rollback['payload'], $context);
        } else {
            throw new RuntimeException('Rollback snapshot is not eligible for the guarded public rollback route.');
        }
        if (! preg_match('/^[a-f0-9]{64}\z/', $fingerprint)) {
            throw new RuntimeException('Public rollback target cannot enforce stale-state protection.');
        }
        if (! empty($context['dry_run'])) {
            return array('would_restore_request_id' => $requestId, 'restore_action' => (string) $rollback['action'], '_current_fingerprint' => $fingerprint);
        }

        if ('connector.public_restore' === (string) $rollback['action']) {
            $result = $this->registry->execute('connector.public_restore', (array) $rollback['payload'], array_merge($context, array('dry_run' => false, 'confirm' => true, 'rollback_mode' => true)));
        } else {
            $result = $this->registry->execute('connector.batch', (array) $rollback['payload'], array_merge($context, array('dry_run' => false, 'confirm' => true, 'rollback_mode' => true)));
        }
        unset($result['_rollback'], $result['_current_fingerprint']);
        return array('restored_request_id' => $requestId, 'restored_with' => (string) $rollback['action'], 'result' => $result);
    }

    private function executeInner(string $action, array $payload, array $context): array
    {
        $descriptor = $this->registry->descriptor($action);
        Policy::assertActionAllowed($descriptor, ! empty($context['dry_run']), ! empty($context['confirm']));
        return $this->registry->execute($action, $payload, $context);
    }

    private function wrapRollback(string $sourceAction, array $result): array
    {
        if (! isset($result['_rollback']) || ! is_array($result['_rollback'])) {
            return $result;
        }
        $rollback = $result['_rollback'];
        if (empty($rollback['action']) || ! isset($rollback['payload']) || ! is_array($rollback['payload'])) {
            throw new RuntimeException('Inner mutation returned an invalid rollback contract.');
        }
        $result['_rollback'] = array(
            'action' => 'connector.public_restore',
            'payload' => array('source_action' => $sourceAction, 'restore_action' => (string) $rollback['action'], 'restore_payload' => $rollback['payload']),
        );
        return $result;
    }

    private function validatedRestoreEnvelope(array $payload): array
    {
        $this->assertOnlyKeys($payload, array('source_action', 'restore_action', 'restore_payload'), 'connector.public_restore payload');
        $source = isset($payload['source_action']) ? (string) $payload['source_action'] : '';
        $action = isset($payload['restore_action']) ? (string) $payload['restore_action'] : '';
        $restorePayload = isset($payload['restore_payload']) && is_array($payload['restore_payload']) ? $payload['restore_payload'] : array();
        $allowed = array(
            'post.public_update' => 'post.update',
            'acf.post_update_text_fields' => 'acf.post_restore_text_fields',
            'elementor.public_patch_element' => 'elementor.replace_document',
        );
        if (! isset($allowed[$source]) || $allowed[$source] !== $action || ! $restorePayload) {
            throw new RuntimeException('Invalid guarded public rollback envelope.');
        }
        return array('source_action' => $source, 'restore_action' => $action, 'restore_payload' => $restorePayload);
    }

    private function previewPublicBatchRollback(array $payload, array $context): string
    {
        $this->assertOnlyKeys($payload, array('operations'), 'public batch rollback payload');
        $operations = isset($payload['operations']) && is_array($payload['operations']) ? array_values($payload['operations']) : array();
        if (! $operations || count($operations) > 25) {
            throw new RuntimeException('Invalid public batch rollback snapshot.');
        }
        $fingerprints = array();
        foreach ($operations as $index => $operation) {
            if (! is_array($operation)) {
                throw new RuntimeException('Invalid public batch rollback operation.');
            }
            $this->assertOnlyKeys($operation, array('action', 'payload'), 'public batch rollback operation');
            if (($operation['action'] ?? '') !== 'connector.public_restore' || ! isset($operation['payload']) || ! is_array($operation['payload'])) {
                throw new RuntimeException('Public batch rollback contains a non-public restore operation.');
            }
            $this->validatedRestoreEnvelope($operation['payload']);
            $preview = $this->registry->execute('connector.public_restore', $operation['payload'], array_merge($context, array('dry_run' => true, 'confirm' => true, 'rollback_mode' => true)));
            $current = isset($preview['_current_fingerprint']) ? (string) $preview['_current_fingerprint'] : '';
            if (! preg_match('/^[a-f0-9]{64}\z/', $current)) {
                throw new RuntimeException('Public batch rollback operation cannot enforce stale-state protection at index ' . $index . '.');
            }
            $fingerprints[] = $current;
        }
        return Fingerprint::make($fingerprints);
    }

    private function publicEditablePost(int $postId): \WP_Post
    {
        if ($postId <= 0) {
            throw new RuntimeException('A positive post id is required.');
        }
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('Public mutation target post was not found.');
        }
        Policy::assertPostReadable($post);
        if (! function_exists('current_user_can') || ! current_user_can('edit_post', $postId)) {
            throw new RuntimeException('Current user lacks permission to edit the target post.');
        }
        return $post;
    }

    private function acfTextDefinitionsForPost(int $postId, array $keys): array
    {
        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields') || ! function_exists('acf_get_field')) {
            throw new RuntimeException('ACF schema API is unavailable for public text-field validation.');
        }
        $allowedParents = array();
        foreach ((array) acf_get_field_groups(array('post_id' => $postId)) as $group) {
            if (! is_array($group)) continue;
            if (! empty($group['key'])) $allowedParents[(string) $group['key']] = true;
            if (! empty($group['ID'])) $allowedParents[(string) $group['ID']] = true;
        }
        $definitions = array();
        foreach ($keys as $key) {
            $key = (string) $key;
            if (! preg_match('/^field_[A-Za-z0-9_-]{6,80}\z/', $key)) {
                throw new RuntimeException('Public ACF text updates require field keys, not field names.');
            }
            Policy::assertKeyAllowed($key);
            $field = acf_get_field($key);
            if (! is_array($field) || 'text' !== (string) ($field['type'] ?? '')) {
                throw new RuntimeException('Public ACF field is missing or is not a text field: ' . $key);
            }
            $name = (string) ($field['name'] ?? '');
            $parent = (string) ($field['parent'] ?? '');
            if ($name === '' || ! isset($allowedParents[$parent])) {
                throw new RuntimeException('Public ACF text field does not belong to a field group for the target post: ' . $key);
            }
            Policy::assertKeyAllowed($name);
            $definitions[$key] = $field;
        }
        return $definitions;
    }

    private function restoreAcfFields(int $postId, array $entries): void
    {
        foreach ($entries as $entry) {
            $key = (string) $entry['key'];
            if (! empty($entry['exists'])) update_field($key, $entry['value'], $postId);
            else delete_field($key, $postId);
        }
    }

    private function assertAcf(): void
    {
        if (! function_exists('get_field') || ! function_exists('update_field') || ! function_exists('delete_field')) {
            throw new RuntimeException('ACF is not active.');
        }
    }

    private function assertPublicRepositoryMode(): void
    {
        if (! Policy::publicRepositoryContext()) {
            throw new RuntimeException('This action is only available in guarded public-repository mode.');
        }
    }

    private function assertSafeSettingTree($value, int $depth = 0): void
    {
        if ($depth > 8) throw new RuntimeException('Elementor public settings exceed the maximum nesting depth.');
        if (is_array($value)) {
            if (count($value) > 200) throw new RuntimeException('Elementor public settings contain too many entries.');
            foreach ($value as $key => $item) {
                Policy::assertKeyAllowed((string) $key);
                $this->assertSafeSettingTree($item, $depth + 1);
            }
            return;
        }
        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && null !== $value) {
            throw new RuntimeException('Elementor public settings contain an unsupported value type.');
        }
    }

    private function assertOnlyKeys(array $value, array $allowed, string $context): void
    {
        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                throw new RuntimeException($context . ' contains unsupported key: ' . (string) $key);
            }
        }
    }
}
