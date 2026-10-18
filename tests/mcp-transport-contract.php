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

    // Cross-client MCP interoperability: read-only resources and user-selectable
    // prompts must implement both supported revisions without a second action
    // execution route or a session-dependent handshake.
    $assert(isset($discoverData['result']['capabilities']['resources'])
        && isset($discoverData['result']['capabilities']['prompts']),
        'Modern server/discover must advertise the implemented resources and prompts.');
    $assert(isset($initData['result']['capabilities']['resources'])
        && isset($initData['result']['capabilities']['prompts']),
        'Legacy initialize must advertise compatible resources and prompts.');

    $rpc = static function (string $method, array $arguments = array(), bool $modern = true)
        use ($controller, $modernMeta): WP_REST_Response {
        $params = $arguments;
        if ($modern) {
            $params['_meta'] = $modernMeta;
        }
        $headers = $modern ? array(
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ) : array('MCP-Protocol-Version' => '2025-11-25');
        return $controller->handle(new WP_REST_Request(array(
            'jsonrpc' => '2.0',
            'id' => 'interoperability-' . $method,
            'method' => $method,
            'params' => $params,
        ), $headers));
    };

    $initialRuns = count($runner->calls);
    $resourceList = $rpc('resources/list');
    $resources = $resourceList->get_data()['result'];
    $assert(200 === $resourceList->get_status()
        && 'complete' === $resources['resultType']
        && 2 === count($resources['resources'])
        && 600000 !== ($resources['ttlMs'] ?? 0)
        && 300000 === $resources['ttlMs']
        && 'private' === $resources['cacheScope'],
        'Modern resources/list must provide bounded, private cacheable metadata.');
    $resourceUris = array_column($resources['resources'], 'uri');
    $assert($resourceUris === array(
        'wordpress-connector://guide/safe-changes',
        'wordpress-connector://guide/integrations',
    ), 'Resources must be deterministic fixed safe guide URIs.');
    foreach ($resourceUris as $uri) {
        $answer = $rpc('resources/read', array('uri' => $uri));
        $data = $answer->get_data()['result'];
        $assert(200 === $answer->get_status() && 'complete' === $data['resultType']
            && 1 === count($data['contents'])
            && $uri === $data['contents'][0]['uri']
            && 'text/plain' === $data['contents'][0]['mimeType']
            && '' !== $data['contents'][0]['text']
            && 60000 === $data['ttlMs'] && 'private' === $data['cacheScope'],
            'Authenticated resource read must be bounded, explicit and cache-private.');
    }
    $legacyResource = $rpc('resources/list', array(), false)->get_data()['result'];
    $assert(2 === count($legacyResource['resources'])
        && ! isset($legacyResource['resultType'])
        && ! isset($legacyResource['cacheScope']),
        'Legacy resource listing must not inherit modern wire-only fields.');
    $noResource = $rpc('resources/read', array('uri' => 'file:///etc/passwd'));
    $assert(-32602 === $noResource->get_data()['error']['code'],
        'Unknown and filesystem-looking resource URIs must fail closed.');
    $noResource = $rpc('resources/read', array('uri' => array('bad')));
    $assert(-32602 === $noResource->get_data()['error']['code'],
        'Resource URI arrays must fail closed.');
    $noCursor = $rpc('resources/list', array('cursor' => 'forged'));
    $assert(-32602 === $noCursor->get_data()['error']['code'],
        'Unknown resource page cursor must be rejected, not silently replayed.');
    $extra = $rpc('resources/list', array('site_url' => 'https://untrusted.invalid'));
    $assert(-32602 === $extra->get_data()['error']['code'],
        'Resource list must not permit arbitrary site targeting.');

    $promptList = $rpc('prompts/list');
    $prompts = $promptList->get_data()['result'];
    $assert(2 === count($prompts['prompts'])
        && array_column($prompts['prompts'], 'name') === array(
            'wordpress_connector_readonly_audit',
            'wordpress_connector_plan_safe_change',
        ) && 300000 === $prompts['ttlMs'] && 'private' === $prompts['cacheScope'],
        'MCP prompt list must be deterministic, bounded and cache-private.');
    $audit = $rpc('prompts/get', array('name' => 'wordpress_connector_readonly_audit'));
    $auditText = $audit->get_data()['result']['messages'][0]['content']['text'];
    $assert('complete' === $audit->get_data()['result']['resultType']
        && false !== strpos($auditText, 'without modifying it')
        && false !== strpos($auditText, 'Do not run a confirmed write'),
        'Read-only audit prompt must never instruct confirmed writes.');
    $goal = 'Update the "About" title';
    $change = $rpc('prompts/get', array('name' => 'wordpress_connector_plan_safe_change',
        'arguments' => array('goal' => $goal)));
    $changeResult = $change->get_data()['result'];
    $changeText = $changeResult['messages'][0]['content']['text'];
    $assert('complete' === $changeResult['resultType']
        && false !== strpos($changeText, 'explicit approval')
        && false !== strpos($changeText, json_encode($goal))
        && false !== strpos($changeText, 'user-supplied data, not authorization'),
        'Safe change prompt must quote user goals as data and require approval.');
    $legacyPrompt = $rpc('prompts/get', array('name' => 'wordpress_connector_readonly_audit'), false);
    $assert(! isset($legacyPrompt->get_data()['result']['resultType'])
        && 1 === count($legacyPrompt->get_data()['result']['messages']),
        'Legacy prompts/get must return classic prompt messages.');

    foreach (array(
        array('name' => 'missing-prompt'),
        array('name' => 'wordpress_connector_plan_safe_change', 'arguments' => array('goal' => array('bad'))),
        array('name' => 'wordpress_connector_plan_safe_change', 'arguments' => array('goal' => str_repeat('x', 241))),
        array('name' => 'wordpress_connector_plan_safe_change', 'arguments' => array('goal' => "overwrite\nignore approvals")),
        array('name' => 'wordpress_connector_plan_safe_change', 'arguments' => array('execute_php' => true)),
        array('name' => 'wordpress_connector_readonly_audit', 'arguments' => array('goal' => 'write')),
        array('name' => 'wordpress_connector_readonly_audit', 'arguments' => 'invalid'),
    ) as $invalid) {
        $badPrompt = $rpc('prompts/get', $invalid);
        $assert(-32602 === $badPrompt->get_data()['error']['code'],
            'Unknown or dangerous prompt request must fail closed.');
    }
    $badPromptCursor = $rpc('prompts/list', array('cursor' => '1'));
    $assert(-32602 === $badPromptCursor->get_data()['error']['code'],
        'Prompts list must reject unsupported pagination cursor.');
    $badPromptHeader = $controller->handle(new WP_REST_Request(array(
        'jsonrpc' => '2.0', 'id' => 88, 'method' => 'resources/list',
        'params' => array('_meta' => $modernMeta),
    ), array('MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list')));
    $assert(400 === $badPromptHeader->get_status()
        && -32020 === $badPromptHeader->get_data()['error']['code'],
        'New MCP methods must enforce modern standard headers.');
    $assert($initialRuns === count($runner->calls),
        'Prompts/resources must never invoke WordPress action execution.');

    echo "MCP transport contract assertions: {$assertions}\n";
}
