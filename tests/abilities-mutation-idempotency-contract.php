<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/elementor/modules/mcp/abilities/native-ability-fixture.php';
if (! defined('WP_PLUGIN_DIR')) { define('WP_PLUGIN_DIR', __DIR__ . '/fixtures'); }

$test_options = array();
$test_actions = array();
$fail_processed_add = false;
$fail_processed_update = false;

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
    global $test_options, $fail_processed_update;
    $name = (string) $name;
    if ($fail_processed_update
        && 0 === strpos($name, 'wpconnector_processed_')
        && is_array($value)
        && 'completed' === ($value['state'] ?? null)) {
        return false;
    }
    $test_options[$name] = $value;
    return true;
}

function get_option($name, $default = false)
{
    global $test_options;
    if ('active_plugins' === (string) $name) {
        return array('elementor/elementor.php');
    }
    return array_key_exists((string) $name, $test_options) ? $test_options[(string) $name] : $default;
}

function get_site_option($name, $default = false)
{
    return $default;
}

function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1): void
{
    global $test_actions;
    $test_actions[(string) $hook][] = array($callback, (int) $priority, (int) $acceptedArgs);
}

function remove_action($hook, $callback, $priority = 10): bool
{
    global $test_actions;
    $hook = (string) $hook;
    if (empty($test_actions[$hook])) return false;
    foreach ($test_actions[$hook] as $index => $entry) {
        if ($entry[0] === $callback && $entry[1] === (int) $priority) {
            unset($test_actions[$hook][$index]);
            return true;
        }
    }
    return false;
}

function do_action($hook, ...$args): void
{
    global $test_actions;
    foreach ($test_actions[(string) $hook] ?? array() as $entry) {
        call_user_func_array($entry[0], array_slice($args, 0, $entry[2]));
    }
}

function add_option($name, $value, $deprecated = '', $autoload = false): bool
{
    global $test_options, $fail_processed_add;
    $name = (string) $name;
    if ($fail_processed_add && 0 === strpos($name, 'wpconnector_processed_')) {
        return false;
    }
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

final class WP_Error
{
    private string $code;
    public function __construct(string $code) { $this->code = $code; }
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error($value): bool
{
    return $value instanceof WP_Error;
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
    public static bool $provider_error = false;
    public static bool $provider_throw = false;
    public static bool $pre_execution_error = false;
    protected $execute_callback;

    public function __construct()
    {
        $this->execute_callback = array(
            new \Elementor\Modules\Mcp\Abilities\Contract_Native_Ability('elementor/manage-global-variable'),
            'execute_guarded'
        );
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
        if (self::$pre_execution_error) {
            return new WP_Error('ability_invalid_permissions');
        }

        do_action('wp_before_execute_ability', 'elementor/manage-global-variable', $input, $this);
        ++self::$executions;
        if (self::$provider_error) {
            return new WP_Error('ability_invalid_output');
        }
        if (self::$provider_throw) {
            throw new RuntimeException('secret=provider-throw-detail');
        }
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

AbilityMutationContractAbility::$provider_error = true;
$errorRequest = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'ability-error-0002',
    'action' => 'wordpress.ability.execute',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(
        'name' => 'elementor/manage-global-variable',
        'input' => array('value' => '#000000'),
    ),
));
$errorFirst = $runner->run($errorRequest);
if (false !== ($errorFirst['ok'] ?? true)
    || true !== ($errorFirst['meta']['terminal_mutation'] ?? false)
    || 'ability_invalid_output' !== ($errorFirst['meta']['terminal_context']['provider_error_code'] ?? null)
    || 2 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Provider WP_Error was not recorded as a terminal mutation outcome.\n");
    exit(1);
}
$errorReplay = $runner->run($errorRequest);
if (false !== ($errorReplay['ok'] ?? true)
    || true !== ($errorReplay['meta']['idempotent_replay'] ?? false)
    || true !== ($errorReplay['meta']['terminal_mutation'] ?? false)
    || 2 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Retry after terminal provider error executed the Ability again.\n");
    exit(1);
}

$differentPayload = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'ability-error-0002',
    'action' => 'wordpress.ability.execute',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(
        'name' => 'elementor/manage-global-variable',
        'input' => array('value' => '#111111'),
    ),
));
$differentResult = $runner->run($differentPayload);
if (false !== ($differentResult['ok'] ?? true)
    || false === strpos((string) ($differentResult['error'] ?? ''), 'different mutation')
    || 2 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "request_id reuse with a changed Ability payload did not fail closed.\n");
    exit(1);
}

AbilityMutationContractAbility::$provider_error = false;
AbilityMutationContractAbility::$pre_execution_error = true;
$preflightRequest = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'ability-preflight-0004',
    'action' => 'wordpress.ability.execute',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(
        'name' => 'elementor/manage-global-variable',
        'input' => array('value' => '#333333'),
    ),
));
$preflightFirst = $runner->run($preflightRequest);
if (false !== ($preflightFirst['ok'] ?? true)
    || true === ($preflightFirst['meta']['terminal_mutation'] ?? false)
    || 2 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Pre-execution Ability failure was incorrectly terminalized.\n");
    exit(1);
}

AbilityMutationContractAbility::$pre_execution_error = false;
$preflightRetry = $runner->run($preflightRequest);
if (true !== ($preflightRetry['ok'] ?? false)
    || true !== ($preflightRetry['data']['execution_completed'] ?? false)
    || true !== ($preflightRetry['data']['result_omitted'] ?? false)
    || 3 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Retry after a pre-execution Ability failure did not execute normally.\n");
    exit(1);
}

$preflightKey = 'wpconnector_processed_' . hash('sha256', 'ability-preflight-0004');
if (! isset($test_options[$preflightKey])
    || 'completed' !== ($test_options[$preflightKey]['state'] ?? null)) {
    fwrite(STDERR, "Successful retry did not replace the execution marker with a completed record.\n");
    exit(1);
}

AbilityMutationContractAbility::$provider_throw = true;
$throwRequest = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'ability-throw-0005',
    'action' => 'wordpress.ability.execute',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(
        'name' => 'elementor/manage-global-variable',
        'input' => array('value' => '#444444'),
    ),
));
$throwFirst = $runner->run($throwRequest);
if (false !== ($throwFirst['ok'] ?? true)
    || true !== ($throwFirst['meta']['terminal_mutation'] ?? false)
    || null !== ($throwFirst['meta']['terminal_context']['provider_error_code'] ?? null)
    || false !== strpos((string) ($throwFirst['error'] ?? ''), 'provider-throw-detail')
    || 4 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Provider exception after execution start was not recorded as a bounded terminal outcome.\n");
    exit(1);
}
$throwReplay = $runner->run($throwRequest);
if (false !== ($throwReplay['ok'] ?? true)
    || true !== ($throwReplay['meta']['idempotent_replay'] ?? false)
    || 4 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Retry after terminal provider exception executed the Ability again.\n");
    exit(1);
}
AbilityMutationContractAbility::$provider_throw = false;

if (! empty($test_actions['wp_before_execute_ability'] ?? array())) {
    fwrite(STDERR, "Ability execution tracker hook leaked after execution.\n");
    exit(1);
}

$fail_processed_add = true;
$markerFailureRequest = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'ability-marker-0006',
    'action' => 'wordpress.ability.execute',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(
        'name' => 'elementor/manage-global-variable',
        'input' => array('value' => '#555555'),
    ),
));
$markerFailure = $runner->run($markerFailureRequest);
$markerFailureKey = 'wpconnector_processed_' . hash('sha256', 'ability-marker-0006');
if (false !== ($markerFailure['ok'] ?? true)
    || array_key_exists($markerFailureKey, $test_options)
    || 4 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Ability executed even though its durable execution marker could not be persisted.\n");
    exit(1);
}

$fail_processed_add = false;
$markerRetry = $runner->run($markerFailureRequest);
if (true !== ($markerRetry['ok'] ?? false)
    || 5 !== AbilityMutationContractAbility::$executions
    || 'completed' !== ($test_options[$markerFailureKey]['state'] ?? null)) {
    fwrite(STDERR, "Retry after execution-marker persistence failure did not execute exactly once.\n");
    exit(1);
}

$unknownPayload = array(
    'name' => 'elementor/manage-global-variable',
    'input' => array('value' => '#666666'),
);
$unknownFingerprint = \Webactueel\WordPressConnector\Support\Fingerprint::make(array(
    'action' => 'wordpress.ability.execute',
    'payload' => $unknownPayload,
    'expected_fingerprint' => null,
    'expected_state_token' => null,
));
$manualStore = new \Webactueel\WordPressConnector\Runtime\ProcessedStore();
$manualStore->putStarted('ability-unknown-0007', $unknownFingerprint, 'wordpress.ability.execute');
$unknownRequest = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'ability-unknown-0007',
    'action' => 'wordpress.ability.execute',
    'dry_run' => false,
    'confirm' => true,
    'payload' => $unknownPayload,
));
$unknownReplay = $runner->run($unknownRequest);
if (false !== ($unknownReplay['ok'] ?? true)
    || true !== ($unknownReplay['meta']['idempotent_replay'] ?? false)
    || true !== ($unknownReplay['meta']['mutation_outcome_unknown'] ?? false)
    || true !== ($unknownReplay['meta']['terminal_mutation'] ?? false)
    || 5 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Orphaned execution-boundary marker did not block automatic replay.\n");
    exit(1);
}

$fail_processed_update = true;
$finalizeRequest = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'ability-finalize-0008',
    'action' => 'wordpress.ability.execute',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(
        'name' => 'elementor/manage-global-variable',
        'input' => array('value' => '#777777'),
    ),
));
$finalizeFirst = $runner->run($finalizeRequest);
$finalizeKey = 'wpconnector_processed_' . hash('sha256', 'ability-finalize-0008');
if (false !== ($finalizeFirst['ok'] ?? true)
    || 'started' !== ($test_options[$finalizeKey]['state'] ?? null)
    || 6 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Final Ability outcome persistence failure was not left in a safe unknown state.\n");
    exit(1);
}

$fail_processed_update = false;
$finalizeReplay = $runner->run($finalizeRequest);
if (false !== ($finalizeReplay['ok'] ?? true)
    || true !== ($finalizeReplay['meta']['mutation_outcome_unknown'] ?? false)
    || true !== ($finalizeReplay['meta']['idempotent_replay'] ?? false)
    || 6 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Retry after final outcome persistence failure re-executed the provider.\n");
    exit(1);
}

$batchRequest = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'ability-batch-0003',
    'action' => 'connector.batch',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(
        'operations' => array(
            array(
                'action' => 'wordpress.ability.execute',
                'payload' => array(
                    'name' => 'elementor/manage-global-variable',
                    'input' => array('value' => '#222222'),
                ),
            ),
        ),
    ),
));
$batchResult = $runner->run($batchRequest);
if (false !== ($batchResult['ok'] ?? true)
    || false === strpos((string) ($batchResult['error'] ?? ''), 'not allowed inside connector.batch')
    || 6 !== AbilityMutationContractAbility::$executions) {
    fwrite(STDERR, "Delegated Ability mutation was not blocked from connector.batch.\n");
    exit(1);
}

echo "abilities mutation idempotency contract OK\n";
