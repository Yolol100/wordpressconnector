<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime {
    final class Request
    {
        private array $data;
        private function __construct(array $data) { $this->data = $data; }
        public static function fromArray(array $data): self { return new self($data); }
        public function id(): string { return (string) $this->data['request_id']; }
        public function action(): string { return (string) $this->data['action']; }
        public function payload(): array { return (array) $this->data['payload']; }
        public function dryRun(): bool { return (bool) $this->data['dry_run']; }
        public function confirm(): bool { return (bool) $this->data['confirm']; }
        public function data(): array { return $this->data; }
    }

    final class Registry
    {
        public function catalog(): array
        {
            return array('post.update' => array('mutation' => true, 'description' => 'Update one post.'));
        }
    }

    final class Runner
    {
        public array $calls = array();
        public function run(Request $request, array $context = array()): array
        {
            $this->calls[] = array('request' => $request->data(), 'context' => $context);
            return array(
                'ok' => true,
                'request_id' => $request->id(),
                'action' => $request->action(),
                'dry_run' => $request->dryRun(),
                'data' => array('echo' => $request->payload()),
            );
        }
    }
}

namespace {
    final class WP_REST_Server { public const CREATABLE = 'POST'; }

    final class WP_REST_Request
    {
        private string $body;
        private array $data;
        private array $headers;
        public function __construct(array $data, array $headers = array())
        {
            $this->data = $data;
            $this->body = json_encode($data, JSON_UNESCAPED_SLASHES);
            $this->headers = array_change_key_case($headers, CASE_LOWER);
        }
        public function get_body(): string { return $this->body; }
        public function get_json_params(): array { return $this->data; }
        public function get_header(string $name): string { return (string) ($this->headers[strtolower($name)] ?? ''); }
    }

    final class WP_REST_Response
    {
        private $data;
        private int $status;
        public function __construct($data = null, int $status = 200) { $this->data = $data; $this->status = $status; }
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
    }

    $GLOBALS['mcp_registered_routes'] = array();
    function add_action(string $hook, $callback): void {}
    function register_rest_route(string $namespace, string $route, array $args): void
    {
        $GLOBALS['mcp_registered_routes'][] = array($namespace, $route, $args);
    }
    function get_current_user_id(): int { return 7; }

    define('WPCONNECTOR_VERSION', '1.16.0-test');

    require __DIR__ . '/../plugin/wordpressconnector/includes/MCP/Controller.php';

    use Webactueel\WordPressConnector\MCP\Controller;
    use Webactueel\WordPressConnector\Runtime\Registry;
    use Webactueel\WordPressConnector\Runtime\Runner;

    $assertions = 0;
    $assert = static function (bool $condition, string $message) use (&$assertions): void {
        ++$assertions;
        if (! $condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $runner = new Runner();
    $registry = new Registry();
    $authCalls = 0;
    $controller = new Controller($runner, $registry, static function (WP_REST_Request $request) use (&$authCalls): bool {
        ++$authCalls;
        return true;
    });

    $controller->registerRoutes();
    $assert(1 === count($GLOBALS['mcp_registered_routes']), 'MCP route must register exactly once.');
    $route = $GLOBALS['mcp_registered_routes'][0];
    $assert('webactueel-wordpress-connector/v1' === $route[0] && '/mcp' === $route[1], 'MCP route path must be canonical.');
    $assert('POST' === $route[2]['methods'], 'MCP route must accept POST only.');
    $assert(is_callable($route[2]['permission_callback']), 'MCP route must have an explicit permission callback.');

    $authRequest = new WP_REST_Request(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'));
    $assert(true === $controller->authorize($authRequest) && 1 === $authCalls, 'MCP authorization must delegate to the existing REST authorization callback.');

    $modernMeta = array(
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => array(),
    );
    $modernHeaders = array(
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'server/discover',
    );
    $discover = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'server/discover',
        'params' => array('_meta' => $modernMeta),
    ), $modernHeaders));
    $discoverData = $discover->get_data();
    $assert(200 === $discover->get_status(), 'Modern discovery must return HTTP 200.');
    $assert('complete' === $discoverData['result']['resultType'], 'Modern results must carry resultType=complete.');
    $assert(array('2026-07-28') === $discoverData['result']['supportedVersions'], 'Modern discovery must advertise only the implemented modern revision.');
    $assert('webactueel-wordpress-connector' === $discoverData['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'], 'Modern result must stamp server identity.');

    $badHeaders = $modernHeaders;
    $badHeaders['Mcp-Method'] = 'tools/list';
    $bad = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'server/discover',
        'params' => array('_meta' => $modernMeta),
    ), $badHeaders));
    $assert(400 === $bad->get_status() && -32020 === $bad->get_data()['error']['code'], 'Modern standard-header mismatch must fail closed.');

    $initialize = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'initialize',
        'params' => array('protocolVersion' => '2025-11-25', 'capabilities' => array(), 'clientInfo' => array('name' => 'test', 'version' => '1')),
    )));
    $initData = $initialize->get_data();
    $assert('2025-11-25' === $initData['result']['protocolVersion'], 'Legacy initialize must negotiate 2025-11-25.');
    $assert(! isset($initData['result']['resultType']), 'Legacy results must not be modern-stamped.');

    $legacyList = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/list',
        'params' => array(),
    ), array('MCP-Protocol-Version' => '2025-11-25')));
    $legacyListData = $legacyList->get_data()['result'];
    $assert(3 === count($legacyListData['tools']), 'MCP must expose exactly three bounded transport tools.');
    $assert(! isset($legacyListData['ttlMs']) && ! isset($legacyListData['cacheScope']), 'Legacy tools/list must not contain modern cache hints.');

    $modernListHeaders = array('MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list');
    $modernList = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 5,
        'method' => 'tools/list',
        'params' => array('_meta' => $modernMeta),
    ), $modernListHeaders));
    $modernTools = $modernList->get_data()['result'];
    $assert('complete' === $modernTools['resultType'], 'Modern tools/list must carry resultType=complete.');
    $assert(300000 === $modernTools['ttlMs'] && 'private' === $modernTools['cacheScope'], 'Modern tools/list must carry bounded private cache hints.');
    $names = array_column($modernTools['tools'], 'name');
    $assert(array('wordpress_connector_actions', 'wordpress_connector_discover', 'wordpress_connector_execute') === $names, 'MCP tool surface must stay minimal and stable.');

    $callHeaders = array('MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'wordpress_connector_actions');
    $actions = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 6,
        'method' => 'tools/call',
        'params' => array('name' => 'wordpress_connector_actions', 'arguments' => array(), '_meta' => $modernMeta),
    ), $callHeaders));
    $actionsResult = $actions->get_data()['result'];
    $assert(false === $actionsResult['isError'] && isset($actionsResult['structuredContent']['result']['actions']['post.update']), 'Actions tool must expose the existing registry catalog without semantic duplication.');

    $executeHeaders = array('MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'wordpress_connector_execute');
    $dryRun = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 'dry-1',
        'method' => 'tools/call',
        'params' => array(
            'name' => 'wordpress_connector_execute',
            'arguments' => array('action' => 'post.get', 'payload' => array('id' => 10)),
            '_meta' => $modernMeta,
        ),
    ), $executeHeaders));
    $dryResult = $dryRun->get_data()['result'];
    $assert(false === $dryResult['isError'], 'MCP execute dry-run path must succeed through Runner.');
    $last = $runner->calls[count($runner->calls) - 1];
    $assert(true === $last['request']['dry_run'] && false === $last['request']['confirm'], 'MCP execute must default to dry-run and no confirmation.');
    $assert((bool) preg_match('/^mcp-[a-f0-9]{48}$/', $last['request']['request_id']), 'Dry-run must receive a bounded generated request id.');
    $assert('mcp' === $last['context']['transport'] && 7 === $last['context']['authenticated_user_id'], 'MCP execution context must identify transport and authenticated user.');

    $before = count($runner->calls);
    $missingId = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 7,
        'method' => 'tools/call',
        'params' => array(
            'name' => 'wordpress_connector_execute',
            'arguments' => array('action' => 'post.update', 'payload' => array('id' => 10), 'dry_run' => false, 'confirm' => true),
            '_meta' => $modernMeta,
        ),
    ), $executeHeaders));
    $assert(true === $missingId->get_data()['result']['isError'] && $before === count($runner->calls), 'Confirmed execution without explicit request_id must fail before Runner.');

    $confirmed = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 8,
        'method' => 'tools/call',
        'params' => array(
            'name' => 'wordpress_connector_execute',
            'arguments' => array(
                'action' => 'post.update',
                'payload' => array('id' => 10, 'title' => 'Changed'),
                'dry_run' => false,
                'confirm' => true,
                'request_id' => 'mcp-live-request-0001',
            ),
            '_meta' => $modernMeta,
        ),
    ), $executeHeaders));
    $assert(false === $confirmed->get_data()['result']['isError'], 'Confirmed execution with stable request id must reach Runner.');
    $last = $runner->calls[count($runner->calls) - 1];
    $assert(false === $last['request']['dry_run'] && true === $last['request']['confirm'] && 'mcp-live-request-0001' === $last['request']['request_id'], 'Confirmed execution must preserve explicit mutation identity and confirmation.');

    $badHash = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 9,
        'method' => 'tools/call',
        'params' => array(
            'name' => 'wordpress_connector_execute',
            'arguments' => array('action' => 'post.get', 'payload' => array('id' => 10), 'expected_fingerprint' => 'bad'),
            '_meta' => $modernMeta,
        ),
    ), $executeHeaders));
    $assert(true === $badHash->get_data()['result']['isError'], 'Malformed stale-state guards must fail closed.');

    $wrongNameHeaders = $executeHeaders;
    $wrongNameHeaders['Mcp-Name'] = 'wordpress_connector_actions';
    $wrongName = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'id' => 10,
        'method' => 'tools/call',
        'params' => array('name' => 'wordpress_connector_execute', 'arguments' => array(), '_meta' => $modernMeta),
    ), $wrongNameHeaders));
    $assert(400 === $wrongName->get_status() && -32020 === $wrongName->get_data()['error']['code'], 'Mcp-Name mismatch must fail closed before tool execution.');

    $before = count($runner->calls);
    $notification = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0',
        'method' => 'tools/call',
        'params' => array('name' => 'wordpress_connector_execute', 'arguments' => array('action' => 'post.get', 'payload' => array('id' => 10))),
    )));
    $assert(202 === $notification->get_status() && null === $notification->get_data() && $before === count($runner->calls), 'No-id notifications must never execute connector actions.');

    echo "MCP transport contract assertions: {$assertions}\n";
}
