<?php

if (! function_exists('rest_get_server') || ! function_exists('register_rest_route')) {
    throw new RuntimeException('WordPress REST server unavailable.');
}
$runtimeReads = 0;
$runtimePermissions = 0;
$registerFixtures = static function () use (&$runtimeReads, &$runtimePermissions): void {
    $read = static function () use (&$runtimeReads) { ++$runtimeReads; return array('secret' => 'never'); };
    $permission = static function () use (&$runtimePermissions) { ++$runtimePermissions; return true; };
    register_rest_route('connector-fixture/v1', '/catalog', array(
        array('methods' => \WP_REST_Server::READABLE, 'callback' => $read, 'permission_callback' => $permission),
        array('methods' => \WP_REST_Server::CREATABLE, 'callback' => $read, 'permission_callback' => $permission),
    ));
    register_rest_route('connector-fixture/v1', '/customer-credentials', array(
        'methods' => 'GET',
        'callback' => static function () use (&$runtimeReads) { ++$runtimeReads; return array('secret' => 'never'); },
        'permission_callback' => static function () use (&$runtimePermissions) { ++$runtimePermissions; return true; },
    ));
};
if (did_action('rest_api_init')) {
    $registerFixtures();
} else {
    add_action('rest_api_init', $registerFixtures);
}
rest_get_server();

$adapter = new \Webactueel\WordPressConnector\Adapters\DiscoveryAdapter();
$catalog = $adapter->restCatalog(array('namespace' => 'connector-fixture/v1'), array());
if (true !== ($catalog['available'] ?? false) || 1 !== ($catalog['total'] ?? 0) ||
    '/connector-fixture/v1/catalog' !== ($catalog['routes'][0]['route_pattern'] ?? '') ||
    true !== in_array('GET', $catalog['routes'][0]['advertised_methods'] ?? array(), true) ||
    true !== in_array('POST', $catalog['routes'][0]['advertised_methods'] ?? array(), true)) {
    throw new RuntimeException('Live WordPress route discovery failed.');
}
if ($runtimeReads !== 0 || $runtimePermissions !== 0 || ! empty($catalog['execution_enabled'])) {
    throw new RuntimeException('REST inventory invoked a provider callback or permission check.');
}
$repeat = $adapter->restCatalog(array('namespace' => 'connector-fixture/v1'), array());
if ($repeat !== $catalog) {
    throw new RuntimeException('REST inventory is not deterministic within runtime.');
}
echo 'REST discovery runtime OK (' . get_bloginfo('version') . ").\n";
