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
	private const NAMESPACE               = 'webactueel-wordpress-connector/v1';
	private const SCOPE                   = 'wpconnector:read';
	private const UPDATE_SCOPE            = 'wpconnector:update';
	private const MAX_TOKEN_AGE           = 3600;
	private const MAX_INTROSPECTION_BYTES = 16384;
	private const RATE_LIMIT_PER_IP       = 12;
	private const RATE_LIMIT_GLOBAL       = 60;
	private bool $authenticated           = false;
	private bool $update_authorized       = false;

	/**
	 * Return strictly configured, site-local OAuth settings.
	 *
	 * Secrets belong in wp-config.php or server configuration, never the DB,
	 * JavaScript, GitHub, a URL query or a ChatGPT conversation.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function config(): ?array {
		if ( class_exists( NativeOAuthServer::class ) && NativeOAuthServer::isEnabled() ) {
			return array( 'native' => true, 'issuer' => NativeOAuthServer::issuer() );
		}
		$names = array(
			'issuer'        => 'WPCONNECTOR_OAUTH_ISSUER',
			'introspection' => 'WPCONNECTOR_OAUTH_INTROSPECTION_URL',
			'client_id'     => 'WPCONNECTOR_OAUTH_CLIENT_ID',
			'client_secret' => 'WPCONNECTOR_OAUTH_CLIENT_SECRET',
			'subject'       => 'WPCONNECTOR_OAUTH_ACCOUNT_SUBJECT',
			'user_id'       => 'WPCONNECTOR_OAUTH_WORDPRESS_USER_ID',
		);
		$data  = array();
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
		// The OAuth authorization client is distinct from the introspection
		// credentials. Pin it only for providers exposing RFC 7662 client_id;
		// dynamic client registration must be bound by the issuer's own policy.
		if ( defined( 'WPCONNECTOR_OAUTH_EXPECTED_TOKEN_CLIENT_ID' ) ) {
			$token_client_id = constant( 'WPCONNECTOR_OAUTH_EXPECTED_TOKEN_CLIENT_ID' );
			if ( ! is_string( $token_client_id ) || '' === $token_client_id
				|| strlen( $token_client_id ) > 512
				|| preg_match( '/[\\x00-\\x20\\x7f]/', $token_client_id ) ) {
				return null;
			}
			$data['token_client_id'] = $token_client_id;
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

	/**
	 * Site-owner opt-in for ChatGPT-initiated updates of this Connector only.
	 * This never enables arbitrary PHP/filesystem modifications.
	 */
	public static function selfUpdateConfigured(): bool {
		return self::isConfigured()
			&& ! ( class_exists( NativeOAuthServer::class ) && NativeOAuthServer::isEnabled() )
			&& defined( 'WPCONNECTOR_OAUTH_ALLOW_SELF_UPDATE' )
			&& true === constant( 'WPCONNECTOR_OAUTH_ALLOW_SELF_UPDATE' );
	}

	/** Exact OAuth audience used by this site and its MCP resource. */
	public static function resource(): string {
		return rest_url( self::NAMESPACE . '/mcp' );
	}

	/**
	 * RFC 9728 well-known discovery URL for the exact protected MCP URI.
	 * The host's web server must forward this route to WordPress.
	 */
	public static function wellKnownUrl(): string {
		$parts = wp_parse_url( self::resource() );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? null )
			|| empty( $parts['host'] )
			|| ( isset( $parts['path'] ) && ! is_string( $parts['path'] ) )
			|| isset( $parts['fragment'] ) || isset( $parts['user'] )
			|| isset( $parts['pass'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		// RFC 9728 removes the terminating slash after the authority before
		// inserting the well-known segment, including for query-only REST.
		$path  = '/' === ( $parts['path'] ?? '' ) ? '' : ( $parts['path'] ?? '' );
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return 'https://' . $parts['host'] . $port
			. '/.well-known/oauth-protected-resource' . $path . $query;
	}

	/** RFC 9728 resource metadata; the authorization server lives elsewhere. */
	public static function metadata(): array {
		$config = self::config();
		if ( null === $config ) {
			return array();
		}
		$scopes = array( self::SCOPE );
		if ( self::selfUpdateConfigured() ) {
			$scopes[] = self::UPDATE_SCOPE;
		}
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( $config['issuer'] ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => $scopes,
		);
	}

	/** Register only the public resource metadata endpoint. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( 'init', array( $this, 'serveWellKnown' ), 1 );
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
	 * Serve the exact RFC 9728 URL early, without an activation-only rewrite.
	 * WordPress must receive the .well-known request from the web server.
	 */
	public function serveWellKnown(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw request URI is used only in strict canonical path/query comparisons, never output or interpreted as code.
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( ! is_string( $uri ) || '' === $uri ) {
			return;
		}
		$url = self::wellKnownUrl();
		if ( '' === $url
			|| wp_parse_url( $uri, PHP_URL_PATH ) !== wp_parse_url( $url, PHP_URL_PATH )
			|| wp_parse_url( $uri, PHP_URL_QUERY ) !== wp_parse_url( $url, PHP_URL_QUERY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact HTTP method literal (GET) comparison; unexpected values are rejected.
		$method = $_SERVER['REQUEST_METHOD'] ?? '';
		if ( 'GET' !== $method ) {
			status_header( 405 );
			header( 'Allow: GET', true );
			exit;
		}
		$metadata = self::metadata();
		if ( ! is_ssl() || array() === $metadata ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8', true );
		status_header( 200 );
		echo wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		exit;
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

	/**
	 * Exact challenge for the MCP OAuth discovery handshake.
	 *
	 * @param bool $include_update Advertise update scope only with explicit opt-in.
	 */
	public static function challengeHeader( bool $include_update = false ): string {
		$url = self::wellKnownUrl();
		if ( ! is_string( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
			|| preg_match( '/[\r\n"]/', $url ) ) {
			return '';
		}
		$scopes = self::SCOPE;
		if ( $include_update && self::selfUpdateConfigured() ) {
			$scopes .= ' ' . self::UPDATE_SCOPE;
		}
		return 'Bearer resource_metadata="' . $url . '", scope="' . $scopes . '"';
	}

	/**
	 * Advertise OAuth only for configured sites, never as a substitute for auth.
	 *
	 * @param string $reason Optional allowlisted OAuth error reason.
	 */
	public static function challenge( string $reason = '' ): void {
		if ( self::isConfigured() && ! headers_sent() ) {
			$header = self::challengeHeader();
			if ( '' !== $header ) {
				if ( in_array( $reason, array( 'invalid_token', 'insufficient_scope' ), true ) ) {
					$header .= ', error="' . $reason . '"';
				}
				header( 'WWW-Authenticate: ' . $header, true );
			}
		}
	}

	/**
	 * Return a sanitized refusal and advertise the OAuth resource challenge.
	 *
	 * @param string $code    Internal error code.
	 * @param string $message Sanitized public failure reason.
	 * @param int    $status  HTTP status.
	 */
	private static function deny( string $code, string $message, int $status ): \WP_Error {
		$reason = 401 === $status ? 'invalid_token' : ( 'wpconnector_oauth_scope_denied' === $code ? 'insufficient_scope' : '' );
		self::challenge( $reason );
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Bound introspection load by trusted webserver IP and a site-wide cap.
	 * Persistent transients are advisory under concurrency; hosts should
	 * additionally configure an edge/WAF limit for this MCP endpoint.
	 */
	private static function canIntrospect(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR is validated with FILTER_VALIDATE_IP below; invalid values are assigned the shared unknown bucket.
		$address = $_SERVER['REMOTE_ADDR'] ?? '';
		$address = is_string( $address ) && filter_var( $address, FILTER_VALIDATE_IP ) ? $address : 'unknown';
		$bucket  = (string) floor( time() / 60 );
		$limits  = array(
			'wpconnector_oauth_ip_' . substr( hash( 'sha256', $address ), 0, 24 ) . '_' . $bucket => self::RATE_LIMIT_PER_IP,
			'wpconnector_oauth_all_' . $bucket => self::RATE_LIMIT_GLOBAL,
		);
		$counts  = array();
		foreach ( $limits as $key => $limit ) {
			$value = get_transient( $key );
			if ( false !== $value && ! is_int( $value )
				&& ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,2})\z/', $value ) ) ) {
				return false;
			}
			$count = false === $value ? 0 : (int) $value;
			if ( $count >= $limit ) {
				return false;
			}
			$counts[ $key ] = $count + 1;
		}
		foreach ( $counts as $key => $count ) {
			if ( ! set_transient( $key, $count, 70 ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Access to the release updater requires the separate OAuth update scope,
	 * the owner's explicit opt-in and the linked user's update_plugins right.
	 */
	public function canSelfUpdate(): bool {
		return $this->authenticated && $this->update_authorized;
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
		$this->authenticated     = false;
		$this->update_authorized = false;
		$authorization           = (string) $request->get_header( 'authorization' );
		if ( '' === $authorization || 0 !== stripos( $authorization, 'bearer' ) ) {
			return null;
		}
		if ( ! is_ssl() ) {
			return self::deny( 'wpconnector_https_required', 'OAuth requires HTTPS.', 403 );
		}
		$config = self::config();
		if ( null === $config || 1 !== preg_match( '/\ABearer +([A-Za-z0-9._~+\/=-]{1,4096})\z/iD', $authorization, $match ) ) {
			return self::deny( 'wpconnector_oauth_invalid_token', 'OAuth access token is invalid.', 401 );
		}
		$access_token = $match[1];
		if ( ! self::canIntrospect() ) {
			unset( $access_token );
			return self::deny( 'wpconnector_oauth_rate_limited', 'OAuth validation is rate limited.', 429 );
		}

		if ( ! empty( $config['native'] ) ) {
			$claims = NativeOAuthServer::introspect( $access_token );
			unset( $access_token );
			$user_id = is_array( $claims ) ? ( $claims['user_id'] ?? null ) : null;
			if ( ! is_int( $user_id ) || $user_id <= 0
				|| ! is_array( $claims ) || ( $claims['sub'] ?? null ) !== 'wp:' . (string) $user_id ) {
				return self::deny( 'wpconnector_oauth_invalid_token', 'OAuth access token is invalid.', 401 );
			}
			$config['subject'] = 'wp:' . (string) $user_id;
			$config['user_id'] = $user_id;
		} else {
			// Remote validation is mandatory: never decode and trust unsigned JWT claims.
			$result = wp_safe_remote_post(
				$config['introspection'],
				array(
					'timeout'             => 5,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_INTROSPECTION_BYTES + 1,
					'sslverify'           => true,
					'headers'             => array(
						'Accept'        => 'application/json',
						'Authorization' => 'Basic ' . base64_encode(
							rawurlencode( $config['client_id'] ) . ':' . rawurlencode( $config['client_secret'] )
						),
					),
					'body'                => array(
						'token'           => $access_token,
						'token_type_hint' => 'access_token',
					),
				)
			);
			unset( $access_token );
			if ( is_wp_error( $result ) || 200 !== (int) wp_remote_retrieve_response_code( $result ) ) {
				return self::deny( 'wpconnector_oauth_provider_unavailable', 'OAuth token validation is unavailable.', 503 );
			}
			$body = (string) wp_remote_retrieve_body( $result );
			if ( strlen( $body ) > self::MAX_INTROSPECTION_BYTES ) {
				return self::deny( 'wpconnector_oauth_invalid_token', 'OAuth access token is invalid.', 401 );
			}
			$claims = json_decode( $body, true );
		}
		$now    = time();
		if ( ! is_array( $claims ) || true !== ( $claims['active'] ?? false )
			|| ( $claims['iss'] ?? null ) !== $config['issuer']
			|| ( $claims['sub'] ?? null ) !== $config['subject']
			|| ! isset( $claims['exp'] ) || ! is_int( $claims['exp'] )
			|| $claims['exp'] <= $now || $claims['exp'] > $now + self::MAX_TOKEN_AGE
			|| ( isset( $claims['nbf'] ) && ( ! is_int( $claims['nbf'] ) || $claims['nbf'] > $now + 60 ) )
			|| ( isset( $claims['iat'] ) && ( ! is_int( $claims['iat'] ) || $claims['iat'] > $now + 60 ) )
			|| ( isset( $claims['token_type'] ) && ( ! is_string( $claims['token_type'] )
				|| 0 !== strcasecmp( $claims['token_type'], 'Bearer' ) ) ) ) {
			return self::deny( 'wpconnector_oauth_invalid_token', 'OAuth access token is invalid.', 401 );
		}
		// Optional strict authorization-client binding. Never compare the
		// token's client_id against the *introspection* client credentials.
		// If the provider omits client_id, a configured pin must fail closed.
		if ( isset( $config['token_client_id'] ) ) {
			$token_client_id = $claims['client_id'] ?? null;
			if ( ! is_string( $token_client_id )
				|| ! hash_equals( $config['token_client_id'], $token_client_id ) ) {
				return self::deny( 'wpconnector_oauth_client_denied', 'OAuth token client is not authorized.', 401 );
			}
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
		$this->update_authorized = self::selfUpdateConfigured()
			&& in_array( self::UPDATE_SCOPE, preg_split( '/ +/', $scope ), true )
			&& user_can( $config['user_id'], 'update_plugins' );
		wp_set_current_user( $config['user_id'] );
		$this->authenticated = true;
		return true;
	}
}
