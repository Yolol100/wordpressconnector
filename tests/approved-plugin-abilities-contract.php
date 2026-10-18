<?php
declare(strict_types=1);

if (! defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (! defined('WP_PLUGIN_DIR')) define('WP_PLUGIN_DIR', __DIR__ . '/fixtures');

require_once __DIR__ . '/fixtures/approved-abilities/approved-abilities.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/AbilitiesAdapter.php';

$GLOBALS['approved_fixture_active'] = true;
$GLOBALS['approved_fixture_permission'] = true;
function get_option($key, $default = false) {
    if ('active_plugins' === $key) {
        return $GLOBALS['approved_fixture_active']
            ? array('approved-abilities/approved-abilities.php') : array();
    }
    return $default;
}
function get_site_option($key, $default = false) { return $default; }
function current_user_can($capability): bool { return 'manage_options' === $capability; }

final class ApprovedNativeAbility
{
    protected $execute_callback;
    public array $meta;
    public function __construct() {
        $this->execute_callback = array(new \ConnectorApprovedAbilitiesFixture\Provider(), 'execute');
        $this->meta = array('mcp' => array('public' => true),
            'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false));
    }
    public function setCallback($callback): void { $this->execute_callback = $callback; }
    public function get_meta(): array { return $this->meta; }
    public function get_label(): string { return 'Safe plugin action'; }
    public function get_description(): string { return 'Approved fixture'; }
    public function get_category(): string { return 'fixture'; }
    public function get_input_schema(): array { return array('type' => 'object'); }
    public function get_output_schema(): array { return array('type' => 'object'); }
    public function execute($input = null) {
        if (! $GLOBALS['approved_fixture_permission']) {
            throw new RuntimeException('Native permission denied');
        }
        return call_user_func($this->execute_callback, $input);
    }
}
$GLOBALS['approved_fixture_ability'] = new ApprovedNativeAbility();
function wp_get_abilities(): array {
    return array('approved-abilities/safe-update' => $GLOBALS['approved_fixture_ability']);
}
function wp_get_ability(string $name) {
    return wp_get_abilities()[$name] ?? null;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\AbilitiesAdapter();
$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter->register($registry);
$descriptor = $registry->descriptor('wordpress.ability.execute_approved');
if (empty($descriptor['mutation']) || empty($descriptor['sensitive'])
    || empty($descriptor['privileged']) || ($descriptor['capability'] ?? '') !== 'manage_options') {
    throw new RuntimeException('New action lacks private sensitive mutation gates.');
}
$payload = array('name' => 'approved-abilities/safe-update', 'input' => array('value' => 'safe'));
$mustDeny = static function () use ($adapter, $payload): void {
    try { $adapter->executeApprovedAbility($payload, array('dry_run' => true)); }
    catch (RuntimeException $expected) { return; }
    throw new RuntimeException('Unapproved native third-party Ability became callable.');
};
$mustDeny();
$discovery = $adapter->catalog(array('namespace' => 'approved-abilities'), array());
if (($discovery['abilities']['approved-abilities/safe-update']['mutation_execution_exposed'] ?? null) !== false) {
    throw new RuntimeException('Third-party ability available without operator approval.');
}

// A server owner, not a request, explicitly binds the Ability to its exact active plugin.
define('WPCONNECTOR_ALLOW_APPROVED_ABILITY_WRITES', true);
define('WPCONNECTOR_APPROVED_ABILITY_PROVIDERS',
    '{"approved-abilities/safe-update":"approved-abilities/approved-abilities.php"}');
$discovery = $adapter->catalog(array('namespace' => 'approved-abilities'), array());
$item = $discovery['abilities']['approved-abilities/safe-update'] ?? array();
if (($item['mutation_execution_exposed'] ?? null) !== true
    || ($item['connector_action'] ?? '') !== 'wordpress.ability.execute_approved') {
    throw new RuntimeException('Explicit active provider approval was not discovered.');
}
$preview = $adapter->executeApprovedAbility($payload, array('dry_run' => true));
if (empty($preview['would_execute']) || \ConnectorApprovedAbilitiesFixture\Provider::$calls !== 0) {
    throw new RuntimeException('Approved third-party dry run unexpectedly executed code.');
}
$done = $adapter->executeApprovedAbility($payload, array('dry_run' => false));
if (empty($done['execution_completed'])
    || \ConnectorApprovedAbilitiesFixture\Provider::$calls !== 1
    || ($done['result']['api_key'] ?? '') !== '[redacted]') {
    throw new RuntimeException('Approved Ability did not execute exactly once and redact secrets.');
}

// Native permission callbacks still control execution even after approval.
$GLOBALS['approved_fixture_permission'] = false;
try { $adapter->executeApprovedAbility($payload, array('dry_run' => false)); }
catch (RuntimeException $expected) {}
if (\ConnectorApprovedAbilitiesFixture\Provider::$calls !== 1) {
    throw new RuntimeException('Rejected native permission still executed Ability.');
}
$GLOBALS['approved_fixture_permission'] = true;

$GLOBALS['approved_fixture_active'] = false;
$mustDeny();
$GLOBALS['approved_fixture_active'] = true;
$GLOBALS['approved_fixture_ability']->meta['mcp']['public'] = false;
$mustDeny();
$GLOBALS['approved_fixture_ability']->meta['mcp']['public'] = true;
$GLOBALS['approved_fixture_ability']->meta['annotations']['destructive'] = true;
$mustDeny();
$GLOBALS['approved_fixture_ability']->meta['annotations']['destructive'] = false;
$GLOBALS['approved_fixture_ability']->setCallback(static function () { return array('invalid' => true); });
$mustDeny(); // Callback source points to this test file, not approved plugin.
echo "Third-party native Ability allowlist and provenance runtime contract OK\n";
