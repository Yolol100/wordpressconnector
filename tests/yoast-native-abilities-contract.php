<?php

declare(strict_types=1);

define('WPSEO_VERSION', '28.7');

class WP_Post
{
    public int $ID;
    public function __construct(int $id) { $this->ID = $id; }
}

$GLOBALS['yoast_test_fields'] = array(
    'post_id' => 42, 'seo_title' => null, 'meta_description' => null,
    'canonical' => null, 'is_cornerstone' => false, 'noindex' => null,
    'nofollow' => false, 'noimageindex' => false, 'noarchive' => false,
    'nosnippet' => false, 'open_graph_title' => null, 'open_graph_description' => null,
    'twitter_title' => null, 'twitter_description' => null,
    'schema_page_type' => null, 'schema_article_type' => null,
    'seo_title_rendered' => 'Product - My Shop',
    'meta_description_rendered' => 'The actual product description',
    'canonical_rendered' => 'https://example.test/product/42/',
);
$GLOBALS['yoast_test_calls'] = 0;
$GLOBALS['yoast_test_reject'] = false;
$GLOBALS['yoast_test_permissions'] = true;

function get_post($id) { return (int)$id === 42 ? new WP_Post(42) : null; }
function current_user_can($cap, ...$args): bool { return $GLOBALS['yoast_test_permissions']; }
function is_wp_error($value): bool { return false; }

final class MockYoastAbility
{
    private string $name;
    public function __construct(string $name) { $this->name = $name; }
    public function execute($input) {
        $GLOBALS['yoast_test_calls']++;
        if ($GLOBALS['yoast_test_reject']) {
            throw new RuntimeException('Simulated native provider rejection');
        }
        if ($this->name === 'yoast-seo/get-post-seo-data') {
            return array($GLOBALS['yoast_test_fields']);
        }
        if ($this->name === 'yoast-seo/update-post-seo-data') {
            if (! isset($input['post_id']) || $input['post_id'] !== 42) {
                throw new RuntimeException('Native ability rejected target');
            }
            unset($input['post_id']);
            foreach ($input as $field => $value) {
                if (! array_key_exists($field, $GLOBALS['yoast_test_fields'])) {
                    throw new RuntimeException('Native field not recognized');
                }
                $GLOBALS['yoast_test_fields'][$field] = $value;
            }
            return $GLOBALS['yoast_test_fields'];
        }
        return array(array('title' => 'Product', 'score' => 'ok', 'label' => 'OK'));
    }
}
function wp_get_ability($name) {
    $valid = array('yoast-seo/get-post-seo-data','yoast-seo/update-post-seo-data',
        'yoast-seo/get-seo-scores','yoast-seo/get-readability-scores',
        'yoast-seo/get-inclusive-language-scores');
    return in_array($name, $valid, true) ? new MockYoastAbility($name) : null;
}

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
foreach (array('Support/Json.php','Support/Fingerprint.php','Security/Policy.php',
    'Runtime/Registry.php','Adapters/YoastNativeAbilitiesAdapter.php') as $file) {
    require_once $root . $file;
}
$policy = 'Webactueel\WordPressConnector\Security\Policy';
$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter = new \Webactueel\WordPressConnector\Adapters\YoastNativeAbilitiesAdapter();
$adapter->register($registry);
$policy::setPublicRepositoryContext(false);

$fail = static function (callable $action, string $phrase): void {
    try { $action(); } catch (RuntimeException $error) {
        if (strpos($error->getMessage(), $phrase) !== false) {
            return;
        }
        throw new RuntimeException('Unexpected error: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected failure containing: ' . $phrase);
};
foreach (array('yoast.native.post.update','yoast.native.post.restore') as $action) {
    $descriptor = $registry->descriptor($action);
    if (! $descriptor['mutation'] || ! $descriptor['privileged'] ||
        $descriptor['capability'] !== 'wpseo_edit_advanced_metadata' ||
        ! empty($descriptor['public_repository_safe'])) {
        throw new RuntimeException('Unsafe native Yoast action registration.');
    }
}
$cap = $registry->execute('yoast.native.capabilities',array());
if (! $cap['available'] || count(array_filter($cap['abilities'])) !== 5 ||
    $cap['transport'] !== 'private_authenticated_only') {
    throw new RuntimeException('Native Yoast capability discovery failed.');
}
$inspect = $registry->execute('yoast.native.post.inspect',array('post_id'=>42));
if ($inspect['fields']['seo_title'] !== null ||
    $inspect['rendered']['seo_title_rendered'] !== 'Product - My Shop' ||
    strlen($inspect['fingerprint']) !== 64) {
    throw new RuntimeException('Native Yoast post snapshot failed.');
}
$start = $GLOBALS['yoast_test_calls'];
$preview = $registry->execute('yoast.native.post.update',array(
    'post_id'=>42,'fields'=>array(
        'seo_title'=>'Sothys Product | Byderma',
        'meta_description'=>'A focused product description.',
    )),array('dry_run'=>true));
if ($GLOBALS['yoast_test_calls'] !== $start + 1 ||
    $preview['_current_fingerprint'] !== $inspect['fingerprint'] ||
    $GLOBALS['yoast_test_fields']['seo_title'] !== null) {
    throw new RuntimeException('Native Yoast dry-run wrote metadata.');
}
$change = $registry->execute('yoast.native.post.update',array(
    'post_id'=>42,'fields'=>array(
        'seo_title'=>'Sothys Product | Byderma',
        'meta_description'=>'A focused product description.',
    )),array('dry_run'=>false));
if ($change['readback_verified'] !== true ||
    $GLOBALS['yoast_test_fields']['seo_title'] !== 'Sothys Product | Byderma' ||
    ! isset($change['_rollback'])) {
    throw new RuntimeException('Native Yoast metadata update failed.');
}
$fail(static function () use ($registry,$change): void {
    $registry->execute('yoast.native.post.restore',$change['_rollback']['payload']);
},'rollback-only');
$rollback = $registry->execute('yoast.native.post.restore',$change['_rollback']['payload'],array('rollback_mode'=>true));
if (! $rollback['restored'] || $GLOBALS['yoast_test_fields']['seo_title'] !== null) {
    throw new RuntimeException('Native Yoast restore failed.');
}
$fail(static function () use ($registry,$change): void {
    $registry->execute('yoast.native.post.restore',$change['_rollback']['payload'],array('rollback_mode'=>true));
},'changed since');
$fail(static function () use ($registry): void {
    $registry->execute('yoast.native.post.update',array('post_id'=>42,
        'fields'=>array('noindex'=>true)),array('dry_run'=>true));
},'risk_acknowledged');
$ok = $registry->execute('yoast.native.post.update',array(
    'post_id'=>42,'fields'=>array('noindex'=>true),'risk_acknowledged'=>true),array('dry_run'=>true));
if (! in_array('noindex',$ok['high_impact_fields'],true)) {
    throw new RuntimeException('Missing indexing risk evidence.');
}
foreach (array(
    array('post_id'=>42,'fields'=>array('unknown_option'=>'oops')),
    array('post_id'=>42,'fields'=>array('is_cornerstone'=>'yes')),
    array('post_id'=>42,'fields'=>array('canonical'=>'http://example.test/x'),'risk_acknowledged'=>true),
    array('post_id'=>0,'fields'=>array('seo_title'=>'test')),
) as $bad) {
    $fail(static function () use ($registry,$bad): void {
        $registry->execute('yoast.native.post.update',$bad,array('dry_run'=>true));
    }, ''); // Any deliberate rejection is acceptable.
}
$policy::setPublicRepositoryContext(true);
$fail(static function () use ($registry): void {
    $registry->execute('yoast.native.post.inspect',array('post_id'=>42));
},'private authenticated transport');
$policy::setPublicRepositoryContext(false);
$GLOBALS['yoast_test_permissions'] = false;
$fail(static function () use ($registry): void {
    $registry->execute('yoast.native.post.inspect',array('post_id'=>42));
},'permission');

$GLOBALS['yoast_test_permissions'] = true;
$scores = $registry->execute('yoast.native.scores',array('kind'=>'seo_scores','number_of_posts'=>3));
if ($scores['count'] !== 1 || $scores['kind'] !== 'seo_scores') {
    throw new RuntimeException('Native Yoast score capability failed.');
}
$fail(static function () use ($registry): void {
    $registry->execute('yoast.native.scores',array('kind'=>'arbitrary','number_of_posts'=>3));
},'Unsupported Yoast analysis kind');
echo "Yoast native Abilities private discovery, metadata, dry-run, permissions and rollback OK\n";
