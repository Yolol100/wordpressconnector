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

$GLOBALS['portfolio_test_values'] = array();

function get_post($id)
{
    $id = (int) $id;
    if (5104 === $id) return new WP_Post($id, 'post', 'draft');
    if (4090 === $id) return new WP_Post($id, 'post', 'private');
    if (99 === $id) return new WP_Post($id, 'portfolio', 'draft');
    if (100 === $id) return new WP_Post($id, 'post', 'trash');
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
        'field_other_text' => array('key' => 'field_other_text', 'name' => 'other_text', 'type' => 'text', 'parent' => 555),
        'field_portfolio_nontext' => array('key' => 'field_portfolio_nontext', 'name' => 'portfolio_stat_2_value', 'type' => 'textarea', 'parent' => 555),
        'field_portfolio_wrongparent' => array('key' => 'field_portfolio_wrongparent', 'name' => 'portfolio_stat_2_label', 'type' => 'text', 'parent' => 999),
    );
    return $fields[(string) $key] ?? null;
}

function get_field($key, $postId, $format = false)
{
    $index = (int) $postId . ':' . (string) $key;
    return $GLOBALS['portfolio_test_values'][$index] ?? 'old-value';
}

function update_field($key, $value, $postId): bool
{
    $index = (int) $postId . ':' . (string) $key;
    $GLOBALS['portfolio_test_values'][$index] = $value;
    return true;
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/PortfolioStatsAdapter.php';

use Webactueel\WordPressConnector\Adapters\PortfolioStatsAdapter;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

Policy::setPublicRepositoryContext(true);
$registry = new Registry();
$adapter = new PortfolioStatsAdapter();
$adapter->register($registry);
$descriptor = $registry->descriptor('acf.portfolio_stats_update');

try {
    Policy::assertActionAllowed($descriptor, false, false);
    fwrite(STDERR, "Unconfirmed portfolio stats mutation unexpectedly passed.\n");
    exit(1);
} catch (RuntimeException $expected) {
    if (false === strpos($expected->getMessage(), 'confirm=true')) {
        fwrite(STDERR, "Unexpected confirmation failure: {$expected->getMessage()}\n");
        exit(1);
    }
}
Policy::assertActionAllowed($descriptor, true, true);
Policy::assertActionAllowed($descriptor, false, true);

$fields = array(
    'field_portfolio_stat_1_value' => '3',
    'field_portfolio_stat_1_label' => 'Practice Areas',
);
$dry = $registry->execute('acf.portfolio_stats_update', array('post_id' => 5104, 'fields' => $fields), array('dry_run' => true, 'confirm' => true));
if (($dry['after'] ?? null) !== $fields || empty($dry['_current_fingerprint'])) {
    fwrite(STDERR, "Draft portfolio stats dry-run did not return the expected guarded preview.\n");
    exit(1);
}

$live = $registry->execute('acf.portfolio_stats_update', array('post_id' => 4090, 'fields' => $fields), array('dry_run' => false, 'confirm' => true));
if (($live['after'] ?? null) !== $fields || empty($live['_rollback'])) {
    fwrite(STDERR, "Private portfolio stats write/readback contract failed.\n");
    exit(1);
}
foreach ($fields as $key => $value) {
    if (get_field($key, 4090, false) !== $value) {
        fwrite(STDERR, "Portfolio stats exact readback failed for {$key}.\n");
        exit(1);
    }
}

$cases = array(
    'custom post type' => array('post_id' => 99, 'fields' => $fields),
    'trash status' => array('post_id' => 100, 'fields' => $fields),
    'field outside allowlist' => array('post_id' => 5104, 'fields' => array('field_other_text' => 'x')),
    'non-text field' => array('post_id' => 5104, 'fields' => array('field_portfolio_nontext' => 'x')),
    'wrong field group' => array('post_id' => 5104, 'fields' => array('field_portfolio_wrongparent' => 'x')),
    'field name instead of key' => array('post_id' => 5104, 'fields' => array('portfolio_stat_1_value' => 'x')),
    'non-string value' => array('post_id' => 5104, 'fields' => array('field_portfolio_stat_1_value' => 3)),
);
foreach ($cases as $label => $payload) {
    try {
        $registry->execute('acf.portfolio_stats_update', $payload, array('dry_run' => true, 'confirm' => true));
        fwrite(STDERR, "Forbidden portfolio stats case passed: {$label}\n");
        exit(1);
    } catch (RuntimeException $expected) {
        // Expected fail-closed behavior.
    }
}

echo "Unpublished portfolio stats contract OK.\n";
