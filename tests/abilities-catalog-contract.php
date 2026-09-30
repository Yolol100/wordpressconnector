<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/AbilitiesAdapter.php';

final class ContractAbility
{
    private array $meta;
    public static int $executions = 0;
    public static bool $large_result = false;
    public function __construct(array $meta) { $this->meta = $meta; }
    public function get_meta(): array { return $this->meta; }
    public function get_label(): string { return 'Plugin ability'; }
    public function get_description(): string { return 'Contract test ability'; }
    public function get_category(): string { return 'test'; }
    public function get_input_schema(): array { return array('type' => 'object'); }
    public function get_output_schema(): array { return array('type' => 'object'); }
    public function execute($input = null) { self::$executions++; return self::$large_result ? str_repeat('x', 270000) : array('received' => $input, 'token' => 'secret-value', 'nested' => (object) array('api_key' => 'private-key', 'visible' => 'safe')); }
}

function wp_get_abilities(): array
{
    return array(
        'third-party-plugin/reindex-content' => new ContractAbility(array(
            'public' => true,
            'show_in_rest' => true,
            'annotations' => array('readonly' => true, 'destructive' => false),
        )),
        'third-party-plugin/private-operation' => new ContractAbility(array()),
        'third-party-plugin/mutating-operation' => new ContractAbility(array(
            'show_in_rest' => true,
            'annotations' => array('readonly' => false, 'destructive' => true),
        )),
        'invalid-name' => new ContractAbility(array('public' => true)),
    );
}

function wp_get_ability(string $name)
{
    $abilities = wp_get_abilities();
    return $abilities[$name] ?? null;
}

$adapter = new \Webactueel\WordPressConnector\Adapters\AbilitiesAdapter();
$catalog = $adapter->catalog(array(), array());
if (! isset($catalog['abilities']['third-party-plugin/reindex-content'])) {
    fwrite(STDERR, "Public third-party ability was not discovered.\n"); exit(1);
}
if (isset($catalog['abilities']['third-party-plugin/private-operation']) || isset($catalog['abilities']['invalid-name'])) {
    fwrite(STDERR, "Private or malformed ability leaked into the catalog.\n"); exit(1);
}
if (true !== $catalog['abilities']['third-party-plugin/reindex-content']['execution_exposed'] || false !== $catalog['abilities']['third-party-plugin/mutating-operation']['execution_exposed']) {
    fwrite(STDERR, "Ability catalog eligibility does not match the read action.\n"); exit(1);
}

$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter->register($registry);
$descriptor = $registry->descriptor('wordpress.ability.read');
if ($descriptor['mutation'] || ! $descriptor['sensitive'] || ! $descriptor['privileged'] || 'manage_options' !== $descriptor['capability']) {
    fwrite(STDERR, "Read-only ability access is missing its security gates.\n"); exit(1);
}
$result = $adapter->readAbility(array('name' => 'third-party-plugin/reindex-content', 'input' => array('limit' => 3)), array());
if (1 !== ContractAbility::$executions || 3 !== $result['result']['received']['limit']) {
    fwrite(STDERR, "Exposed read-only ability did not execute through its native API.\n"); exit(1);
}
$largeResultRejected = false;
ContractAbility::$large_result = true;
try { $adapter->readAbility(array('name' => 'third-party-plugin/reindex-content'), array()); } catch (RuntimeException $expected) { $largeResultRejected = true; }
ContractAbility::$large_result = false;
if (! $largeResultRejected || 2 !== ContractAbility::$executions) { fwrite(STDERR, "Oversized read-only ability result was not bounded.\n"); exit(1); }
if ('[redacted]' !== $result['result']['token'] || '[redacted]' !== $result['result']['nested']['api_key'] || 'safe' !== $result['result']['nested']['visible']) {
    fwrite(STDERR, "Sensitive values in read-only ability results were not recursively redacted.\n"); exit(1);
}
try {
    $adapter->readAbility(array('name' => 'third-party-plugin/mutating-operation'), array());
    fwrite(STDERR, "Mutating ability was executed by the read-only action.\n"); exit(1);
} catch (RuntimeException $expected) {
}
if (2 !== ContractAbility::$executions) {
    fwrite(STDERR, "Blocked mutating ability still executed.\n"); exit(1);
}
echo "abilities catalog contract OK\n";
