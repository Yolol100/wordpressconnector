<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

class WP_Post
{
    public int $ID;
    public string $post_type;
    public string $post_status;
    public string $post_password = '';
    public int $post_parent = 0;

    public function __construct(int $id, string $type, string $status)
    {
        $this->ID = $id;
        $this->post_type = $type;
        $this->post_status = $status;
    }
}

$GLOBALS['portfolio_runtime_values'] = array();
$GLOBALS['portfolio_runtime_options'] = array();
$GLOBALS['portfolio_runtime_uuid'] = 0;

function get_post($id)
{
    $id = (int) $id;
    if (5104 === $id) return new WP_Post($id, 'post', 'draft');
    if (4090 === $id) return new WP_Post($id, 'post', 'private');
    return null;
}

function current_user_can($capability, ...$args): bool
{
    return 'edit_post' === (string) $capability;
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
    );
    return $fields[(string) $key] ?? null;
}

function get_field($key, $postId, $format = false)
{
    $index = (int) $postId . ':' . (string) $key;
    return $GLOBALS['portfolio_runtime_values'][$index] ?? 'old-value';
}

function update_field($key, $value, $postId): bool
{
    $GLOBALS['portfolio_runtime_values'][(int) $postId . ':' . (string) $key] = $value;
    return true;
}

function wp_json_encode($value)
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function wp_generate_uuid4(): string
{
    ++$GLOBALS['portfolio_runtime_uuid'];
    return sprintf('00000000-0000-4000-8000-%012d', $GLOBALS['portfolio_runtime_uuid']);
}

function add_option($key, $value, $deprecated = '', $autoload = false): bool
{
    if (array_key_exists((string) $key, $GLOBALS['portfolio_runtime_options'])) {
        return false;
    }
    $GLOBALS['portfolio_runtime_options'][(string) $key] = $value;
    return true;
}

function update_option($key, $value, $autoload = false): bool
{
    $GLOBALS['portfolio_runtime_options'][(string) $key] = $value;
    return true;
}

function get_option($key, $default = false)
{
    return array_key_exists((string) $key, $GLOBALS['portfolio_runtime_options'])
        ? $GLOBALS['portfolio_runtime_options'][(string) $key]
        : $default;
}

function delete_option($key): bool
{
    unset($GLOBALS['portfolio_runtime_options'][(string) $key]);
    return true;
}

function wp_cache_delete($key, $group = ''): bool
{
    return true;
}

final class PortfolioRuntimeWpdb
{
    public string $options = 'wp_options';

    public function update($table, $data, $where, $format = null, $whereFormat = null): int
    {
        $key = (string) ($where['option_name'] ?? '');
        $expected = $where['option_value'] ?? null;
        if (! array_key_exists($key, $GLOBALS['portfolio_runtime_options']) || $GLOBALS['portfolio_runtime_options'][$key] !== $expected) {
            return 0;
        }
        $GLOBALS['portfolio_runtime_options'][$key] = $data['option_value'] ?? null;
        return 1;
    }

    public function delete($table, $where, $whereFormat = null): int
    {
        $key = (string) ($where['option_name'] ?? '');
        $expected = $where['option_value'] ?? null;
        if (! array_key_exists($key, $GLOBALS['portfolio_runtime_options']) || $GLOBALS['portfolio_runtime_options'][$key] !== $expected) {
            return 0;
        }
        unset($GLOBALS['portfolio_runtime_options'][$key]);
        return 1;
    }
}

$GLOBALS['wpdb'] = new PortfolioRuntimeWpdb();

$root = dirname(__DIR__);
require_once $root . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once $root . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once $root . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/Request.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/Result.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/SnapshotStore.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/ProcessedStore.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/Runner.php';
require_once $root . '/plugin/wordpressconnector/includes/Adapters/PortfolioStatsAdapter.php';

use Webactueel\WordPressConnector\Adapters\PortfolioStatsAdapter;
use Webactueel\WordPressConnector\Runtime\ProcessedStore;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Runtime\SnapshotStore;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

Policy::setPublicRepositoryContext(true);
$registry = new Registry();
$adapter = new PortfolioStatsAdapter();
$adapter->register($registry);
$runner = new Runner($registry, new SnapshotStore(), new ProcessedStore());

$fields = array(
    'field_portfolio_stat_1_value' => '3',
    'field_portfolio_stat_1_label' => 'Practice Areas',
);
$payload = array('post_id' => 5104, 'fields' => $fields);

$dryRequest = Request::fromArray(array(
    'version' => 1,
    'request_id' => 'portfolio-runtime-dry-0001',
    'action' => 'acf.portfolio_stats_update',
    'dry_run' => true,
    'confirm' => false,
    'payload' => $payload,
));
$dry = $runner->run($dryRequest);
if (empty($dry['ok']) || ($dry['data']['after'] ?? null) !== $fields || ($dry['data']['before'] ?? null) !== array(
    'field_portfolio_stat_1_value' => 'old-value',
    'field_portfolio_stat_1_label' => 'old-value',
)) {
    fwrite(STDERR, "Guarded public portfolio dry-run failed.\n");
    exit(1);
}
$expectedFingerprint = Fingerprint::make($dry['data']['before']);

$missingFingerprint = Request::fromArray(array(
    'version' => 1,
    'request_id' => 'portfolio-runtime-live-0001',
    'action' => 'acf.portfolio_stats_update',
    'dry_run' => false,
    'confirm' => true,
    'payload' => $payload,
));
$missing = $runner->run($missingFingerprint);
if (! empty($missing['ok']) || false === strpos((string) ($missing['error'] ?? ''), 'expected_fingerprint')) {
    fwrite(STDERR, "Confirmed public portfolio mutation was not fingerprint-gated.\n");
    exit(1);
}

$liveArray = array(
    'version' => 1,
    'request_id' => 'portfolio-runtime-live-0002',
    'action' => 'acf.portfolio_stats_update',
    'dry_run' => false,
    'confirm' => true,
    'expected_fingerprint' => $expectedFingerprint,
    'payload' => $payload,
);
$liveRequest = Request::fromArray($liveArray);
$live = $runner->run($liveRequest);
if (empty($live['ok']) || ($live['data']['after'] ?? null) !== $fields || ($live['data']['rollback_request_id'] ?? '') !== 'portfolio-runtime-live-0002') {
    fwrite(STDERR, "Guarded public portfolio live mutation failed.\n");
    exit(1);
}
foreach ($fields as $key => $value) {
    if (get_field($key, 5104, false) !== $value) {
        fwrite(STDERR, "Guarded public portfolio live readback failed.\n");
        exit(1);
    }
}

$rollbackFingerprint = Fingerprint::make($fields);
$rollbackDry = $runner->run(Request::fromArray(array(
    'version' => 1,
    'request_id' => 'portfolio-rollback-dry-0001',
    'action' => 'connector.rollback',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('request_id' => 'portfolio-runtime-live-0002'),
)));
if (empty($rollbackDry['ok']) || ($rollbackDry['data']['would_execute_action'] ?? '') !== 'acf.portfolio_stats_update') {
    fwrite(STDERR, "Unpublished portfolio rollback dry-run was not admitted.\n");
    exit(1);
}

$rollbackLive = $runner->run(Request::fromArray(array(
    'version' => 1,
    'request_id' => 'portfolio-rollback-live-0001',
    'action' => 'connector.rollback',
    'dry_run' => false,
    'confirm' => true,
    'expected_fingerprint' => $rollbackFingerprint,
    'payload' => array('request_id' => 'portfolio-runtime-live-0002'),
)));
if (empty($rollbackLive['ok']) || ($rollbackLive['data']['restored_with'] ?? '') !== 'acf.portfolio_stats_update') {
    fwrite(STDERR, "Unpublished portfolio rollback execution failed.\n");
    exit(1);
}
foreach (array_keys($fields) as $key) {
    if ('old-value' !== get_field($key, 5104, false)) {
        fwrite(STDERR, "Unpublished portfolio rollback readback failed.\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/wpconnector-portfolio-receipt-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
$requestPath = $tmp . '/request.json';
$resultPath = $tmp . '/result.json';
$receiptPath = $tmp . '/receipt.json';
file_put_contents($requestPath, json_encode($liveArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
file_put_contents($resultPath, json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/build-public-receipt.php') . ' ' . escapeshellarg($requestPath) . ' ' . escapeshellarg($resultPath) . ' ' . escapeshellarg($receiptPath);
$output = array();
$status = 0;
exec($command . ' 2>&1', $output, $status);
if (0 !== $status || ! is_file($receiptPath)) {
    fwrite(STDERR, "Portfolio stats public receipt builder failed.\n");
    exit(1);
}
$receiptRaw = (string) file_get_contents($receiptPath);
$receipt = json_decode($receiptRaw, true, 512, JSON_THROW_ON_ERROR);
if (true !== ($receipt['readback_verified'] ?? null) || empty($receipt['rollback_available'])) {
    fwrite(STDERR, "Portfolio stats receipt is missing verified mutation evidence.\n");
    exit(1);
}
foreach (array('before_fingerprint', 'after_fingerprint') as $key) {
    if (empty($receipt[$key]) || ! preg_match('/^[a-f0-9]{64}\z/', (string) $receipt[$key])) {
        fwrite(STDERR, "Portfolio stats receipt is missing {$key}.\n");
        exit(1);
    }
}
foreach (array('Practice Areas', 'old-value') as $needle) {
    if (false !== strpos($receiptRaw, $needle)) {
        fwrite(STDERR, "Portfolio stats receipt leaked field values.\n");
        exit(1);
    }
}
@unlink($requestPath);
@unlink($resultPath);
@unlink($receiptPath);
@rmdir($tmp);

echo "Portfolio stats guarded public runtime contract OK.\n";
