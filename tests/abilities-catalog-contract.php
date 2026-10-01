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
    public static bool $wide_result = false;
    public static bool $repeated_result = false;
    public static bool $deep_result = false;
    public static bool $throw_result = false;
    public function __construct(array $meta) { $this->meta = $meta; }
    public function get_meta(): array { return $this->meta; }
    public function get_label(): string { return 'Plugin ability'; }
    public function get_description(): string { return 'Contract test ability'; }
    public function get_category(): string { return 'test'; }
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

function wp_get_abilities(): array
{
    $abilities = array(
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
if (10 !== $catalog['per_page'] || 1 !== $catalog['page'] || 14 !== $catalog['total'] || 2 !== $catalog['pages'] || 10 !== count($catalog['abilities'])) {
    fwrite(STDERR, "Ability catalog pagination bounds failed.\n"); exit(1);
}
$catalogPageTwo = $adapter->catalog(array('per_page' => 10, 'page' => 2), array());
if (4 !== count($catalogPageTwo['abilities']) || 2 !== $catalogPageTwo['page']) {
    fwrite(STDERR, "Ability catalog second page failed.\n"); exit(1);
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
$repeatedResult = null;
ContractAbility::$repeated_result = true;
$repeatedResult = $adapter->readAbility(array('name' => 'third-party-plugin/reindex-content'), array());
ContractAbility::$repeated_result = false;
if ('safe' !== ($repeatedResult['result']['summary']['visible'] ?? null) || 'safe' !== ($repeatedResult['result']['details']['visible'] ?? null)) {
    fwrite(STDERR, "Repeated non-circular ability objects were treated as circular.\n"); exit(1);
}
$largeResultRejected = false;
ContractAbility::$large_result = true;
try { $adapter->readAbility(array('name' => 'third-party-plugin/reindex-content'), array()); } catch (RuntimeException $expected) { $largeResultRejected = true; }
ContractAbility::$large_result = false;
if (! $largeResultRejected || 3 !== ContractAbility::$executions) { fwrite(STDERR, "Oversized read-only ability result was not bounded.\n"); exit(1); }
$wideResultRejected = false;
ContractAbility::$wide_result = true;
try { $adapter->readAbility(array('name' => 'third-party-plugin/reindex-content'), array()); } catch (RuntimeException $expected) { $wideResultRejected = 'WordPress Ability result exceeds the traversal limit.' === $expected->getMessage(); }
ContractAbility::$wide_result = false;
if (! $wideResultRejected || 4 !== ContractAbility::$executions) { fwrite(STDERR, "High-node-count read-only ability result was not bounded.\n"); exit(1); }
$deepResultRejected = false;
ContractAbility::$deep_result = true;
try { $adapter->readAbility(array('name' => 'third-party-plugin/reindex-content'), array()); } catch (RuntimeException $expected) { $deepResultRejected = 'WordPress Ability result exceeds the traversal limit.' === $expected->getMessage(); }
ContractAbility::$deep_result = false;
if (! $deepResultRejected || 5 !== ContractAbility::$executions) { fwrite(STDERR, "Deep read-only ability result was not bounded.\n"); exit(1); }
$exceptionRejected = false;
ContractAbility::$throw_result = true;
try {
    $adapter->readAbility(array('name' => 'third-party-plugin/reindex-content'), array());
} catch (RuntimeException $expected) {
    $exceptionRejected = 'WordPress Ability failed.' === $expected->getMessage();
}
ContractAbility::$throw_result = false;
if (! $exceptionRejected || 6 !== ContractAbility::$executions) { fwrite(STDERR, "Ability exceptions were not replaced with a generic failure.\n"); exit(1); }
if ('[redacted]' !== $result['result']['token'] || '[redacted]' !== $result['result']['nested']['api_key'] || 'safe' !== $result['result']['nested']['visible']) {
    fwrite(STDERR, "Sensitive values in read-only ability results were not recursively redacted.\n"); exit(1);
}
try {
    $adapter->readAbility(array('name' => 'third-party-plugin/mutating-operation'), array());
    fwrite(STDERR, "Mutating ability was executed by the read-only action.\n"); exit(1);
} catch (RuntimeException $expected) {
}
if (6 !== ContractAbility::$executions) {
    fwrite(STDERR, "Blocked mutating ability still executed.\n"); exit(1);
}
echo "abilities catalog contract OK\n";
