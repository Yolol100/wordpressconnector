<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/elementor/modules/mcp/abilities/native-ability-fixture.php';
require_once __DIR__ . '/fixtures/elementor-pro/modules/mcp/abilities/native-pro-ability-fixture.php';
require_once __DIR__ . '/fixtures/untrusted/elementor-spoof-ability-fixture.php';

if (! defined('WP_PLUGIN_DIR')) define('WP_PLUGIN_DIR', __DIR__ . '/fixtures');

function get_option($name, $default = false)
{
    if ('active_plugins' === $name) {
        return array('elementor/elementor.php', 'elementor-pro/elementor-pro.php');
    }
    return $default;
}

function get_site_option($name, $default = false)
{
    return $default;
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/AbilitiesAdapter.php';

final class ContractAbility
{
    private array $meta;
    protected $execute_callback;
    public static int $executions = 0;
    public static bool $large_result = false;
    public static bool $wide_result = false;
    public static bool $repeated_result = false;
    public static bool $deep_result = false;
    public static bool $throw_result = false;
    public function __construct(array $meta, $executeCallback = null) {
        $this->meta = $meta;
        $this->execute_callback = $executeCallback;
    }
    public function get_meta(): array { return $this->meta; }
    public function get_label(): string { return 'Plugin ability'; }
    public function get_description(): string { return 'Contract test ability'; }
    public function get_category(): string { return isset($this->meta['category']) ? (string) $this->meta['category'] : 'test'; }
    public function get_input_schema(): array {
        if (! empty($this->meta['large_schema'])) return array('type' => 'object', 'description' => str_repeat('x', 9000));
        return array('type' => 'object');
    }
    public function get_output_schema(): array { return array('type' => 'object'); }
    public function execute($input = null) {
        self::$executions++;
        if (self::$throw_result) throw new RuntimeException('api_key=private-key upstream failure');
        if (self::$large_result) return str_repeat('x', 270000);
        if (self::$wide_result) return array_fill(0, 10001, 1);
        if (self::$deep_result) {
            $value = 'leaf';
            for ($depth = 0; $depth < 22; $depth++) $value = array('level' => $value);
            return $value;
        }
        if (self::$repeated_result) {
            $dto = (object) array('visible' => 'safe');
            return array('summary' => $dto, 'details' => $dto);
        }
        return array('received' => $input, 'token' => 'secret-value', 'nested' => (object) array('api_key' => 'private-key', 'visible' => 'safe'));
    }
}

$ability_catalog_overflow = false;
function wp_get_abilities(): array
{
    global $ability_catalog_overflow;
    if ($ability_catalog_overflow) return array_fill(0, 10001, null);
    $abilities = array(
        'third-party-plugin/reindex-content' => new ContractAbility(array(
            'public' => true,
            'show_in_rest' => true,
            'annotations' => array('readonly' => true, 'destructive' => false),
        )),
        'third-party-plugin/private-operation' => new ContractAbility(array()),
        'third-party-plugin/mutating-operation' => new ContractAbility(array(
            'show_in_rest' => true,
            'mcp' => array('public' => false),
            'annotations' => array('readonly' => false, 'destructive' => true),
        )),
        'third-party-plugin/public-mutating-operation' => new ContractAbility(array(
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'category' => 'plugin',
            'annotations' => array('readonly' => false, 'destructive' => true),
        )),
        'elementor/spoofed-write' => new ContractAbility(array(
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'category' => 'elementor',
            'annotations' => array('readonly' => false, 'destructive' => true),
        ), array(new \ContractSpoof\Elementor_Spoof_Ability('elementor/spoofed-write'), 'execute_guarded')),
        'elementor/disabled-write' => new ContractAbility(array(
            'show_in_rest' => true,
            'mcp' => array('public' => false),
            'category' => 'elementor',
            'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
        ), array(new \Elementor\Modules\Mcp\Abilities\Contract_Native_Ability('elementor/disabled-write'), 'execute_guarded')),
        'elementor/list-posts' => new ContractAbility(array(
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'category' => 'elementor',
            'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
        )),
        'elementor/manage-global-variable' => new ContractAbility(array(
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'category' => 'elementor',
            'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
        ), array(new \Elementor\Modules\Mcp\Abilities\Contract_Native_Ability('elementor/manage-global-variable'), 'execute_guarded')),
        'elementor/publish-document' => new ContractAbility(array(
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'category' => 'elementor',
            'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => true),
        ), array(new \Elementor\Modules\Mcp\Abilities\Contract_Native_Ability('elementor/publish-document'), 'execute_guarded')),
        'elementor-pro/theme-builder-write' => new ContractAbility(array(
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'category' => 'elementor-pro',
            'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => false),
        ), array(new \ElementorPro\Modules\Mcp\Abilities\Contract_Pro_Ability('elementor-pro/theme-builder-write'), 'execute_guarded')),
        'elementor/mismatched-provider' => new ContractAbility(array(
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'category' => 'elementor',
            'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
        ), array(new \Elementor\Modules\Mcp\Abilities\Contract_Native_Ability('elementor/different-id'), 'execute_guarded')),
        'third-party-plugin/ambiguous-readonly' => new ContractAbility(array(
            'show_in_rest' => true,
            'annotations' => array('readonly' => true),
        )),
        'invalid-name' => new ContractAbility(array('public' => true)),
    );
    for ($i = 1; $i <= 12; $i++) {
        $abilities[sprintf('zzz-plugin/ability-%02d', $i)] = new ContractAbility(array(
            'show_in_rest' => true,
            'large_schema' => 1 === $i,
            'annotations' => array('readonly' => true, 'destructive' => false),
        ));
    }
    return $abilities;
}

function wp_get_ability(string $name)
{
    $abilities = wp_get_abilities();
    return $abilities[$name] ?? null;
}

$adapter = new \Webactueel\WordPressConnector\Adapters\AbilitiesAdapter();
$catalog = $adapter->catalog(array('per_page' => 50, 'page' => 1), array());
if (10 !== $catalog['per_page'] || 1 !== $catalog['page'] || 21 !== $catalog['total'] || 2 !== $catalog['pages'] || 10 !== count($catalog['abilities'])) {
    fwrite(STDERR, "Ability catalog pagination bounds failed.\n"); exit(1);
}
$catalogPageTwo = $adapter->catalog(array('per_page' => 10, 'page' => 2), array());
if (10 !== count($catalogPageTwo['abilities']) || 2 !== $catalogPageTwo['page']) {
    fwrite(STDERR, "Ability catalog second page failed.\n"); exit(1);
}
$elementorCatalog = $adapter->catalog(array('namespace' => 'elementor', 'per_page' => 10, 'page' => 1), array());
if (5 !== $elementorCatalog['total'] || 'elementor' !== $elementorCatalog['namespace']
    || ! isset($elementorCatalog['abilities']['elementor/list-posts'])
    || ! isset($elementorCatalog['abilities']['elementor/manage-global-variable'])
    || ! isset($elementorCatalog['abilities']['elementor/publish-document'])
    || ! isset($elementorCatalog['abilities']['elementor/spoofed-write'])
    || ! isset($elementorCatalog['abilities']['elementor/mismatched-provider'])) {
    fwrite(STDERR, "Ability namespace filtering failed.\n"); exit(1);
}
try {
    $adapter->catalog(array('namespace' => 'Bad/Namespace'), array());
    fwrite(STDERR, "Malformed Ability namespace was accepted.\n"); exit(1);
} catch (RuntimeException $expected) {
    if ('namespace must be a valid Ability namespace.' !== $expected->getMessage()) throw $expected;
}
$catalogEncoded = json_encode($catalog);
if (! is_string($catalogEncoded) || strlen($catalogEncoded) > 262144) {
    fwrite(STDERR, "Ability catalog page exceeded its transport budget.\n"); exit(1);
}
if (true !== ($catalog['abilities']['zzz-plugin/ability-01']['input_schema_omitted'] ?? false) || null !== ($catalog['abilities']['zzz-plugin/ability-01']['input_schema'] ?? null)) {
    fwrite(STDERR, "Oversized Ability schema was not omitted safely.\n"); exit(1);
}
try {
    $adapter->catalog(array('per_page' => '10junk'), array());
    fwrite(STDERR, "Malformed Ability catalog pagination was accepted.\n"); exit(1);
} catch (RuntimeException $expected) {
    if ('per_page must be a positive integer.' !== $expected->getMessage()) throw $expected;
}
$ability_catalog_overflow = true;
try {
    $adapter->catalog(array(), array());
    fwrite(STDERR, "Oversized Ability registry was accepted.\n"); exit(1);
} catch (RuntimeException $expected) {
    if ('WordPress Ability catalog exceeds the discovery limit.' !== $expected->getMessage()) throw $expected;
}
$ability_catalog_overflow = false;
if (! isset($catalog['abilities']['third-party-plugin/reindex-content'])) {
    fwrite(STDERR, "Public third-party ability was not discovered.\n"); exit(1);
}
if (isset($catalog['abilities']['third-party-plugin/private-operation'])
    || isset($catalog['abilities']['third-party-plugin/mutating-operation'])
    || isset($catalog['abilities']['elementor/disabled-write'])
    || isset($catalog['abilities']['invalid-name'])) {
    fwrite(STDERR, "Private, MCP-disabled, or malformed ability leaked into the catalog.\n"); exit(1);
}
if (true !== $catalog['abilities']['third-party-plugin/reindex-content']['execution_exposed']
    || true !== $catalog['abilities']['elementor/list-posts']['execution_exposed']
    || false !== $catalog['abilities']['third-party-plugin/ambiguous-readonly']['execution_exposed']) {
    fwrite(STDERR, "Ability catalog eligibility does not match the read action.\n"); exit(1);
}
if (false !== $catalog['abilities']['third-party-plugin/public-mutating-operation']['mutation_execution_exposed']
    || false !== $catalog['abilities']['elementor/spoofed-write']['mutation_execution_exposed']
    || false !== $catalog['abilities']['elementor/mismatched-provider']['mutation_execution_exposed']
    || true !== $catalog['abilities']['elementor/manage-global-variable']['mutation_execution_exposed']
    || true !== $catalog['abilities']['elementor/publish-document']['mutation_execution_exposed']
    || true !== $catalog['abilities']['elementor-pro/theme-builder-write']['mutation_execution_exposed']
    || 'wordpress.ability.execute' !== $catalog['abilities']['elementor/manage-global-variable']['connector_action']
    || 'wordpress.ability.execute' !== $catalog['abilities']['elementor-pro/theme-builder-write']['connector_action']) {
    fwrite(STDERR, "Ability mutation exposure or MCP enablement contract failed.\n"); exit(1);
}

$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter->register($registry);
$descriptor = $registry->descriptor('wordpress.ability.read');
if ($descriptor['mutation'] || ! $descriptor['sensitive'] || ! $descriptor['privileged'] || 'manage_options' !== $descriptor['capability']) {
    fwrite(STDERR, "Read-only ability access is missing its security gates.\n"); exit(1);
}
$mutationDescriptor = $registry->descriptor('wordpress.ability.execute');
if (! $mutationDescriptor['mutation'] || ! $mutationDescriptor['privileged'] || $mutationDescriptor['sensitive'] || 'manage_options' !== $mutationDescriptor['capability']) {
    fwrite(STDERR, "Mutating ability access is missing its security gates.\n"); exit(1);
}
$result = $adapter->readAbility(array('name' => 'elementor/list-posts', 'input' => array('limit' => 3)), array());
if (1 !== ContractAbility::$executions || 3 !== $result['result']['received']['limit']) {
    fwrite(STDERR, "Exposed read-only ability did not execute through its native API.\n"); exit(1);
}
$repeatedResult = null;
ContractAbility::$repeated_result = true;
$repeatedResult = $adapter->readAbility(array('name' => 'elementor/list-posts'), array());
ContractAbility::$repeated_result = false;
if ('safe' !== ($repeatedResult['result']['summary']['visible'] ?? null) || 'safe' !== ($repeatedResult['result']['details']['visible'] ?? null)) {
    fwrite(STDERR, "Repeated non-circular ability objects were treated as circular.\n"); exit(1);
}
$largeResultRejected = false;
ContractAbility::$large_result = true;
try { $adapter->readAbility(array('name' => 'elementor/list-posts'), array()); } catch (RuntimeException $expected) { $largeResultRejected = true; }
ContractAbility::$large_result = false;
if (! $largeResultRejected || 3 !== ContractAbility::$executions) { fwrite(STDERR, "Oversized read-only ability result was not bounded.\n"); exit(1); }
$wideResultRejected = false;
ContractAbility::$wide_result = true;
try { $adapter->readAbility(array('name' => 'elementor/list-posts'), array()); } catch (RuntimeException $expected) { $wideResultRejected = 'WordPress Ability result exceeds the traversal limit.' === $expected->getMessage(); }
ContractAbility::$wide_result = false;
if (! $wideResultRejected || 4 !== ContractAbility::$executions) { fwrite(STDERR, "High-node-count read-only ability result was not bounded.\n"); exit(1); }
$deepResultRejected = false;
ContractAbility::$deep_result = true;
try { $adapter->readAbility(array('name' => 'elementor/list-posts'), array()); } catch (RuntimeException $expected) { $deepResultRejected = 'WordPress Ability result exceeds the traversal limit.' === $expected->getMessage(); }
ContractAbility::$deep_result = false;
if (! $deepResultRejected || 5 !== ContractAbility::$executions) { fwrite(STDERR, "Deep read-only ability result was not bounded.\n"); exit(1); }
$exceptionRejected = false;
ContractAbility::$throw_result = true;
try {
    $adapter->readAbility(array('name' => 'elementor/list-posts'), array());
} catch (RuntimeException $expected) {
    $exceptionRejected = 'WordPress Ability failed.' === $expected->getMessage();
}
ContractAbility::$throw_result = false;
if (! $exceptionRejected || 6 !== ContractAbility::$executions) { fwrite(STDERR, "Ability exceptions were not replaced with a generic failure.\n"); exit(1); }
if ('[redacted]' !== $result['result']['token'] || '[redacted]' !== $result['result']['nested']['api_key'] || 'safe' !== $result['result']['nested']['visible']) {
    fwrite(STDERR, "Sensitive values in read-only ability results were not recursively redacted.\n"); exit(1);
}
$thirdPartyRead = $adapter->readAbility(array('name' => 'third-party-plugin/reindex-content', 'input' => array('limit' => 2)), array());
if (7 !== ContractAbility::$executions || 2 !== $thirdPartyRead['result']['received']['limit']) {
    fwrite(STDERR, "Existing generic read-only Ability compatibility regressed.\n"); exit(1);
}
try {
    $adapter->readAbility(array('name' => 'third-party-plugin/mutating-operation'), array());
    fwrite(STDERR, "Mutating ability was executed by the read-only action.\n"); exit(1);
} catch (RuntimeException $expected) {
}
if (7 !== ContractAbility::$executions) {
    fwrite(STDERR, "Blocked mutating ability still executed.\n"); exit(1);
}
try {
    $adapter->readAbility(array('name' => 'third-party-plugin/ambiguous-readonly'), array());
    fwrite(STDERR, "Ability without explicit destructive=false was executed.\n"); exit(1);
} catch (RuntimeException $expected) {
}
if (7 !== ContractAbility::$executions) {
    fwrite(STDERR, "Ambiguous Ability metadata still reached execute().\n"); exit(1);
}

$dryRun = $adapter->executeAbility(
    array('name' => 'elementor/manage-global-variable', 'input' => array('value' => '#ffffff')),
    array('dry_run' => true)
);
if (7 !== ContractAbility::$executions || empty($dryRun['would_execute']) || false !== $dryRun['rollback_supported']) {
    fwrite(STDERR, "Mutating Ability dry-run executed provider code or returned unsafe metadata.\n"); exit(1);
}

$mutationResult = $adapter->executeAbility(
    array('name' => 'elementor/manage-global-variable', 'input' => array('value' => '#ffffff')),
    array('dry_run' => false)
);
if (8 !== ContractAbility::$executions || '#ffffff' !== $mutationResult['result']['received']['value']
    || '[redacted]' !== $mutationResult['result']['token'] || false !== $mutationResult['rollback_supported']) {
    fwrite(STDERR, "Mutating Ability execution or result hardening failed.\n"); exit(1);
}

$destructiveResult = $adapter->executeAbility(array('name' => 'elementor/publish-document'), array('dry_run' => false));
if (9 !== ContractAbility::$executions || true !== $destructiveResult['annotations']['destructive']) {
    fwrite(STDERR, "Destructive annotated Ability execution failed.\n"); exit(1);
}

$proResult = $adapter->executeAbility(array('name' => 'elementor-pro/theme-builder-write'), array('dry_run' => false));
if (10 !== ContractAbility::$executions || true !== $proResult['annotations']['destructive']) {
    fwrite(STDERR, "Trusted Elementor Pro Ability execution failed.\n"); exit(1);
}

ContractAbility::$large_result = true;
$terminalLargeResult = $adapter->executeAbility(array('name' => 'elementor/manage-global-variable'), array('dry_run' => false));
ContractAbility::$large_result = false;
if (11 !== ContractAbility::$executions
    || true !== ($terminalLargeResult['execution_completed'] ?? false)
    || true !== ($terminalLargeResult['result_omitted'] ?? false)
    || ! array_key_exists('result', $terminalLargeResult)
    || null !== $terminalLargeResult['result']
    || false !== $terminalLargeResult['rollback_supported']) {
    fwrite(STDERR, "Completed mutating Ability with oversized output was not converted to a terminal bounded result.\n"); exit(1);
}

ContractAbility::$deep_result = true;
$terminalDeepResult = $adapter->executeAbility(array('name' => 'elementor/manage-global-variable'), array('dry_run' => false));
ContractAbility::$deep_result = false;
if (12 !== ContractAbility::$executions
    || true !== ($terminalDeepResult['execution_completed'] ?? false)
    || true !== ($terminalDeepResult['result_omitted'] ?? false)
    || ! array_key_exists('result', $terminalDeepResult)
    || null !== $terminalDeepResult['result']) {
    fwrite(STDERR, "Completed mutating Ability with deep output was not converted to a terminal bounded result.\n"); exit(1);
}

foreach (array('third-party-plugin/mutating-operation', 'third-party-plugin/public-mutating-operation', 'elementor/disabled-write', 'elementor/spoofed-write', 'elementor/mismatched-provider', 'third-party-plugin/ambiguous-readonly', 'third-party-plugin/reindex-content') as $blockedAbility) {
    try {
        $adapter->executeAbility(array('name' => $blockedAbility), array('dry_run' => false));
        fwrite(STDERR, "Ineligible Ability was executed by the mutation route: {$blockedAbility}\n"); exit(1);
    } catch (RuntimeException $expected) {
    }
}
if (12 !== ContractAbility::$executions) {
    fwrite(STDERR, "Blocked mutation Ability still reached execute().\n"); exit(1);
}

echo "abilities catalog contract OK\n";
