<?php
/**
 * Opt-in WordPress-hosted OAuth authorization server for the pinned ChatGPT client.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

final class NativeOAuthServer {
	private const NAMESPACE = 'webactueel-wordpress-connector/v1';
	private const CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	private const REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';
	private const OPT_ENABLED = 'wpconnector_native_oauth_enabled';
	private const OPT_REVISION = 'wpconnector_native_oauth_revision';
	private const STORE_PREFIX = 'wpconnector_native_oauth_';
	private const SCOPE = 'wpconnector:read';
	private const CODE_TTL = 300;
	private const ACCESS_TTL = 900;
	private const REFRESH_TTL = 2592000;

	public function register(): void {
		add_action( 'init', array( $this, 'serveDiscovery' ), 1 );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( 'admin_post_wpconnector_oauth_authorize', array( $this, 'authorize' ) );
		add_action( 'admin_post_nopriv_wpconnector_oauth_authorize', array( $this, 'authorize' ) );
		add_action( 'admin_post_wpconnector_native_oauth_mode', array( $this, 'updateMode' ) );
		add_action( 'wpconnector_native_oauth_cleanup', array( $this, 'cleanup' ) );
	}

	public static function isEnabled(): bool {
		$approval = get_option( self::OPT_ENABLED, array() );
		$home = home_url( '/' );
		$mcp = rest_url( self::NAMESPACE . '/mcp' );
		$admin = admin_url( 'admin-post.php' );
		return is_array( $approval )
			&& ( $approval['site'] ?? '' ) === self::issuer()
			&& is_int( $approval['approved_by'] ?? null )
			&& $approval['approved_by'] > 0
			&& ! defined( 'WPCONNECTOR_OAUTH_ISSUER' )
			&& is_ssl()
			&& 'https' === wp_parse_url( $home, PHP_URL_SCHEME )
			&& 'https' === wp_parse_url( $mcp, PHP_URL_SCHEME )
			&& 'https' === wp_parse_url( $admin, PHP_URL_SCHEME )
			&& wp_parse_url( $home, PHP_URL_HOST ) === wp_parse_url( $mcp, PHP_URL_HOST )
			&& wp_parse_url( $home, PHP_URL_HOST ) === wp_parse_url( $admin, PHP_URL_HOST );
	}

	public static function issuer(): string {
		return rtrim( home_url( '/' ), '/' );
	}

	public static function metadata(): array {
		if ( ! self::isEnabled() ) {
			return array();
		}
		return array(
			'issuer'                                       => self::issuer(),
			'authorization_endpoint'                       => admin_url( 'admin-post.php?action=wpconnector_oauth_authorize' ),
			'token_endpoint'                               => rest_url( self::NAMESPACE . '/oauth/token' ),
			'revocation_endpoint'                          => rest_url( self::NAMESPACE . '/oauth/revoke' ),
			'response_types_supported'                     => array( 'code' ),
			'grant_types_supported'                        => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'             => array( 'S256' ),
			'token_endpoint_auth_methods_supported'        => array( 'none' ),
			'client_id_metadata_document_supported'       => true,
			'authorization_response_iss_parameter_supported' => true,
			'scopes_supported'                             => array( self::SCOPE ),
		);
	}

	private static function discoveryPath(): string {
		// RFC 8414 inserts .well-known between the host and the issuer subpath.
		$issuer_path = wp_parse_url( self::issuer(), PHP_URL_PATH );
		$suffix      = is_string( $issuer_path ) ? rtrim( $issuer_path, '/' ) : '';
		return '/.well-known/oauth-authorization-server' . $suffix;
	}

	public function serveDiscovery(): void {
		// The web server must route this canonical path into WordPress.
		$uri      = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$path     = wp_parse_url( $uri, PHP_URL_PATH );
		$expected = self::discoveryPath();
		if ( ! is_string( $path ) || $path !== $expected ) {
			return;
		}
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
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

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/oauth/authorization-server',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'restMetadata' ),
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

	public function restMetadata() {
		$metadata = self::metadata();
		if ( empty( $metadata ) ) {
			return new \WP_Error( 'wpconnector_native_oauth_disabled', 'OAuth is not enabled.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $metadata, 200 );
	}

	private static function clientValid( string $client_id, string $redirect ): bool {
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

	private static function authorizationParams( array $source ): ?array {
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

	private static function consentNonce( array $params ): string {
		return 'wpconnector_native_oauth_consent_' . hash( 'sha256', wp_json_encode( $params ) );
	}

	private static function sendCallback( array $params, array $values ): void {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer', true );
		$url = add_query_arg( array_merge( $values, array( 'state' => $params['state'], 'iss' => self::issuer() ) ), self::REDIRECT );
		wp_redirect( $url, 302, 'WordPress Connector OAuth' );
		exit;
	}

	public function authorize(): void {
		if ( ! self::isEnabled() ) {
			wp_die( 'OAuth authorization is disabled.', 'OAuth unavailable', array( 'response' => 404 ) );
		}
		$method = $_SERVER['REQUEST_METHOD'] ?? '';
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			wp_die( 'Unsupported HTTP method.', 'Method not allowed', array( 'response' => 405 ) );
		}
		$source = 'POST' === $method ? $_POST : $_GET;
		$params = self::authorizationParams( $source );
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
		if ( ! self::clientValid( $params['client_id'], $params['redirect_uri'] ) ) {
			wp_die( 'Untrusted OAuth client metadata.', 'OAuth request rejected', array( 'response' => 400 ) );
		}
		if ( 'POST' === $method ) {
			check_admin_referer( self::consentNonce( $params ) );
			$decision = isset( $_POST['wpconnector_oauth_decision'] ) && is_string( $_POST['wpconnector_oauth_decision'] )
				? wp_unslash( $_POST['wpconnector_oauth_decision'] ) : '';
			if ( 'approve' !== $decision ) {
				self::sendCallback( $params, array( 'error' => 'access_denied' ) );
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
			self::sendCallback( $params, array( 'code' => $code ) );
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Frame-Options: DENY', true );
		$html = '<h1>' . esc_html__( 'Connect ChatGPT to this WordPress site', 'wordpressconnector' ) . '</h1>';
		$html .= '<p>' . esc_html__( 'Allow the verified ChatGPT client to inspect this site with your current administrator permissions. This connection cannot edit content or install updates.', 'wordpressconnector' ) . '</p>';
		$html .= '<p><strong>' . esc_html( home_url( '/' ) ) . '</strong></p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="wpconnector_oauth_authorize" />';
		foreach ( $params as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		$html .= wp_nonce_field( self::consentNonce( $params ), '_wpnonce', true, false );
		$html .= '<p><button type="submit" name="wpconnector_oauth_decision" value="approve">' . esc_html__( 'Allow read-only access', 'wordpressconnector' ) . '</button> ';
		$html .= '<button type="submit" name="wpconnector_oauth_decision" value="deny">' . esc_html__( 'Deny', 'wordpressconnector' ) . '</button></p></form>';
		wp_die( $html, esc_html__( 'WordPress Connector OAuth consent', 'wordpressconnector' ), array( 'response' => 200, 'back_link' => false ) );
	}

	private static function revision(): int {
		return max( 1, (int) get_option( self::OPT_REVISION, 1 ) );
	}

	private static function optionKey( string $kind, string $token ): string {
		return self::STORE_PREFIX . $kind . '_' . hash_hmac( 'sha256', home_url( '/' ) . ':' . $token, wp_salt( 'auth' ) );
	}

	private static function issue( string $kind, array $data, int $ttl ): ?string {
		for ( $i = 0; $i < 3; ++$i ) {
			try {
				$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
			} catch ( \Throwable $error ) {
				return null;
			}
			$key = self::optionKey( $kind, $token );
			$value = array(
				'data'     => $data,
				'revision' => self::revision(),
				'created'  => time(),
				'expires'  => time() + $ttl,
			);
			if ( add_option( $key, $value, '', false ) ) {
				return $token;
			}
		}
		return null;
	}

	private static function stored( string $kind, string $token, bool $consume = false ): ?array {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]{43}\z/D', $token ) ) {
			return null;
		}
		$key = self::optionKey( $kind, $token );
		$record = get_option( $key, false );
		if ( ! is_array( $record ) ) {
			return null;
		}
		if ( $consume ) {
			global $wpdb;
			// A single SQL DELETE decides the winner when two token redemptions race.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) );
			wp_cache_delete( $key, 'options' );
			if ( 1 !== $deleted ) {
				return null;
			}
		}
		if ( ! isset( $record['expires'], $record['revision'], $record['data'] )
			|| ! is_int( $record['expires'] ) || $record['expires'] <= time()
			|| (int) $record['revision'] !== self::revision() || ! is_array( $record['data'] ) ) {
			return null;
		}
		return $record['data'];
	}

	public static function introspect( string $token ): ?array {
		if ( ! self::isEnabled() ) {
			return null;
		}
		$data = self::stored( 'access', $token );
		if ( null === $data || ! self::accountValid( $data ) || ! self::grantActive( $data ) ) {
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

	private static function grantActive( array $owner ): bool {
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

	private static function revokeGrant( array $owner ): void {
		$id = $owner['grant_id'] ?? '';
		if ( is_string( $id ) && '' !== $id ) {
			// Deleting the grant invalidates all its access and refresh tokens immediately.
			self::stored( 'grant', $id, true );
		}
	}

	private static function accountValid( array $data ): bool {
		$id = $data['user_id'] ?? 0;
		return is_int( $id ) && $id > 0
			&& ( $data['client_id'] ?? '' ) === self::CLIENT_ID
			&& ( $data['resource'] ?? '' ) === OAuthResourceServer::resource()
			&& ( $data['scope'] ?? '' ) === self::SCOPE
			&& (bool) get_userdata( $id )
			&& user_can( $id, 'manage_options' );
	}

	private static function throttle( string $operation, int $limit ): bool {
		$address = $_SERVER['REMOTE_ADDR'] ?? '';
		$address = is_string( $address ) && filter_var( $address, FILTER_VALIDATE_IP ) ? $address : 'unknown';
		$bucket = (string) floor( time() / 60 );
		$keys = array(
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

	private static function tokenError( string $code, int $status = 400 ): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'error' => $code ), $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

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

	private static function validClientBody( array $body ): bool {
		return ( $body['client_id'] ?? '' ) === self::CLIENT_ID
			&& ! isset( $body['client_secret'] )
			&& ! isset( $body['client_assertion'] )
			&& ( ! isset( $body['resource'] ) || OAuthResourceServer::resource() === $body['resource'] );
	}

	private static function tokenResponse( array $owner ): \WP_REST_Response {
		if ( ! self::grantActive( $owner ) ) {
			return self::tokenError( 'invalid_grant' );
		}
		$now = time();
		$payload = array(
			'grant_id'  => $owner['grant_id'],
			'user_id'   => $owner['user_id'],
			'client_id' => self::CLIENT_ID,
			'resource'  => OAuthResourceServer::resource(),
			'scope'     => self::SCOPE,
			'issued_at' => $now,
			'expires_at'=> $now + self::ACCESS_TTL,
		);
		$access = self::issue( 'access', $payload, self::ACCESS_TTL );
		if ( null === $access ) {
			return self::tokenError( 'server_error', 503 );
		}
		$refresh = self::issue( 'refresh', $owner, self::REFRESH_TTL );
		if ( null === $refresh ) {
			// Revoke the issued access token if issuing the pair fails.
			self::stored( 'access', $access, true );
			return self::tokenError( 'server_error', 503 );
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

	public function token( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::isEnabled() ) {
			return self::tokenError( 'invalid_request', 404 );
		}
		if ( ! self::throttle( 'token', 30 ) ) {
			return self::tokenError( 'temporarily_unavailable', 429 );
		}
		$body = self::body( $request );
		if ( '' !== $request->get_header( 'authorization' )
			|| null === $body || ! self::validClientBody( $body ) ) {
			return self::tokenError( 'invalid_client' );
		}
		$grant = $body['grant_type'] ?? '';
		if ( 'authorization_code' === $grant ) {
			if ( ! isset( $body['resource'] )
				|| ( $body['redirect_uri'] ?? '' ) !== self::REDIRECT
				|| ! isset( $body['code'], $body['code_verifier'] )
				|| ! preg_match( '/\A[A-Za-z0-9._~-]{43,128}\z/D', $body['code_verifier'] ) ) {
				return self::tokenError( 'invalid_request' );
			}
			$data = self::stored( 'code', $body['code'], true );
			if ( null === $data || ! self::accountValid( $data )
				|| ( $data['redirect_uri'] ?? '' ) !== self::REDIRECT
				|| ! isset( $data['code_challenge'] )
				|| ! hash_equals(
					$data['code_challenge'],
					rtrim( strtr( base64_encode( hash( 'sha256', $body['code_verifier'], true ) ), '+/', '-_' ), '=' )
				) ) {
				return self::tokenError( 'invalid_grant' );
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
				return self::tokenError( 'server_error', 503 );
			}
			$data['grant_id'] = $grant_id;
			$response         = self::tokenResponse( $data );
			if ( 200 !== $response->get_status() ) {
				self::revokeGrant( $data );
			}
			return $response;
		}
		if ( 'refresh_token' === $grant ) {
			if ( ! isset( $body['resource'], $body['refresh_token'] ) ) {
				return self::tokenError( 'invalid_request' );
			}
			$refresh = $body['refresh_token'];
			$data    = self::stored( 'refresh', $refresh );
			if ( null === $data ) {
				// Preserve spent-token ancestry to revoke the active grant on reuse.
				$spent = self::stored( 'spent', $refresh );
				if ( null !== $spent ) {
					self::revokeGrant( $spent );
				}
				return self::tokenError( 'invalid_grant' );
			}
			if ( ! self::accountValid( $data ) || ! self::grantActive( $data ) ) {
				return self::tokenError( 'invalid_grant' );
			}
			$now         = time();
			$spent_key   = self::optionKey( 'spent', $refresh );
			$spent_value = array(
				'data'     => $data,
				'revision' => self::revision(),
				'created'  => $now,
				'expires'  => $now + self::REFRESH_TTL,
			);
			// add_option is atomic on option_name: only one worker can rotate.
			if ( ! add_option( $spent_key, $spent_value, '', false ) ) {
				if ( null !== self::stored( 'spent', $refresh ) ) {
					self::revokeGrant( $data );
				}
				return self::tokenError( 'invalid_grant' );
			}
			if ( null === self::stored( 'refresh', $refresh, true ) ) {
				self::revokeGrant( $data );
				return self::tokenError( 'invalid_grant' );
			}
			return self::tokenResponse( $data );
		}
		return self::tokenError( 'unsupported_grant_type' );
	}

	public function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::isEnabled() ) {
			return self::tokenError( 'invalid_request', 404 );
		}
		if ( ! self::throttle( 'revoke', 30 ) ) {
			return self::tokenError( 'temporarily_unavailable', 429 );
		}
		$body = self::body( $request );
		if ( '' !== $request->get_header( 'authorization' )
			|| null === $body || ! self::validClientBody( $body )
			|| ! isset( $body['token'] ) ) {
			return self::tokenError( 'invalid_request' );
		}
		// RFC 7009: any known token identifies its entire authorization grant.
		foreach ( array( 'access', 'refresh', 'spent' ) as $kind ) {
			$owner = self::stored( $kind, $body['token'] );
			if ( null !== $owner ) {
				self::revokeGrant( $owner );
				break;
			}
		}
		$response = new \WP_REST_Response( array(), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public function updateMode(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized OAuth settings operation.', 'Forbidden', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wpconnector_native_oauth_mode' );
		$decision = isset( $_POST['native_oauth_decision'] ) && is_string( $_POST['native_oauth_decision'] )
			? wp_unslash( $_POST['native_oauth_decision'] ) : '';
		if ( 'enable' === $decision ) {
			if ( ! is_ssl() || 'https' !== wp_parse_url( rest_url( self::NAMESPACE . '/mcp' ), PHP_URL_SCHEME )
				|| defined( 'WPCONNECTOR_OAUTH_ISSUER' ) ) {
				wp_die( 'HTTPS and a non-conflicting native OAuth setup are required.', 'OAuth blocked', array( 'response' => 403 ) );
			}
			update_option( self::OPT_ENABLED, array( 'site' => self::issuer(), 'approved_by' => get_current_user_id() ), false );
			if ( ! wp_next_scheduled( 'wpconnector_native_oauth_cleanup' ) ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'wpconnector_native_oauth_cleanup' );
			}
		} elseif ( 'disable' === $decision ) {
			update_option( self::OPT_ENABLED, '0', false );
			update_option( self::OPT_REVISION, self::revision() + 1, false );
		} else {
			wp_die( 'Unknown OAuth settings operation.', 'Invalid request', array( 'response' => 400 ) );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=wordpressconnector&native_oauth_saved=1' ) );
		exit;
	}

	public function cleanup(): void {
		global $wpdb;
		$cursor_key = self::STORE_PREFIX . 'cleanup_cursor';
		$cursors    = get_option( $cursor_key, array() );
		$cursors    = is_array( $cursors ) ? $cursors : array();
		$more       = false;
		foreach ( array( 'code', 'access', 'refresh', 'spent', 'grant' ) as $kind ) {
			$cursor = isset( $cursors[ $kind ] ) && is_string( $cursors[ $kind ] ) ? $cursors[ $kind ] : '';
			$like   = $wpdb->esc_like( self::STORE_PREFIX . $kind . '_' ) . '%';
			// A persisted keyset cursor prevents the first 100 live rows from hiding expired rows.
			$names  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT 100",
					$like,
					$cursor
				)
			);
			foreach ( (array) $names as $name ) {
				$record = get_option( $name, false );
				if ( ! is_array( $record ) || ! is_int( $record['expires'] ?? null )
					|| $record['expires'] <= time() || (int) ( $record['revision'] ?? 0 ) !== self::revision() ) {
					delete_option( $name );
				}
			}
			if ( count( (array) $names ) === 100 ) {
				$cursors[ $kind ] = (string) end( $names );
				$more             = true;
			} else {
				unset( $cursors[ $kind ] );
			}
		}
		update_option( $cursor_key, $cursors, false );
		if ( $more && ! wp_next_scheduled( 'wpconnector_native_oauth_cleanup', array( 'continuation' ) ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'wpconnector_native_oauth_cleanup', array( 'continuation' ) );
		}
	}
}
