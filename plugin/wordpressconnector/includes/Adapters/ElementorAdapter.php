<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Json;

final class ElementorAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('elementor.inspect', array($this, 'inspect'), array('description' => 'Read Elementor document JSON and document metadata.'));
        $registry->register('elementor.create_document', array($this, 'createDocument'), array('mutation' => true, 'description' => 'Create a page, post, product or Elementor library document through the Elementor document API.'));
        $registry->register('elementor.replace_document', array($this, 'replaceDocument'), array('mutation' => true, 'description' => 'Replace Elementor elements/settings through the Elementor document API.'));
        $registry->register('elementor.patch_element', array($this, 'patchElement'), array('mutation' => true, 'description' => 'Patch an Elementor element by stable element id and save through the Elementor document API.'));
        $registry->register('elementor.regenerate', array($this, 'regenerate'), array('mutation' => true, 'description' => 'Clear Elementor generated CSS/files cache.'));
    }

    public function inspect(array $payload): array
    {
        $post = $this->post($payload);
        Policy::assertPostReadable($post);
        $snapshot = $this->snapshot((int) $post->ID);
        return array('document' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function createDocument(array $payload, array $context): array
    {
        $postType = isset($payload['post_type']) ? sanitize_key((string) $payload['post_type']) : 'page';
        if (! post_type_exists($postType)) {
            throw new RuntimeException('Unknown post type: ' . $postType);
        }
        Policy::assertReadablePostType($postType);

        $data = $this->normalizeData($payload['data'] ?? array());
        $pageSettings = isset($payload['page_settings']) && is_array($payload['page_settings']) ? $payload['page_settings'] : array();
        $postFields = array(
            'post_type' => $postType,
            'post_status' => isset($payload['status']) ? sanitize_key((string) $payload['status']) : 'draft',
            'post_title' => isset($payload['title']) ? (string) $payload['title'] : 'Elementor document',
            'post_name' => isset($payload['slug']) ? sanitize_title((string) $payload['slug']) : '',
            'post_content' => isset($payload['content']) ? (string) $payload['content'] : '',
        );

        if (! empty($context['dry_run'])) {
            return array(
                'would_create' => array('post' => $postFields, 'elementor' => $this->requestedMeta($payload, $data)),
                '_current_fingerprint' => Fingerprint::make(array('new' => true, 'post_type' => $postType)),
            );
        }

        $id = wp_insert_post(wp_slash($postFields), true);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }

        try {
            $this->prepareDocumentMeta((int) $id, $payload);
            $this->saveDocumentViaApi((int) $id, $data, $pageSettings);
            $this->writeSupplementalMeta((int) $id, $payload);
            $this->clearCache();
            $after = $this->snapshot((int) $id);
            $this->assertDocumentReadback($after, $data, $pageSettings);
        } catch (Throwable $error) {
            wp_delete_post((int) $id, true);
            throw $error;
        }

        return array(
            'document' => $after,
            '_rollback' => array('action' => 'post.trash', 'payload' => array('id' => (int) $id)),
        );
    }

    public function replaceDocument(array $payload, array $context): array
    {
        $post = $this->post($payload);
        $before = $this->snapshot((int) $post->ID);
        $data = array_key_exists('data', $payload) ? $this->normalizeData($payload['data']) : $before['data'];
        $pageSettings = array_key_exists('page_settings', $payload)
            ? (is_array($payload['page_settings']) ? $payload['page_settings'] : array())
            : $before['page_settings'];

        $after = $before;
        $after['data'] = $data;
        $after['page_settings'] = $pageSettings;
        foreach (array('template_type', 'conditions', 'edit_mode') as $field) {
            if (array_key_exists($field, $payload)) {
                $after[$field] = $payload[$field];
            }
        }

        $result = array(
            'before' => $before,
            'after' => $after,
            '_current_fingerprint' => Fingerprint::make($before),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        $result['after'] = $this->applyExistingDocumentMutation(
            (int) $post->ID,
            $payload,
            $data,
            $pageSettings,
            $before
        );
        $result['_rollback'] = array('action' => 'elementor.replace_document', 'payload' => $this->rollbackPayload($before));
        return $result;
    }

    public function patchElement(array $payload, array $context): array
    {
        $post = $this->post($payload);
        $before = $this->snapshot((int) $post->ID);
        $elementId = isset($payload['element_id']) ? (string) $payload['element_id'] : '';
        if ('' === $elementId) {
            throw new RuntimeException('element_id is required.');
        }

        $data = $before['data'];
        $element =& $this->findElement($data, $elementId);
        $beforeElement = $element;

        if (isset($payload['replace']) && is_array($payload['replace'])) {
            $replacement = $payload['replace'];
            if (! isset($replacement['id'])) {
                $replacement['id'] = $elementId;
            }
            $element = $replacement;
        } else {
            if (isset($payload['settings']) && is_array($payload['settings'])) {
                $replaceSettings = ! empty($payload['replace_settings']);
                $element['settings'] = $replaceSettings
                    ? $payload['settings']
                    : array_replace_recursive(isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : array(), $payload['settings']);
            }
            if (array_key_exists('widgetType', $payload)) {
                $element['widgetType'] = (string) $payload['widgetType'];
            }
            if (array_key_exists('elType', $payload)) {
                $element['elType'] = (string) $payload['elType'];
            }
        }

        $requestedElement = $element;
        $result = array(
            'post_id' => (int) $post->ID,
            'element_id' => $elementId,
            'before_element' => $beforeElement,
            'after_element' => $requestedElement,
            '_current_fingerprint' => Fingerprint::make($before),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        $readback = $this->applyExistingDocumentMutation(
            (int) $post->ID,
            array(),
            $data,
            $before['page_settings'],
            $before
        );
        $readbackData = $readback['data'];
        $readbackElement =& $this->findElement($readbackData, $elementId);
        if (! hash_equals(Fingerprint::make($requestedElement), Fingerprint::make($readbackElement))) {
            $this->restoreSnapshot($before);
            throw new RuntimeException('Elementor element readback verification failed; previous snapshot restored.');
        }

        $result['after_element'] = $readbackElement;
        $result['_rollback'] = array('action' => 'elementor.replace_document', 'payload' => $this->rollbackPayload($before));
        return $result;
    }

    public function regenerate(array $payload, array $context): array
    {
        if (! class_exists('Elementor\\Plugin')) {
            throw new RuntimeException('Elementor is not active.');
        }
        if (! empty($context['dry_run'])) {
            return array('would_clear_elementor_cache' => true, '_current_fingerprint' => Fingerprint::make(array('elementor' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'active')));
        }
        $this->clearCache();
        return array('cleared' => true, 'rollback_supported' => false);
    }

    private function post(array $payload): \WP_Post
    {
        $post = get_post(isset($payload['id']) ? (int) $payload['id'] : 0);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('Post not found.');
        }
        Policy::assertReadablePostType((string) $post->post_type);
        return $post;
    }

    private function snapshot(int $postId): array
    {
        $document = $this->documentOrNull($postId);
        if ($document) {
            $data = method_exists($document, 'get_elements_data') ? $document->get_elements_data() : array();
            $settings = method_exists($document, 'get_db_document_settings') ? $document->get_db_document_settings() : array();
            $data = is_array($data) ? $data : array();
            $settings = is_array($settings) ? $settings : array();
            $documentType = method_exists($document, 'get_name') ? (string) $document->get_name() : '';
        } else {
            $raw = get_post_meta($postId, '_elementor_data', true);
            $data = $this->normalizeData($raw ?: array());
            $settings = $this->normalizeMetaArray(get_post_meta($postId, '_elementor_page_settings', true));
            $documentType = '';
        }

        return array(
            'post_id' => $postId,
            'data' => $data,
            'document_type' => $documentType,
            'edit_mode' => (string) get_post_meta($postId, '_elementor_edit_mode', true),
            'template_type' => (string) get_post_meta($postId, '_elementor_template_type', true),
            'page_settings' => $settings,
            'conditions' => $this->normalizeMetaArray(get_post_meta($postId, '_elementor_conditions', true)),
            'elementor_version' => (string) get_post_meta($postId, '_elementor_version', true),
        );
    }

    private function requestedMeta(array $payload, array $data): array
    {
        return array(
            'data' => $data,
            'document_type' => isset($payload['document_type']) ? (string) $payload['document_type'] : '',
            'edit_mode' => isset($payload['edit_mode']) ? (string) $payload['edit_mode'] : 'builder',
            'template_type' => isset($payload['template_type']) ? (string) $payload['template_type'] : '',
            'page_settings' => isset($payload['page_settings']) && is_array($payload['page_settings']) ? $payload['page_settings'] : array(),
            'conditions' => isset($payload['conditions']) && is_array($payload['conditions']) ? $payload['conditions'] : array(),
        );
    }

    private function prepareDocumentMeta(int $postId, array $payload): void
    {
        if (array_key_exists('template_type', $payload)) {
            update_post_meta($postId, '_elementor_template_type', (string) $payload['template_type']);
        }
        update_post_meta($postId, '_elementor_edit_mode', isset($payload['edit_mode']) ? (string) $payload['edit_mode'] : 'builder');
    }

    private function writeSupplementalMeta(int $postId, array $payload): void
    {
        if (array_key_exists('template_type', $payload)) {
            update_post_meta($postId, '_elementor_template_type', (string) $payload['template_type']);
        }
        if (array_key_exists('conditions', $payload)) {
            update_post_meta($postId, '_elementor_conditions', is_array($payload['conditions']) ? $payload['conditions'] : array());
        }
        if (array_key_exists('edit_mode', $payload)) {
            update_post_meta($postId, '_elementor_edit_mode', (string) $payload['edit_mode']);
        }
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($postId, '_elementor_version', ELEMENTOR_VERSION);
        }
    }

    private function saveDocumentViaApi(int $postId, array $data, array $pageSettings): void
    {
        if (function_exists('is_user_logged_in') && is_user_logged_in() && ! current_user_can('edit_post', $postId)) {
            throw new RuntimeException('You are not allowed to edit this Elementor document.');
        }

        $document = $this->document($postId);
        $result = $document->save(array(
            'elements' => $data,
            'settings' => $pageSettings,
        ));

        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
        if (false === $result || null === $result) {
            throw new RuntimeException('Elementor rejected the document save.');
        }

        clean_post_cache($postId);
    }

    private function applyExistingDocumentMutation(int $postId, array $payload, array $data, array $pageSettings, array $before): array
    {
        try {
            $this->saveDocumentViaApi($postId, $data, $pageSettings);
            $this->writeSupplementalMeta($postId, $payload);
            $this->clearCache();
            $after = $this->snapshot($postId);
            $this->assertDocumentReadback($after, $data, $pageSettings);
            return $after;
        } catch (Throwable $error) {
            try {
                $this->restoreSnapshot($before);
            } catch (Throwable $rollbackError) {
                throw new RuntimeException(
                    'Elementor mutation failed and automatic rollback also failed: ' . $error->getMessage() . ' | rollback: ' . $rollbackError->getMessage(),
                    0,
                    $error
                );
            }
            throw new RuntimeException('Elementor mutation failed; previous snapshot restored: ' . $error->getMessage(), 0, $error);
        }
    }

    private function restoreSnapshot(array $snapshot): void
    {
        $postId = isset($snapshot['post_id']) ? (int) $snapshot['post_id'] : 0;
        if ($postId < 1) {
            throw new RuntimeException('Rollback snapshot is missing post_id.');
        }

        $payload = $this->rollbackPayload($snapshot);
        $this->prepareDocumentMeta($postId, $payload);
        $this->saveDocumentViaApi($postId, $snapshot['data'], $snapshot['page_settings']);
        $this->writeSupplementalMeta($postId, $payload);
        $this->clearCache();

        $restored = $this->snapshot($postId);
        $this->assertDocumentReadback($restored, $snapshot['data'], $snapshot['page_settings']);
        foreach (array('edit_mode', 'template_type', 'conditions') as $field) {
            if (! hash_equals(Fingerprint::make($snapshot[$field]), Fingerprint::make($restored[$field]))) {
                throw new RuntimeException('Elementor rollback readback failed for: ' . $field);
            }
        }
    }

    private function document(int $postId): object
    {
        if (! class_exists('Elementor\\Plugin')) {
            throw new RuntimeException('Elementor is not active.');
        }

        $plugin = \Elementor\Plugin::$instance;
        $manager = isset($plugin->documents) ? $plugin->documents : null;
        if (! is_object($manager) || ! method_exists($manager, 'get')) {
            throw new RuntimeException('Elementor document manager is unavailable.');
        }

        $document = $manager->get($postId);
        if (! is_object($document) || ! method_exists($document, 'save') || ! method_exists($document, 'get_elements_data')) {
            throw new RuntimeException('This item is not an editable Elementor document.');
        }

        return $document;
    }

    private function documentOrNull(int $postId): ?object
    {
        try {
            return $this->document($postId);
        } catch (Throwable $error) {
            return null;
        }
    }

    private function assertDocumentReadback(array $actual, array $expectedData, array $expectedSettings): void
    {
        if (! hash_equals(Fingerprint::make($expectedData), Fingerprint::make($actual['data']))) {
            throw new RuntimeException('Elementor document readback verification failed for elements.');
        }

        foreach ($expectedSettings as $key => $expectedValue) {
            if (! array_key_exists($key, $actual['page_settings'])) {
                throw new RuntimeException('Elementor document readback is missing page setting: ' . (string) $key);
            }
            if (! hash_equals(Fingerprint::make($expectedValue), Fingerprint::make($actual['page_settings'][$key]))) {
                throw new RuntimeException('Elementor page setting readback verification failed: ' . (string) $key);
            }
        }
    }

    private function normalizeData($data): array
    {
        if (is_string($data)) {
            $trimmed = trim($data);
            if ('' === $trimmed) {
                return array();
            }
            return Json::decode($trimmed);
        }
        if (! is_array($data)) {
            throw new RuntimeException('Elementor data must be an array or JSON string.');
        }
        return $data;
    }

    private function normalizeMetaArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && '' !== trim($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return array();
    }

    private function &findElement(array &$elements, string $elementId): array
    {
        foreach ($elements as &$element) {
            if (! is_array($element)) {
                continue;
            }
            if (isset($element['id']) && (string) $element['id'] === $elementId) {
                return $element;
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                try {
                    return $this->findElement($element['elements'], $elementId);
                } catch (RuntimeException $error) {
                    // Continue searching siblings.
                }
            }
        }
        unset($element);
        throw new RuntimeException('Elementor element not found: ' . $elementId);
    }

    private function rollbackPayload(array $snapshot): array
    {
        return array(
            'id' => $snapshot['post_id'],
            'data' => $snapshot['data'],
            'edit_mode' => $snapshot['edit_mode'],
            'template_type' => $snapshot['template_type'],
            'page_settings' => $snapshot['page_settings'],
            'conditions' => $snapshot['conditions'],
        );
    }

    private function clearCache(): void
    {
        if (! class_exists('Elementor\\Plugin')) {
            return;
        }

        $plugin = \Elementor\Plugin::$instance;
        if (isset($plugin->files_manager) && is_object($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
            $plugin->files_manager->clear_cache();
        }
    }
}
