<?php

declare(strict_types=1);

$fixtureRoot = __DIR__ . '/fixtures';
$tempRoot = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wpconnector-elementor-provenance-' . getmypid();

if (is_dir($tempRoot)) {
    @unlink($tempRoot . DIRECTORY_SEPARATOR . 'elementor');
    @rmdir($tempRoot);
}
if (! mkdir($tempRoot, 0700, true) && ! is_dir($tempRoot)) {
    fwrite(STDERR, "Unable to create temporary plugin root.\n");
    exit(1);
}

$elementorLink = $tempRoot . DIRECTORY_SEPARATOR . 'elementor';
if (! function_exists('symlink') || ! @symlink($fixtureRoot . '/elementor', $elementorLink)) {
    @rmdir($tempRoot);
    fwrite(STDERR, "Unable to create Elementor symlink fixture.\n");
    exit(1);
}

register_shutdown_function(static function () use ($elementorLink, $tempRoot): void {
    @unlink($elementorLink);
    @rmdir($tempRoot);
});

define('WP_PLUGIN_DIR', $tempRoot);

require_once $fixtureRoot . '/elementor/modules/mcp/abilities/native-ability-fixture.php';

$siteActive = array();
$networkActive = array();

function get_option($name, $default = false)
{
    global $siteActive;
    return 'active_plugins' === $name ? $siteActive : $default;
}

function get_site_option($name, $default = false)
{
    global $networkActive;
    return 'active_sitewide_plugins' === $name ? $networkActive : $default;
}

final class ProvenanceContractAbility
{
    protected $execute_callback;

    public function __construct()
    {
        $this->execute_callback = array(
            new \Elementor\Modules\Mcp\Abilities\Contract_Native_Ability('elementor/network-write'),
            'execute_guarded'
        );
    }

    public function get_meta(): array
    {
        return array(
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'annotations' => array('readonly' => false, 'destructive' => false),
        );
    }

    public function get_label(): string { return 'Network write'; }
    public function get_description(): string { return 'Provider provenance edge contract.'; }
    public function get_category(): string { return 'elementor'; }
    public function get_input_schema(): array { return array('type' => 'object'); }
    public function get_output_schema(): array { return array('type' => 'object'); }
    public function execute($input = null) { return array('ok' => true); }
}

$provenanceAbility = new ProvenanceContractAbility();

function wp_get_abilities(): array
{
    global $provenanceAbility;
    return array('elementor/network-write' => $provenanceAbility);
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/AbilitiesAdapter.php';

$adapter = new \Webactueel\WordPressConnector\Adapters\AbilitiesAdapter();

$catalog = $adapter->catalog(array('namespace' => 'elementor'), array());
if (! isset($catalog['abilities']['elementor/network-write'])
    || false !== ($catalog['abilities']['elementor/network-write']['execution_exposed'] ?? true)
    || false !== ($catalog['abilities']['elementor/network-write']['mutation_execution_exposed'] ?? true)) {
    fwrite(STDERR, "Inactive Elementor unexpectedly received mutation trust.\n");
    exit(1);
}

$networkActive = array('elementor/elementor.php' => time());
$catalog = $adapter->catalog(array('namespace' => 'elementor'), array());
if (true !== ($catalog['abilities']['elementor/network-write']['mutation_execution_exposed'] ?? false)) {
    fwrite(STDERR, "Network-active Elementor did not receive native mutation trust.\n");
    exit(1);
}

$resolvedLinkRoot = realpath($elementorLink);
$resolvedFixtureRoot = realpath($fixtureRoot . '/elementor');
if (false === $resolvedLinkRoot || false === $resolvedFixtureRoot || $resolvedLinkRoot !== $resolvedFixtureRoot) {
    fwrite(STDERR, "Symlink/realpath fixture did not resolve to the canonical Elementor root.\n");
    exit(1);
}

$networkActive = array();
$siteActive = array('elementor/elementor.php');
$catalog = $adapter->catalog(array('namespace' => 'elementor'), array());
if (true !== ($catalog['abilities']['elementor/network-write']['mutation_execution_exposed'] ?? false)) {
    fwrite(STDERR, "Site-active Elementor through a symlinked canonical plugin root was rejected.\n");
    exit(1);
}

echo "abilities provider provenance contract OK\n";
