<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/AbilitiesAdapter.php';

final class ContractAbility
{
    private array $meta;
    public function __construct(array $meta) { $this->meta = $meta; }
    public function get_meta(): array { return $this->meta; }
    public function get_label(): string { return 'Plugin ability'; }
    public function get_description(): string { return 'Contract test ability'; }
    public function get_category(): string { return 'test'; }
    public function get_input_schema(): array { return array('type' => 'object'); }
    public function get_output_schema(): array { return array('type' => 'object'); }
}

function wp_get_abilities(): array
{
    return array(
        'third-party-plugin/reindex-content' => new ContractAbility(array('public' => true)),
        'third-party-plugin/private-operation' => new ContractAbility(array()),
        'invalid-name' => new ContractAbility(array('public' => true)),
    );
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
echo "abilities catalog contract OK\n";
