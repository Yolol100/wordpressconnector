<?php
/**
 * Implements Controller handling for the connector MCP endpoint.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\MCP;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Security\FilesystemPolicy;
use Webactueel\WordPressConnector\Security\NativeOAuthServer;
use Webactueel\WordPressConnector\Security\OAuthResourceServer;

/**
 * Implements Controller handling for the connector MCP endpoint.
 */
final class Controller {
	private const NAMESPACE             = 'webactueel-wordpress-connector/v1';
	private const ROUTE                 = '/mcp';
	private const MODERN_VERSION        = '2026-07-28';
	private const LEGACY_VERSION        = '2025-11-25';
	private const MAX_REQUEST_BYTES     = 262144;
	private const RESOURCE_SAFE_CHANGES = 'wordpress-connector://guide/safe-changes';
	private const RESOURCE_INTEGRATIONS = 'wordpress-connector://guide/integrations';
	private const PROMPT_AUDIT          = 'wordpress_connector_readonly_audit';
	private const PROMPT_CHANGE         = 'wordpress_connector_plan_safe_change';

	/**
	 * Runner maintained by the connector.
	 *
	 * @var Runner
	 */
	private Runner $runner;
	/**
	 * Registry maintained by the connector.
	 *
	 * @var Registry
	 */
	private Registry $registry;
	/**
	 * Authorization callback maintained by the connector.
	 *
	 * @var mixed
	 */
	private $authorization_callback;
	/**
	 * Optional OAuth protected-resource authenticator.
	 *
	 * @var OAuthResourceServer|null
	 */
	private ?OAuthResourceServer $oauth_resource;

	/**
	 * Initialize the MCP controller dependencies.
	 *
	 * @param Runner                   $runner Value used by this operation.
	 * @param Registry                 $registry Connector action registry.
	 * @param callable                 $authorizationCallback Value used by this operation.
	 * @param OAuthResourceServer|null $oauth_resource Optional protected resource authenticator.
	 */
	public function __construct( Runner $runner, Registry $registry, callable $authorizationCallback, ?OAuthResourceServer $oauth_resource = null ) {
		$this->runner                 = $runner;
		$this->registry               = $registry;
		$this->authorization_callback = $authorizationCallback;
		$this->oauth_resource         = $oauth_resource;
	}

	/**
	 * Register the MCP controller actions and handlers.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Register the supported REST and MCP routes.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * Authorize the incoming MCP request.
	 *
	 * @param \WP_REST_Request $request Incoming request object.
	 *
	 * @return mixed Result of the operation.
	 */
	public function authorize( \WP_REST_Request $request ) {
		if ( null !== $this->oauth_resource ) {
			$oauth_result = $this->oauth_resource->authenticate( $request );
			if ( null !== $oauth_result ) {
				// Request a fresh OAuth grant before running a staging file tool.
				// An insufficient-scope error at the HTTP gate lets MCP clients
				// invoke their standard authorization step-up flow.
				if ( true === $oauth_result && $this->oauth_resource->isAuthenticated()
					&& NativeOAuthServer::staging_files_enabled() && ! $this->oauth_resource->can_edit_staging_files() ) {
					$message = $request->get_json_params();
					$params  = is_array( $message ) ? ( $message['params'] ?? null ) : null;
					$args    = is_array( $params ) ? ( $params['arguments'] ?? null ) : null;
					$action  = is_array( $args ) ? ( $args['action'] ?? null ) : null;
					if ( is_array( $message ) && 'tools/call' === ( $message['method'] ?? null )
						&& is_array( $params ) && 'wordpress_connector_execute' === ( $params['name'] ?? null )
						&& in_array( $action, array( 'filesystem.inspect', 'filesystem.list', 'filesystem.read_text', 'filesystem.write_text' ), true ) ) {
						OAuthResourceServer::challenge( 'insufficient_scope', true );
						return new \WP_Error( 'wpconnector_oauth_scope_denied', 'Staging file access requires separate OAuth approval.', array( 'status' => 403 ) );
					}
				}
				return $oauth_result;
			}
		}
		$result = call_user_func( $this->authorization_callback, $request );
		if ( null !== $this->oauth_resource && $result instanceof \WP_Error ) {
			OAuthResourceServer::challenge();
		}
		return $result;
	}

	/**
	 * Handle the incoming MCP request.
	 *
	 * @param \WP_REST_Request $request Incoming request object.
	 *
	 * @return \WP_REST_Response Result of the operation.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body    = (string) $request->get_body();
		$trimmed = ltrim( $body );
		if ( '' === $trimmed || strlen( $body ) > self::MAX_REQUEST_BYTES || '{' !== $trimmed[0] ) {
			return $this->error_response( null, -32600, 'Invalid Request', 400 );
		}

		$message = $request->get_json_params();
		if ( ! is_array( $message ) || ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return $this->error_response( null, -32600, 'Invalid Request', 400 );
		}

		$id_present = array_key_exists( 'id', $message );
		$id         = $id_present ? $message['id'] : null;
		if ( $id_present && ! is_int( $id ) && ! is_string( $id ) ) {
			return $this->error_response( null, -32600, 'Invalid Request', 400 );
		}

		if ( ! isset( $message['method'] ) || ! is_string( $message['method'] ) || '' === $message['method'] ) {
			return $this->error_response( $id, -32600, 'Invalid Request', 400 );
		}
		$method = $message['method'];

		$params = array();
		if ( array_key_exists( 'params', $message ) ) {
			if ( ! is_array( $message['params'] ) ) {
				return $this->error_response( $id, -32602, 'Invalid params', 400 );
			}
			$params = $message['params'];
		}

		if ( 'notifications/initialized' === $method ) {
			return new \WP_REST_Response( null, 202 );
		}

		if ( ! $id_present ) {
			return new \WP_REST_Response( null, 202 );
		}

		$modern = $this->is_modern_request( $request, $method, $params );
		if ( $modern ) {
			try {
				$this->validate_modern_envelope( $request, $method, $params );
			} catch ( RuntimeException $error ) {
				return $this->error_response( $id, -32020, $error->getMessage(), 400 );
			}
		} else {
			$header_version = (string) $request->get_header( 'mcp-protocol-version' );
			if ( '' !== $header_version && self::LEGACY_VERSION !== $header_version ) {
				return $this->error_response( $id, -32600, 'Unsupported MCP protocol version.', 400 );
			}
		}

		try {
			switch ( $method ) {
				case 'server/discover':
					if ( ! $modern ) {
						return $this->error_response( $id, -32601, 'Method not found', 200 );
					}
					return $this->result_response( $id, $this->discover_result(), true );

				case 'initialize':
					if ( $modern ) {
						return $this->error_response( $id, -32601, 'Method not found', 200 );
					}
					return $this->result_response( $id, $this->initialize_result( $params ), false );

				case 'tools/list':
					return $this->result_response( $id, $this->list_tools( $modern ), $modern );

				case 'tools/call':
					return $this->result_response( $id, $this->call_tool( $id, $params ), $modern );

				case 'resources/list':
					return $this->result_response( $id, $this->list_resources( $params, $modern ), $modern );

				case 'resources/read':
					return $this->result_response( $id, $this->read_resource( $params, $modern ), $modern );

				case 'prompts/list':
					return $this->result_response( $id, $this->list_prompts( $params, $modern ), $modern );

				case 'prompts/get':
					return $this->result_response( $id, $this->get_prompt( $params ), $modern );

				default:
					return $this->error_response( $id, -32601, 'Method not found', 200 );
			}
		} catch ( RuntimeException $error ) {
			return $this->error_response( $id, -32602, $this->bounded_message( $error->getMessage() ), 200 );
		} catch ( Throwable $error ) {
			return $this->error_response( $id, -32603, 'Internal error', 500 );
		}
	}

	/**
	 * Check whether modern request in the MCP controller.
	 *
	 * @param \WP_REST_Request $request Incoming request object.
	 * @param string           $method Value used by this operation.
	 * @param array            $params Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function is_modern_request( \WP_REST_Request $request, string $method, array $params ): bool {
		if ( 'server/discover' === $method ) {
			return true;
		}
		if ( self::MODERN_VERSION === (string) $request->get_header( 'mcp-protocol-version' ) ) {
			return true;
		}
		$meta = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
		return self::MODERN_VERSION === (string) ( $meta['io.modelcontextprotocol/protocolVersion'] ?? '' );
	}

	/**
	 * Validate modern envelope in the MCP controller.
	 *
	 * @param \WP_REST_Request $request Incoming request object.
	 * @param string           $method Value used by this operation.
	 * @param array            $params Value used by this operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function validate_modern_envelope( \WP_REST_Request $request, string $method, array $params ): void {
		if ( self::MODERN_VERSION !== (string) $request->get_header( 'mcp-protocol-version' ) ) {
			throw new RuntimeException( 'MCP-Protocol-Version header is missing or does not match the request.' );
		}
		if ( $method !== (string) $request->get_header( 'mcp-method' ) ) {
			throw new RuntimeException( 'Mcp-Method header is missing or does not match the request.' );
		}

		$meta = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : null;
		if ( null === $meta
			|| self::MODERN_VERSION !== (string) ( $meta['io.modelcontextprotocol/protocolVersion'] ?? '' )
			|| ! array_key_exists( 'io.modelcontextprotocol/clientCapabilities', $meta )
			|| ! is_array( $meta['io.modelcontextprotocol/clientCapabilities'] ) ) {
			throw new RuntimeException( 'Modern MCP requests require protocolVersion and clientCapabilities in params._meta.' );
		}

		if ( 'tools/call' === $method ) {
			$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
			if ( '' === $name || $name !== (string) $request->get_header( 'mcp-name' ) ) {
				throw new RuntimeException( 'Mcp-Name header is missing or does not match params.name.' );
			}
		}
	}

	/**
	 * Process the discover result operation in the MCP controller.
	 *
	 * @return array Result of the operation.
	 */
	private function discover_result(): array {
		return array(
			'supportedVersions' => array( self::MODERN_VERSION ),
			'capabilities'      => $this->capabilities(),
			'instructions'      => $this->instructions(),
			'ttlMs'             => 300000,
			'cacheScope'        => 'private',
		);
	}

	/**
	 * Process the initialize result operation in the MCP controller.
	 *
	 * @param array $params Value used by this operation.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function initialize_result( array $params ): array {
		if ( isset( $params['protocolVersion'] ) && ! is_string( $params['protocolVersion'] ) ) {
			throw new RuntimeException( 'initialize.protocolVersion must be a string.' );
		}
		return array(
			'protocolVersion' => self::LEGACY_VERSION,
			'capabilities'    => $this->capabilities(),
			'serverInfo'      => $this->server_info(),
			'instructions'    => $this->instructions(),
		);
	}


	/**
	 * The MCP resources/prompts are metadata-only, not alternative WordPress
	 * execution routes. Every request retains the existing authenticated
	 * WordPress REST permission_callback and never calls the Runner.
	 */
	private function capabilities(): array {
		return array(
			'tools'     => array( 'listChanged' => false ),
			'resources' => array(
				'listChanged' => false,
				'subscribe'   => false,
			),
			'prompts'   => array( 'listChanged' => false ),
		);
	}

	/**
	 * Ensure allowed params in the MCP controller.
	 *
	 * @param array $params Value used by this operation.
	 * @param array $allowed Value used by this operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function assert_allowed_params( array $params, array $allowed ): void {
		$allowed[] = '_meta';
		foreach ( array_keys( $params ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				throw new RuntimeException( 'MCP request contains an unsupported parameter.' );
			}
		}
	}

	/**
	 * Ensure unpaged list in the MCP controller.
	 *
	 * @param array $params Value used by this operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function assert_unpaged_list( array $params ): void {
		$this->assert_allowed_params( $params, array( 'cursor' ) );
		if ( array_key_exists( 'cursor', $params ) && null !== $params['cursor'] ) {
			throw new RuntimeException( 'Pagination cursor is not supported for this bounded list.' );
		}
	}

	/**
	 * Process the private cache operation in the MCP controller.
	 *
	 * @param array $result Value used by this operation.
	 * @param bool  $modern Value used by this operation.
	 * @param int   $ttl_ms Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function private_cache( array $result, bool $modern, int $ttl_ms ): array {
		if ( $modern ) {
			$result['ttlMs']      = $ttl_ms;
			$result['cacheScope'] = 'private';
		}
		return $result;
	}

	/**
	 * List resources in the MCP controller.
	 *
	 * @param array $params Value used by this operation.
	 * @param bool  $modern Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function list_resources( array $params, bool $modern ): array {
		$this->assert_unpaged_list( $params );
		return $this->private_cache(
			array(
				'resources' => array(
					array(
						'uri'         => self::RESOURCE_SAFE_CHANGES,
						'name'        => 'safe-changes',
						'title'       => 'Safe WordPress change workflow',
						'description' => 'Read-only guidance for dry-runs, confirmations, backups and verification.',
						'mimeType'    => 'text/plain',
					),
					array(
						'uri'         => self::RESOURCE_INTEGRATIONS,
						'name'        => 'integrations',
						'title'       => 'WordPress plugin and builder integration guide',
						'description' => 'Read-only guidance on native WordPress Abilities and provider boundaries.',
						'mimeType'    => 'text/plain',
					),
				),
			),
			$modern,
			300000
		);
	}

	/**
	 * Read resource in the MCP controller.
	 *
	 * @param array $params Value used by this operation.
	 * @param bool  $modern Value used by this operation.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function read_resource( array $params, bool $modern ): array {
		$this->assert_allowed_params( $params, array( 'uri' ) );
		if ( ! isset( $params['uri'] ) || ! is_string( $params['uri'] ) ) {
			throw new RuntimeException( 'Resource URI must be a string.' );
		}

		if ( self::RESOURCE_SAFE_CHANGES === $params['uri'] ) {
			$text = 'WordPress Connector safe change workflow: inspect authenticated runtime and action catalog; '
				. 'select one supported semantic action; do a dry-run; verify the exact target and expected '
				. 'fingerprint/state token where provided; get explicit user approval before confirm=true; '
				. 'send a stable request_id; verify changes by independent readback; restore from a proven '
				. 'backup when an action lacks rollback. Never copy credentials into a GitHub repository. '
				. 'GitHub OIDC and WordPress Application Passwords are separate credentials. '
				. 'Server-side theme replacement and third-party Ability writes are disabled by default.';
		} elseif ( self::RESOURCE_INTEGRATIONS === $params['uri'] ) {
			// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Client tool names must remain exact protocol identifiers.
			$text = 'Use wordpress_connector_discover for the current environment and wordpress_connector_actions '
				. 'for permitted semantic actions. Use wordpress.abilities to discover installed provider ' // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Canonical public action name, not prose.
				. 'Abilities: only entries with an exposed connector_action can be executed through the '
				. 'normal WordPress permissions and Connector policy. Elementor and WooCommerce manage their '
				. 'own provider-side requirements; never assume a plugin feature is installed, enabled or '
				. 'safe to modify without provider discovery and target readback. Never invent actions, '
				. 'run arbitrary PHP, shell or SQL, or bypass an unavailable capability.';
		} else {
			throw new RuntimeException( 'Resource not found.' );
		}

		return $this->private_cache(
			array(
				'contents' => array(
					array(
						'uri'      => $params['uri'],
						'mimeType' => 'text/plain',
						'text'     => $text,
					),
				),
			),
			$modern,
			60000
		);
	}

	/**
	 * List prompts in the MCP controller.
	 *
	 * @param array $params Value used by this operation.
	 * @param bool  $modern Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function list_prompts( array $params, bool $modern ): array {
		$this->assert_unpaged_list( $params );
		return $this->private_cache(
			array(
				'prompts' => array(
					array(
						'name'        => self::PROMPT_AUDIT,
						'title'       => 'Audit a WordPress site (read-only)',
						'description' => 'Inspect available capabilities and report actionable risks without changes.',
					),
					array(
						'name'        => self::PROMPT_CHANGE,
						'title'       => 'Plan a safe WordPress change',
						'description' => 'Prepare a change with dry-run, approval, readback and rollback steps.',
						'arguments'   => array(
							array(
								'name'        => 'goal',
								'description' => 'Optional short description of the intended change.',
								'required'    => false,
							),
						),
					),
				),
			),
			$modern,
			300000
		);
	}

	/**
	 * Retrieve prompt in the MCP controller.
	 *
	 * @param array $params Value used by this operation.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function get_prompt( array $params ): array {
		$this->assert_allowed_params( $params, array( 'name', 'arguments' ) );
		$name = $params['name'] ?? null;
		if ( ! is_string( $name ) || ! in_array( $name, array( self::PROMPT_AUDIT, self::PROMPT_CHANGE ), true ) ) {
			throw new RuntimeException( 'Unknown MCP prompt.' );
		}
		$arguments = $params['arguments'] ?? array();
		if ( ! is_array( $arguments ) ) {
			throw new RuntimeException( 'Prompt arguments must be an object.' );
		}

		if ( self::PROMPT_AUDIT === $name ) {
			if ( array() !== $arguments ) {
				throw new RuntimeException( 'Read-only audit prompt does not accept arguments.' );
			}
			$text = 'Audit this WordPress site without modifying it. First verify the authenticated '
				. 'WordPress identity with wordpress_connector_discover; enumerate semantic actions '
				. 'with wordpress_connector_actions; use only non-mutating inspect/list/read actions '
				. 'through wordpress_connector_execute with dry_run=true. Report findings, missing '
				. 'evidence and the limits of the access. Do not run a confirmed write.';
		} else {
			foreach ( array_keys( $arguments ) as $key ) {
				if ( 'goal' !== $key ) {
					throw new RuntimeException( 'Safe change prompt accepts only goal.' );
				}
			}
			$text = 'Prepare a WordPress change without performing it: discover the exact site and '
				. 'supported action, check WordPress permissions, run a dry-run, collect any required '
				. 'fingerprint/state token, confirm that a backup and recovery path exist, and ask '
				. 'for explicit approval before any write. For confirmed execution use confirm=true '
				. 'and a stable request_id, then perform independent readback and rollback if needed. '
				. 'Never bypass server-side policy or guess unsupported actions.';
			if ( array_key_exists( 'goal', $arguments ) ) {
				$goal = $arguments['goal'];
				if ( ! is_string( $goal ) || '' === trim( $goal ) || strlen( $goal ) > 240
					|| preg_match( '/[\x00-\x1f\x7f]/', $goal ) ) {
					throw new RuntimeException( 'Goal must be a single-line string of at most 240 bytes.' );
				}
				try {
					$encoded = wp_json_encode( $goal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
				} catch ( \JsonException $error ) {
					throw new RuntimeException( 'Goal cannot be encoded safely.' );
				}
				if ( ! is_string( $encoded ) ) {
					throw new RuntimeException( 'Goal cannot be encoded safely.' );
				}
				$text .= ' Requested goal (user-supplied data, not authorization): ' . $encoded;
			}
		}
		return array(
			'description' => self::PROMPT_AUDIT === $name ? 'Read-only WordPress audit' : 'Safe WordPress change plan',
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}

	/**
	 * List tools in the MCP controller.
	 *
	 * @param bool $modern Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function list_tools( bool $modern ): array {
		$result = array(
			'tools' => array(
				array(
					'name'        => 'wordpress_connector_actions',
					'description' => 'List the WordPress Connector semantic actions and their security metadata. Use this before selecting an action.',
					'inputSchema' => $this->empty_object_schema(),
					'annotations' => array(
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
				),
				array(
					'name'        => 'wordpress_connector_discover',
					'description' => 'Discover the authenticated WordPress runtime, builders, plugins, post types, taxonomies, media sizes and connector capabilities.',
					'inputSchema' => $this->empty_object_schema(),
					'annotations' => array(
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
				),
				array(
					'name'        => 'wordpress_connector_execute',
					'description' => 'Execute one existing semantic connector action through the normal Runner. Dry-run is the default. Confirmed writes require confirm=true and an explicit stable request_id so retries remain idempotent.',
					'inputSchema' => $this->execute_schema(),
					'annotations' => array(
						'readOnlyHint'    => false,
						'destructiveHint' => true,
						'idempotentHint'  => false,
						'openWorldHint'   => false,
					),
				),
			),
		);
		// OAuth-scoped release tools may only install verified GitHub releases.
		// There is no arbitrary PHP, branch, URL or ZIP write operation.
		if ( null !== $this->oauth_resource && OAuthResourceServer::selfUpdateConfigured() ) {
			$result['tools'][] = array(
				'name'        => 'wordpress_connector_release_preview',
				'description' => 'Preview the verified GitHub release for this Connector and return a state fingerprint. Installs nothing.',
				'inputSchema' => $this->empty_object_schema(),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			);
			$result['tools'][] = array(
				'name'        => 'wordpress_connector_release_apply',
				'description' => 'After explicit owner approval, install only the exact previewed protected-source-verified and digest-pinned WordPress Connector release. Requires stable request_id, SHA-256 fingerprint and confirm=true.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'request_id'           => array(
							'type'      => 'string',
							'minLength' => 8,
							'maxLength' => 100,
							'pattern'   => '^[A-Za-z0-9][A-Za-z0-9._-]{7,99}$',
						),
						'expected_fingerprint' => array(
							'type'    => 'string',
							'pattern' => '^[a-f0-9]{64}$',
						),
						'confirm'              => array(
							'type'  => 'boolean',
							'const' => true,
						),
					),
					'required'             => array( 'request_id', 'expected_fingerprint', 'confirm' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			);
		}
		// Descriptors never override OAuth introspection or Runner policy.
		if ( null !== $this->oauth_resource && OAuthResourceServer::isConfigured() ) {
			foreach ( $result['tools'] as &$tool ) {
				$is_update = in_array(
					$tool['name'],
					array(
						'wordpress_connector_release_preview',
						'wordpress_connector_release_apply',
					),
					true
				);
				// Keep the initial ChatGPT connection read-only. A file action
				// requests separate consent using the scoped HTTP challenge in authorize().
				$scopes = $is_update ? array( 'wpconnector:read', 'wpconnector:update' ) : array( 'wpconnector:read' );

				$schemes                          = array(
					array(
						'type'   => 'oauth2',
						'scopes' => $scopes,
					),
				);
				$tool['securitySchemes']          = $schemes;
				$tool['_meta']['securitySchemes'] = $schemes;
			}
			unset( $tool );
		}
		if ( $modern ) {
			$result['ttlMs']      = 300000;
			$result['cacheScope'] = 'private';
		}
		return $result;
	}

	/**
	 * Process the call tool operation in the MCP controller.
	 *
	 * @param mixed $rpc_id Identifier of the requested object.
	 * @param array $params Value used by this operation.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function call_tool( $rpc_id, array $params ): array {
		$allowed = array( 'name', 'arguments', '_meta' );
		foreach ( array_keys( $params ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				throw new RuntimeException( 'tools/call contains an unsupported parameter.' );
			}
		}

		$name      = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		$arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();
		if ( '' === $name || ! is_array( $arguments ) ) {
			throw new RuntimeException( 'tools/call requires a tool name and object arguments.' );
		}

		if ( 'wordpress_connector_actions' === $name ) {
			$this->assert_no_arguments( $arguments, $name );
			return $this->tool_result( array( 'actions' => $this->registry->catalog() ), false );
		}

		if ( 'wordpress_connector_discover' === $name ) {
			$this->assert_no_arguments( $arguments, $name );
			$result = $this->run_semantic( $rpc_id, $name, 'connector.discover', array(), true, false, null, null, null );
			return $this->tool_result( $result, empty( $result['ok'] ) );
		}

		if ( 'wordpress_connector_execute' === $name ) {
			return $this->execute_tool( $rpc_id, $name, $arguments );
		}
		if ( in_array( $name, array( 'wordpress_connector_release_preview', 'wordpress_connector_release_apply' ), true ) ) {
			return $this->release_tool( $rpc_id, $name, $arguments );
		}

		throw new RuntimeException( 'Unknown MCP tool: ' . $name . '.' );
	}

	/**
	 * Narrow, opt-in OAuth filesystem access. This is not a general admin mode.
	 * File replacement delegates SHA-256, content validation and rollback to
	 * the already guarded FilesystemAdapter; all other actions remain denied.
	 *
	 * @param string $action Existing semantic action.
	 * @param array  $payload User-supplied action payload.
	 * @param bool   $dry_run Dry-run request.
	 * @param bool   $confirm Explicit write confirmation.
	 */
	private function staging_file_action_allowed( string $action, array $payload, bool $dry_run, bool $confirm ): bool {
		if ( null === $this->oauth_resource || ! $this->oauth_resource->can_edit_staging_files() ) {
			return false;
		}
		if ( ! in_array( $action, array( 'filesystem.inspect', 'filesystem.list', 'filesystem.read_text', 'filesystem.write_text' ), true ) ) {
			return false;
		}
		if ( 'filesystem.inspect' === $action ) {
			return array() === $payload && $dry_run && ! $confirm;
		}
		if ( ! isset( $payload['path'] ) || ! is_string( $payload['path'] ) ) {
			return false;
		}
		$path = $payload['path'];
		try {
			$normalized = FilesystemPolicy::assertListable( $path );
		} catch ( RuntimeException $error ) {
			return false;
		}
		if ( $path !== $normalized ) {
			return false;
		}
		$segments = explode( '/', $path );
		if ( count( $segments ) < 2 || 'wp-content' !== $segments[0]
			|| ! in_array( $segments[1], array( 'plugins', 'themes' ), true ) ) {
			return false;
		}
		if ( 'filesystem.list' === $action ) {
			return $dry_run && ! $confirm && array_keys( $payload ) === array( 'path' );
		}
		// Require a file within an actual plugin/theme folder, never a root
		// file. Reuse the adapter's canonical path and secret protections.
		if ( count( $segments ) < 4
			|| ! in_array( strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'css', 'txt', 'md' ), true ) ) {
			return false;
		}
		try {
			FilesystemPolicy::assertWritableText( $path );
		} catch ( RuntimeException $error ) {
			return false;
		}
		if ( 'filesystem.read_text' === $action ) {
			return $dry_run && ! $confirm && array_keys( $payload ) === array( 'path' );
		}
		if ( 'filesystem.write_text' === $action ) {
			$keys = array_keys( $payload );
			foreach ( $keys as $key ) {
				if ( ! in_array( $key, array( 'path', 'content', 'expected_sha256', 'acknowledge_active_content' ), true ) ) {
					return false;
				}
			}
			if ( ! isset( $payload['content'] ) || ! is_string( $payload['content'] ) ) {
				return false;
			}
			if ( ! $dry_run ) {
				return $confirm && isset( $payload['expected_sha256'] ) && is_string( $payload['expected_sha256'] )
					&& 1 === preg_match( '/\\A[a-f0-9]{64}\\z/D', $payload['expected_sha256'] );
			}
			return ! $confirm;
		}
		return false;
	}

	/**
	 * The dedicated self-update tools do not expose a general plugin file write.
	 * WordPress Core and ConnectorUpdateAdapter verify and install the immutable
	 * GitHub release. OAuth write scope is never inherited by generic execute.
	 *
	 * @param mixed  $rpc_id JSON-RPC identifier.
	 * @param string $name Dedicated release action.
	 * @param array  $arguments Exact request arguments.
	 * @return array MCP response.
	 */
	private function release_tool( $rpc_id, string $name, array $arguments ): array {
		if ( null === $this->oauth_resource || ! OAuthResourceServer::selfUpdateConfigured()
			|| ! $this->oauth_resource->isAuthenticated() ) {
			return $this->tool_error( 'An authenticated, owner-enabled OAuth release connection is required.' );
		}
		if ( ! $this->oauth_resource->canSelfUpdate() ) {
			$result    = $this->tool_error( 'OAuth update scope and current WordPress update_plugins capability are required.' );
			$challenge = OAuthResourceServer::challengeHeader( true );
			if ( '' !== $challenge ) {
				$result['_meta']['mcp/www_authenticate'] = $challenge . ', error="insufficient_scope"';
			}
			return $result;
		}
		if ( 'wordpress_connector_release_preview' === $name ) {
			if ( array() !== $arguments ) {
				return $this->tool_error( 'Release preview takes no arguments.' );
			}
			$result = $this->run_semantic( $rpc_id, $name, 'connector.update.apply', array(), true, false, null, null, null, true );
			return $this->tool_result( $result, empty( $result['ok'] ) );
		}
		$allowed = array( 'request_id', 'expected_fingerprint', 'confirm' );
		if ( 3 !== count( $arguments ) || array_diff( array_keys( $arguments ), $allowed )
			|| ! isset( $arguments['request_id'] ) || ! is_string( $arguments['request_id'] )
			|| ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $arguments['request_id'] )
			|| true !== ( $arguments['confirm'] ?? null ) ) {
			return $this->tool_error( 'Release installation requires stable request_id, fingerprint and confirm=true.' );
		}
		$fingerprint = $this->optional_hash( $arguments, 'expected_fingerprint' );
		if ( false === $fingerprint || null === $fingerprint ) {
			return $this->tool_error( 'Release installation requires the SHA-256 fingerprint from a fresh preview.' );
		}
		$result = $this->run_semantic(
			$rpc_id,
			$name,
			'connector.update.apply',
			array(),
			false,
			true,
			$arguments['request_id'],
			$fingerprint,
			null,
			true,
			true
		);
		return $this->tool_result( $result, empty( $result['ok'] ) );
	}

	/**
	 * Process the execute tool operation in the MCP controller.
	 *
	 * @param mixed  $rpc_id Identifier of the requested object.
	 * @param string $tool_name Value used by this operation.
	 * @param array  $arguments Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function execute_tool( $rpc_id, string $tool_name, array $arguments ): array {
		$allowed = array( 'action', 'payload', 'dry_run', 'confirm', 'request_id', 'expected_fingerprint', 'expected_state_token' );
		foreach ( array_keys( $arguments ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				return $this->tool_error( 'wordpress_connector_execute contains an unsupported argument.' );
			}
		}

		$action = isset( $arguments['action'] ) && is_string( $arguments['action'] ) ? $arguments['action'] : '';
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*\z/', $action ) ) {
			return $this->tool_error( 'action is invalid.' );
		}
		if ( ! array_key_exists( 'payload', $arguments ) || ! is_array( $arguments['payload'] ) ) {
			return $this->tool_error( 'payload must be an object.' );
		}

		$dry_run = array_key_exists( 'dry_run', $arguments ) ? $arguments['dry_run'] : true;
		$confirm = array_key_exists( 'confirm', $arguments ) ? $arguments['confirm'] : false;
		if ( ! is_bool( $dry_run ) || ! is_bool( $confirm ) ) {
			return $this->tool_error( 'dry_run and confirm must be booleans.' );
		}
		// Native OAuth is read-only unless this token has a separately consented
		// staging-file scope. Never inherit GitHub or browser permissions.
		if ( null !== $this->oauth_resource && $this->oauth_resource->isAuthenticated() ) {
			$read_only_probe = in_array( $action, array( 'connector.discover', 'site.identity.inspect' ), true )
				&& true === $dry_run && false === $confirm && array() === $arguments['payload'];
			if ( ! $read_only_probe && ! $this->staging_file_action_allowed( $action, $arguments['payload'], $dry_run, $confirm ) ) {
				return $this->tool_error( 'OAuth bearer access is restricted to read-only site inspection or separately approved staging file edits.' );
			}
		}

		$request_id = isset( $arguments['request_id'] ) && is_string( $arguments['request_id'] ) ? $arguments['request_id'] : null;
		if ( ! $dry_run && null === $request_id ) {
			return $this->tool_error( 'Confirmed execution requires an explicit stable request_id.' );
		}
		if ( ! $dry_run && ! $confirm ) {
			return $this->tool_error( 'Confirmed execution requires confirm=true.' );
		}

		$expected_fingerprint = $this->optional_hash( $arguments, 'expected_fingerprint' );
		if ( false === $expected_fingerprint ) {
			return $this->tool_error( 'expected_fingerprint must be a lowercase SHA-256 hex string.' );
		}
		$expected_state_token = $this->optional_hash( $arguments, 'expected_state_token' );
		if ( false === $expected_state_token ) {
			return $this->tool_error( 'expected_state_token must be a lowercase SHA-256 hex string.' );
		}

		try {
			$result = $this->run_semantic(
				$rpc_id,
				$tool_name,
				$action,
				$arguments['payload'],
				$dry_run,
				$confirm,
				$request_id,
				$expected_fingerprint,
				$expected_state_token
			);
		} catch ( RuntimeException $error ) {
			return $this->tool_error( $this->bounded_message( $error->getMessage() ) );
		}

		return $this->tool_result( $result, empty( $result['ok'] ) );
	}

	/**
	 * Process the run semantic operation in the MCP controller.
	 *
	 * @param mixed       $rpc_id Identifier of the requested object.
	 * @param string      $tool_name Value used by this operation.
	 * @param string      $action Value used by this operation.
	 * @param array       $payload Requested action payload.
	 * @param bool        $dry_run Value used by this operation.
	 * @param bool        $confirm Value used by this operation.
	 * @param string|null $request_id Identifier of the requested object.
	 * @param string|null $expected_fingerprint Value used by this operation.
	 * @param string|null $expected_state_token Value used by this operation.
	 * @param bool        $require_release_provenance Require protected release provenance.
	 * @param bool        $require_owner_approval Require administrator approval.
	 *
	 * @return array Result of the operation.
	 */
	private function run_semantic( $rpc_id, string $tool_name, string $action, array $payload, bool $dry_run, bool $confirm, ?string $request_id, ?string $expected_fingerprint, ?string $expected_state_token, bool $require_release_provenance = false, bool $require_owner_approval = false ): array {
		if ( null === $request_id ) {
			$request_id = $this->generated_request_id( $rpc_id, $tool_name, $action, $payload );
		}

		$data = array(
			'version'    => 1,
			'request_id' => $request_id,
			'action'     => $action,
			'dry_run'    => $dry_run,
			'confirm'    => $confirm,
			'payload'    => $payload,
		);
		if ( null !== $expected_fingerprint ) {
			$data['expected_fingerprint'] = $expected_fingerprint;
		}
		if ( null !== $expected_state_token ) {
			$data['expected_state_token'] = $expected_state_token;
		}

		$connector_request = Request::fromArray( $data );
		return $this->runner->run(
			$connector_request,
			array(
				'transport'                  => 'mcp',
				'authenticated_user_id'      => get_current_user_id(),
				'require_release_provenance' => $require_release_provenance,
				'require_owner_approval'     => $require_owner_approval,
			)
		);
	}

	/**
	 * Process the result response operation in the MCP controller.
	 *
	 * @param mixed $id Identifier of the requested object.
	 * @param array $result Value used by this operation.
	 * @param bool  $modern Value used by this operation.
	 *
	 * @return \WP_REST_Response Result of the operation.
	 */
	private function result_response( $id, array $result, bool $modern ): \WP_REST_Response {
		if ( $modern ) {
			if ( ! isset( $result['resultType'] ) ) {
				$result = array_merge( array( 'resultType' => 'complete' ), $result );
			}
			$meta                                       = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();
			$meta['io.modelcontextprotocol/serverInfo'] = $this->server_info();
			$result['_meta']                            = $meta;
		}

		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Process the error response operation in the MCP controller.
	 *
	 * @param mixed  $id Identifier of the requested object.
	 * @param int    $code Value used by this operation.
	 * @param string $message Value used by this operation.
	 * @param int    $status Value used by this operation.
	 *
	 * @return \WP_REST_Response Result of the operation.
	 */
	private function error_response( $id, int $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $this->bounded_message( $message ),
				),
			),
			$status
		);
	}

	/**
	 * Process the tool result operation in the MCP controller.
	 *
	 * @param array $value Value used by this operation.
	 * @param bool  $is_error Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function tool_result( array $value, bool $is_error ): array {
		return array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => $this->encode_for_text( $value ),
				),
			),
			'structuredContent' => array( 'result' => $value ),
			'isError'           => $is_error,
		);
	}

	/**
	 * Process the tool error operation in the MCP controller.
	 *
	 * @param string $message Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function tool_error( string $message ): array {
		$value = array(
			'ok'    => false,
			'error' => $this->bounded_message( $message ),
		);
		return $this->tool_result( $value, true );
	}

	/**
	 * Process the encode for text operation in the MCP controller.
	 *
	 * @param array $value Value used by this operation.
	 *
	 * @return string Result of the operation.
	 */
	private function encode_for_text( array $value ): string {
		try {
			$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			return '{"ok":false,"error":"Result could not be encoded."}';
		}
		if ( ! is_string( $json ) ) {
			return '{"ok":false,"error":"Result could not be encoded."}';
		}
		return $json;
	}

	/**
	 * Process the generated request id operation in the MCP controller.
	 *
	 * @param mixed  $rpc_id Identifier of the requested object.
	 * @param string $tool_name Value used by this operation.
	 * @param string $action Value used by this operation.
	 * @param array  $payload Requested action payload.
	 *
	 * @return string Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function generated_request_id( $rpc_id, string $tool_name, string $action, array $payload ): string {
		try {
			$json = wp_json_encode(
				array(
					'rpc_id'  => $rpc_id,
					'tool'    => $tool_name,
					'action'  => $action,
					'payload' => $payload,
					'user_id' => get_current_user_id(),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
			);
		} catch ( \JsonException $error ) {
			throw new RuntimeException( 'Unable to generate a connector request id.' );
		}
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Unable to generate a connector request id.' );
		}
		return 'mcp-' . substr( hash( 'sha256', $json ), 0, 48 );
	}

	/**
	 * Process the optional hash operation in the MCP controller.
	 *
	 * @param array  $arguments Value used by this operation.
	 * @param string $key Field or lookup key.
	 *
	 * @return mixed Result of the operation.
	 */
	private function optional_hash( array $arguments, string $key ) {
		if ( ! array_key_exists( $key, $arguments ) || null === $arguments[ $key ] ) {
			return null;
		}
		if ( ! is_string( $arguments[ $key ] ) || ! preg_match( '/^[a-f0-9]{64}\z/', $arguments[ $key ] ) ) {
			return false;
		}
		return $arguments[ $key ];
	}

	/**
	 * Ensure no arguments in the MCP controller.
	 *
	 * @param array  $arguments Value used by this operation.
	 * @param string $tool_name Value used by this operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function assert_no_arguments( array $arguments, string $tool_name ): void {
		if ( array() !== $arguments ) {
			throw new RuntimeException( $tool_name . ' does not accept arguments.' );
		}
	}

	/**
	 * Process the empty object schema operation in the MCP controller.
	 *
	 * @return array Result of the operation.
	 */
	private function empty_object_schema(): array {
		return array(
			'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Process the execute schema operation in the MCP controller.
	 *
	 * @return array Result of the operation.
	 */
	private function execute_schema(): array {
		return array(
			'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'action'               => array(
					'type'    => 'string',
					'pattern' => '^[a-z0-9][a-z0-9._-]*$',
				),
				'payload'              => array( 'type' => 'object' ),
				'dry_run'              => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'confirm'              => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'request_id'           => array(
					'type'    => 'string',
					'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{7,99}$',
				),
				'expected_fingerprint' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'expected_state_token' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
			),
			'required'             => array( 'action', 'payload' ),
			'allOf'                => array(
				array(
					'if'   => array(
						'properties' => array( 'dry_run' => array( 'const' => false ) ),
						'required'   => array( 'dry_run' ),
					),
					'then' => array(
						'required'   => array( 'request_id', 'confirm' ),
						'properties' => array( 'confirm' => array( 'const' => true ) ),
					),
				),
			),
		);
	}

	/**
	 * Process the server info operation in the MCP controller.
	 *
	 * @return array Result of the operation.
	 */
	private function server_info(): array {
		return array(
			'name'        => 'webactueel-wordpress-connector',
			'title'       => 'WordPress Connector',
			'version'     => defined( 'WPCONNECTOR_VERSION' ) ? (string) WPCONNECTOR_VERSION : 'unknown',
			'description' => 'Authenticated WordPress MCP transport over the existing semantic connector action registry.',
		);
	}

	/**
	 * Process the instructions operation in the MCP controller.
	 *
	 * @return string Result of the operation.
	 */
	private function instructions(): string {
		return 'Use wordpress_connector_discover and wordpress_connector_actions before execution. '
			. 'wordpress_connector_execute defaults to dry-run. Real mutations require confirm=true and a stable explicit request_id, '
			. 'and remain subject to WordPress capabilities, connector policy, stale-state guards, readback, idempotency and rollback.';
	}

	/**
	 * Process the bounded message operation in the MCP controller.
	 *
	 * @param string $message Value used by this operation.
	 *
	 * @return string Result of the operation.
	 */
	private function bounded_message( string $message ): string {
		$message = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message );
		$message = is_string( $message ) ? trim( $message ) : '';
		if ( '' === $message ) {
			return 'Request failed.';
		}
		return strlen( $message ) > 1000 ? substr( $message, 0, 1000 ) : $message;
	}
}
