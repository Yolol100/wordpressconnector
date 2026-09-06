<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class ElementorFormsAdapter
{
    private const ATOMIC_CONFIG_KEYS = array(
        'atomic_controls',
        'atomic_props_schema',
        'atomic_style_states',
        'atomic_pseudo_states',
        'dependencies_per_target_mapping',
        'base_styles',
        'version',
        'show_in_panel',
        'default_children',
        'initial_attributes',
        'default_html_tag',
        'meta',
        'allowed_child_types',
    );

    public function register(Registry $registry): void
    {
        $registry->register('elementor.form_capabilities', array($this, 'capabilities'), array(
            'description' => 'Inspect target-runtime Elementor V3 Form and V4 Atomic Form schemas without exposing form defaults or site secrets.',
        ));
        $registry->register('elementor.form_inspect', array($this, 'inspect'), array(
            'description' => 'Read complete V3 and V4 Elementor form subtrees from one document.',
        ));
        $registry->register('elementor.form_upsert', array($this, 'upsert'), array(
            'mutation' => true,
            'description' => 'Insert or fully replace one V3 Form widget or V4 Atomic Form subtree through the Elementor document API.',
        ));
    }

    public function capabilities(array $payload, array $context): array
    {
        $environment = $this->environment();
        if (! class_exists('Elementor\\Plugin')) {
            return array(
                'available' => false,
                'environment' => $environment,
                'classic' => array('available' => false, 'controls' => array(), 'submit_action_choice_keys' => array()),
                'atomic' => array('available' => false, 'types' => array()),
                'recommended_family' => null,
                'schema_fingerprint' => null,
                'warnings' => array('elementor_not_active'),
            );
        }

        $warnings = array();
        $elementor = \Elementor\Plugin::$instance;
        $classic = $this->classicCapabilities($elementor, $warnings);
        $atomic = $this->atomicCapabilities($elementor, $warnings);
        $available = ! empty($classic['available']) || ! empty($atomic['available']);
        $fingerprint = Fingerprint::make(array('classic' => $classic, 'atomic' => $atomic));

        return array(
            'available' => $available,
            'environment' => $environment,
            'classic' => $classic,
            'atomic' => $atomic,
            'recommended_family' => ! empty($atomic['available']) ? 'v4' : (! empty($classic['available']) ? 'v3' : null),
            'schema_fingerprint' => $fingerprint,
            'warnings' => array_values(array_unique($warnings)),
        );
    }

    public function inspect(array $payload, array $context = array()): array
    {
        $post = $this->post($payload);
        Policy::assertPostReadable($post);
        $snapshot = $this->snapshot((int) $post->ID);
        $forms = array();
        $this->collectForms($snapshot['data'], $forms, array(), null);

        return array(
            'post_id' => (int) $post->ID,
            'forms' => $forms,
            'form_count' => count($forms),
            'document_fingerprint' => Fingerprint::make($snapshot),
        );
    }

    public function upsert(array $payload, array $context): array
    {
        $post = $this->post($payload);
        $before = $this->snapshot((int) $post->ID);
        $data = $before['data'];

        if (! isset($payload['form']) || ! is_array($payload['form'])) {
            throw new RuntimeException('form is required and must contain one complete Elementor form JSON element.');
        }

        $form = $payload['form'];
        $family = $this->formFamily($form);
        if (null === $family) {
            throw new RuntimeException('form must be a V3 widgetType=form element or a V4 elType=e-form element.');
        }

        $requestedFamily = isset($payload['family']) ? strtolower((string) $payload['family']) : 'auto';
        if (! in_array($requestedFamily, array('auto', 'v3', 'v4'), true)) {
            throw new RuntimeException('family must be auto, v3 or v4.');
        }
        if ('auto' !== $requestedFamily && $requestedFamily !== $family) {
            throw new RuntimeException('Requested form family does not match the supplied form JSON.');
        }

        $capabilities = $this->capabilities(array(), array());
        $expectedSchemaFingerprint = isset($payload['expected_schema_fingerprint']) ? (string) $payload['expected_schema_fingerprint'] : '';
        if ('' !== $expectedSchemaFingerprint && ! hash_equals((string) $capabilities['schema_fingerprint'], $expectedSchemaFingerprint)) {
            throw new RuntimeException('Elementor form schema fingerprint changed; refresh form capabilities before writing.');
        }

        $this->validateForm($form, $family, $capabilities);
        $formId = (string) $form['id'];
        $existing = $this->findElementCopy($data, $formId);
        $existingFamily = is_array($existing) ? $this->formFamily($existing) : null;
        $operation = 'insert';
        $beforeForm = null;
        $parentElementId = isset($payload['parent_element_id']) ? (string) $payload['parent_element_id'] : '';
        $position = array_key_exists('position', $payload) ? (int) $payload['position'] : null;

        if (is_array($existing)) {
            if (null === $existingFamily) {
                throw new RuntimeException('The supplied form id already belongs to a non-form Elementor element.');
            }
            if ($existingFamily !== $family && empty($payload['allow_family_change'])) {
                throw new RuntimeException('Changing an existing form between V3 and V4 requires allow_family_change=true.');
            }
            if ('' !== $parentElementId || null !== $position) {
                throw new RuntimeException('parent_element_id and position are only valid when inserting a new form id.');
            }
            $beforeForm = $existing;
            if (! $this->replaceElementById($data, $formId, $form)) {
                throw new RuntimeException('Unable to replace the existing Elementor form.');
            }
            $operation = 'replace';
        } else {
            if ('' !== $parentElementId) {
                if (! $this->insertIntoParent($data, $parentElementId, $form, $position)) {
                    throw new RuntimeException('parent_element_id was not found in the Elementor document.');
                }
            } else {
                $this->insertAt($data, $form, $position);
            }
        }

        $this->assertUniqueElementIds($data);
        $result = array(
            'post_id' => (int) $post->ID,
            'operation' => $operation,
            'family' => $family,
            'form_id' => $formId,
            'parent_element_id' => '' !== $parentElementId ? $parentElementId : null,
            'position' => $position,
            'before_form' => $beforeForm,
            'after_form' => $form,
            'schema_fingerprint' => $capabilities['schema_fingerprint'],
            '_current_fingerprint' => Fingerprint::make($before),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        try {
            $this->saveDocument((int) $post->ID, $data, $before['page_settings']);
            $this->clearCache();
            $after = $this->snapshot((int) $post->ID);
            $readback = $this->findElementCopy($after['data'], $formId);
            if (! is_array($readback) || ! hash_equals(Fingerprint::make($form), Fingerprint::make($readback))) {
                throw new RuntimeException('Elementor form readback verification failed.');
            }
            $this->assertUniqueElementIds($after['data']);
        } catch (Throwable $error) {
            try {
                $this->restoreSnapshot($before);
            } catch (Throwable $rollbackError) {
                throw new RuntimeException(
                    'Elementor form mutation failed and automatic rollback also failed: ' . $error->getMessage() . ' | rollback: ' . $rollbackError->getMessage(),
                    0,
                    $error
                );
            }
            throw new RuntimeException('Elementor form mutation failed; previous document restored: ' . $error->getMessage(), 0, $error);
        }

        $result['after_form'] = $readback;
        $result['document_fingerprint'] = Fingerprint::make($after);
        $result['_rollback'] = array(
            'action' => 'elementor.replace_document',
            'payload' => array(
                'id' => (int) $post->ID,
                'data' => $before['data'],
                'page_settings' => $before['page_settings'],
            ),
        );
        return $result;
    }

    private function environment(): array
    {
        return array(
            'elementor' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            'elementor_pro' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
            'theme' => function_exists('wp_get_theme') ? (string) wp_get_theme()->get('Name') : null,
            'theme_version' => function_exists('wp_get_theme') ? (string) wp_get_theme()->get('Version') : null,
        );
    }

    private function classicCapabilities(object $elementor, array &$warnings): array
    {
        $widgets = $this->managerTypes($elementor, 'widgets_manager', 'get_widget_types', $warnings, 'classic_widgets');
        if (! isset($widgets['form']) || ! is_object($widgets['form'])) {
            return array(
                'available' => false,
                'widget_type' => 'form',
                'controls' => array(),
                'field_type_choice_keys' => array(),
                'submit_action_choice_keys' => array(),
            );
        }

        $controls = $this->collectControlSchema($widgets['form'], $warnings);
        $fieldTypes = array();
        if (isset($controls['form_fields']['fields']['field_type']['choice_keys'])) {
            $fieldTypes = $controls['form_fields']['fields']['field_type']['choice_keys'];
        }
        $submitActions = isset($controls['submit_actions']['choice_keys']) ? $controls['submit_actions']['choice_keys'] : array();

        return array(
            'available' => true,
            'widget_type' => 'form',
            'class' => get_class($widgets['form']),
            'controls' => $controls,
            'field_type_choice_keys' => $fieldTypes,
            'submit_action_choice_keys' => $submitActions,
        );
    }

    private function atomicCapabilities(object $elementor, array &$warnings): array
    {
        $components = $this->allRegisteredComponents($elementor, $warnings);
        $types = array();
        foreach ($components as $name => $component) {
            if ('e-form' !== $name && 0 !== strpos($name, 'e-form-')) {
                continue;
            }
            $types[$name] = array(
                'class' => get_class($component),
                'config' => $this->collectAtomicConfig($component, $warnings, $name),
            );
        }
        ksort($types, SORT_STRING);

        $formAvailable = isset($types['e-form']);
        $formConfig = $formAvailable ? $types['e-form']['config'] : array();
        $props = isset($formConfig['atomic_props_schema']) && is_array($formConfig['atomic_props_schema'])
            ? array_keys($formConfig['atomic_props_schema'])
            : array();
        sort($props, SORT_STRING);

        return array(
            'available' => $formAvailable,
            'root_type' => 'e-form',
            'types' => $types,
            'root_setting_keys' => $props,
            'required_direct_children' => array('e-form-success-message', 'e-form-error-message'),
        );
    }

    private function collectControlSchema(object $component, array &$warnings): array
    {
        if (! method_exists($component, 'get_controls')) {
            return array();
        }
        try {
            $raw = (array) $component->get_controls();
        } catch (Throwable $error) {
            $warnings[] = 'form_controls_inventory_failed';
            return array();
        }

        $result = array();
        foreach ($raw as $name => $control) {
            if (! is_array($control)) {
                continue;
            }
            $result[(string) $name] = $this->controlRecord((string) $name, $control);
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function controlRecord(string $name, array $control): array
    {
        $record = array(
            'name' => $name,
            'type' => isset($control['type']) && is_scalar($control['type']) ? (string) $control['type'] : null,
            'responsive' => ! empty($control['responsive']) || ! empty($control['is_responsive']),
            'dynamic_active' => ! empty($control['dynamic']['active']),
        );

        if (isset($control['responsive']['devices']) && is_array($control['responsive']['devices'])) {
            $record['responsive_devices'] = array_values(array_filter(array_map('strval', $control['responsive']['devices'])));
        }
        if (isset($control['options']) && is_array($control['options'])) {
            $record['choice_keys'] = array_slice(array_values(array_map('strval', array_keys($control['options']))), 0, 250);
        }
        if (isset($control['fields']) && is_array($control['fields'])) {
            $record['fields'] = array();
            foreach ($control['fields'] as $fieldName => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $key = isset($field['name']) && is_scalar($field['name']) ? (string) $field['name'] : (string) $fieldName;
                $record['fields'][$key] = $this->controlRecord($key, $field);
            }
            ksort($record['fields'], SORT_STRING);
        }
        return $record;
    }

    private function collectAtomicConfig(object $component, array &$warnings, string $name): array
    {
        if (! method_exists($component, 'get_config')) {
            return array();
        }
        try {
            $config = (array) $component->get_config();
        } catch (Throwable $error) {
            $warnings[] = 'atomic_form_config_failed:' . sanitize_key($name);
            return array();
        }

        $result = array();
        foreach (self::ATOMIC_CONFIG_KEYS as $key) {
            if (! array_key_exists($key, $config)) {
                continue;
            }
            $normalized = $this->normalizeJsonValue($config[$key]);
            if (null !== $normalized) {
                $result[$key] = $this->redactSecrets($normalized);
            }
        }
        return $result;
    }

    private function managerTypes(object $elementor, string $managerName, string $method, array &$warnings, string $warningPrefix): array
    {
        if (! isset($elementor->{$managerName}) || ! is_object($elementor->{$managerName}) || ! method_exists($elementor->{$managerName}, $method)) {
            return array();
        }
        try {
            return (array) $elementor->{$managerName}->{$method}();
        } catch (Throwable $error) {
            $warnings[] = $warningPrefix . '_inventory_failed';
            return array();
        }
    }

    private function allRegisteredComponents(object $elementor, array &$warnings): array
    {
        $result = array();
        foreach (array(
            array('elements_manager', 'get_element_types', 'atomic_elements'),
            array('widgets_manager', 'get_widget_types', 'widgets'),
        ) as $source) {
            $types = $this->managerTypes($elementor, $source[0], $source[1], $warnings, $source[2]);
            foreach ($types as $name => $component) {
                if (is_object($component)) {
                    $result[(string) $name] = $component;
                }
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function validateForm(array $form, string $family, array $capabilities): void
    {
        $this->assertElementId($form, 'form');
        if (! array_key_exists('settings', $form) || ! is_array($form['settings'])) {
            throw new RuntimeException('Form settings must be an array/object.');
        }
        if (! array_key_exists('elements', $form) || ! is_array($form['elements'])) {
            throw new RuntimeException('Form elements must be an array.');
        }

        if ('v3' === $family) {
            if (empty($capabilities['classic']['available'])) {
                throw new RuntimeException('The V3 Elementor Form widget is not registered in the target runtime.');
            }
            $this->validateClassicForm($form, $capabilities['classic']);
        } else {
            if (empty($capabilities['atomic']['available'])) {
                throw new RuntimeException('The V4 Atomic Form element is not registered in the target runtime.');
            }
            $this->validateAtomicForm($form, $capabilities['atomic']);
        }

        $ids = array();
        $this->collectElementIds($form, $ids, 'form');
    }

    private function validateClassicForm(array $form, array $capabilities): void
    {
        if ('widget' !== (string) $form['elType'] || 'form' !== (string) $form['widgetType']) {
            throw new RuntimeException('V3 forms must use elType=widget and widgetType=form.');
        }
        if (! empty($form['elements'])) {
            throw new RuntimeException('V3 Form widgets are leaf widgets; form fields belong in settings.form_fields.');
        }

        $settings = $form['settings'];
        if (isset($settings['form_fields'])) {
            if (! is_array($settings['form_fields'])) {
                throw new RuntimeException('V3 settings.form_fields must be an array.');
            }
            $ids = array();
            $customIds = array();
            foreach ($settings['form_fields'] as $index => $field) {
                if (! is_array($field)) {
                    throw new RuntimeException('Each V3 form field must be an object/array.');
                }
                foreach (array('_id' => &$ids, 'custom_id' => &$customIds) as $key => &$seen) {
                    $value = isset($field[$key]) ? (string) $field[$key] : '';
                    if ('' === $value) {
                        continue;
                    }
                    if (isset($seen[$value])) {
                        throw new RuntimeException('Duplicate V3 form field ' . $key . ': ' . $value);
                    }
                    $seen[$value] = (int) $index;
                }
                unset($seen);
            }
        }

        if (isset($settings['submit_actions'])) {
            if (! is_array($settings['submit_actions'])) {
                throw new RuntimeException('V3 settings.submit_actions must be an array.');
            }
            $available = isset($capabilities['submit_action_choice_keys']) && is_array($capabilities['submit_action_choice_keys'])
                ? $capabilities['submit_action_choice_keys']
                : array();
            if (! empty($settings['submit_actions']) && empty($available)) {
                throw new RuntimeException('V3 submit actions cannot be validated against the target runtime.');
            }
            foreach ($settings['submit_actions'] as $action) {
                if (! in_array((string) $action, $available, true)) {
                    throw new RuntimeException('V3 submit action is not registered in the target runtime: ' . (string) $action);
                }
            }
        }
    }

    private function validateAtomicForm(array $form, array $capabilities): void
    {
        if ('e-form' !== (string) $form['elType']) {
            throw new RuntimeException('V4 Atomic forms must use elType=e-form.');
        }
        $registered = array_keys(isset($capabilities['types']) && is_array($capabilities['types']) ? $capabilities['types'] : array());
        $allRegistered = $this->registeredTypeNames();
        $counts = array();
        $this->validateAtomicNode($form, $allRegistered, $counts, true);

        foreach (array('e-form-success-message', 'e-form-error-message', 'e-form-submit-button') as $required) {
            if (empty($counts[$required])) {
                throw new RuntimeException('V4 Atomic Form is missing required descendant: ' . $required);
            }
        }
        foreach (array('e-form-success-message', 'e-form-error-message') as $messageType) {
            $this->assertMessageHasParagraph($form, $messageType);
        }

        if (! in_array('e-form', $registered, true)) {
            throw new RuntimeException('V4 Atomic Form root is not available in the target form capability inventory.');
        }
    }

    private function validateAtomicNode(array $node, array $registered, array &$counts, bool $root = false): void
    {
        $this->assertElementId($node, $root ? 'atomic form' : 'atomic form child');
        $type = isset($node['elType']) ? (string) $node['elType'] : '';
        if ('' === $type || 0 !== strpos($type, 'e-')) {
            throw new RuntimeException('V4 Atomic Form descendants must use registered Atomic e-* element types.');
        }
        if (! in_array($type, $registered, true)) {
            throw new RuntimeException('Atomic element type is not registered in the target runtime: ' . $type);
        }
        $counts[$type] = isset($counts[$type]) ? $counts[$type] + 1 : 1;

        if (! isset($node['version']) || '' === (string) $node['version']) {
            throw new RuntimeException('Atomic element ' . $type . ' is missing its schema version.');
        }
        foreach (array('settings', 'editor_settings', 'styles', 'interactions', 'elements') as $key) {
            if (! array_key_exists($key, $node) || ! is_array($node[$key])) {
                throw new RuntimeException('Atomic element ' . $type . ' must contain array/object key: ' . $key);
            }
        }
        $this->validateAtomicSettings($node['settings'], $type);

        foreach ($node['elements'] as $child) {
            if (! is_array($child)) {
                throw new RuntimeException('Atomic form child entries must be objects/arrays.');
            }
            $this->validateAtomicNode($child, $registered, $counts, false);
        }
    }

    private function validateAtomicSettings(array $settings, string $type): void
    {
        foreach ($settings as $key => $value) {
            if (! is_array($value)) {
                throw new RuntimeException('Atomic setting ' . (string) $key . ' on ' . $type . ' must be a typed prop object.');
            }
            $isTyped = isset($value['$$type']) && is_string($value['$$type']) && array_key_exists('value', $value);
            $isMulti = ! empty($value['$$multi-props']) && array_key_exists('value', $value);
            if (! $isTyped && ! $isMulti) {
                throw new RuntimeException('Atomic setting ' . (string) $key . ' on ' . $type . ' is missing $$type/value metadata.');
            }
        }
    }

    private function assertMessageHasParagraph(array $form, string $messageType): void
    {
        $message = $this->findFirstByType($form, $messageType);
        if (! is_array($message)) {
            throw new RuntimeException('V4 Atomic Form is missing ' . $messageType . '.');
        }
        foreach ($message['elements'] as $child) {
            if (is_array($child) && 'e-paragraph' === (string) ($child['elType'] ?? '')) {
                return;
            }
        }
        throw new RuntimeException($messageType . ' must contain an e-paragraph child.');
    }

    private function registeredTypeNames(): array
    {
        if (! class_exists('Elementor\\Plugin')) {
            return array();
        }
        $warnings = array();
        $components = $this->allRegisteredComponents(\Elementor\Plugin::$instance, $warnings);
        return array_keys($components);
    }

    private function formFamily(array $element): ?string
    {
        $elType = isset($element['elType']) ? (string) $element['elType'] : '';
        $widgetType = isset($element['widgetType']) ? (string) $element['widgetType'] : '';
        if ('widget' === $elType && 'form' === $widgetType) {
            return 'v3';
        }
        if ('e-form' === $elType || 'e-form' === $widgetType) {
            return 'v4';
        }
        return null;
    }

    private function collectForms(array $elements, array &$forms, array $path, ?string $parentId): void
    {
        foreach ($elements as $index => $element) {
            if (! is_array($element)) {
                continue;
            }
            $elementPath = array_merge($path, array((int) $index));
            $family = $this->formFamily($element);
            if (null !== $family) {
                $forms[] = array(
                    'id' => isset($element['id']) ? (string) $element['id'] : '',
                    'family' => $family,
                    'parent_element_id' => $parentId,
                    'path' => $elementPath,
                    'form' => $element,
                );
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->collectForms(
                    $element['elements'],
                    $forms,
                    array_merge($elementPath, array('elements')),
                    isset($element['id']) ? (string) $element['id'] : $parentId
                );
            }
        }
    }

    private function findElementCopy(array $elements, string $id): ?array
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            if (isset($element['id']) && hash_equals((string) $element['id'], $id)) {
                return $element;
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                $found = $this->findElementCopy($element['elements'], $id);
                if (is_array($found)) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function replaceElementById(array &$elements, string $id, array $replacement): bool
    {
        foreach ($elements as $index => &$element) {
            if (! is_array($element)) {
                continue;
            }
            if (isset($element['id']) && hash_equals((string) $element['id'], $id)) {
                $elements[$index] = $replacement;
                unset($element);
                return true;
            }
            if (isset($element['elements']) && is_array($element['elements']) && $this->replaceElementById($element['elements'], $id, $replacement)) {
                unset($element);
                return true;
            }
        }
        unset($element);
        return false;
    }

    private function insertIntoParent(array &$elements, string $parentId, array $form, ?int $position): bool
    {
        foreach ($elements as &$element) {
            if (! is_array($element)) {
                continue;
            }
            if (isset($element['id']) && hash_equals((string) $element['id'], $parentId)) {
                if (! isset($element['elements']) || ! is_array($element['elements'])) {
                    throw new RuntimeException('Target parent cannot contain Elementor child elements.');
                }
                $this->insertAt($element['elements'], $form, $position);
                unset($element);
                return true;
            }
            if (isset($element['elements']) && is_array($element['elements']) && $this->insertIntoParent($element['elements'], $parentId, $form, $position)) {
                unset($element);
                return true;
            }
        }
        unset($element);
        return false;
    }

    private function insertAt(array &$elements, array $form, ?int $position): void
    {
        if (null === $position) {
            $elements[] = $form;
            return;
        }
        if ($position < 0 || $position > count($elements)) {
            throw new RuntimeException('position is outside the valid child range.');
        }
        array_splice($elements, $position, 0, array($form));
    }

    private function assertUniqueElementIds(array $elements): void
    {
        $ids = array();
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            $this->collectElementIds($element, $ids, 'document');
        }
    }

    private function collectElementIds(array $element, array &$ids, string $context): void
    {
        $this->assertElementId($element, $context);
        $id = (string) $element['id'];
        if (isset($ids[$id])) {
            throw new RuntimeException('Duplicate Elementor element id: ' . $id);
        }
        $ids[$id] = true;
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $child) {
                if (is_array($child)) {
                    $this->collectElementIds($child, $ids, $context);
                }
            }
        }
    }

    private function assertElementId(array $element, string $context): void
    {
        $id = isset($element['id']) ? (string) $element['id'] : '';
        if ('' === $id || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new RuntimeException('Invalid or missing Elementor element id in ' . $context . '.');
        }
    }

    private function findFirstByType(array $node, string $type): ?array
    {
        if (isset($node['elType']) && $type === (string) $node['elType']) {
            return $node;
        }
        if (isset($node['elements']) && is_array($node['elements'])) {
            foreach ($node['elements'] as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $found = $this->findFirstByType($child, $type);
                if (is_array($found)) {
                    return $found;
                }
            }
        }
        return null;
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
            $pageSettings = method_exists($document, 'get_db_document_settings') ? $document->get_db_document_settings() : array();
            return array(
                'post_id' => $postId,
                'data' => is_array($data) ? $data : array(),
                'page_settings' => is_array($pageSettings) ? $pageSettings : array(),
            );
        }

        $raw = get_post_meta($postId, '_elementor_data', true);
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        return array(
            'post_id' => $postId,
            'data' => is_array($data) ? $data : array(),
            'page_settings' => $this->normalizeMetaArray(get_post_meta($postId, '_elementor_page_settings', true)),
        );
    }

    private function documentOrNull(int $postId): ?object
    {
        if (! class_exists('Elementor\\Plugin')) {
            return null;
        }
        $plugin = \Elementor\Plugin::$instance;
        $manager = isset($plugin->documents) ? $plugin->documents : null;
        if (! is_object($manager) || ! method_exists($manager, 'get')) {
            return null;
        }
        try {
            $document = $manager->get($postId);
            return is_object($document) ? $document : null;
        } catch (Throwable $error) {
            return null;
        }
    }

    private function document(int $postId): object
    {
        $document = $this->documentOrNull($postId);
        if (! $document) {
            throw new RuntimeException('Elementor document manager could not load this document.');
        }
        if (! method_exists($document, 'save')) {
            throw new RuntimeException('Elementor document save API is unavailable.');
        }
        return $document;
    }

    private function saveDocument(int $postId, array $data, array $pageSettings): void
    {
        if (function_exists('is_user_logged_in') && is_user_logged_in() && ! current_user_can('edit_post', $postId)) {
            throw new RuntimeException('You are not allowed to edit this Elementor document.');
        }
        $document = $this->document($postId);
        $result = $document->save(array('elements' => $data, 'settings' => $pageSettings));
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
        if (false === $result || null === $result) {
            throw new RuntimeException('Elementor rejected the form document save.');
        }
        if (function_exists('clean_post_cache')) {
            clean_post_cache($postId);
        }
    }

    private function restoreSnapshot(array $snapshot): void
    {
        $this->saveDocument((int) $snapshot['post_id'], $snapshot['data'], $snapshot['page_settings']);
        $this->clearCache();
        $restored = $this->snapshot((int) $snapshot['post_id']);
        if (! hash_equals(Fingerprint::make($snapshot['data']), Fingerprint::make($restored['data']))
            || ! hash_equals(Fingerprint::make($snapshot['page_settings']), Fingerprint::make($restored['page_settings']))) {
            throw new RuntimeException('Elementor form rollback readback verification failed.');
        }
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

    private function normalizeMetaArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && '' !== trim($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : array();
        }
        return array();
    }

    private function normalizeJsonValue($value)
    {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            return null;
        }
        $decoded = json_decode($encoded, true);
        return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
    }

    private function redactSecrets($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        $result = array();
        foreach ($value as $key => $item) {
            if (preg_match('/(password|passwd|secret|token|api[_-]?key|private[_-]?key|consumer_secret|authorization|cookie)/i', (string) $key)) {
                $result[$key] = '[redacted]';
                continue;
            }
            $result[$key] = $this->redactSecrets($item);
        }
        return $result;
    }
}
