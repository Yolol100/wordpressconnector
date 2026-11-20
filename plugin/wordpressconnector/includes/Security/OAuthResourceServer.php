<?php
/**
 * OAuth 2.1 protected-resource authentication for direct MCP clients.
 *
 * The authorization server is operated independently. WordPress never
 * issues OAuth tokens or treats an arbitrary JWT as a site administrator.
 *
 * @package WordPressConnector
 */
declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

/**
 * Validate remote OAuth bearer tokens through a pinned, trusted introspection
 * endpoint and an explicit WordPress account/subject binding.
 */
final class OAuthResourceServer {
	private const NAMESPACE = 'webactueel-wordpress-connector/v1';
	private const SCOPE     = 'wpconnector:read';
	private const MAX_TOKEN_AGE = 3600;
	private bool $authenticated = false;

	/**
	 * Return strictly configured, site-local OAuth settings.
	 *
	 * Secrets belong in wp-config.php or server configuration, never the DB,
	 * JavaScript, GitHub, a URL query or a ChatGPT conversation.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function config(): ?array {
		$names = array(
			'issuer'         => 'WPCONNECTOR_OAUTH_ISSUER',
			'introspection'  => 'WPCONNECTOR_OAUTH_INTROSPECTION_URL',
			'client_id'      => 'WPCONNECTOR_OAUTH_CLIENT_ID',
			'client_secret'  => 'WPCONNECTOR_OAUTH_CLIENT_SECRET',
			'subject'        => 'WPCONNECTOR_OAUTH_ACCOUNT_SUBJECT',
			'user_id'        => 'WPCONNECTOR_OAUTH_WORDPRESS_USER_ID',
		);
		$data = array();
		foreach ( $names as $key => $name ) {
			if ( ! defined( $name ) ) {
				return null;
			}
			$data[ $key ] = constant( $name );
		}
		if ( ! defined( 'WPCONNECTOR_OAUTH_ACCOUNT_LINK_APPROVED' )
			|| true !== constant( 'WPCONNECTOR_OAUTH_ACCOUNT_LINK_APPROVED' ) ) {
			return null;
		}
		foreach ( array( 'issuer', 'introspection', 'client_id', 'client_secret', 'subject' ) as $name ) {
			if ( ! is_string( $data[ $name ] ) || '' === $data[ $name ]
				|| strlen( $data[ $name ] ) > ( 'client_secret' === $name ? 1024 : 512 )
				|| preg_match( '/[\x00-\x20\x7f]/', $data[ $name ] ) ) {
				return null;
			}
		}
		if ( ! is_int( $data['user_id'] ) || $data['user_id'] <= 0 ) {
			return null;
		}
		$issuer = wp_parse_url( $data['issuer'] );
		$target = wp_parse_url( $data['introspection'] );
		foreach ( array( $issuer, $target ) as $url ) {
			if ( ! is_array( $url ) || 'https' !== ( $url['scheme'] ?? null )
				|| empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] )
				|| isset( $url['query'] ) || isset( $url['fragment'] ) ) {
				return null;
			}
		}
		if ( strtolower( $issuer['host'] ) !== strtolower( $target['host'] )
			|| ( $issuer['port'] ?? 443 ) !== ( $target['port'] ?? 443 ) ) {
			return null;
		}
		return $data;
	}

	/** Whether the site owner has configured (not proven) an OAuth provider. */
	public static function isConfigured(): bool {
		return null !== self::config();
	}

	/** Exact OAuth audience used by this site and its MCP resource. */
	public static function resource(): string {
		return rest_url( self::NAMESPACE . '/mcp' );
	}

	/** RFC 9728 resource metadata; the authorization server lives elsewhere. */
	public static function metadata(): array {
		$config = self::config();
		if ( null === $config ) {
			return array();
		}
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( $config['issuer'] ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( self::SCOPE ),
		);
	}

	/** Register only the public resource metadata endpoint. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/** REST routes preserve the plugin's existing canonical namespace. */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/oauth-resource',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'resourceMetadata' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Public discovery contains only an issuer URI. No usernames or secrets.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resourceMetadata() {
		$metadata = self::metadata();
		if ( ! is_ssl() || array() === $metadata ) {
			return new \WP_Error( 'wpconnector_oauth_unavailable', 'OAuth is not available for this site.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $metadata, 200 );
	}

	/** Exact challenge for the MCP OAuth discovery handshake. */
	public static function challengeHeader(): string {
		$url = rest_url( self::NAMESPACE . '/oauth-resource' );
		if ( ! is_string( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
			|| preg_match( '/[\r\n"]/', $url ) ) {
			return '';
		}
		return 'Bearer resource_metadata="' . $url . '", scope="' . self::SCOPE . '"';
	}

	/** Advertise OAuth only for configured sites, never as a substitute for auth. */
	public static function challenge(): void {
		if ( self::isConfigured() && ! headers_sent() ) {
			$header = self::challengeHeader();
			if ( '' !== $header ) {
				header( 'WWW-Authenticate: ' . $header, true );
			}
		}
	}

	/**
	 * Return a sanitized refusal and advertise the OAuth resource challenge.
	 */
	private static function deny( string $code, string $message, int $status ): \WP_Error {
		self::challenge();
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Did this request actually authenticate using a valid external bearer? */
	public function isAuthenticated(): bool {
		return $this->authenticated;
	}

	/**
	 * Return null when no bearer is supplied, true on valid bearer, WP_Error
	 * otherwise. Invalid bearers never fall back to a logged-in browser session.
	 *
	 * @param \WP_REST_Request $request Incoming MCP request.
	 * @return bool|\WP_Error|null
	 */
	public function authenticate( \WP_REST_Request $request ) {
		$this->authenticated = false;
		$authorization = (string) $request->get_header( 'authorization' );
		if ( '' === $authorization || 0 !== stripos( $authorization, 'bearer' ) ) {
			return null;
		}
		if ( ! is_ssl() ) {
			return self::deny( 'wpconnector_https_required', 'OAuth requires HTTPS.', 403 );
		}
		$config = self::config();
		if ( null === $config || 1 !== preg_match( '/\ABearer ([A-Za-z0-9._~+\/=-]{32,4096})\z/iD', $authorization, $match ) ) {
			return self::deny( 'wpconnector_oauth_invalid_token', 'OAuth access token is invalid.', 401 );
		}
		$access_token = $match[1];

		// Remote validation is mandatory: never decode and trust unsigned JWT claims.
		$result = wp_safe_remote_post(
			$config['introspection'],
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . base64_encode(
						rawurlencode( $config['client_id'] ) . ':' . rawurlencode( $config['client_secret'] )
					),
				),
				'body'        => array(
					'token'           => $access_token,
					'token_type_hint' => 'access_token',
					'resource'        => self::resource(),
				),
			)
		);
		unset( $access_token );
		if ( is_wp_error( $result ) || 200 !== (int) wp_remote_retrieve_response_code( $result ) ) {
			return self::deny( 'wpconnector_oauth_provider_unavailable', 'OAuth token validation is unavailable.', 503 );
		}
		$body = (string) wp_remote_retrieve_body( $result );
		if ( strlen( $body ) > 16384 ) {
			return self::deny( 'wpconnector_oauth_invalid_token', 'OAuth access token is invalid.', 401 );
		}
		$claims = json_decode( $body, true );
		$now = time();
		if ( ! is_array( $claims ) || true !== ( $claims['active'] ?? false )
			|| ( $claims['iss'] ?? null ) !== $config['issuer']
			|| ( $claims['sub'] ?? null ) !== $config['subject']
			|| ! isset( $claims['exp'] ) || ! is_int( $claims['exp'] )
			|| $claims['exp'] <= $now || $claims['exp'] > $now + self::MAX_TOKEN_AGE
			|| ( isset( $claims['nbf'] ) && ( ! is_int( $claims['nbf'] ) || $claims['nbf'] > $now + 60 ) )
			|| ( isset( $claims['iat'] ) && ( ! is_int( $claims['iat'] ) || $claims['iat'] > $now + 60 ) )
			|| ( isset( $claims['token_type'] ) && 'Bearer' !== $claims['token_type'] ) ) {
			return self::deny( 'wpconnector_oauth_invalid_token', 'OAuth access token is invalid.', 401 );
		}
		$audiences = $claims['aud'] ?? null;
		$audiences = is_string( $audiences ) ? array( $audiences ) : $audiences;
		if ( ! is_array( $audiences ) || 1 !== count( $audiences )
			|| self::resource() !== reset( $audiences ) ) {
			return self::deny( 'wpconnector_oauth_invalid_audience', 'OAuth token audience is invalid.', 401 );
		}
		$scope = $claims['scope'] ?? null;
		if ( ! is_string( $scope ) || ! in_array( self::SCOPE, preg_split( '/ +/', $scope ), true ) ) {
			return self::deny( 'wpconnector_oauth_scope_denied', 'OAuth read scope is required.', 403 );
		}
		$user = get_userdata( $config['user_id'] );
		if ( ! $user || ! user_can( $config['user_id'], 'manage_options' ) ) {
			return self::deny( 'wpconnector_oauth_account_unavailable', 'Linked WordPress account is unavailable.', 403 );
		}
		wp_set_current_user( $config['user_id'] );
		$this->authenticated = true;
		return true;
	}
}
