<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/AbilitiesAdapter.php';

final class ContractAbility
{
    private array $meta;
    public static int $executions = 0;
    public function __construct(array $meta) { $this->meta = $meta; }
    public function get_meta(): array { return $this->meta; }
    public function get_label(): string { return 'Plugin ability'; }
    public function get_description(): string { return 'Contract test ability'; }
    public function get_category(): string { return 'test'; }
    public function get_input_schema(): array { return array('type' => 'object'); }
    public function get_output_schema(): array { return array('type' => 'object'); }
    public function execute($input = null) { self::$executions++; return array('received' => $input); }
}

function wp_get_abilities(): array
{
    return array(
        'third-party-plugin/reindex-content' => new ContractAbility(array('public' => true, 'show_in_rest' => true)),
        'third-party-plugin/private-operation' => new ContractAbility(array()),
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
if (false !== $catalog['abilities']['third-party-plugin/reindex-content']['execution_exposed']) {
    fwrite(STDERR, "Ability catalog unexpectedly exposes execution.\n"); exit(1);
}
$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter->register($registry);
$descriptor = $registry->descriptor('wordpress.ability.run');
if (! $descriptor['mutation'] || ! $descriptor['sensitive'] || ! $descriptor['privileged'] || 'manage_options' !== $descriptor['capability']) {
    fwrite(STDERR, "Ability execution is missing its security gates.\n"); exit(1);
}
$preview = $adapter->run(array('name' => 'third-party-plugin/reindex-content', 'input' => array('limit' => 3)), array('dry_run' => true));
if (empty($preview['would_execute']) || 0 !== ContractAbility::$executions || empty($preview['_current_fingerprint'])) {
    fwrite(STDERR, "Ability dry-run executed code or omitted its fingerprint.\n"); exit(1);
}
$run = $adapter->run(array('name' => 'third-party-plugin/reindex-content', 'input' => array('limit' => 3)), array('dry_run' => false));
if (1 !== ContractAbility::$executions || 3 !== $run['result']['received']['limit']) {
    fwrite(STDERR, "Exposed ability did not execute through its native execute method.\n"); exit(1);
}
try {
    $adapter->run(array('name' => 'third-party-plugin/private-operation'), array('dry_run' => false));
    fwrite(STDERR, "Non-REST-exposed ability was executed.\n"); exit(1);
} catch (RuntimeException $expected) {
}
echo "abilities catalog contract OK\n";
