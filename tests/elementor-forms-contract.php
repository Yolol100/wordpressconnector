<?php

declare(strict_types=1);

namespace Elementor {
    final class Plugin
    {
        public static $instance;
    }
}

namespace {
    define('ELEMENTOR_VERSION', '4.1.0-test');
    define('ELEMENTOR_PRO_VERSION', '4.1.0-test');

    function sanitize_key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $value));
    }

    function wp_json_encode($value, int $flags = 0)
    {
        return json_encode($value, $flags);
    }

    function wp_get_theme()
    {
        return new class {
            public function get(string $key): string
            {
                return 'Version' === $key ? '1.0.0' : 'Test Theme';
            }
        };
    }

    require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
    require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
    require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/ElementorFormsAdapter.php';

    final class FakeManager
    {
        private $types;
        private $method;

        public function __construct(array $types, string $method)
        {
            $this->types = $types;
            $this->method = $method;
        }

        public function get_widget_types(): array
        {
            return 'widgets' === $this->method ? $this->types : array();
        }

        public function get_element_types(): array
        {
            return 'elements' === $this->method ? $this->types : array();
        }
    }

    final class FakeClassicForm
    {
        public function get_controls(): array
        {
            return array(
                'form_fields' => array(
                    'type' => 'repeater',
                    'fields' => array(
                        array('name' => 'field_type', 'type' => 'select', 'options' => array('text' => 'Text', 'email' => 'Email', 'textarea' => 'Textarea')),
                        array('name' => 'field_label', 'type' => 'text'),
                        array('name' => 'custom_id', 'type' => 'text'),
                        array('name' => 'required', 'type' => 'switcher'),
                    ),
                ),
                'submit_actions' => array(
                    'type' => 'select2',
                    'options' => array('email' => 'Email', 'email_2' => 'Email 2', 'save-to-database' => 'Collect Submissions'),
                ),
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

    class FakeAtomicComponent
    {
        private $props;

        public function __construct(array $props = array())
        {
            $this->props = $props;
        }

        public function get_config(): array
        {
            return array(
                'atomic_props_schema' => $this->props,
                'atomic_controls' => array(),
                'version' => '1.0',
                'allowed_child_types' => array(),
            );
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

    $invoke = static function (string $method, array $args = array()) use ($adapter, $reflection, &$scenarios) {
        $scenarios++;
        $ref = $reflection->getMethod($method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($adapter, $args);
    };

    $expectFailure = static function (callable $callback, string $contains) use (&$scenarios): void {
        $scenarios++;
        try {
            $callback();
        } catch (\RuntimeException $error) {
            if (false === strpos($error->getMessage(), $contains)) {
                fwrite(STDERR, "Unexpected error: {$error->getMessage()}\n");
                exit(1);
            }
            return;
        }
        fwrite(STDERR, "Expected failure containing: {$contains}\n");
        exit(1);
    };

    $typed = static function (string $type, $value): array {
        return array('$$type' => $type, 'value' => $value);
    };

    $atomicNode = static function (string $id, string $type, array $settings = array(), array $children = array(), bool $withInteractions = false): array {
        $node = array(
            'id' => $id,
            'elType' => $type,
            'version' => '1.0',
            'settings' => $settings,
            'editor_settings' => array(),
            'styles' => array(),
            'elements' => $children,
        );
        if ($withInteractions) {
            $node['interactions'] = array();
        }
        return $node;
    };

    $classic = array(
        'id' => 'formv3',
        'elType' => 'widget',
        'widgetType' => 'form',
        'settings' => array(
            'form_name' => 'Contact',
            'form_fields' => array(
                array('_id' => 'field1', 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Name'),
                array('_id' => 'field2', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Email', 'required' => 'true'),
            ),
            'submit_actions' => array('email', 'save-to-database'),
            'email_to' => 'team@example.test',
            'email_subject' => 'New form submission',
            'email_content' => '[all-fields]',
            'email_from' => 'website@example.test',
            'email_from_name' => 'Website',
            'email_reply_to' => '[field id="email"]',
            'email_cc' => 'archive@example.test',
            'email_bcc' => 'audit@example.test',
            'success_message' => 'Thank you',
            'error_message' => 'Try again',
        ),
        'elements' => array(),
    );

    $success = $atomicNode('success1', 'e-form-success-message', array(), array(
        $atomicNode('successp', 'e-paragraph', array('text' => $typed('string', 'Thank you')), array()),
    ));
    $error = $atomicNode('error1', 'e-form-error-message', array(), array(
        $atomicNode('errorp', 'e-paragraph', array('text' => $typed('string', 'Try again')), array()),
    ));
    $input = $atomicNode('input1', 'e-form-input', array(
        'name' => $typed('string', 'email'),
        'type' => $typed('string', 'email'),
        'required' => $typed('boolean', true),
    ), array());
    $submit = $atomicNode('submit1', 'e-form-submit-button', array('text' => $typed('string', 'Send')), array(), true);
    $atomic = $atomicNode('formv4', 'e-form', array(
        'submit-actions' => $typed('string-array', array('email', 'email_2')),
        'email' => $typed('emails', array(
            'to' => array('value' => array('team@example.test')),
            'subject' => 'Atomic subject',
            'message' => '[all-fields]',
            'from' => 'website@example.test',
            'from-name' => 'Website',
            'reply-to' => 'reply@example.test',
            'cc' => array('value' => array('archive@example.test')),
            'bcc' => array('value' => array('audit@example.test')),
            'send-as' => 'html',
        )),
    ), array($input, $submit, $success, $error));

    $capabilities = $adapter->capabilities(array(), array());
    $scenarios++;
    if (($capabilities['recommended_family'] ?? null) !== 'v4') {
        fwrite(STDERR, "Expected V4 to be recommended when Atomic Form is registered.\n");
        exit(1);
    }
    $scenarios++;
    if (($capabilities['classic']['submit_action_choice_keys'] ?? array()) !== array('email', 'email_2', 'save-to-database')) {
        fwrite(STDERR, "Classic submit action runtime inventory failed.\n");
        exit(1);
    }
    $scenarios++;
    if (! isset($capabilities['atomic']['types']['e-form']['config']['atomic_props_schema'])) {
        fwrite(STDERR, "Atomic form props schema inventory failed.\n");
        exit(1);
    }
    $scenarios++;
    if (empty($capabilities['schema_fingerprint'])) {
        fwrite(STDERR, "Form schema fingerprint missing.\n");
        exit(1);
    }

    if ($invoke('formFamily', array($classic)) !== 'v3') {
        fwrite(STDERR, "V3 family detection failed.\n");
        exit(1);
    }
    if ($invoke('formFamily', array($atomic)) !== 'v4') {
        fwrite(STDERR, "V4 family detection failed.\n");
        exit(1);
    }
    if (null !== $invoke('formFamily', array(array('id' => 'x', 'elType' => 'widget', 'widgetType' => 'heading')))) {
        fwrite(STDERR, "Non-form family detection failed.\n");
        exit(1);
    }

    $classicBefore = $classic;
    $invoke('validateClassicForm', array($classic, $capabilities['classic']));
    $scenarios++;
    if ($classic !== $classicBefore || ($classic['settings']['email_content'] ?? '') !== '[all-fields]') {
        fwrite(STDERR, "V3 complete settings must be preserved unchanged.\n");
        exit(1);
    }

    $bad = $classic;
    $bad['settings']['form_fields'][1]['_id'] = 'field1';
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateClassicForm', array($bad, $capabilities['classic']));
    }, 'Duplicate V3 form field _id');

    $bad = $classic;
    $bad['settings']['form_fields'][1]['custom_id'] = 'name';
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateClassicForm', array($bad, $capabilities['classic']));
    }, 'Duplicate V3 form field custom_id');

    $bad = $classic;
    $bad['settings']['submit_actions'][] = 'missing-action';
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateClassicForm', array($bad, $capabilities['classic']));
    }, 'not registered');

    $bad = $classic;
    $bad['settings']['submit_actions'] = 'email';
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateClassicForm', array($bad, $capabilities['classic']));
    }, 'submit_actions must be an array');

    $bad = $classic;
    $bad['settings']['form_fields'] = 'bad';
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateClassicForm', array($bad, $capabilities['classic']));
    }, 'form_fields must be an array');

    $bad = $classic;
    $bad['elements'][] = array('id' => 'child');
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateClassicForm', array($bad, $capabilities['classic']));
    }, 'leaf widgets');

    $invoke('validateAtomicForm', array($atomic, $capabilities['atomic']));
    $scenarios++;
    if (($atomic['settings']['email']['value']['message'] ?? '') !== '[all-fields]') {
        fwrite(STDERR, "V4 email content must remain part of the complete typed form settings.\n");
        exit(1);
    }

    $bad = $atomic;
    unset($bad['elements'][2]);
    $bad['elements'] = array_values($bad['elements']);
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'e-form-success-message');

    $bad = $atomic;
    unset($bad['elements'][3]);
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'e-form-error-message');

    $bad = $atomic;
    $bad['elements'] = array_values(array_filter($bad['elements'], static function (array $node): bool {
        return 'e-form-submit-button' !== $node['elType'];
    }));
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'exactly one e-form-submit-button');

    $bad = $atomic;
    $bad['elements'][] = $atomicNode('submit2', 'e-form-submit-button');
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'exactly one e-form-submit-button');

    $bad = $atomic;
    $bad['elements'][2]['elements'] = array();
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'must contain an e-paragraph');

    $bad = $atomic;
    $bad['elements'][] = $atomicNode('nested', 'e-form', array(), array());
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'cannot be nested');

    $bad = $atomic;
    $bad['elements'][] = $atomicNode('unknown', 'e-form-unknown-field');
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'not registered');

    $bad = $atomic;
    unset($bad['version']);
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'missing its schema version');

    $bad = $atomic;
    $bad['settings']['email'] = array('value' => array());
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'missing $$type/value');

    $bad = $atomic;
    $bad['interactions'] = 'invalid';
    $expectFailure(static function () use ($invoke, $bad, $capabilities): void {
        $invoke('validateAtomicForm', array($bad, $capabilities['atomic']));
    }, 'interactions must be an array');

    $noInteractions = $atomic;
    unset($noInteractions['interactions']);
    $invoke('validateAtomicForm', array($noInteractions, $capabilities['atomic']));

    $tree = array(array('id' => 'container1', 'elType' => 'container', 'settings' => array(), 'elements' => array()));
    $insertArgs = array(&$tree, 'container1', $classic, 0);
    $invoke('insertIntoParent', $insertArgs);
    $scenarios++;
    if (($tree[0]['elements'][0]['id'] ?? '') !== 'formv3') {
        fwrite(STDERR, "Nested insertion failed.\n");
        exit(1);
    }

    $replace = $classic;
    $replace['settings']['email_subject'] = 'Changed subject';
    $replaceArgs = array(&$tree, 'formv3', $replace);
    $invoke('replaceElementById', $replaceArgs);
    $scenarios++;
    if (($tree[0]['elements'][0]['settings']['email_subject'] ?? '') !== 'Changed subject') {
        fwrite(STDERR, "Complete form replacement failed.\n");
        exit(1);
    }

    $expectFailure(static function () use ($invoke, $atomic): void {
        $children = array();
        $args = array(&$children, $atomic, 2);
        $invoke('insertAt', $args);
    }, 'outside the valid child range');

    $nested = array($atomicNode('outer', 'e-form', array(), array($classic)));
    $expectFailure(static function () use ($invoke, $nested): void {
        $invoke('assertNoNestedForms', array($nested));
    }, 'cannot be nested');

    $duplicate = array(
        array('id' => 'same', 'elType' => 'container', 'settings' => array(), 'elements' => array()),
        array('id' => 'same', 'elType' => 'container', 'settings' => array(), 'elements' => array()),
    );
    $expectFailure(static function () use ($invoke, $duplicate): void {
        $invoke('assertUniqueElementIds', array($duplicate));
    }, 'Duplicate Elementor element id');

    $forms = array();
    $document = array(
        array('id' => 'layout', 'elType' => 'container', 'settings' => array(), 'elements' => array($classic, $atomic)),
    );
    $collectArgs = array($document, &$forms, array(), null);
    $invoke('collectForms', $collectArgs);
    $scenarios++;
    if (count($forms) !== 2 || $forms[0]['family'] !== 'v3' || $forms[1]['family'] !== 'v4') {
        fwrite(STDERR, "V3/V4 form inspection traversal failed.\n");
        exit(1);
    }

    $secretConfig = array('token' => 'secret', 'safe' => array('api_key' => 'secret', 'label' => 'ok'));
    $redacted = $invoke('redactSecrets', array($secretConfig));
    $scenarios++;
    if (($redacted['token'] ?? null) !== '[redacted]' || ($redacted['safe']['api_key'] ?? null) !== '[redacted]' || ($redacted['safe']['label'] ?? null) !== 'ok') {
        fwrite(STDERR, "Capability secret redaction failed.\n");
        exit(1);
    }

    $source = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/ElementorFormsAdapter.php');
    foreach (array(
        "elementor.form_capabilities",
        "elementor.form_inspect",
        "elementor.form_upsert",
        "expected_schema_fingerprint",
        "allow_family_change",
        "save(array('elements' => $data, 'settings' => $pageSettings))",
        "restoreSnapshot($before)",
        "Fingerprint::make($form)",
    ) as $needle) {
        $scenarios++;
        if (false === strpos($source, $needle)) {
            fwrite(STDERR, "Form adapter contract missing: {$needle}\n");
            exit(1);
        }
    }

    echo "elementor forms contract OK ({$scenarios} scenarios)\n";
}
