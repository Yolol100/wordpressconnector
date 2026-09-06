<?php

declare(strict_types=1);

namespace Elementor {
    final class Plugin { public static $instance; }
}

namespace {
    define('ELEMENTOR_VERSION', '4.1.0-test');
    define('ELEMENTOR_PRO_VERSION', '4.1.0-test');

    function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $value)); }
    function wp_json_encode($value, int $flags = 0) { return json_encode($value, $flags); }
    function wp_get_theme() { return new class { public function get(string $key): string { return 'Version' === $key ? '1.0.0' : 'Test Theme'; } }; }

    require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
    require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
    require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/ElementorFormsAdapter.php';

    final class FakeManager {
        private $types;
        private $kind;
        public function __construct(array $types, string $kind) { $this->types = $types; $this->kind = $kind; }
        public function get_widget_types(): array { return 'widgets' === $this->kind ? $this->types : array(); }
        public function get_element_types(): array { return 'elements' === $this->kind ? $this->types : array(); }
    }

    final class FakeClassicForm {
        public function get_controls(): array {
            return array(
                'form_fields' => array('type' => 'repeater', 'fields' => array(
                    array('name' => 'field_type', 'type' => 'select', 'options' => array('text' => 'Text', 'email' => 'Email', 'textarea' => 'Textarea')),
                    array('name' => 'field_label', 'type' => 'text'),
                    array('name' => 'custom_id', 'type' => 'text'),
                    array('name' => 'required', 'type' => 'switcher'),
                )),
                'submit_actions' => array('type' => 'select2', 'options' => array('email' => 'Email', 'email_2' => 'Email 2', 'save-to-database' => 'Collect')),
                'email_to' => array('type' => 'text'),
                'email_subject' => array('type' => 'text'),
                'email_content' => array('type' => 'textarea'),
                'email_from' => array('type' => 'text'),
                'email_from_name' => array('type' => 'text'),
                'email_reply_to' => array('type' => 'text'),
                'email_cc' => array('type' => 'text'),
                'email_bcc' => array('type' => 'text'),
            );
        }
    }

    class FakeAtomicComponent {
        private $props;
        public function __construct(array $props = array()) { $this->props = $props; }
        public function get_config(): array {
            return array('atomic_props_schema' => $this->props, 'atomic_controls' => array(), 'version' => '1.0', 'allowed_child_types' => array());
        }
    }

    $atomicTypes = array(
        'e-form' => new FakeAtomicComponent(array('submit-actions' => array(), 'email' => array(), 'email_2' => array())),
        'e-form-input' => new FakeAtomicComponent(),
        'e-form-textarea' => new FakeAtomicComponent(),
        'e-form-submit-button' => new FakeAtomicComponent(),
        'e-form-success-message' => new FakeAtomicComponent(),
        'e-form-error-message' => new FakeAtomicComponent(),
        'e-paragraph' => new FakeAtomicComponent(),
        'e-flexbox' => new FakeAtomicComponent(),
    );
    \Elementor\Plugin::$instance = (object) array(
        'widgets_manager' => new FakeManager(array('form' => new FakeClassicForm()), 'widgets'),
        'elements_manager' => new FakeManager($atomicTypes, 'elements'),
    );

    $adapter = new \Webactueel\WordPressConnector\Adapters\ElementorFormsAdapter();
    $reflection = new \ReflectionClass($adapter);
    $scenarios = 0;

    $invoke = static function (string $name, array $args = array()) use ($adapter, $reflection, &$scenarios) {
        $scenarios++;
        $method = $reflection->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($adapter, $args);
    };
    $mustFail = static function (callable $callback, string $needle) use (&$scenarios): void {
        $scenarios++;
        try { $callback(); } catch (\RuntimeException $error) {
            if (false !== strpos($error->getMessage(), $needle)) { return; }
            fwrite(STDERR, "Unexpected error: {$error->getMessage()}\n"); exit(1);
        }
        fwrite(STDERR, "Expected failure: {$needle}\n"); exit(1);
    };
    $typed = static function (string $type, $value): array { return array('$$type' => $type, 'value' => $value); };
    $node = static function (string $id, string $type, array $settings = array(), array $children = array()): array {
        return array('id' => $id, 'elType' => $type, 'version' => '1.0', 'settings' => $settings, 'editor_settings' => array(), 'styles' => array(), 'elements' => $children);
    };

    $v3 = array(
        'id' => 'formv3', 'elType' => 'widget', 'widgetType' => 'form', 'elements' => array(),
        'settings' => array(
            'form_name' => 'Contact',
            'form_fields' => array(
                array('_id' => 'f1', 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Name'),
                array('_id' => 'f2', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Email', 'required' => 'true'),
            ),
            'submit_actions' => array('email', 'save-to-database'),
            'email_to' => 'team@example.test', 'email_subject' => 'Subject', 'email_content' => '[all-fields]',
            'email_from' => 'site@example.test', 'email_from_name' => 'Website', 'email_reply_to' => '[field id="email"]',
            'email_cc' => 'archive@example.test', 'email_bcc' => 'audit@example.test',
            'success_message' => 'Thanks', 'error_message' => 'Try again',
        ),
    );

    $success = $node('success', 'e-form-success-message', array(), array($node('successp', 'e-paragraph', array('text' => $typed('string', 'Thanks')))));
    $error = $node('error', 'e-form-error-message', array(), array($node('errorp', 'e-paragraph', array('text' => $typed('string', 'Try again')))));
    $input = $node('input', 'e-form-input', array('name' => $typed('string', 'email'), 'type' => $typed('string', 'email'), 'required' => $typed('boolean', true)));
    $submit = $node('submit', 'e-form-submit-button', array('text' => $typed('string', 'Send')));
    $v4 = $node('formv4', 'e-form', array(
        'submit-actions' => $typed('string-array', array('email', 'email_2')),
        'email' => $typed('emails', array(
            'to' => array('value' => array('team@example.test')), 'subject' => 'Atomic subject', 'message' => '[all-fields]',
            'from' => 'site@example.test', 'from-name' => 'Website', 'reply-to' => 'reply@example.test',
            'cc' => array('value' => array('archive@example.test')), 'bcc' => array('value' => array('audit@example.test')), 'send-as' => 'html',
        )),
    ), array($input, $submit, $success, $error));

    $caps = $adapter->capabilities(array(), array());
    $scenarios += 4;
    if (($caps['recommended_family'] ?? null) !== 'v4') { fwrite(STDERR, "V4 recommendation failed.\n"); exit(1); }
    if (($caps['classic']['submit_action_choice_keys'] ?? array()) !== array('email', 'email_2', 'save-to-database')) { fwrite(STDERR, "V3 action inventory failed.\n"); exit(1); }
    if (! isset($caps['atomic']['types']['e-form']['config']['atomic_props_schema'])) { fwrite(STDERR, "V4 schema inventory failed.\n"); exit(1); }
    if (empty($caps['schema_fingerprint'])) { fwrite(STDERR, "Schema fingerprint missing.\n"); exit(1); }

    if ($invoke('formFamily', array($v3)) !== 'v3' || $invoke('formFamily', array($v4)) !== 'v4') { fwrite(STDERR, "Family detection failed.\n"); exit(1); }
    if (null !== $invoke('formFamily', array(array('id' => 'x', 'elType' => 'widget', 'widgetType' => 'heading')))) { fwrite(STDERR, "Non-form detection failed.\n"); exit(1); }

    $before = $v3;
    $invoke('validateClassicForm', array($v3, $caps['classic']));
    $scenarios++;
    if ($v3 !== $before || $v3['settings']['email_content'] !== '[all-fields]') { fwrite(STDERR, "V3 settings were altered.\n"); exit(1); }

    $bad = $v3; $bad['settings']['form_fields'][1]['_id'] = 'f1';
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateClassicForm', array($bad, $caps['classic'])); }, 'Duplicate V3 form field _id');
    $bad = $v3; $bad['settings']['form_fields'][1]['custom_id'] = 'name';
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateClassicForm', array($bad, $caps['classic'])); }, 'Duplicate V3 form field custom_id');
    $bad = $v3; $bad['settings']['submit_actions'][] = 'unknown';
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateClassicForm', array($bad, $caps['classic'])); }, 'not registered');
    $bad = $v3; $bad['settings']['submit_actions'] = 'email';
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateClassicForm', array($bad, $caps['classic'])); }, 'submit_actions must be an array');
    $bad = $v3; $bad['settings']['form_fields'] = 'bad';
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateClassicForm', array($bad, $caps['classic'])); }, 'form_fields must be an array');
    $bad = $v3; $bad['elements'] = array(array('id' => 'child'));
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateClassicForm', array($bad, $caps['classic'])); }, 'leaf widgets');

    $invoke('validateAtomicForm', array($v4, $caps['atomic']));
    $scenarios++;
    if ($v4['settings']['email']['value']['message'] !== '[all-fields]') { fwrite(STDERR, "V4 email settings were altered.\n"); exit(1); }

    $bad = $v4; $bad['elements'] = array_values(array_filter($bad['elements'], static function (array $item): bool { return 'e-form-success-message' !== $item['elType']; }));
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'e-form-success-message');
    $bad = $v4; $bad['elements'] = array_values(array_filter($bad['elements'], static function (array $item): bool { return 'e-form-error-message' !== $item['elType']; }));
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'e-form-error-message');
    $bad = $v4; $bad['elements'] = array_values(array_filter($bad['elements'], static function (array $item): bool { return 'e-form-submit-button' !== $item['elType']; }));
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'exactly one e-form-submit-button');
    $bad = $v4; $bad['elements'][] = $node('submit2', 'e-form-submit-button');
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'exactly one e-form-submit-button');
    $bad = $v4; $bad['elements'][2]['elements'] = array();
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'must contain an e-paragraph');
    $bad = $v4; $bad['elements'][] = $node('nested', 'e-form');
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'cannot be nested');
    $bad = $v4; $bad['elements'][] = $node('unknown', 'e-form-unknown');
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'not registered');
    $bad = $v4; unset($bad['version']);
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'missing its schema version');
    $bad = $v4; $bad['settings']['email'] = array('value' => array());
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'missing $$type/value');
    $bad = $v4; $bad['interactions'] = 'bad';
    $mustFail(static function () use ($invoke, $bad, $caps): void { $invoke('validateAtomicForm', array($bad, $caps['atomic'])); }, 'interactions must be an array');
    $withoutInteractions = $v4; unset($withoutInteractions['interactions']);
    $invoke('validateAtomicForm', array($withoutInteractions, $caps['atomic']));

    $tree = array(array('id' => 'container', 'elType' => 'container', 'settings' => array(), 'elements' => array()));
    $args = array(&$tree, 'container', $v3, 0); $invoke('insertIntoParent', $args);
    $scenarios++;
    if (($tree[0]['elements'][0]['id'] ?? '') !== 'formv3') { fwrite(STDERR, "Insert failed.\n"); exit(1); }
    $replacement = $v3; $replacement['settings']['email_subject'] = 'Changed';
    $args = array(&$tree, 'formv3', $replacement); $invoke('replaceElementById', $args);
    $scenarios++;
    if (($tree[0]['elements'][0]['settings']['email_subject'] ?? '') !== 'Changed') { fwrite(STDERR, "Replace failed.\n"); exit(1); }

    $mustFail(static function () use ($invoke, $v4): void { $children = array(); $args = array(&$children, $v4, 2); $invoke('insertAt', $args); }, 'outside the valid child range');
    $nested = array($node('outer', 'e-form', array(), array($v3)));
    $mustFail(static function () use ($invoke, $nested): void { $invoke('assertNoNestedForms', array($nested)); }, 'cannot be nested');
    $duplicate = array(array('id' => 'same', 'elType' => 'container', 'elements' => array()), array('id' => 'same', 'elType' => 'container', 'elements' => array()));
    $mustFail(static function () use ($invoke, $duplicate): void { $invoke('assertUniqueElementIds', array($duplicate)); }, 'Duplicate Elementor element id');

    $forms = array(); $document = array(array('id' => 'layout', 'elType' => 'container', 'elements' => array($v3, $v4)));
    $args = array($document, &$forms, array(), null); $invoke('collectForms', $args);
    $scenarios++;
    if (count($forms) !== 2 || $forms[0]['family'] !== 'v3' || $forms[1]['family'] !== 'v4') { fwrite(STDERR, "Inspection traversal failed.\n"); exit(1); }

    $redacted = $invoke('redactSecrets', array(array('token' => 'x', 'nested' => array('api_key' => 'y', 'label' => 'ok'))));
    $scenarios++;
    if ($redacted['token'] !== '[redacted]' || $redacted['nested']['api_key'] !== '[redacted]' || $redacted['nested']['label'] !== 'ok') { fwrite(STDERR, "Redaction failed.\n"); exit(1); }

    $source = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/ElementorFormsAdapter.php');
    foreach (array(
        'elementor.form_capabilities', 'elementor.form_inspect', 'elementor.form_upsert', 'expected_schema_fingerprint', 'allow_family_change',
        "save(array('elements' => \$data, 'settings' => \$pageSettings))", 'restoreSnapshot($before)', 'Fingerprint::make($form)'
    ) as $needle) {
        $scenarios++;
        if (false === strpos($source, $needle)) { fwrite(STDERR, "Missing contract: {$needle}\n"); exit(1); }
    }

    echo "elementor forms contract OK ({$scenarios} scenarios)\n";
}
