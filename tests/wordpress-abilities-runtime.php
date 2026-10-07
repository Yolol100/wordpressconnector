<?php

declare(strict_types=1);

if (! function_exists('wp_get_abilities') || ! class_exists('WP_Abilities_Registry') || ! class_exists('WP_Ability_Categories_Registry')) {
    echo "WordPress Abilities runtime unavailable; skipped.\n";
    return;
}

require_once __DIR__ . '/fixtures/elementor-plugin/NativeMcpProvider.php';
require_once __DIR__ . '/fixtures/third-party-plugin/SpoofedMcpProvider.php';

if (! defined('ELEMENTOR_PATH')) {
    define('ELEMENTOR_PATH', __DIR__ . '/fixtures/elementor-plugin/');
}

$categories = \WP_Ability_Categories_Registry::get_instance();
if (! $categories->is_registered('elementor-runtime')) {
    $category = $categories->register('elementor-runtime', array(
        'label' => 'Elementor Runtime Test',
        'description' => 'WordPress Connector controlled runtime category.',
    ));
    if (! $category) {
        throw new RuntimeException('Could not register controlled-runtime Ability category.');
    }
}

$registry = \WP_Abilities_Registry::get_instance();

$nativeName = 'elementor/runtime-native';
if (! $registry->is_registered($nativeName)) {
    $native = $registry->register($nativeName, array(
        'label' => 'Native Elementor Runtime Ability',
        'description' => 'Controlled runtime test for native Elementor Ability ownership.',
        'category' => 'elementor-runtime',
        'execute_callback' => array(new \Webactueel\Tests\Fixtures\ElementorPlugin\NativeMcpProvider(), 'execute'),
        'permission_callback' => static function (): bool { return true; },
        'meta' => array(
            'mcp' => array('public' => true),
            'show_in_rest' => true,
            'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
        ),
    ));
    if (! $native) {
        throw new RuntimeException('Could not register controlled-runtime native Ability.');
    }
}

$spoofName = 'elementor/runtime-spoof';
if (! $registry->is_registered($spoofName)) {
    $spoof = $registry->register($spoofName, array(
        'label' => 'Spoofed Elementor Runtime Ability',
        'description' => 'Controlled runtime spoof test.',
        'category' => 'elementor-runtime',
        'execute_callback' => array(new \Webactueel\Tests\Fixtures\ThirdPartyPlugin\SpoofedMcpProvider(), 'execute'),
        'permission_callback' => static function (): bool { return true; },
        'meta' => array(
            'mcp' => array('public' => true),
            'show_in_rest' => true,
            'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => false),
        ),
    ));
    if (! $spoof) {
        throw new RuntimeException('Could not register controlled-runtime spoof Ability.');
    }
}

$disabledName = 'elementor/runtime-disabled';
if (! $registry->is_registered($disabledName)) {
    $disabled = $registry->register($disabledName, array(
        'label' => 'Disabled Elementor Runtime Ability',
        'description' => 'Controlled runtime MCP opt-out test.',
        'category' => 'elementor-runtime',
        'execute_callback' => array(new \Webactueel\Tests\Fixtures\ElementorPlugin\NativeMcpProvider(), 'execute'),
        'permission_callback' => static function (): bool { return true; },
        'meta' => array(
            'mcp' => array('public' => false),
            'show_in_rest' => true,
            'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
        ),
    ));
    if (! $disabled) {
        throw new RuntimeException('Could not register controlled-runtime disabled Ability.');
    }
}

$adapter = new \Webactueel\WordPressConnector\Adapters\AbilitiesAdapter();
$catalog = $adapter->catalog(array('namespace' => 'elementor', 'per_page' => 10, 'page' => 1), array());

if (! isset($catalog['abilities'][$nativeName])) {
    throw new RuntimeException('Native Elementor Ability was not discovered in real WordPress runtime.');
}
if (true !== ($catalog['abilities'][$nativeName]['mutation_execution_exposed'] ?? false)) {
    throw new RuntimeException('Native Elementor Ability ownership was not accepted in real WordPress runtime.');
}
if (false !== ($catalog['abilities'][$spoofName]['mutation_execution_exposed'] ?? null)) {
    throw new RuntimeException('Spoofed Elementor Ability was accepted in real WordPress runtime.');
}
if (isset($catalog['abilities'][$disabledName])) {
    throw new RuntimeException('Explicit mcp.public=false Ability leaked into connector discovery.');
}

$preview = $adapter->executeAbility(array('name' => $nativeName), array('dry_run' => true));
if (true !== ($preview['would_execute'] ?? false)) {
    throw new RuntimeException('Native Elementor Ability dry-run failed in real WordPress runtime.');
}

$executed = $adapter->executeAbility(array('name' => $nativeName), array('dry_run' => false));
if ('elementor-core' !== ($executed['result']['provider'] ?? null)) {
    throw new RuntimeException('Native Elementor Ability did not execute through real WP_Ability.');
}

try {
    $adapter->executeAbility(array('name' => $spoofName), array('dry_run' => false));
    throw new RuntimeException('Spoofed Elementor Ability executed unexpectedly.');
} catch (RuntimeException $expected) {
    if ('Spoofed Elementor Ability executed unexpectedly.' === $expected->getMessage()) {
        throw $expected;
    }
}

echo 'WordPress Abilities runtime OK (' . get_bloginfo('version') . ").\n";
