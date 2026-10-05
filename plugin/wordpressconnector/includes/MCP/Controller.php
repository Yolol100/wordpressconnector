<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\MCP;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;

final class Controller
{
    private const NAMESPACE = 'webactueel-wordpress-connector/v1';
    private const ROUTE = '/mcp';
    private const MODERN_VERSION = '2026-07-28';
    private const LEGACY_VERSION = '2025-11-25';
    private const MAX_REQUEST_BYTES = 262144;

    private Runner $runner;
    private Registry $registry;
    private $authorizationCallback;

    public function __construct(Runner $runner, Registry $registry, callable $authorizationCallback)
    {
        $this->runner = $runner;
        $this->registry = $registry;
        $this->authorizationCallback = $authorizationCallback;
    }

    public function register(): void
    {
        add_action('rest_api_init', array($this, 'registerRoutes'));
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'handle'),
            'permission_callback' => array($this, 'authorize'),
        ));
    }

    public function authorize(\WP_REST_Request $request)
    {
        return call_user_func($this->authorizationCallback, $request);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = (string) $request->get_body();
        $trimmed = ltrim($body);
        if ('' === $trimmed || strlen($body) > self::MAX_REQUEST_BYTES || '{' !== $trimmed[0]) {
            return $this->errorResponse(null, -32600, 'Invalid Request', 400);
        }

        $message = $request->get_json_params();
        if (! is_array($message) || ! isset($message['jsonrpc']) || '2.0' !== $message['jsonrpc']) {
            return $this->errorResponse(null, -32600, 'Invalid Request', 400);
        }

        $idPresent = array_key_exists('id', $message);
        $id = $idPresent ? $message['id'] : null;
        if ($idPresent && ! is_int($id) && ! is_string($id)) {
            return $this->errorResponse(null, -32600, 'Invalid Request', 400);
        }

        if (! isset($message['method']) || ! is_string($message['method']) || '' === $message['method']) {
            return $this->errorResponse($id, -32600, 'Invalid Request', 400);
        }
        $method = $message['method'];

        $params = array();
        if (array_key_exists('params', $message)) {
            if (! is_array($message['params'])) {
                return $this->errorResponse($id, -32602, 'Invalid params', 400);
            }
            $params = $message['params'];
        }

        if ('notifications/initialized' === $method) {
            return new \WP_REST_Response(null, 202);
        }

        if (! $idPresent) {
            return new \WP_REST_Response(null, 202);
        }

        $modern = $this->isModernRequest($request, $method, $params);
        if ($modern) {
            try {
                $this->validateModernEnvelope($request, $method, $params);
            } catch (RuntimeException $error) {
                return $this->errorResponse($id, -32020, $error->getMessage(), 400);
            }
        } else {
            $headerVersion = (string) $request->get_header('mcp-protocol-version');
            if ('' !== $headerVersion && self::LEGACY_VERSION !== $headerVersion) {
                return $this->errorResponse($id, -32600, 'Unsupported MCP protocol version.', 400);
            }
        }

        try {
            switch ($method) {
                case 'server/discover':
                    if (! $modern) {
                        return $this->errorResponse($id, -32601, 'Method not found', 200);
                    }
                    return $this->resultResponse($id, $this->discoverResult(), true);

                case 'initialize':
                    if ($modern) {
                        return $this->errorResponse($id, -32601, 'Method not found', 200);
                    }
                    return $this->resultResponse($id, $this->initializeResult($params), false);

                case 'tools/list':
                    return $this->resultResponse($id, $this->listTools($modern), $modern);

                case 'tools/call':
                    return $this->resultResponse($id, $this->callTool($id, $params), $modern);

                default:
                    return $this->errorResponse($id, -32601, 'Method not found', 200);
            }
        } catch (RuntimeException $error) {
            return $this->errorResponse($id, -32602, $this->boundedMessage($error->getMessage()), 200);
        } catch (Throwable $error) {
            return $this->errorResponse($id, -32603, 'Internal error', 500);
        }
    }

    private function isModernRequest(\WP_REST_Request $request, string $method, array $params): bool
    {
        if ('server/discover' === $method) {
            return true;
        }
        if (self::MODERN_VERSION === (string) $request->get_header('mcp-protocol-version')) {
            return true;
        }
        $meta = isset($params['_meta']) && is_array($params['_meta']) ? $params['_meta'] : array();
        return self::MODERN_VERSION === (string) ($meta['io.modelcontextprotocol/protocolVersion'] ?? '');
    }

    private function validateModernEnvelope(\WP_REST_Request $request, string $method, array $params): void
    {
        if (self::MODERN_VERSION !== (string) $request->get_header('mcp-protocol-version')) {
            throw new RuntimeException('MCP-Protocol-Version header is missing or does not match the request.');
        }
        if ($method !== (string) $request->get_header('mcp-method')) {
            throw new RuntimeException('Mcp-Method header is missing or does not match the request.');
        }

        $meta = isset($params['_meta']) && is_array($params['_meta']) ? $params['_meta'] : null;
        if (null === $meta
            || self::MODERN_VERSION !== (string) ($meta['io.modelcontextprotocol/protocolVersion'] ?? '')
            || ! array_key_exists('io.modelcontextprotocol/clientCapabilities', $meta)
            || ! is_array($meta['io.modelcontextprotocol/clientCapabilities'])) {
            throw new RuntimeException('Modern MCP requests require protocolVersion and clientCapabilities in params._meta.');
        }

        if ('tools/call' === $method) {
            $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
            if ('' === $name || $name !== (string) $request->get_header('mcp-name')) {
                throw new RuntimeException('Mcp-Name header is missing or does not match params.name.');
            }
        }
    }

    private function discoverResult(): array
    {
        return array(
            'supportedVersions' => array(self::MODERN_VERSION),
            'capabilities' => array('tools' => array('listChanged' => false)),
            'instructions' => $this->instructions(),
            'ttlMs' => 300000,
            'cacheScope' => 'private',
        );
    }

    private function initializeResult(array $params): array
    {
        if (isset($params['protocolVersion']) && ! is_string($params['protocolVersion'])) {
            throw new RuntimeException('initialize.protocolVersion must be a string.');
        }
        return array(
            'protocolVersion' => self::LEGACY_VERSION,
            'capabilities' => array('tools' => array('listChanged' => false)),
            'serverInfo' => $this->serverInfo(),
            'instructions' => $this->instructions(),
        );
    }

    private function listTools(bool $modern): array
    {
        $result = array(
            'tools' => array(
                array(
                    'name' => 'wordpress_connector_actions',
                    'description' => 'List the WordPress Connector semantic actions and their security metadata. Use this before selecting an action.',
                    'inputSchema' => $this->emptyObjectSchema(),
                    'annotations' => array('readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false),
                ),
                array(
                    'name' => 'wordpress_connector_discover',
                    'description' => 'Discover the authenticated WordPress runtime, builders, plugins, post types, taxonomies, media sizes and connector capabilities.',
                    'inputSchema' => $this->emptyObjectSchema(),
                    'annotations' => array('readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false),
                ),
                array(
                    'name' => 'wordpress_connector_execute',
                    'description' => 'Execute one existing semantic connector action through the normal Runner. Dry-run is the default. Confirmed writes require confirm=true and an explicit stable request_id so retries remain idempotent.',
                    'inputSchema' => $this->executeSchema(),
                    'annotations' => array('readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false),
                ),
            ),
        );
        if ($modern) {
            $result['ttlMs'] = 300000;
            $result['cacheScope'] = 'private';
        }
        return $result;
    }

    private function callTool($rpcId, array $params): array
    {
        $allowed = array('name', 'arguments', '_meta');
        foreach (array_keys($params) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new RuntimeException('tools/call contains an unsupported parameter.');
            }
        }

        $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
        $arguments = isset($params['arguments']) ? $params['arguments'] : array();
        if ('' === $name || ! is_array($arguments)) {
            throw new RuntimeException('tools/call requires a tool name and object arguments.');
        }

        if ('wordpress_connector_actions' === $name) {
            $this->assertNoArguments($arguments, $name);
            return $this->toolResult(array('actions' => $this->registry->catalog()), false);
        }

        if ('wordpress_connector_discover' === $name) {
            $this->assertNoArguments($arguments, $name);
            $result = $this->runSemantic($rpcId, $name, 'connector.discover', array(), true, false, null, null, null);
            return $this->toolResult($result, empty($result['ok']));
        }

        if ('wordpress_connector_execute' === $name) {
            return $this->executeTool($rpcId, $name, $arguments);
        }

        throw new RuntimeException('Unknown MCP tool: ' . $name . '.');
    }

    private function executeTool($rpcId, string $toolName, array $arguments): array
    {
        $allowed = array('action', 'payload', 'dry_run', 'confirm', 'request_id', 'expected_fingerprint', 'expected_state_token');
        foreach (array_keys($arguments) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                return $this->toolError('wordpress_connector_execute contains an unsupported argument.');
            }
        }

        $action = isset($arguments['action']) && is_string($arguments['action']) ? $arguments['action'] : '';
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*\z/', $action)) {
            return $this->toolError('action is invalid.');
        }
        if (! array_key_exists('payload', $arguments) || ! is_array($arguments['payload'])) {
            return $this->toolError('payload must be an object.');
        }

        $dryRun = array_key_exists('dry_run', $arguments) ? $arguments['dry_run'] : true;
        $confirm = array_key_exists('confirm', $arguments) ? $arguments['confirm'] : false;
        if (! is_bool($dryRun) || ! is_bool($confirm)) {
            return $this->toolError('dry_run and confirm must be booleans.');
        }

        $requestId = isset($arguments['request_id']) && is_string($arguments['request_id']) ? $arguments['request_id'] : null;
        if (! $dryRun && null === $requestId) {
            return $this->toolError('Confirmed execution requires an explicit stable request_id.');
        }
        if (! $dryRun && ! $confirm) {
            return $this->toolError('Confirmed execution requires confirm=true.');
        }

        $expectedFingerprint = $this->optionalHash($arguments, 'expected_fingerprint');
        if (false === $expectedFingerprint) {
            return $this->toolError('expected_fingerprint must be a lowercase SHA-256 hex string.');
        }
        $expectedStateToken = $this->optionalHash($arguments, 'expected_state_token');
        if (false === $expectedStateToken) {
            return $this->toolError('expected_state_token must be a lowercase SHA-256 hex string.');
        }

        try {
            $result = $this->runSemantic(
                $rpcId,
                $toolName,
                $action,
                $arguments['payload'],
                $dryRun,
                $confirm,
                $requestId,
                $expectedFingerprint,
                $expectedStateToken
            );
        } catch (RuntimeException $error) {
            return $this->toolError($this->boundedMessage($error->getMessage()));
        }

        return $this->toolResult($result, empty($result['ok']));
    }

    private function runSemantic($rpcId, string $toolName, string $action, array $payload, bool $dryRun, bool $confirm, ?string $requestId, ?string $expectedFingerprint, ?string $expectedStateToken): array
    {
        if (null === $requestId) {
            $requestId = $this->generatedRequestId($rpcId, $toolName, $action, $payload);
        }

        $data = array(
            'version' => 1,
            'request_id' => $requestId,
            'action' => $action,
            'dry_run' => $dryRun,
            'confirm' => $confirm,
            'payload' => $payload,
        );
        if (null !== $expectedFingerprint) {
            $data['expected_fingerprint'] = $expectedFingerprint;
        }
        if (null !== $expectedStateToken) {
            $data['expected_state_token'] = $expectedStateToken;
        }

        $connectorRequest = Request::fromArray($data);
        return $this->runner->run($connectorRequest, array(
            'transport' => 'mcp',
            'authenticated_user_id' => get_current_user_id(),
        ));
    }

    private function resultResponse($id, array $result, bool $modern): \WP_REST_Response
    {
        if ($modern) {
            if (! isset($result['resultType'])) {
                $result = array_merge(array('resultType' => 'complete'), $result);
            }
            $meta = isset($result['_meta']) && is_array($result['_meta']) ? $result['_meta'] : array();
            $meta['io.modelcontextprotocol/serverInfo'] = $this->serverInfo();
            $result['_meta'] = $meta;
        }

        return new \WP_REST_Response(array(
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ), 200);
    }

    private function errorResponse($id, int $code, string $message, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response(array(
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => array('code' => $code, 'message' => $this->boundedMessage($message)),
        ), $status);
    }

    private function toolResult(array $value, bool $isError): array
    {
        return array(
            'content' => array(array(
                'type' => 'text',
                'text' => $this->encodeForText($value),
            )),
            'structuredContent' => array('result' => $value),
            'isError' => $isError,
        );
    }

    private function toolError(string $message): array
    {
        $value = array('ok' => false, 'error' => $this->boundedMessage($message));
        return $this->toolResult($value, true);
    }

    private function encodeForText(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            return '{"ok":false,"error":"Result could not be encoded."}';
        }
        return $json;
    }

    private function generatedRequestId($rpcId, string $toolName, string $action, array $payload): string
    {
        $json = json_encode(array(
            'rpc_id' => $rpcId,
            'tool' => $toolName,
            'action' => $action,
            'payload' => $payload,
            'user_id' => get_current_user_id(),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to generate a connector request id.');
        }
        return 'mcp-' . substr(hash('sha256', $json), 0, 48);
    }

    private function optionalHash(array $arguments, string $key)
    {
        if (! array_key_exists($key, $arguments) || null === $arguments[$key]) {
            return null;
        }
        if (! is_string($arguments[$key]) || ! preg_match('/^[a-f0-9]{64}\z/', $arguments[$key])) {
            return false;
        }
        return $arguments[$key];
    }

    private function assertNoArguments(array $arguments, string $toolName): void
    {
        if (array() !== $arguments) {
            throw new RuntimeException($toolName . ' does not accept arguments.');
        }
    }

    private function emptyObjectSchema(): array
    {
        return array(
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        );
    }

    private function executeSchema(): array
    {
        return array(
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'action' => array('type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]*$'),
                'payload' => array('type' => 'object'),
                'dry_run' => array('type' => 'boolean', 'default' => true),
                'confirm' => array('type' => 'boolean', 'default' => false),
                'request_id' => array('type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{7,99}$'),
                'expected_fingerprint' => array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$'),
                'expected_state_token' => array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$'),
            ),
            'required' => array('action', 'payload'),
            'allOf' => array(
                array(
                    'if' => array(
                        'properties' => array('dry_run' => array('const' => false)),
                        'required' => array('dry_run'),
                    ),
                    'then' => array(
                        'required' => array('request_id', 'confirm'),
                        'properties' => array('confirm' => array('const' => true)),
                    ),
                ),
            ),
        );
    }

    private function serverInfo(): array
    {
        return array(
            'name' => 'webactueel-wordpress-connector',
            'title' => 'WordPress Connector',
            'version' => defined('WPCONNECTOR_VERSION') ? (string) WPCONNECTOR_VERSION : 'unknown',
            'description' => 'Authenticated WordPress MCP transport over the existing semantic connector action registry.',
        );
    }

    private function instructions(): string
    {
        return 'Use wordpress_connector_discover and wordpress_connector_actions before execution. '
            . 'wordpress_connector_execute defaults to dry-run. Real mutations require confirm=true and a stable explicit request_id, '
            . 'and remain subject to WordPress capabilities, connector policy, stale-state guards, readback, idempotency and rollback.';
    }

    private function boundedMessage(string $message): string
    {
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);
        $message = is_string($message) ? trim($message) : '';
        if ('' === $message) {
            return 'Request failed.';
        }
        return strlen($message) > 1000 ? substr($message, 0, 1000) : $message;
    }
}
