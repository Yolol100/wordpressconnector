<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/elementor-plugin/NativeMcpProvider.php';
if (! defined('ELEMENTOR_PATH')) { define('ELEMENTOR_PATH', __DIR__ . '/fixtures/elementor-plugin/'); }

$test_options = array();

function wp_json_encode($value)
{
    return json_encode($value);
}

function current_user_can($capability): bool
{
    return 'manage_options' === (string) $capability;
}

function update_option($name, $value, $autoload = false): bool
{
    global $test_options;
    $test_options[(string) $name] = $value;
    return true;
}

function get_option($name, $default = false)
{
    global $test_options;
    return array_key_exists((string) $name, $test_options) ? $test_options[(string) $name] : $default;
}

function add_option($name, $value, $deprecated = '', $autoload = false): bool
{
    global $test_options;
    $name = (string) $name;
    if (array_key_exists($name, $test_options)) {
        return false;
    }
    $test_options[$name] = $value;
    return true;
}

function delete_option($name): bool
{
    global $test_options;
    unset($test_options[(string) $name]);
    return true;
}

function wp_cache_delete($key, $group = ''): bool
{
    return true;
}

function wp_generate_uuid4(): string
{
    static $counter = 0;
    ++$counter;
    return sprintf('00000000-0000-4000-8000-%012d', $counter);
}

function is_wp_error($value): bool
{
    return false;
}

final class AbilityMutationContractWpdb
{
    public string $options = 'wp_options';

    public function delete($table, $where, $format = null): int
    {
        global $test_options;
        $name = isset($where['option_name']) ? (string) $where['option_name'] : '';
        if ('' === $name || ! array_key_exists($name, $test_options)) {
            return 0;
        }
        unset($test_options[$name]);
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null): int
    {
        global $test_options;
        $name = isset($where['option_name']) ? (string) $where['option_name'] : '';
        $expected = $where['option_value'] ?? null;
        if ('' === $name || ! array_key_exists($name, $test_options) || $test_options[$name] !== $expected) {
            return 0;
        }
        $test_options[$name] = $data['option_value'] ?? null;
        return 1;
    }
}

$wpdb = new AbilityMutationContractWpdb();

final class AbilityMutationContractAbility
{
    public static int $executions = 0;
    protected $execute_callback;

    public function __construct()
    {
        $this->execute_callback = array(new \\Webactueel\\Tests\\Fixtures\\ElementorPlugin\\NativeMcpProvider(), 'execute');
    }

    public function get_meta(): array
    {
        return array(
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'annotations' => array(
                'readonly' => false,
                'destructive' => false,
                'idempotent' => false,
            ),
        );
    }

    public function get_label(): string { return 'Elementor test mutation'; }
    public function get_description(): string { return 'Test mutation with oversized output.'; }
    public function get_category(): string { return 'elementor'; }
    public function get_input_schema(): array { return array('type' => 'object'); }
    public function get_output_schema(): array { return array('type' => 'string'); }

    public function execute($input = null)
    {
        ++self::$executions;
        return str_repeat('x', 270000);
    }
}

$ability = new AbilityMutationContractAbility();

function wp_get_ability(string $name)
{
    global $ability;
    return 'elementor/manage-global-variable' === $name ? $ability : null;
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Request.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Result.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/SnapshotStore.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/ProcessedStore.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/AbilitiesAdapter.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Runner.php';

$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter = new \Webactueel\WordPressConnector\Adapters\AbilitiesAdapter();
$adapter->register($registry);

$runner = new \Webactueel\WordPressConnector\Runtime\Runner(
    $registry,
    new \Webactueel\WordPressConnector\Runtime\SnapshotStore(),
    new \Webactueel\WordPressConnector\Runtime\ProcessedStore()
);

$request = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'ability-retry-0001',
    'action' => 'wordpress.ability.execute',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(
        'name' => 'elementor/manage-global-variable',
        'input' => array('value' => '#ffffff'),
    ),
));

$first = $runner->run($request);
if (true !== ($first['ok'] ?? false)
    || true !== ($first['data']['execution_completed'] ?? false)
    || true !== ($first['data']['result_omitted'] ?? false)
    || 1 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "First completed Ability mutation did not return a bounded terminal success.\n");
    exit(1);
}

$second = $runner->run($request);
if (true !== ($second['ok'] ?? false)
    || true !== ($second['data']['idempotent_replay'] ?? false)
    || 1 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Retry after completed Ability mutation executed provider code again.\n");
    exit(1);
}

echo "abilities mutation idempotency contract OK\n";
