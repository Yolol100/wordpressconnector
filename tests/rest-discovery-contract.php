<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/DiscoveryAdapter.php';

final class ContractRestServer
{
    public array $routes = array();
    public array $namespaces = array('wp/v2', 'fixture-plugin/v1', 'other-plugin/v2');
    public function get_routes(): array { return $this->routes; }
    public function get_namespaces(): array { return $this->namespaces; }
}

$server = new ContractRestServer();
$called = 0;
$callback = static function () use (&$called): void { ++$called; };
$server->routes = array(
    '/fixture-plugin/v1' => array(array('methods' => 'GET')),
    '/fixture-plugin/v1/catalog' => array(array(
        'methods' => array('GET' => true, 'POST' => true),
        'callback' => $callback, 'permission_callback' => $callback,
        'args' => array('api_key' => array('default' => 'do-not-disclose')),
    )),
    '/fixture-plugin/v1/items/(?P<id>[\d]+)' => array(array('methods' => 'GET, PATCH')),
    '/fixture-plugin/v1/customer' => array(array('methods' => 'GET')),
    '/fixture-plugin/v1/private/orders' => array(array('methods' => 'GET')),
    '/fixture-plugin/v1/login' => array(array('methods' => 'GET')),
    '/fixture-plugin/v1/cart/add-item' => array(array('methods' => array('POST'))),
    '/fixture-plugin/v1/credential-reset' => array(array('methods' => 'GET')),
    '/other-plugin/v2/process' => array(array('methods' => array('POST'))),
    '/other-plugin/v2/bad route' => array(array('methods' => array('GET'))),
    '/wp/v2/posts' => array(array('methods' => array('GET', 'POST'))),
    '/wp/v2/users' => array(array('methods' => array('GET'))),
);
function rest_get_server() { global $server; return $server; }

$adapter = new \Webactueel\WordPressConnector\Adapters\DiscoveryAdapter();
$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter->register($registry);
$descriptor = $registry->descriptor('connector.rest_catalog');
if (empty($descriptor['privileged']) || $descriptor['mutation'] ||
    'manage_options' !== $descriptor['capability'] || ! empty($descriptor['public_repository_safe'])) {
    throw new RuntimeException('REST catalog does not require private administrator access.');
}
$catalog = $adapter->restCatalog(array(), array());
if (true !== $catalog['available'] || false !== $catalog['execution_enabled'] ||
    true !== $catalog['discovery_only'] || 4 !== $catalog['total'] ||
    count($catalog['routes']) !== 4 || 0 !== $called) {
    throw new RuntimeException('REST discovery incorrectly inventories or executes provider callbacks.');
}
$encoded = json_encode($catalog);
if (! is_string($encoded) ||
    strpos($encoded, 'do-not-disclose') !== false ||
    strpos($encoded, '/users') !== false ||
    strpos($encoded, '/customer') !== false ||
    strpos($encoded, '/login') !== false ||
    strpos($encoded, '/private/orders') !== false ||
    strpos($encoded, '/cart/') !== false ||
    strpos($encoded, '/credential-reset') !== false) {
    throw new RuntimeException('REST route inventory leaked sensitive metadata.');
}
$patterns = array_column($catalog['routes'], 'route_pattern');
foreach (array('/fixture-plugin/v1/catalog', '/fixture-plugin/v1/items/(?P<id>[\d]+)',
               '/other-plugin/v2/process', '/wp/v2/posts') as $expected) {
    if (! in_array($expected, $patterns, true)) {
        throw new RuntimeException('Expected safe REST route not discovered: ' . $expected);
    }
}
$filtered = $adapter->restCatalog(array('namespace' => 'fixture-plugin/v1', 'per_page' => 1, 'page' => 2), array());
if (2 !== $filtered['total'] || 2 !== $filtered['pages'] ||
    1 !== count($filtered['routes']) ||
    'fixture-plugin/v1' !== $filtered['routes'][0]['namespace']) {
    throw new RuntimeException('REST namespace filtering/paging failed.');
}
$empty = $adapter->restCatalog(array('namespace' => 'missing/v1', 'page' => 2), array());
if (0 !== $empty['total'] || array() !== $empty['routes'] || 1 !== $empty['pages']) {
    throw new RuntimeException('Unknown namespace must return an empty inventory.');
}
$repeat = $adapter->restCatalog(array(), array());
if ($repeat !== $catalog) {
    throw new RuntimeException('REST discovery is not deterministic.');
}
foreach (array(
    array('namespace' => '../wp/v2'),
    array('namespace' => array('fixture-plugin/v1')),
    array('page' => 0),
    array('page' => '-1'),
    array('page' => 10001),
    array('per_page' => 26),
    array('per_page' => '1junk'),
    array('dry_run' => true),
    array('method' => 'POST'),
) as $invalid) {
    try {
        $adapter->restCatalog($invalid, array());
        throw new RuntimeException('Invalid REST discovery input accepted: ' . json_encode($invalid));
    } catch (RuntimeException $error) {
        if (0 === strpos($error->getMessage(), 'Invalid REST discovery input accepted:')) {
            throw $error;
        }
    }
}
$server = null;
$unavailable = $adapter->restCatalog(array(), array());
if ($unavailable['available'] || $unavailable['routes'] ||
    true !== $unavailable['discovery_only']) {
    throw new RuntimeException('Absent server must fail safely.');
}
$server = new ContractRestServer();
$server->routes = array_fill_keys(array_map(static function (int $i): string {
    return '/fixture-plugin/v1/route-' . $i;
}, range(1, 15001)), array(array('methods' => 'GET')));
try {
    $adapter->restCatalog(array(), array());
    throw new RuntimeException('REST registry overflow accepted.');
} catch (RuntimeException $error) {
    if ('REST registry overflow accepted.' === $error->getMessage()) throw $error;
}
echo "REST discovery contract OK\n";
