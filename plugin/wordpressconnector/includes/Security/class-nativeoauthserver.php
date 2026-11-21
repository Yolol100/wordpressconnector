<?php
/**
 * Opt-in WordPress-hosted OAuth authorization server for the pinned ChatGPT client.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

/** Native OAuth authorization server with grant-bound token lifecycle. */
final class NativeOAuthServer {
	private const NAMESPACE        = 'webactueel-wordpress-connector/v1';
	private const CLIENT_ID        = 'https://chatgpt.com/oauth/client.json';
	private const REDIRECT         = 'https://chatgpt.com/connector_platform_oauth_redirect';
	private const OPT_ENABLED      = 'wpconnector_native_oauth_enabled';
	private const OPT_REVISION     = 'wpconnector_native_oauth_revision';
	private const OPT_INSTALLATION = 'wpconnector_native_oauth_installation';
	private const STORE_PREFIX     = 'wpconnector_native_oauth_';
	private const SCOPE            = 'wpconnector:read';
	private const CODE_TTL         = 300;
	private const ACCESS_TTL       = 900;
	private const REFRESH_TTL      = 2592000;

	/** Register. */
	public function register(): void {
		add_action( 'init', array( $this, 'serve_discovery' ), 1 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_post_wpconnector_oauth_authorize', array( $this, 'authorize' ) );
		add_action( 'admin_post_nopriv_wpconnector_oauth_authorize', array( $this, 'authorize' ) );
		add_action( 'admin_post_wpconnector_native_oauth_mode', array( $this, 'update_mode' ) );
		add_action( 'wpconnector_native_oauth_cleanup', array( $this, 'cleanup' ) );
	}

	/** Is enabled. */
	public static function is_enabled(): bool {
		$approval = get_option( self::OPT_ENABLED, array() );
		$home     = home_url( '/' );
		$mcp      = rest_url( self::NAMESPACE . '/mcp' );
		$admin    = admin_url( 'admin-post.php' );
		return is_array( $approval )
			&& ( $approval['site'] ?? '' ) === self::issuer()
			&& is_int( $approval['approved_by'] ?? null )
			&& $approval['approved_by'] > 0
			&& null !== self::installation_id()
			&& ! defined( 'WPCONNECTOR_OAUTH_ISSUER' )
			&& is_ssl()
			&& 'https' === wp_parse_url( $home, PHP_URL_SCHEME )
			&& 'https' === wp_parse_url( $mcp, PHP_URL_SCHEME )
			&& 'https' === wp_parse_url( $admin, PHP_URL_SCHEME )
			&& wp_parse_url( $home, PHP_URL_HOST ) === wp_parse_url( $mcp, PHP_URL_HOST )
			&& wp_parse_url( $home, PHP_URL_HOST ) === wp_parse_url( $admin, PHP_URL_HOST );
	}

	/** Report any prior owner approval, including when external OAuth is now selected. */
	public static function has_prior_approval(): bool {
		$approval = get_option( self::OPT_ENABLED, array() );
		return is_array( $approval )
			&& is_int( $approval['approved_by'] ?? null )
			&& $approval['approved_by'] > 0;
	}

	/** Issuer. */
	public static function issuer(): string {
		return rtrim( home_url( '/' ), '/' );
	}

	/** Metadata. */
	public static function metadata(): array {
		if ( ! self::is_enabled() ) {
			return array();
		}
		return array(
			'issuer'                                     => self::issuer(),
			'authorization_endpoint'                     => admin_url( 'admin-post.php?action=wpconnector_oauth_authorize' ),
			'token_endpoint'                             => rest_url( self::NAMESPACE . '/oauth/token' ),
			'revocation_endpoint'                        => rest_url( self::NAMESPACE . '/oauth/revoke' ),
			'response_types_supported'                   => array( 'code' ),
			'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			'token_endpoint_auth_methods_supported'      => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'client_id_metadata_document_supported'      => true,
			'authorization_response_iss_parameter_supported' => true,
			'scopes_supported'                           => array( self::SCOPE ),
		);
	}

	/** Discovery path. */
	private static function discovery_path(): string {
		// RFC 8414 inserts .well-known between the host and the issuer subpath.
		$issuer_path = wp_parse_url( self::issuer(), PHP_URL_PATH );
		$suffix      = is_string( $issuer_path ) ? rtrim( $issuer_path, '/' ) : '';
		return '/.well-known/oauth-authorization-server' . $suffix;
	}

	/** Return the canonical RFC 8414 discovery URL, including issuer subpaths. */
	public static function discovery_url(): string {
		$parts = wp_parse_url( self::issuer() );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $parts['scheme'] . '://' . $parts['host'] . $port . self::discovery_path();
	}

	/** Serve discovery. */
	public function serve_discovery(): void {
		// The web server must route this canonical path into WordPress.
		$uri      = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path     = wp_parse_url( $uri, PHP_URL_PATH );
		$expected = self::discovery_path();
		if ( ! is_string( $path ) || $path !== $expected ) {
			return;
		}
		if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			status_header( 405 );
			header( 'Allow: GET', true );
			exit;
		}
		$metadata = self::metadata();
		if ( empty( $metadata ) || null !== wp_parse_url( $uri, PHP_URL_QUERY ) ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8', true );
		status_header( 200 );
		echo wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		exit;
	}

	/** Register routes. */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/oauth/authorization-server',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/oauth/revoke',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** Rest metadata. */
	public function rest_metadata() {
		$metadata = self::metadata();
		if ( empty( $metadata ) ) {
			return new \WP_Error( 'wpconnector_native_oauth_disabled', 'OAuth is not enabled.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $metadata, 200 );
	}

	/**
	 * Client valid.
	 *
	 * @param string $client_id client id.
	 * @param string $redirect redirect.
	 */
	private static function client_valid( string $client_id, string $redirect ): bool {
		if ( self::CLIENT_ID !== $client_id || self::REDIRECT !== $redirect ) {
			return false;
		}
		$cached = get_transient( 'wpconnector_native_oauth_cimd_verified' );
		if ( '1' === (string) $cached ) {
			return true;
		}
		$result = wp_safe_remote_get(
			self::CLIENT_ID,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 16385,
				'sslverify'           => true,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $result ) || 200 !== (int) wp_remote_retrieve_response_code( $result ) ) {
			return false;
		}
		$body = (string) wp_remote_retrieve_body( $result );
		if ( strlen( $body ) > 16384 ) {
			return false;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ( $data['client_id'] ?? null ) !== self::CLIENT_ID
			|| ! isset( $data['redirect_uris'] ) || ! is_array( $data['redirect_uris'] )
			|| ! in_array( self::REDIRECT, $data['redirect_uris'], true )
			|| ! in_array( 'none', (array) ( $data['token_endpoint_auth_methods_supported'] ?? array() ), true )
			|| ! in_array( 'authorization_code', (array) ( $data['grant_types'] ?? array() ), true ) ) {
			return false;
		}
		set_transient( 'wpconnector_native_oauth_cimd_verified', 1, 300 );
		return true;
	}

	/**
	 * Authorization params.
	 *
	 * @param array $source source.
	 */
	private static function authorization_params( array $source ): ?array {
		$keys = array( 'client_id', 'redirect_uri', 'response_type', 'state', 'code_challenge', 'code_challenge_method', 'resource', 'scope' );
		$data = array();
		foreach ( $keys as $key ) {
			$value = $source[ $key ] ?? '';
			if ( ! is_string( $value ) || strlen( $value ) > 2048 ) {
				return null;
			}
			$data[ $key ] = wp_unslash( $value );
		}
		if ( self::CLIENT_ID !== $data['client_id'] || self::REDIRECT !== $data['redirect_uri']
			|| 'code' !== $data['response_type'] || 'S256' !== $data['code_challenge_method']
			|| ! preg_match( '/\A[A-Za-z0-9_-]{43}\z/D', $data['code_challenge'] )
			|| ! preg_match( '/\A[A-Za-z0-9._~-]{8,1024}\z/D', $data['state'] )
			|| OAuthResourceServer::resource() !== $data['resource']
			|| ! in_array( $data['scope'], array( '', self::SCOPE ), true ) ) {
			return null;
		}
		$data['scope'] = self::SCOPE;
		return $data;
	}

	/**
	 * Consent nonce.
	 *
	 * @param array $params params.
	 */
	private static function consent_nonce( array $params ): string {
		return 'wpconnector_native_oauth_consent_' . hash( 'sha256', wp_json_encode( $params ) );
	}

	/**
	 * Send callback.
	 *
	 * @param array $params params.
	 * @param array $values values.
	 */
	private static function send_callback( array $params, array $values ): void {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer', true );
		$url = add_query_arg(
			array_merge(
				$values,
				array(
					'state' => $params['state'],
					'iss'   => self::issuer(),
				)
			),
			self::REDIRECT
		);
		// Allow only the already-pinned OAuth callback host for this redirect.
		$allow_callback = static function ( array $hosts ): array {
			$hosts[] = 'chatgpt.com';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_callback );
		wp_safe_redirect( $url, 302, 'WordPress Connector OAuth' );
		remove_filter( 'allowed_redirect_hosts', $allow_callback );
		exit;
	}

	/** Authorize. */
	public function authorize(): void {
		if ( ! self::is_enabled() ) {
			wp_die( 'OAuth authorization is disabled.', 'OAuth unavailable', array( 'response' => 404 ) );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			wp_die( 'Unsupported HTTP method.', 'Method not allowed', array( 'response' => 405 ) );
		}
		// OAuth's initial GET request intentionally has no WordPress nonce.
		// Read HTTP input without bypassing authentication or POST consent checks.
		$source = filter_input_array( 'POST' === $method ? INPUT_POST : INPUT_GET, FILTER_UNSAFE_RAW );
		$params = is_array( $source ) ? self::authorization_params( $source ) : null;
		if ( null === $params ) {
			wp_die( 'Invalid authorization request.', 'OAuth request rejected', array( 'response' => 400 ) );
		}
		if ( ! is_user_logged_in() ) {
			if ( 'POST' === $method ) {
				wp_die( 'Please sign in again.', 'OAuth session expired', array( 'response' => 403 ) );
			}
			$back = add_query_arg( array_merge( $params, array( 'action' => 'wpconnector_oauth_authorize' ) ), admin_url( 'admin-post.php' ) );
			wp_safe_redirect( wp_login_url( $back ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'An administrator account is required.', 'Access denied', array( 'response' => 403 ) );
		}
		if ( ! self::client_valid( $params['client_id'], $params['redirect_uri'] ) ) {
			wp_die( 'Untrusted OAuth client metadata.', 'OAuth request rejected', array( 'response' => 400 ) );
		}
		if ( 'POST' === $method ) {
			check_admin_referer( self::consent_nonce( $params ) );
			$decision = isset( $_POST['wpconnector_oauth_decision'] ) && is_string( $_POST['wpconnector_oauth_decision'] )
				? sanitize_text_field( wp_unslash( $_POST['wpconnector_oauth_decision'] ) ) : '';
			if ( 'approve' !== $decision ) {
				self::send_callback( $params, array( 'error' => 'access_denied' ) );
			}
			$code = self::issue(
				'code',
				array(
					'user_id'        => get_current_user_id(),
					'client_id'      => $params['client_id'],
					'redirect_uri'   => $params['redirect_uri'],
					'code_challenge' => $params['code_challenge'],
					'resource'       => $params['resource'],
					'scope'          => self::SCOPE,
				),
				self::CODE_TTL
			);
			if ( null === $code ) {
				wp_die( 'Unable to issue authorization code.', 'OAuth unavailable', array( 'response' => 503 ) );
			}
			self::send_callback( $params, array( 'code' => $code ) );
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Frame-Options: DENY', true );
		$html  = '<h1>' . esc_html__( 'Connect ChatGPT to this WordPress site', 'wordpressconnector' ) . '</h1>';
		$html .= '<p>' . esc_html__( 'Allow the verified ChatGPT client to inspect this site with your current administrator permissions. This connection cannot edit content or install updates.', 'wordpressconnector' ) . '</p>';
		$html .= '<p><strong>' . esc_html( home_url( '/' ) ) . '</strong></p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="wpconnector_oauth_authorize" />';
		foreach ( $params as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		$html .= wp_nonce_field( self::consent_nonce( $params ), '_wpnonce', true, false );
		$html .= '<p><button type="submit" name="wpconnector_oauth_decision" value="approve">' . esc_html__( 'Allow read-only access', 'wordpressconnector' ) . '</button> ';
		$html .= '<button type="submit" name="wpconnector_oauth_decision" value="deny">' . esc_html__( 'Deny', 'wordpressconnector' ) . '</button></p></form>';
		wp_die(
			wp_kses(
				$html,
				array(
					'h1'     => array(),
					'p'      => array(),
					'strong' => array(),
					'form'   => array(
						'method' => true,
						'action' => true,
					),
					'input'  => array(
						'type'  => true,
						'name'  => true,
						'value' => true,
					),
					'button' => array(
						'type'  => true,
						'name'  => true,
						'value' => true,
					),
				)
			),
			esc_html__( 'WordPress Connector OAuth consent', 'wordpressconnector' ),
			array(
				'response'  => 200,
				'back_link' => false,
			)
		);
	}

	/**
	 * Encode bytes using the unpadded RFC 4648 URL-safe alphabet.
	 *
	 * @param string $bytes Raw bytes to encode.
	 */
	private static function base64_url_encode( string $bytes ): string {
		// RFC 4648 encoding is not encryption. Avoid optional PHP extensions.
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
		$output   = '';
		$length   = strlen( $bytes );
		for ( $i = 0; $i < $length; $i += 3 ) {
			$a       = ord( $bytes[ $i ] );
			$b       = $i + 1 < $length ? ord( $bytes[ $i + 1 ] ) : 0;
			$c       = $i + 2 < $length ? ord( $bytes[ $i + 2 ] ) : 0;
			$output .= $alphabet[ $a >> 2 ];
			$output .= $alphabet[ ( ( $a & 3 ) << 4 ) | ( $b >> 4 ) ];
			if ( $i + 1 < $length ) {
				$output .= $alphabet[ ( ( $b & 15 ) << 2 ) | ( $c >> 6 ) ];
			}
			if ( $i + 2 < $length ) {
				$output .= $alphabet[ $c & 63 ];
			}
		}
		return $output;
	}

	/**
	 * Get the random installation identifier, never a default reusable generation.
	 * A new value is required after uninstall or re-enabling native OAuth.
	 */
	private static function installation_id(): ?string {
		$value = get_option( self::OPT_INSTALLATION, '' );
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $value ) ? $value : null;
	}

	/** Revision. */
	private static function revision(): int {
		return max( 1, (int) get_option( self::OPT_REVISION, 1 ) );
	}

	/**
	 * Option key.
	 *
	 * @param string $kind kind.
	 * @param string $token token.
	 */
	private static function option_key( string $kind, string $token ): string {
		return self::STORE_PREFIX . $kind . '_' . hash_hmac( 'sha256', home_url( '/' ) . ':' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Issue.
	 *
	 * @param string $kind kind.
	 * @param array  $data data.
	 * @param int    $ttl ttl.
	 */
	private static function issue( string $kind, array $data, int $ttl ): ?string {
		$installation = self::installation_id();
		if ( null === $installation ) {
			return null;
		}
		for ( $i = 0; $i < 3; ++$i ) {
			try {
				$token = self::base64_url_encode( random_bytes( 32 ) );
			} catch ( \Throwable $error ) {
				return null;
			}
			$key   = self::option_key( $kind, $token );
			$value = array(
				'data'         => $data,
				'installation' => $installation,
				'revision'     => self::revision(),
				'created'      => time(),
				'expires'      => time() + $ttl,
			);
			// Core transients have a real TTL, including external object caches.
			// A collision is negligible with 256 random bits; still refuse overwrites.
			if ( false === get_transient( $key ) && set_transient( $key, $value, $ttl ) ) {
				return $token;
			}
		}
		return null;
	}

	/**
	 * Stored.
	 *
	 * @param string $kind kind.
	 * @param string $token token.
	 * @param bool   $consume consume.
	 */
	private static function stored( string $kind, string $token, bool $consume = false ): ?array {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]{43}\z/D', $token ) ) {
			return null;
		}
		$key    = self::option_key( $kind, $token );
		$record = get_transient( $key );
		if ( ! is_array( $record ) ) {
			return null;
		}
		$installation = self::installation_id();
		if ( null === $installation || ! isset( $record['expires'], $record['revision'], $record['data'], $record['installation'] )
			|| ! is_string( $record['installation'] ) || ! hash_equals( $installation, $record['installation'] )
			|| ! is_int( $record['expires'] ) || $record['expires'] <= time()
			|| self::revision() !== (int) $record['revision'] || ! is_array( $record['data'] ) ) {
			return null;
		}
		// delete_transient returns false for a concurrent losing redeemer.
		// The result must be checked before trusting a single-use credential.
		if ( $consume && ! delete_transient( $key ) ) {
			return null;
		}
		return $record['data'];
	}

	/**
	 * Introspect.
	 *
	 * @param string $token token.
	 */
	public static function introspect( string $token ): ?array {
		if ( ! self::is_enabled() ) {
			return null;
		}
		$data = self::stored( 'access', $token );
		if ( null === $data || ! self::account_valid( $data ) || ! self::grant_active( $data ) ) {
			return null;
		}
		return array(
			'active'     => true,
			'iss'        => self::issuer(),
			'sub'        => 'wp:' . (string) $data['user_id'],
			'user_id'    => (int) $data['user_id'],
			'client_id'  => self::CLIENT_ID,
			'aud'        => array( OAuthResourceServer::resource() ),
			'scope'      => self::SCOPE,
			'exp'        => $data['expires_at'],
			'iat'        => $data['issued_at'],
			'token_type' => 'Bearer',
		);
	}

	/**
	 * Grant active.
	 *
	 * @param array $owner owner.
	 */
	private static function grant_active( array $owner ): bool {
		$id = $owner['grant_id'] ?? '';
		if ( ! is_string( $id ) || '' === $id ) {
			return false;
		}
		$grant = self::stored( 'grant', $id );
		return is_array( $grant )
			&& ( $grant['user_id'] ?? null ) === ( $owner['user_id'] ?? null )
			&& ( $grant['client_id'] ?? '' ) === self::CLIENT_ID
			&& ( $grant['resource'] ?? '' ) === OAuthResourceServer::resource()
			&& ( $grant['scope'] ?? '' ) === self::SCOPE;
	}

	/**
	 * Revoke grant.
	 *
	 * @param array $owner owner.
	 */
	private static function revoke_grant( array $owner ): void {
		$id = $owner['grant_id'] ?? '';
		if ( is_string( $id ) && '' !== $id ) {
			// Deleting the grant invalidates all its access and refresh tokens immediately.
			self::stored( 'grant', $id, true );
		}
	}

	/**
	 * Account valid.
	 *
	 * @param array $data data.
	 */
	private static function account_valid( array $data ): bool {
		$id = $data['user_id'] ?? 0;
		return is_int( $id ) && $id > 0
			&& ( $data['client_id'] ?? '' ) === self::CLIENT_ID
			&& ( $data['resource'] ?? '' ) === OAuthResourceServer::resource()
			&& ( $data['scope'] ?? '' ) === self::SCOPE
			&& (bool) get_userdata( $id )
			&& user_can( $id, 'manage_options' );
	}

	/**
	 * Throttle.
	 *
	 * @param string $operation operation.
	 * @param int    $limit limit.
	 */
	private static function throttle( string $operation, int $limit ): bool {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$address = is_string( $address ) && filter_var( $address, FILTER_VALIDATE_IP ) ? $address : 'unknown';
		$bucket  = (string) floor( time() / 60 );
		$keys    = array(
			'wpconnector_nat_oauth_' . $operation . '_' . substr( hash( 'sha256', $address ), 0, 20 ) . '_' . $bucket => $limit,
			'wpconnector_nat_oauth_all_' . $operation . '_' . $bucket => 300,
		);
		foreach ( $keys as $key => $max ) {
			$count = get_transient( $key );
			if ( false !== $count && ( ! is_numeric( $count ) || (int) $count >= $max ) ) {
				return false;
			}
			if ( ! set_transient( $key, (int) ( false === $count ? 0 : $count ) + 1, 70 ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Token error.
	 *
	 * @param string $code code.
	 * @param int    $status status.
	 */
	private static function token_error( string $code, int $status = 400 ): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'error' => $code ), $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Body.
	 *
	 * @param \WP_REST_Request $request request.
	 */
	private static function body( \WP_REST_Request $request ): ?array {
		$ctype = (string) $request->get_header( 'content-type' );
		if ( 0 !== stripos( $ctype, 'application/x-www-form-urlencoded' ) ) {
			return null;
		}
		$data = $request->get_body_params();
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) || strlen( $value ) > 4096 ) {
				return null;
			}
		}
		return $data;
	}

	/**
	 * Valid client body.
	 *
	 * @param array $body body.
	 */
	private static function valid_client_body( array $body ): bool {
		return ( $body['client_id'] ?? '' ) === self::CLIENT_ID
			&& ! isset( $body['client_secret'] )
			&& ! isset( $body['client_assertion'] )
			&& ( ! isset( $body['resource'] ) || OAuthResourceServer::resource() === $body['resource'] );
	}

	/**
	 * Token response.
	 *
	 * @param array $owner owner.
	 */
	private static function token_response( array $owner ): \WP_REST_Response {
		if ( ! self::grant_active( $owner ) ) {
			return self::token_error( 'invalid_grant' );
		}
		$now     = time();
		$payload = array(
			'grant_id'   => $owner['grant_id'],
			'user_id'    => $owner['user_id'],
			'client_id'  => self::CLIENT_ID,
			'resource'   => OAuthResourceServer::resource(),
			'scope'      => self::SCOPE,
			'issued_at'  => $now,
			'expires_at' => $now + self::ACCESS_TTL,
		);
		$access  = self::issue( 'access', $payload, self::ACCESS_TTL );
		if ( null === $access ) {
			return self::token_error( 'server_error', 503 );
		}
		$refresh = self::issue( 'refresh', $owner, self::REFRESH_TTL );
		if ( null === $refresh ) {
			// Revoke the issued access token if issuing the pair fails.
			self::stored( 'access', $access, true );
			return self::token_error( 'server_error', 503 );
		}
		$result = new \WP_REST_Response(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => self::SCOPE,
			),
			200
		);
		$result->header( 'Cache-Control', 'no-store' );
		$result->header( 'Pragma', 'no-cache' );
		return $result;
	}

	/**
	 * Token.
	 *
	 * @param \WP_REST_Request $request request.
	 */
	public function token( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::is_enabled() ) {
			return self::token_error( 'invalid_request', 404 );
		}
		if ( ! self::throttle( 'token', 30 ) ) {
			return self::token_error( 'temporarily_unavailable', 429 );
		}
		$body = self::body( $request );
		if ( '' !== (string) $request->get_header( 'authorization' )
			|| null === $body || ! self::valid_client_body( $body ) ) {
			return self::token_error( 'invalid_client' );
		}
		$grant = $body['grant_type'] ?? '';
		if ( 'authorization_code' === $grant ) {
			if ( ! isset( $body['resource'] )
				|| ( $body['redirect_uri'] ?? '' ) !== self::REDIRECT
				|| ! isset( $body['code'], $body['code_verifier'] )
				|| ! preg_match( '/\A[A-Za-z0-9._~-]{43,128}\z/D', $body['code_verifier'] ) ) {
				return self::token_error( 'invalid_request' );
			}
			$data = self::stored( 'code', $body['code'], true );
			if ( null === $data || ! self::account_valid( $data )
				|| ( $data['redirect_uri'] ?? '' ) !== self::REDIRECT
				|| ! isset( $data['code_challenge'] )
				|| ! hash_equals(
					$data['code_challenge'],
					self::base64_url_encode( hash( 'sha256', $body['code_verifier'], true ) )
				) ) {
				return self::token_error( 'invalid_grant' );
			}
			$grant_id = self::issue(
				'grant',
				array(
					'user_id'   => $data['user_id'],
					'client_id' => self::CLIENT_ID,
					'resource'  => OAuthResourceServer::resource(),
					'scope'     => self::SCOPE,
				),
				self::REFRESH_TTL
			);
			if ( null === $grant_id ) {
				return self::token_error( 'server_error', 503 );
			}
			$data['grant_id'] = $grant_id;
			$response         = self::token_response( $data );
			if ( 200 !== $response->get_status() ) {
				self::revoke_grant( $data );
			}
			return $response;
		}
		if ( 'refresh_token' === $grant ) {
			if ( ! isset( $body['resource'], $body['refresh_token'] ) ) {
				return self::token_error( 'invalid_request' );
			}
			$refresh = $body['refresh_token'];
			$data    = self::stored( 'refresh', $refresh );
			if ( null === $data ) {
				// Preserve spent-token ancestry to revoke the active grant on reuse.
				$spent = self::stored( 'spent', $refresh );
				if ( null !== $spent ) {
					self::revoke_grant( $spent );
				}
				return self::token_error( 'invalid_grant' );
			}
			if ( ! self::account_valid( $data ) || ! self::grant_active( $data ) ) {
				return self::token_error( 'invalid_grant' );
			}
			$now         = time();
			$spent_key   = self::option_key( 'spent', $refresh );
			$spent_value = array(
				'data'         => $data,
				'installation' => self::installation_id(),
				'revision'     => self::revision(),
				'created'      => $now,
				'expires'      => $now + self::REFRESH_TTL,
			);
			if ( false !== get_transient( $spent_key ) ) {
				self::revoke_grant( $data );
				return self::token_error( 'invalid_grant' );
			}
			if ( ! set_transient( $spent_key, $spent_value, self::REFRESH_TTL ) ) {
				return self::token_error( 'server_error', 503 );
			}
			if ( null === self::stored( 'refresh', $refresh, true ) ) {
				self::revoke_grant( $data );
				return self::token_error( 'invalid_grant' );
			}
			return self::token_response( $data );
		}
		return self::token_error( 'unsupported_grant_type' );
	}

	/**
	 * Revoke.
	 *
	 * @param \WP_REST_Request $request request.
	 */
	public function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::is_enabled() ) {
			return self::token_error( 'invalid_request', 404 );
		}
		if ( ! self::throttle( 'revoke', 30 ) ) {
			return self::token_error( 'temporarily_unavailable', 429 );
		}
		$body = self::body( $request );
		if ( '' !== (string) $request->get_header( 'authorization' )
			|| null === $body || ! self::valid_client_body( $body )
			|| ! isset( $body['token'] ) ) {
			return self::token_error( 'invalid_request' );
		}
		// RFC 7009: any known token identifies its entire authorization grant.
		foreach ( array( 'access', 'refresh', 'spent' ) as $kind ) {
			$owner = self::stored( $kind, $body['token'] );
			if ( null !== $owner ) {
				self::revoke_grant( $owner );
				break;
			}
		}
		$response = new \WP_REST_Response( array(), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** Update mode. */
	public function update_mode(): void {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized OAuth settings operation.', 'Forbidden', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wpconnector_native_oauth_mode' );
		$decision = isset( $_POST['native_oauth_decision'] ) && is_string( $_POST['native_oauth_decision'] )
			? sanitize_text_field( wp_unslash( $_POST['native_oauth_decision'] ) ) : '';
		if ( 'enable' === $decision ) {
			if ( ! is_ssl() || 'https' !== wp_parse_url( rest_url( self::NAMESPACE . '/mcp' ), PHP_URL_SCHEME )
				|| defined( 'WPCONNECTOR_OAUTH_ISSUER' ) ) {
				wp_die( 'HTTPS and a non-conflicting native OAuth setup are required.', 'OAuth blocked', array( 'response' => 403 ) );
			}
			// Never reuse the previous installation's token generation, even if a
			// persistent Redis/Memcached transient cache survives uninstall.
			try {
				$installation = bin2hex( random_bytes( 32 ) );
			} catch ( \Throwable $error ) {
				wp_die( 'Unable to establish a new OAuth installation identity.', 'OAuth unavailable', array( 'response' => 503 ) );
			}
			if ( ! update_option( self::OPT_INSTALLATION, $installation, false )
				&& get_option( self::OPT_INSTALLATION, '' ) !== $installation ) {
				wp_die( 'Unable to store the OAuth installation identity.', 'OAuth unavailable', array( 'response' => 503 ) );
			}
			update_option(
				self::OPT_ENABLED,
				array(
					'site'        => self::issuer(),
					'approved_by' => get_current_user_id(),
				),
				false
			);
			if ( ! wp_next_scheduled( 'wpconnector_native_oauth_cleanup' ) ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'wpconnector_native_oauth_cleanup' );
			}
		} elseif ( 'disable' === $decision ) {
			update_option( self::OPT_ENABLED, '0', false );
			update_option( self::OPT_REVISION, self::revision() + 1, false );
			delete_option( self::OPT_INSTALLATION );
		} else {
			wp_die( 'Unknown OAuth settings operation.', 'Invalid request', array( 'response' => 400 ) );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=wordpressconnector&native_oauth_saved=1' ) );
		exit;
	}

	/**
	 * Purge expired native token transients using WordPress's own storage API.
	 * Core removes the full expired set (not just the first 100 keys).
	 */
	public function cleanup(): void {
		delete_expired_transients();
	}
}
