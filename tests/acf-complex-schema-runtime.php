<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

class WP_Post
{
    public int $ID;
    public string $post_type = 'page';
    public string $post_status = 'publish';
    public string $post_password = '';

    public function __construct(int $id)
    {
        $this->ID = $id;
    }
}

$GLOBALS['acf_test_groups'] = array(
    'group_existing123' => array(
        'ID' => 500,
        'key' => 'group_existing123',
        'title' => 'Existing',
        'active' => true,
        'location' => array(array(array('param' => 'post_type', 'operator' => '==', 'value' => 'page'))),
    ),
);
$GLOBALS['acf_test_fields'] = array();
$GLOBALS['acf_test_next_id'] = 1000;
$GLOBALS['acf_test_fail_key'] = '';

function get_post($id)
{
    return (int) $id > 0 ? new WP_Post((int) $id) : null;
}
function get_post_type_object($type)
{
    return (object) array('public' => true);
}
function current_user_can($capability, ...$args): bool
{
    return true;
}
function get_fields($target, $format = false): array
{
    return array();
}
function update_field($key, $value, $target): bool
{
    return empty($GLOBALS['acf_test_update_failure']);
}
function esc_html($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}
function get_field($key, $target, $format = false)
{
    return null;
}
function wp_json_encode($value): string
{
    return json_encode($value);
}

function acf_get_field_type($type)
{
    return in_array((string) $type, array('text','textarea','number','email','url','image','relationship','group','repeater'), true)
        ? (object) array('name' => (string) $type)
        : false;
}
function acf_get_location_rule_types(): array
{
    return array('Post' => array('post_type' => 'Post Type'));
}
function acf_get_location_rule_operators($rule): array
{
    return array('==' => 'is equal to', '!=' => 'is not equal to');
}
function acf_get_field_group($key)
{
    return $GLOBALS['acf_test_groups'][(string) $key] ?? null;
}
function acf_get_field_groups($args = array()): array
{
    return array_values($GLOBALS['acf_test_groups']);
}
function acf_get_field($key)
{
    $key = (string) $key;
    if (isset($GLOBALS['acf_test_fields'][$key])) {
        return $GLOBALS['acf_test_fields'][$key];
    }
    foreach ($GLOBALS['acf_test_fields'] as $field) {
        if ((string) ($field['ID'] ?? '') === $key || (string) ($field['name'] ?? '') === $key) {
            return $field;
        }
    }
    return null;
}
function acf_get_fields($parent): array
{
    $parentId = is_array($parent) ? (int) ($parent['ID'] ?? 0) : (int) $parent;
    $fields = array();
    foreach ($GLOBALS['acf_test_fields'] as $field) {
        if ((int) ($field['parent'] ?? 0) === $parentId) {
            $fields[] = $field;
        }
    }
    usort($fields, static function (array $a, array $b): int {
        return ((int) ($a['menu_order'] ?? 0)) <=> ((int) ($b['menu_order'] ?? 0));
    });
    return $fields;
}
function acf_update_field($field)
{
    if (! is_array($field) || empty($field['key'])) {
        return false;
    }
    if ((string) $field['key'] === (string) ($GLOBALS['acf_test_fail_key'] ?? '')) {
        return false;
    }
    if (empty($field['ID'])) {
        $field['ID'] = ++$GLOBALS['acf_test_next_id'];
    }
    if (! isset($field['menu_order'])) {
        $field['menu_order'] = 0;
    }
    $GLOBALS['acf_test_fields'][(string) $field['key']] = $field;
    return $field;
}
function acf_delete_field($id = 0): bool
{
    foreach ($GLOBALS['acf_test_fields'] as $key => $field) {
        if ((int) ($field['ID'] ?? 0) === (int) $id || (string) $key === (string) $id) {
            unset($GLOBALS['acf_test_fields'][$key]);
            return true;
        }
    }
    return false;
}
function acf_import_field_group($group)
{
    if (! is_array($group) || empty($group['key']) || isset($GLOBALS['acf_test_groups'][(string) $group['key']])) {
        return array();
    }
    $fields = isset($group['fields']) && is_array($group['fields']) ? array_values($group['fields']) : array();
    unset($group['fields']);
    $group['ID'] = ++$GLOBALS['acf_test_next_id'];
    if (! isset($group['active'])) {
        $group['active'] = true;
    }
    $GLOBALS['acf_test_groups'][(string) $group['key']] = $group;

    $saveTree = static function (array $definitions, int $parentId) use (&$saveTree): void {
        foreach (array_values($definitions) as $index => $definition) {
            $children = isset($definition['sub_fields']) && is_array($definition['sub_fields'])
                ? array_values($definition['sub_fields'])
                : array();
            unset($definition['sub_fields']);
            $definition['parent'] = $parentId;
            $definition['menu_order'] = $index;
            $saved = acf_update_field($definition);
            if ($children) {
                $saveTree($children, (int) $saved['ID']);
            }
        }
    };
    $saveTree($fields, (int) $group['ID']);
    return $group;
}
function acf_delete_field_group($id = 0): bool
{
    $key = '';
    foreach ($GLOBALS['acf_test_groups'] as $candidateKey => $group) {
        if ((string) $candidateKey === (string) $id || (int) ($group['ID'] ?? 0) === (int) $id) {
            $key = (string) $candidateKey;
            break;
        }
    }
    if ('' === $key) {
        return false;
    }
    $deleteTree = static function (int $parentId) use (&$deleteTree): void {
        foreach (acf_get_fields(array('ID' => $parentId)) as $field) {
            $deleteTree((int) $field['ID']);
            acf_delete_field((int) $field['ID']);
        }
    };
    $deleteTree((int) $GLOBALS['acf_test_groups'][$key]['ID']);
    unset($GLOBALS['acf_test_groups'][$key]);
    return true;
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/class-registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/AcfAdapter.php';

use Webactueel\WordPressConnector\Adapters\AcfAdapter;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

Policy::setPublicRepositoryContext(false);
$adapter = new AcfAdapter();
$registry = new Registry();
$adapter->register($registry);

$nested = array(
    array(
        'key' => 'field_heroimage123',
        'name' => 'hero_image',
        'label' => 'Hero image',
        'type' => 'image',
        'return_format' => 'id',
        'preview_size' => 'medium',
    ),
    array(
        'key' => 'field_details123',
        'name' => 'details',
        'label' => 'Details',
        'type' => 'group',
        'layout' => 'block',
        'sub_fields' => array(
            array(
                'key' => 'field_details_title123',
                'name' => 'title',
                'label' => 'Title',
                'type' => 'text',
            ),
            array(
                'key' => 'field_details_related123',
                'name' => 'related',
                'label' => 'Related',
                'type' => 'relationship',
                'post_type' => array('page'),
                'return_format' => 'id',
            ),
        ),
    ),
    array(
        'key' => 'field_items123',
        'name' => 'items',
        'label' => 'Items',
        'type' => 'repeater',
        'layout' => 'row',
        'min' => 0,
        'max' => 8,
        'collapsed' => 'field_item_title123',
        'sub_fields' => array(
            array(
                'key' => 'field_item_title123',
                'name' => 'item_title',
                'label' => 'Item title',
                'type' => 'text',
            ),
            array(
                'key' => 'field_item_image123',
                'name' => 'item_image',
                'label' => 'Item image',
                'type' => 'image',
            ),
        ),
    ),
);

$ensurePayload = array(
    'post_id' => 4470,
    'group_key' => 'group_existing123',
    'fields' => $nested,
);
$preview = $adapter->ensureFields($ensurePayload, array('dry_run' => true));
if (count($preview['would_create'] ?? array()) !== 3 || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($preview['_current_fingerprint'] ?? ''))) {
    fwrite(STDERR, "Complex ACF ensure dry-run failed.\n");
    exit(1);
}
$created = $adapter->ensureFields($ensurePayload, array('dry_run' => false));
if (($created['created'] ?? array()) !== array('field_heroimage123', 'field_details123', 'field_items123')) {
    fwrite(STDERR, "Complex ACF ensure create set mismatch.\n");
    exit(1);
}
if (! isset($created['after']['field_items123']['sub_fields'][1]['key'])
    || 'field_item_image123' !== $created['after']['field_items123']['sub_fields'][1]['key']) {
    fwrite(STDERR, "Complex ACF nested readback failed.\n");
    exit(1);
}
$repeatPreview = $adapter->ensureFields($ensurePayload, array('dry_run' => true));
if (! empty($repeatPreview['would_create'])) {
    fwrite(STDERR, "Complex ACF ensure is not idempotent for matching fields.\n");
    exit(1);
}
$removed = $adapter->removeFields($ensurePayload, array('dry_run' => false));
if (($removed['removed'] ?? array()) !== array('field_heroimage123', 'field_details123', 'field_items123')
    || is_array(acf_get_field('field_item_image123'))) {
    fwrite(STDERR, "Complex ACF recursive removal failed.\n");
    exit(1);
}

$failurePayload = array(
    'post_id' => 4470,
    'group_key' => 'group_existing123',
    'fields' => array(
        array(
            'key' => 'field_failure_parent123',
            'name' => 'failure_parent',
            'label' => 'Failure parent',
            'type' => 'group',
            'sub_fields' => array(
                array(
                    'key' => 'field_failure_child123',
                    'name' => 'failure_child',
                    'label' => 'Failure child',
                    'type' => 'text',
                ),
                array(
                    'key' => 'field_failure_trigger123',
                    'name' => 'failure_trigger',
                    'label' => 'Failure trigger',
                    'type' => 'text',
                ),
            ),
        ),
    ),
);
$GLOBALS['acf_test_fail_key'] = 'field_failure_trigger123';
$failedAsExpected = false;
try {
    $adapter->ensureFields($failurePayload, array('dry_run' => false));
} catch (RuntimeException $error) {
    $failedAsExpected = true;
}
$GLOBALS['acf_test_fail_key'] = '';
if (! $failedAsExpected) {
    fwrite(STDERR, "Complex ACF injected partial-create failure did not fail.\n");
    exit(1);
}
foreach (array('field_failure_parent123', 'field_failure_child123', 'field_failure_trigger123') as $key) {
    if (is_array(acf_get_field($key))) {
        fwrite(STDERR, "Complex ACF partial-create compensation left residue: {$key}\n");
        exit(1);
    }
}

$groupPayload = array(
    'group' => array(
        'key' => 'group_landing123',
        'title' => 'Landing page fields',
        'location' => array(array(array('param' => 'post_type', 'operator' => '==', 'value' => 'page'))),
        'show_in_rest' => true,
    ),
    'fields' => array(
        array(
            'key' => 'field_landing_title123',
            'name' => 'landing_title',
            'label' => 'Landing title',
            'type' => 'text',
        ),
        array(
            'key' => 'field_landing_gallery123',
            'name' => 'landing_items',
            'label' => 'Landing items',
            'type' => 'repeater',
            'sub_fields' => array(
                array(
                    'key' => 'field_landing_image123',
                    'name' => 'landing_image',
                    'label' => 'Image',
                    'type' => 'image',
                    'return_format' => 'array',
                ),
            ),
        ),
    ),
);
$groupPreview = $adapter->createFieldGroup($groupPayload, array('dry_run' => true));
if (empty($groupPreview['would_create']) || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($groupPreview['_current_fingerprint'] ?? ''))) {
    fwrite(STDERR, "Complex ACF field-group dry-run failed.\n");
    exit(1);
}
$groupCreated = $adapter->createFieldGroup($groupPayload, array('dry_run' => false));
if (empty($groupCreated['created']) || ! is_array(acf_get_field_group('group_landing123'))) {
    fwrite(STDERR, "Complex ACF field-group creation failed.\n");
    exit(1);
}
$rollback = $groupCreated['_rollback']['payload'] ?? array();
$groupDeleted = $adapter->deleteFieldGroup($rollback, array('dry_run' => false));
if (empty($groupDeleted['deleted']) || is_array(acf_get_field_group('group_landing123'))) {
    fwrite(STDERR, "Complex ACF field-group rollback deletion failed.\n");
    exit(1);
}
if (empty($groupDeleted['_rollback']) || 'acf.schema.create_field_group' !== ($groupDeleted['_rollback']['action'] ?? '')) {
    fwrite(STDERR, "Complex ACF field-group restore rollback was not produced.\n");
    exit(1);
}

// Invalid schema input must be rejected without reflecting caller-controlled
// names, coercing arrays to strings, or changing persistent ACF state.
$opaque = 'qa_opaque_diagnostic_marker_4e62';
$baseField = array('key' => 'field_test_safe123', 'name' => 'safe_name', 'label' => 'Safe label', 'type' => 'text');
$schemaPayload = array('post_id' => 4470, 'group_key' => 'group_existing123', 'fields' => array($baseField));
$cases = array();
foreach (array('ensureFields', 'removeFields') as $operation) {
    $bad = $schemaPayload;
    $bad[$opaque] = true;
    $cases[] = array($operation, $bad, 'Unknown ACF');
}
foreach (array('createFieldGroup', 'deleteFieldGroup') as $operation) {
    $bad = $operation === 'createFieldGroup' ? $groupPayload : $rollback;
    $bad[$opaque] = true;
    $cases[] = array($operation, $bad, 'Unknown ACF');
}
foreach (array('ensureTextFields', 'ensureFields') as $operation) {
    $valid = $schemaPayload;
    if ('ensureTextFields' === $operation) {
        unset($valid['fields'][0]['type']);
    }
    foreach (array('key' => 'Invalid ACF field key', 'name' => 'Invalid ACF field name') as $field => $message) {
        foreach (array('<' . $opaque . '>', array($opaque)) as $input) {
            $bad = $valid;
            $bad['fields'][0][$field] = $input;
            $cases[] = array($operation, $bad, $message);
        }
    }
    $bad = $valid;
    $bad['fields'][0]['label'] = array($opaque);
    $cases[] = array($operation, $bad, 'Invalid ACF field label');
    $bad = $valid;
    $bad['fields'][0][$opaque] = true;
    $cases[] = array($operation, $bad, 'Unknown ACF');
    $bad = $valid;
    $bad['fields'] = array($opaque => 'invalid');
    $cases[] = array($operation, $bad, 'ACF schema field at index 0');
}
foreach (array($opaque, array($opaque)) as $input) {
    $bad = $schemaPayload;
    $bad['fields'][0]['type'] = $input;
    $cases[] = array('ensureFields', $bad, 'Unsupported ACF complex schema field type');
    $bad = $groupPayload;
    $bad['group']['key'] = $input;
    $cases[] = array('createFieldGroup', $bad, 'Invalid ACF field-group key');
    $bad = $groupPayload;
    $bad['group']['hide_on_screen'] = array($input);
    $cases[] = array('createFieldGroup', $bad, 'Invalid ACF hide_on_screen item');
    foreach (array('param' => 'Unsupported ACF location parameter', 'operator' => 'Unsupported ACF location operator') as $field => $message) {
        $bad = $groupPayload;
        $bad['group']['location'][0][0][$field] = $input;
        $cases[] = array('createFieldGroup', $bad, $message);
    }
}
$bad = $groupPayload;
$bad['group']['location'][0][0]['value'] = array($opaque);
$cases[] = array('createFieldGroup', $bad, 'Invalid ACF location value at group 0, rule 0');
$bad = $groupPayload;
$bad['group']['location'][0][0][$opaque] = true;
$cases[] = array('createFieldGroup', $bad, 'Unknown ACF location rule property');
$bad = $groupPayload;
$bad['group']['location'] = array($opaque => array());
$cases[] = array('createFieldGroup', $bad, 'ACF location group at index 0');
$bad = $groupPayload;
$bad['group'][$opaque] = true;
$cases[] = array('createFieldGroup', $bad, 'Unknown ACF field-group property');
$bad = $schemaPayload;
$bad['fields'][0]['wrapper'] = array($opaque => 'unknown');
$cases[] = array('ensureFields', $bad, 'Unknown ACF wrapper property');
$bad = $schemaPayload;
$bad['fields'][0]['conditional_logic'] = array(array(array('field' => 'field_test_safe123', 'operator' => '==', 'value' => 'yes', $opaque => true)));
$cases[] = array('ensureFields', $bad, 'Unknown ACF conditional_logic property');

$beforeInvalid = array($GLOBALS['acf_test_groups'], $GLOBALS['acf_test_fields'], $GLOBALS['acf_test_next_id']);
set_error_handler(static function (int $severity, string $message): bool {
    throw new ErrorException($message, 0, $severity);
});
try {
    foreach ($cases as $case) {
        list($operation, $payload, $expected) = $case;
        $rejected = false;
        try {
            $adapter->$operation($payload, array('dry_run' => false));
        } catch (RuntimeException $error) {
            if (false === strpos($error->getMessage(), $expected) || false !== strpos($error->getMessage(), $opaque)) {
                throw new LogicException('Unexpected or reflected ACF diagnostic for ' . $operation . ': ' . $expected);
            }
            $rejected = true;
        }
        if (!$rejected || $beforeInvalid !== array($GLOBALS['acf_test_groups'], $GLOBALS['acf_test_fields'], $GLOBALS['acf_test_next_id'])) {
            throw new LogicException('Invalid ACF request changed state: ' . $operation . ' / ' . $expected);
        }
    }
} finally {
    restore_error_handler();
}
echo "ACF malformed schema rejection cases: " . count($cases) . "\n";
// Provider failure for an arbitrary field selector must not reflect it.
$GLOBALS['acf_test_update_failure'] = true;
$untrustedField = '<qa_private_field_marker>';
$rejected = false;
try {
    $adapter->update(array('post_id'=>4470,'fields'=>array($untrustedField=>'fixture')),array('dry_run'=>false));
} catch (RuntimeException $error) {
    $rejected = $error->getMessage() === 'ACF update failed for a requested field.';
}
unset($GLOBALS['acf_test_update_failure']);
if (!$rejected) {
    throw new LogicException('Unsafe field selector was reflected or a failed update was accepted.');
}
echo "complex ACF schema runtime contract OK\n";
