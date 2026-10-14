<?php

declare(strict_types=1);

define('WPSEO_VERSION', '28.7');

final class YoastFeatureFakeSchema
{
    private $group;
    public function __construct(string $group) { $this->group = $group; }
    public function get_defaults(): array
    {
        if ($this->group === 'wpseo') {
            return array_fill_keys(array(
                'keyword_analysis_active', 'content_analysis_active', 'enable_xml_sitemap',
                'enable_schema', 'enable_headless_rest_endpoints', 'enable_enhanced_slack_sharing',
                'enable_admin_bar_menu',
            ), true);
        }
        if ($this->group === 'wpseo_titles') {
            return array_fill_keys(array('breadcrumbs-enable', 'breadcrumbs-boldlast', 'noindex-post'), true);
        }
        return array();
    }
}
final class WPSEO_Options
{
    public static function get_option_instance(string $group): YoastFeatureFakeSchema
    {
        return new YoastFeatureFakeSchema($group);
    }
}

$GLOBALS['feature_opts'] = array(
    'wpseo' => array(
        'keyword_analysis_active' => true,
        'content_analysis_active' => true,
        'enable_xml_sitemap' => true,
        'enable_schema' => true,
        'enable_headless_rest_endpoints' => true,
        'semrush_tokens' => array('secret' => 'never-export-this-secret'),
        'workouts_data' => array('configuration' => array('finishedSteps' => array(12))),
        'integration_private_setting' => 'preserve',
    ),
    'wpseo_titles' => array(
        'breadcrumbs-enable' => true,
        'breadcrumbs-boldlast' => false,
        'noindex-post' => false,
        'company_name' => 'Test Brand',
    ),
);
$GLOBALS['feature_caps'] = array();
$GLOBALS['simulate_feature_rejection'] = false;

function get_option($name, $default = null)
{
    return $GLOBALS['feature_opts'][$name] ?? $default;
}
function update_option($name, $value)
{
    if ($name === 'wpseo' && $GLOBALS['simulate_feature_rejection']) {
        $GLOBALS['simulate_feature_rejection'] = false;
        $value['enable_schema'] = true;
    }
    $GLOBALS['feature_opts'][$name] = $value;
    return true;
}
function current_user_can($cap)
{
    return in_array($cap, $GLOBALS['feature_caps'], true);
}

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Security/Policy.php',
    'Runtime/Registry.php', 'Adapters/YoastFeatureSettingsAdapter.php') as $file) {
    require $root . $file;
}

$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter = new \Webactueel\WordPressConnector\Adapters\YoastFeatureSettingsAdapter();
$adapter->register($registry);
$policy = 'Webactueel\\WordPressConnector\\Security\\Policy';
$policy::setPublicRepositoryContext(false);

$fail = static function (callable $fn, string $error): void {
    try {
        $fn();
    } catch (\RuntimeException $exception) {
        if (strpos($exception->getMessage(), $error) !== false) {
            return;
        }
        throw new \RuntimeException('Unexpected error: ' . $exception->getMessage());
    }
    throw new \RuntimeException('Expected a failure: ' . $error);
};

$descriptor = $registry->descriptor('yoast.features.update');
if (empty($descriptor['privileged']) || empty($descriptor['mutation'])
    || $descriptor['capability'] !== 'manage_options'
    || ! empty($descriptor['public_repository_safe'])) {
    throw new \RuntimeException('Yoast feature descriptor is not privileged/private-only.');
}
$fail(static function () use ($policy, $descriptor): void {
    $policy::assertActionAllowed($descriptor, false, true);
}, 'lacks the required WordPress capability');
$GLOBALS['feature_caps'][] = 'manage_options';
$policy::assertActionAllowed($descriptor, false, true);

$before = $registry->execute('yoast.features.inspect', array());
if (! array_key_exists('tag_noindex', $before['fields'])
    || $before['fields']['category_noindex'] !== null
    || $before['fields']['breadcrumbs_enabled'] !== true
    || $before['fields']['xml_sitemaps_enabled'] !== true
    || strpos(json_encode($before), 'never-export-this-secret') !== false) {
    throw new \RuntimeException('Typed private feature inventory is incorrect or leaked hidden keys.');
}
$policy::setPublicRepositoryContext(true);
$fail(static function () use ($registry): void {
    $registry->execute('yoast.features.inspect', array());
}, 'not exposed in the public GitHub runtime');
$fail(static function () use ($registry): void {
    $registry->execute('yoast.features.update', array('fields' => array('seo_analysis_enabled' => false)), array('dry_run' => true));
}, 'not exposed in the public GitHub runtime');
$fail(static function () use ($policy, $descriptor): void {
    $policy::assertActionAllowed($descriptor, true, false);
}, 'not allowed in public-repository mode');
$policy::setPublicRepositoryContext(false);

$noncritical = array('fields' => array('seo_analysis_enabled' => false, 'slack_sharing_enabled' => false));
$preview = $registry->execute('yoast.features.update', $noncritical, array('dry_run' => true));
if ($preview['_current_fingerprint'] !== $before['fingerprint']
    || $preview['after']['seo_analysis_enabled'] !== false
    || $GLOBALS['feature_opts']['wpseo']['keyword_analysis_active'] !== true) {
    throw new \RuntimeException('Yoast feature dry-run unexpectedly mutated site state.');
}
$applied = $registry->execute('yoast.features.update', $noncritical, array('dry_run' => false));
if ($GLOBALS['feature_opts']['wpseo']['keyword_analysis_active'] !== false
    || $GLOBALS['feature_opts']['wpseo']['semrush_tokens']['secret'] !== 'never-export-this-secret'
    || $GLOBALS['feature_opts']['wpseo']['workouts_data']['configuration']['finishedSteps'] !== array(12)
    || $GLOBALS['feature_opts']['wpseo']['integration_private_setting'] !== 'preserve') {
    throw new \RuntimeException('Yoast feature update affected another provider-owned option.');
}
$fail(static function () use ($registry, $applied): void {
    $registry->execute('yoast.features.restore', $applied['_rollback']['payload'], array());
}, 'rollback-only');
$registry->execute('yoast.features.restore', $applied['_rollback']['payload'], array('rollback_mode' => true));
if ($registry->execute('yoast.features.inspect', array())['fingerprint'] !== $before['fingerprint']) {
    throw new \RuntimeException('Yoast feature rollback did not restore the initial state.');
}

// Caller field order must not affect a verified rollback.
$reversed = $registry->execute('yoast.features.update', array(
    'fields' => array('slack_sharing_enabled' => false, 'seo_analysis_enabled' => false),
), array('dry_run' => false));
$registry->execute('yoast.features.restore', $reversed['_rollback']['payload'], array('rollback_mode' => true));
if ($registry->execute('yoast.features.inspect', array())['fingerprint'] !== $before['fingerprint']) {
    throw new \RuntimeException('Yoast feature rollback depends incorrectly on field order.');
}

$absentBefore = $registry->execute('yoast.features.inspect', array())['fields']['admin_bar_enabled'];
if ($absentBefore !== null) {
    throw new \RuntimeException('Expected an absent boolean field for rollback test.');
}
$created = $registry->execute('yoast.features.update', array('fields' => array('admin_bar_enabled' => false)), array('dry_run' => false));
$registry->execute('yoast.features.restore', $created['_rollback']['payload'], array('rollback_mode' => true));
if (array_key_exists('enable_admin_bar_menu', $GLOBALS['feature_opts']['wpseo'])) {
    throw new \RuntimeException('Yoast feature rollback did not remove the previously absent option.');
}

$highImpact = array('fields' => array('xml_sitemaps_enabled' => false));
$preview = $registry->execute('yoast.features.update', $highImpact, array('dry_run' => true));
if (! $preview['requires_risk_acknowledgement']
    || $preview['high_impact_fields'] !== array('xml_sitemaps_enabled')) {
    throw new \RuntimeException('High impact SEO update did not report the required approval.');
}
$fail(static function () use ($registry, $highImpact): void {
    $registry->execute('yoast.features.update', $highImpact, array('dry_run' => false));
}, 'risk_acknowledged=true');
$highImpact['risk_acknowledged'] = true;
$applied = $registry->execute('yoast.features.update', $highImpact, array('dry_run' => false));
if ($GLOBALS['feature_opts']['wpseo']['enable_xml_sitemap'] !== false) {
    throw new \RuntimeException('Explicitly approved high-impact feature update was not saved.');
}
$registry->execute('yoast.features.restore', $applied['_rollback']['payload'], array('rollback_mode' => true));

$groups = array('fields' => array('breadcrumbs_enabled' => false, 'seo_analysis_enabled' => false));
$fail(static function () use ($registry, $groups): void {
    $registry->execute('yoast.features.update', $groups, array('dry_run' => true));
}, 'one option group');
foreach (array(
    array('fields' => array('semrush_tokens' => false)),
    array('fields' => array('seo_analysis_enabled' => 'false')),
    array('fields' => array('admin_bar_enabled' => null)),
    array('fields' => array('post_noindex' => array(1))),
    array('fields' => array('seo_analysis_enabled' => true), 'arbitrary' => true),
) as $bad) {
    $fail(static function () use ($registry, $bad): void {
        $registry->execute('yoast.features.update', $bad, array('dry_run' => true));
    }, isset($bad['arbitrary']) ? 'accepts only fields' : 'Unsupported Yoast feature field or value');
}

$fail(static function () use ($registry): void {
    $registry->execute('yoast.features.update', array(
        'fields' => array('wincher_integration_enabled' => false),
    ), array('dry_run' => true));
}, 'does not recognize the requested feature');

$GLOBALS['simulate_feature_rejection'] = true;
$schemaChange = array('fields' => array('schema_enabled' => false), 'risk_acknowledged' => true);
$fail(static function () use ($registry, $schemaChange): void {
    $registry->execute('yoast.features.update', $schemaChange, array('dry_run' => false));
}, 'readback mismatch; original options restored');
if ($registry->execute('yoast.features.inspect', array())['fingerprint'] !== $before['fingerprint']) {
    throw new \RuntimeException('Yoast feature provider rejection left residual changes.');
}
echo "private Yoast typed features, policy, dry-run, risk gate, rollback and provider-rejection recovery OK\n";
