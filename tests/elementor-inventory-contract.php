<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/wpconnector-elementor-inventory-' . getmypid();
$coreRoot = $root . '/elementor/';
$proRoot = $root . '/elementor-pro/';
$pluginRoot = $root . '/plugins';
@mkdir($coreRoot, 0777, true);
@mkdir($proRoot, 0777, true);
@mkdir($pluginRoot . '/awesome-addon', 0777, true);

define('ELEMENTOR_PATH', $coreRoot);
define('ELEMENTOR_PRO_PATH', $proRoot);
define('ELEMENTOR_VERSION', '9.9.1');
define('ELEMENTOR_PRO_VERSION', '9.9.2');
define('WP_PLUGIN_DIR', $pluginRoot);

function get_plugins(): array
{
    return array(
        'awesome-addon/awesome-addon.php' => array('Name' => 'Awesome Addon', 'Version' => '4.2.0'),
    );
}

file_put_contents($coreRoot . 'core-widget.php', "<?php\nnamespace Elementor; class InventoryCoreWidget {}\n");
file_put_contents($proRoot . 'pro-widget.php', "<?php\nnamespace ElementorPro; class InventoryProWidget {}\n");
file_put_contents($pluginRoot . '/awesome-addon/widget.php', "<?php\nclass InventoryAddonWidget {}\n");
require $coreRoot . 'core-widget.php';
require $proRoot . 'pro-widget.php';
require $pluginRoot . '/awesome-addon/widget.php';
require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/ElementorCapabilitiesAdapter.php';

$adapter = new Webactueel\WordPressConnector\Adapters\ElementorCapabilitiesAdapter();
$reflection = new ReflectionClass($adapter);

$collect = $reflection->getMethod('collectElementUsage');
$collect->setAccessible(true);
$widgets = array();
$elements = array();
$families = array('legacy' => false, 'container' => false, 'atomic' => false);
$data = array(
    array(
        'elType' => 'section',
        'elements' => array(
            array('elType' => 'column', 'elements' => array(
                array('elType' => 'widget', 'widgetType' => 'heading', 'elements' => array()),
            )),
        ),
    ),
    array(
        'elType' => 'container',
        'elements' => array(
            array('elType' => 'widget', 'widgetType' => 'button', 'elements' => array()),
            array('elType' => 'widget', 'widgetType' => 'button', 'elements' => array()),
            array('elType' => 'e-div-block', 'elements' => array(
                array('elType' => 'widget', 'widgetType' => 'image', 'elements' => array()),
            )),
        ),
    ),
);
$args = array($data, &$widgets, &$elements, &$families);
$collect->invokeArgs($adapter, $args);

if ($widgets !== array('button' => 2, 'heading' => 1, 'image' => 1)) {
    fwrite(STDERR, 'Widget recursion/count contract failed: ' . json_encode($widgets) . "\n");
    exit(1);
}
if (empty($families['legacy']) || empty($families['container']) || empty($families['atomic'])) {
    fwrite(STDERR, "Architecture family detection contract failed.\n");
    exit(1);
}
if (($elements['section'] ?? 0) !== 1 || ($elements['column'] ?? 0) !== 1 || ($elements['container'] ?? 0) !== 1 || ($elements['e-div-block'] ?? 0) !== 1) {
    fwrite(STDERR, 'Element type inventory contract failed: ' . json_encode($elements) . "\n");
    exit(1);
}

$architecture = $reflection->getMethod('architectureLabel');
$architecture->setAccessible(true);
if ($architecture->invoke($adapter, $families) !== 'mixed') {
    fwrite(STDERR, "Mixed architecture contract failed.\n");
    exit(1);
}

$sourceMethod = $reflection->getMethod('widgetSource');
$sourceMethod->setAccessible(true);
$core = $sourceMethod->invoke($adapter, new Elementor\InventoryCoreWidget());
$pro = $sourceMethod->invoke($adapter, new ElementorPro\InventoryProWidget());
$addon = $sourceMethod->invoke($adapter, new InventoryAddonWidget());

if (($core['family'] ?? null) !== 'elementor-core' || ($core['version'] ?? null) !== '9.9.1') {
    fwrite(STDERR, 'Elementor Core source detection failed: ' . json_encode($core) . "\n");
    exit(1);
}
if (($pro['family'] ?? null) !== 'elementor-pro' || ($pro['version'] ?? null) !== '9.9.2') {
    fwrite(STDERR, 'Elementor Pro source detection failed: ' . json_encode($pro) . "\n");
    exit(1);
}
if (($addon['family'] ?? null) !== 'addon' || ($addon['slug'] ?? null) !== 'awesome-addon' || ($addon['version'] ?? null) !== '4.2.0') {
    fwrite(STDERR, 'Addon source detection failed: ' . json_encode($addon) . "\n");
    exit(1);
}

foreach (array($coreRoot . 'core-widget.php', $proRoot . 'pro-widget.php', $pluginRoot . '/awesome-addon/widget.php') as $file) {
    @unlink($file);
}
@rmdir($pluginRoot . '/awesome-addon');
@rmdir($pluginRoot);
@rmdir($coreRoot);
@rmdir($proRoot);
@rmdir($root);

echo "elementor inventory contract OK\n";
