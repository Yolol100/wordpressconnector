<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

class WP_Post
{
    public int $ID;
    public string $post_type = 'portfolio';
    public string $post_status = 'publish';
    public string $post_password = '';
    public int $post_parent = 0;

    public function __construct(int $id, string $status = 'publish')
    {
        $this->ID = $id;
        $this->post_status = $status;
    }
}

function get_post($id)
{
    $id = (int) $id;
    if (99 === $id) return new WP_Post($id, 'draft');
    if ($id <= 0) return null;
    return new WP_Post($id);
}
function get_post_type_object($type)
{
    return (object) array('public' => true);
}
function current_user_can($capability, ...$args): bool
{
    return 'edit_post' === (string) $capability || 'manage_options' === (string) $capability;
}
function acf_get_field_groups($args = array()): array
{
    return array(array('key' => 'group_portfolio', 'ID' => 555));
}
function acf_get_field($key)
{
    $fields = array(
        'field_portfolio_stat_1_value' => array('key' => 'field_portfolio_stat_1_value', 'name' => 'portfolio_stat_1_value', 'type' => 'text', 'parent' => 555),
        'field_portfolio_stat_1_label' => array('key' => 'field_portfolio_stat_1_label', 'name' => 'portfolio_stat_1_label', 'type' => 'text', 'parent' => 555),
        'field_portfolio_nontext' => array('key' => 'field_portfolio_nontext', 'name' => 'portfolio_nontext', 'type' => 'textarea', 'parent' => 555),
        'field_portfolio_wrongparent' => array('key' => 'field_portfolio_wrongparent', 'name' => 'portfolio_wrongparent', 'type' => 'text', 'parent' => 999),
        'field_portfolio_secretname' => array('key' => 'field_portfolio_secretname', 'name' => 'api_key', 'type' => 'text', 'parent' => 555),
    );
    return $fields[(string) $key] ?? null;
}
function get_field($key, $postId, $format = false)
{
    return 'old-value';
}
function update_field($key, $value, $postId): bool
{
    return true;
}
function update_option($key, $value, $autoload = null): bool { return true; }
function get_option($key, $default = false) { return $default; }
function delete_option($key): bool { return true; }
function add_option($key, $value, $deprecated = '', $autoload = true): bool { return true; }
function wp_json_encode($value): string { return json_encode($value); }
function wp_cache_delete($key, $group = ''): bool { return true; }

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Request.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Result.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/SnapshotStore.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/ProcessedStore.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Runner.php';

use Webactueel\WordPressConnector\Runtime\ProcessedStore;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Runtime\SnapshotStore;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

Policy::setPublicRepositoryContext(true);
$registry = new Registry();
$registry->register('acf.update', static function (array $payload, array $context): array {
    $before = array();
    foreach ((array) ($payload['fields'] ?? array()) as $key => $value) {
        $before[(string) $key] = 'old-value';
    }
    return array(
        'target' => $payload['post_id'] ?? $payload['target'] ?? null,
        'before' => $before,
        'after' => $payload['fields'] ?? array(),
        '_current_fingerprint' => Fingerprint::make($before),
    );
}, array('mutation' => true, 'privileged' => true, 'description' => 'test ACF update'));
$runner = new Runner($registry, new SnapshotStore(), new ProcessedStore());

$descriptor = $registry->descriptor('acf.update');
try {
    Policy::assertActionAllowed($descriptor, true, false);
    fwrite(STDERR, "Raw privileged acf.update unexpectedly passed public policy.\n");
    exit(1);
} catch (RuntimeException $expected) {
    if (false === strpos($expected->getMessage(), 'public-repository mode')) {
        fwrite(STDERR, "Unexpected raw policy failure: {$expected->getMessage()}\n");
        exit(1);
    }
}

$base = array(
    'version' => 1,
    'request_id' => 'public-acf-boundary-0001',
    'action' => 'acf.update',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array(
        'post_id' => 4470,
        'fields' => array(
            'field_portfolio_stat_1_value' => '+18%',
            'field_portfolio_stat_1_label' => 'Adviesaanvragen',
        ),
    ),
);
$result = $runner->run(Request::fromArray($base));
if (empty($result['ok']) || ($result['action'] ?? '') !== 'acf.update') {
    fwrite(STDERR, 'Bounded public ACF update dry-run was rejected: ' . (string) ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}

$cases = array();
$case = $base; $case['request_id'] = 'public-acf-boundary-1001'; $case['payload']['fields'] = array('portfolio_stat_1_value' => '+18%'); $cases['field name instead of key'] = $case;
$case = $base; $case['request_id'] = 'public-acf-boundary-1002'; $case['payload']['fields'] = array('field_portfolio_nontext' => 'text'); $cases['non-text field'] = $case;
$case = $base; $case['request_id'] = 'public-acf-boundary-1003'; $case['payload']['fields'] = array('field_portfolio_wrongparent' => 'text'); $cases['field outside target group'] = $case;
$case = $base; $case['request_id'] = 'public-acf-boundary-1004'; $case['payload']['post_id'] = 99; $cases['non-public post'] = $case;
$case = $base; $case['request_id'] = 'public-acf-boundary-1005'; $case['payload']['fields'] = array('field_portfolio_stat_1_value' => array('not' => 'text')); $cases['non-string value'] = $case;
$case = $base; $case['request_id'] = 'public-acf-boundary-1006'; $case['payload'] = array('target' => 'options', 'fields' => array('field_portfolio_stat_1_value' => '+18%')); $cases['options target'] = $case;
$case = $base; $case['request_id'] = 'public-acf-boundary-1007'; $case['payload']['fields'] = array('field_portfolio_secretname' => 'value'); $cases['secret-like field name'] = $case;
$case = $base; $case['request_id'] = 'public-acf-boundary-1008'; $case['payload']['unexpected'] = true; $cases['unknown payload key'] = $case;

foreach ($cases as $label => $request) {
    $result = $runner->run(Request::fromArray($request));
    if (! empty($result['ok'])) {
        fwrite(STDERR, "Forbidden public ACF boundary case passed: {$label}\n");
        exit(1);
    }
}

$source = (string) file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Runner.php');
$snapshotSource = (string) file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/SnapshotStore.php');
foreach (array('assertPublicAcfUpdatePayload', 'assertPublicRollbackRecord', 'publicRepositoryContext') as $needle) {
    if (false === strpos($source, $needle)) {
        fwrite(STDERR, "Runner is missing guarded public ACF/rollback boundary token: {$needle}\n");
        exit(1);
    }
}
foreach (array('source_action', 'public_repository_mode', 'getRecord') as $needle) {
    if (false === strpos($snapshotSource, $needle)) {
        fwrite(STDERR, "SnapshotStore is missing rollback provenance token: {$needle}\n");
        exit(1);
    }
}

echo "Public ACF update boundary contract OK.\n";
