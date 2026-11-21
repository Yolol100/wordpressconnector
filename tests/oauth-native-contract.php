<?php
/**
 * Isolated WordPress-hosted OAuth security contract, no real users or secrets.
 *
 * @package WordPressConnector
 */
declare(strict_types=1);

$GLOBALS['options'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['transient_expires'] = array();
$GLOBALS['user_allowed'] = true;
$GLOBALS['ssl_ready'] = true;
$GLOBALS['environment'] = 'production';
$GLOBALS['file_edit_allowed'] = false;
$GLOBALS['home_origin'] = 'https://example.invalid';
$GLOBALS['client_response_ok'] = true;
$GLOBALS['http_calls'] = array();
$GLOBALS['methods'] = array();
$GLOBALS['stored_deletions'] = 0;

final class WP_Error {
	private string $code;
	private array $data;
	public function __construct( string $code, string $message, array $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): array { return $this->data; }
}
final class WP_REST_Server {
	public const READABLE = 'GET';
	public const CREATABLE = 'POST';
}
final class WP_REST_Response {
	private array $data;
	private int $status;
	private array $headers = array();
	public function __construct( array $data, int $status ) { $this->data = $data; $this->status = $status; }
	public function header( string $name, string $value ): void { $this->headers[$name] = $value; }
	public function get_data(): array { return $this->data; }
	public function get_status(): int { return $this->status; }
	public function get_headers(): array { return $this->headers; }
}
final class WP_REST_Request {
	private array $headers;
	private array $body;
	public function __construct( array $headers = array(), array $body = array() ) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->body = $body;
	}
	public function get_header( string $key ): ?string { return isset( $this->headers[strtolower( $key )] ) ? (string) $this->headers[strtolower( $key )] : null; }
	public function get_body_params(): array { return $this->body; }
}
final class FakeWpdb {
	public string $options = 'wp_options';
	public function prepare( string $query, string $param ): string {
		return str_replace( '%s', "'" . str_replace( "'", "''", $param ) . "'", $query );
	}
	public function query( string $sql ): int {
		if ( ! preg_match( "/WHERE option_name = '([^']+)'/", $sql, $match ) ) {
			throw new RuntimeException( 'Unexpected SQL query' );
		}
		$key = $match[1];
		if ( ! array_key_exists( $key, $GLOBALS['options'] ) ) {
			return 0;
		}
		unset( $GLOBALS['options'][$key] );
		++$GLOBALS['stored_deletions'];
		return 1;
	}
	public function esc_like( string $str ): string { return $str; }
	public function get_col( string $query ): array {
		return array_filter( array_keys( $GLOBALS['options'] ), static function ( string $key ): bool {
			return strpos( $key, 'wpconnector_native_oauth_' ) === 0;
		} );
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

function get_option( string $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function add_option( string $name, $value, string $deprecated = '', $autoload = false ): bool {
	if ( array_key_exists( $name, $GLOBALS['options'] ) ) { return false; }
	$GLOBALS['options'][$name] = $value;
	return true;
}
function update_option( string $name, $value, $autoload = false ): bool { $GLOBALS['options'][$name] = $value; return true; }
function delete_option( string $name ): bool { unset( $GLOBALS['options'][$name] ); return true; }
function wp_cache_delete( string $name, string $group ): void {}
function get_transient( string $name ) {
	if ( isset( $GLOBALS['transient_expires'][$name] ) && $GLOBALS['transient_expires'][$name] <= time() ) {
		unset( $GLOBALS['transients'][$name], $GLOBALS['transient_expires'][$name] );
	}
	return $GLOBALS['transients'][$name] ?? false;
}
function set_transient( string $name, $value, int $seconds ): bool {
	$GLOBALS['transients'][$name] = $value;
	$GLOBALS['transient_expires'][$name] = time() + $seconds;
	return true;
}
function delete_transient( string $name ): bool {
	if ( false === get_transient( $name ) ) { return false; }
	unset( $GLOBALS['transients'][$name], $GLOBALS['transient_expires'][$name] );
	++$GLOBALS['stored_deletions'];
	return true;
}
function delete_expired_transients(): void {
	foreach ( array_keys( $GLOBALS['transient_expires'] ) as $name ) {
		get_transient( $name );
	}
}
function wp_parse_url( string $url, int $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function home_url( string $path = '' ): string { return $GLOBALS['home_origin'] . '/' . ltrim( $path, '/' ); }
function rest_url( string $path ): string { return 'https://example.invalid/wp-json/' . ltrim( $path, '/' ); }
function admin_url( string $path ): string { return 'https://example.invalid/wp-admin/' . $path; }
function is_ssl(): bool { return $GLOBALS['ssl_ready']; }
function wp_get_environment_type(): string { return $GLOBALS['environment']; }
function wp_salt( string $scheme ): string { return 'synthetic-secret-for-tests-only'; }
function is_wp_error( $result ): bool { return $result instanceof WP_Error; }
function wp_safe_remote_get( string $url, array $args ) {
	$GLOBALS['http_calls'][] = array( 'url' => $url, 'args' => $args );
	if ( ! $GLOBALS['client_response_ok'] ) {
		return new WP_Error( 'unavailable', 'No external metadata' );
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body' => json_encode(
			array(
				'client_id' => 'https://chatgpt.com/oauth/client.json',
				'redirect_uris' => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ),
				'token_endpoint_auth_methods_supported' => array( 'none', 'private_key_jwt' ),
				// ChatGPT's singular preference can be private_key_jwt while its plural supported methods include none.
				'token_endpoint_auth_method' => 'private_key_jwt',
				'grant_types' => array( 'authorization_code', 'refresh_token' ),
			)
		),
	);
}
function wp_safe_remote_post(): void { throw new RuntimeException( 'Native validation must not call remote token introspection' ); }
function wp_remote_retrieve_response_code( array $response ): int { return $response['response']['code']; }
function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
function get_userdata( int $user_id ) { return 123 === $user_id ? (object) array( 'ID' => $user_id ) : false; }
function user_can( int $user_id, string $permission ): bool { return 123 === $user_id && $GLOBALS['user_allowed'] && ( 'manage_options' === $permission || ( 'edit_plugins' === $permission && $GLOBALS['file_edit_allowed'] ) ); }
function current_user_can( string $permission ): bool { return user_can( 123, $permission ); }
function wp_set_current_user( int $id ): void { $GLOBALS['current_user'] = $id; }
function __return_true(): bool { return true; }
function register_rest_route( string $namespace, string $route, array $args ): void {
	$GLOBALS['routes'][$route] = array( $namespace, $args );
}
function add_action( string $name, $callback, int $priority = 10 ): void { $GLOBALS['actions'][$name][] = $callback; }
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }

require dirname( __DIR__ ) . '/plugin/wordpressconnector/includes/Security/class-nativeoauthserver.php';
require dirname( __DIR__ ) . '/plugin/wordpressconnector/includes/Security/OAuthResourceServer.php';

use Webactueel\WordPressConnector\Security\NativeOAuthServer;
use Webactueel\WordPressConnector\Security\OAuthResourceServer;

function must( bool $test, string $why ): void {
	if ( ! $test ) { throw new RuntimeException( $why ); }
}
function internal( string $method, array $args = array() ) {
	$r = new ReflectionMethod( NativeOAuthServer::class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$r->setAccessible( true ); // Private reflection requires this before PHP 8.1.
	}
	return $r->invokeArgs( null, $args );
}
function token_request( array $data, string $content_type = 'application/x-www-form-urlencoded' ): WP_REST_Request {
	return new WP_REST_Request( array( 'content-type' => $content_type ), $data );
}
function base_token( string $grant, string $token, string $verifier = '' ): array {
	return array(
		'grant_type' => $grant,
		'client_id' => 'https://chatgpt.com/oauth/client.json',
		'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
		'resource' => OAuthResourceServer::resource(),
		'code' => $token,
		'code_verifier' => $verifier,
	);
}

must( ! NativeOAuthServer::is_enabled(), 'Native OAuth is off by default' );
must( ! NativeOAuthServer::has_prior_approval(), 'No prior native approval by default' );
must( array() === NativeOAuthServer::metadata(), 'No authorization metadata before consent toggle' );
must( ! OAuthResourceServer::isConfigured(), 'External mode must remain disabled by default' );
$server = new NativeOAuthServer();
$server->register();
foreach ( $GLOBALS['actions']['rest_api_init'] as $callback ) {
	call_user_func( $callback );
}
must( isset( $GLOBALS['routes']['/oauth/token'], $GLOBALS['routes']['/oauth/revoke'] ), 'OAuth routes registered' );
must( 404 === $server->rest_metadata()->get_error_data()['status'], 'Disabled metadata must be 404' );

update_option( 'wpconnector_native_oauth_enabled', array( 'site' => 'https://example.invalid', 'approved_by' => 123 ) );
must( ! NativeOAuthServer::is_enabled(), 'Approval without a fresh installation identifier cannot activate OAuth' );
update_option( 'wpconnector_native_oauth_installation', str_repeat( 'a', 64 ) );
must( NativeOAuthServer::is_enabled(), 'Owner opt-in and installation generation enable HTTPS-native mode' );
must( NativeOAuthServer::has_prior_approval(), 'Prior approval is tracked for later revocation' );
$GLOBALS['home_origin'] = 'https://cloned-site.example.invalid';
must( ! NativeOAuthServer::is_enabled(), 'Cloned site must not inherit OAuth approval' );
$GLOBALS['home_origin'] = 'https://example.invalid';
$meta = NativeOAuthServer::metadata();
must( $meta['issuer'] === 'https://example.invalid', 'Issuer must be exact canonical origin' );
must( NativeOAuthServer::discovery_url() === 'https://example.invalid/.well-known/oauth-authorization-server', 'Root issuer metadata discovery' );
$GLOBALS['home_origin'] = 'https://example.invalid/blog';
update_option( 'wpconnector_native_oauth_enabled', array( 'site' => NativeOAuthServer::issuer(), 'approved_by' => 123 ) );
must( NativeOAuthServer::discovery_url() === 'https://example.invalid/.well-known/oauth-authorization-server/blog', 'Issuer subpath follows RFC 8414' );
$GLOBALS['home_origin'] = 'https://example.invalid';
update_option( 'wpconnector_native_oauth_enabled', array( 'site' => NativeOAuthServer::issuer(), 'approved_by' => 123 ) );
must( $meta['authorization_response_iss_parameter_supported'] === true, 'Issuer response must be protected' );
must( $meta['client_id_metadata_document_supported'] === true, 'CIMD required' );
must( $meta['code_challenge_methods_supported'] === array( 'S256' ), 'No plain PKCE fallback' );
must( $meta['token_endpoint_auth_methods_supported'] === array( 'none' ), 'No shared client secret required' );
must( $meta['revocation_endpoint_auth_methods_supported'] === array( 'none' ), 'Public client revocation does not require a shared secret' );
must( OAuthResourceServer::metadata()['authorization_servers'] === array( $meta['issuer'] ), 'Resource must advertise the matching issuer' );

$resource = OAuthResourceServer::resource();
// Regression vectors prevent custom Base64URL encoding drift.
foreach ( array( '', 'f', 'fo', 'foo', "\0\xff", random_bytes( 32 ), random_bytes( 64 ) ) as $bytes ) {
	$expected = rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	must( internal( 'base64_url_encode', array( $bytes ) ) === $expected, 'RFC 4648 URL-safe encoding vector' );
}
$verifier = str_repeat( 'v', 50 );
$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
$params = array(
	'client_id' => 'https://chatgpt.com/oauth/client.json',
	'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
	'response_type' => 'code',
	'state' => 'opaque-state-0123456789',
	'code_challenge' => $challenge,
	'code_challenge_method' => 'S256',
	'resource' => $resource,
	'scope' => 'wpconnector:read',
);
must( internal( 'authorization_params', array( $params ) ) === $params, 'Valid authorization request accepted' );
$filesParams = $params;
$filesParams['scope'] = 'wpconnector:read wpconnector:files';
must( null === internal( 'authorization_params', array( $filesParams ) ),
    'Staging file grant must not be requested on a production runtime.' );
define( 'WPCONNECTOR_OAUTH_ALLOW_STAGING_FILES', true );
must( null === internal( 'authorization_params', array( $filesParams ) ),
    'A server opt-in alone must never grant file access outside staging.' );
$GLOBALS['environment'] = 'staging';
must( null === internal( 'authorization_params', array( $filesParams ) ),
    'A staging environment without an exact owner-pinned origin still denies file grants.' );
define( 'WPCONNECTOR_OAUTH_STAGING_ORIGIN', 'https://example.invalid' );
must( null !== internal( 'authorization_params', array( $filesParams ) ) && NativeOAuthServer::staging_files_enabled(),
    'Only server opt-in, exact origin and staging environment enable file grants.' );
must( 'wpconnector:read' === internal( 'consent_scope', array( 'wpconnector:read', 'approve', false ) ),
    'Normal read-only consent remains available without file-editor permission.' );
must( 'wpconnector:read' === internal( 'consent_scope', array( $filesParams['scope'], 'approve', false ) ),
    'A staging file request may be safely downscoped to read-only when WordPress blocks file editors.' );
must( 'wpconnector:read' === internal( 'consent_scope', array( $filesParams['scope'], 'approve', true ) ),
    'An editor-capable administrator can still choose a read-only grant.' );
must( null === internal( 'consent_scope', array( $filesParams['scope'], 'approve_files', false ) ),
    'Explicit staging file consent is refused without WordPress file-editor permission.' );
must( $filesParams['scope'] === internal( 'consent_scope', array( $filesParams['scope'], 'approve_files', true ) ),
    'File scope is issued only after distinct consent and WordPress capabilities.' );
must( null === internal( 'consent_scope', array( 'wpconnector:read', 'approve_files', true ) ),
    'An attacker cannot add unrequested file scope using a forged consent decision.' );
must( null === internal( 'consent_scope', array( $filesParams['scope'], 'deny', true ) ),
    'Deny never issues a read or file grant.' );
$GLOBALS['home_origin'] = 'https://other.example.invalid';
must( null === internal( 'authorization_params', array( $filesParams ) ),
    'Cloned or misdirected origin cannot inherit file editing even when marked staging.' );
$GLOBALS['home_origin'] = 'https://example.invalid';
$reversedScopes = $filesParams;
$reversedScopes['scope'] = 'wpconnector:files wpconnector:read';
must( internal( 'authorization_params', array( $reversedScopes ) ) === $filesParams,
    'OAuth scope ordering does not change permission; persist canonical read-then-files order.' );
foreach ( array(
    'wpconnector:files',
    'wpconnector:read wpconnector:read',
    'wpconnector:read wpconnector:files wpconnector:files',
    'wpconnector:read wpconnector:update',
    'wpconnector:read  wpconnector:files',
) as $invalidScope ) {
    $invalidRequest = $filesParams;
    $invalidRequest['scope'] = $invalidScope;
    must( null === internal( 'authorization_params', array( $invalidRequest ) ),
        'Reject duplicate, unknown or malformed staging OAuth scopes.' );
}
must( in_array( 'wpconnector:files', NativeOAuthServer::metadata()['scopes_supported'], true ),
    'Staging metadata advertises a distinct files permission.' );
must( in_array( 'wpconnector:files', OAuthResourceServer::metadata()['scopes_supported'], true ),
    'Protected resource metadata advertises the same files permission.' );
$oauthChallengeBuilder = new ReflectionMethod( OAuthResourceServer::class, 'build_challenge_header' );
if ( PHP_VERSION_ID < 80100 ) {
    $oauthChallengeBuilder->setAccessible( true );
}
$filesChallenge = $oauthChallengeBuilder->invoke( null, false, true );
must( false !== strpos( $filesChallenge, 'scope="wpconnector:read wpconnector:files"' )
    && false !== strpos( $filesChallenge, 'resource_metadata="' ),
    'OAuth 403 step-up advertises the exact read and staging-file scopes.' );

$GLOBALS['environment'] = 'production';
must( null === internal( 'authorization_params', array( $filesParams ) ) && ! NativeOAuthServer::staging_files_enabled(),
    'Changing the runtime to production revokes eligibility immediately.' );

foreach ( array(
	array( 'redirect_uri', 'https://evil.invalid/callback' ),
	array( 'resource', 'https://evil.invalid/mcp' ),
	array( 'code_challenge_method', 'plain' ),
	array( 'scope', 'wpconnector:update' ),
	array( 'response_type', 'token' ),
	array( 'state', 'tiny' ),
	array( 'code_challenge', str_repeat( 'X', 42 ) ),
	array( 'client_id', 'https://evil.invalid/client.json' ),
) as $change ) {
	$bad = $params;
	$bad[$change[0]] = $change[1];
	must( null === internal( 'authorization_params', array( $bad ) ), 'Reject invalid ' . $change[0] );
}
must( ! internal( 'client_valid', array( 'https://evil.invalid/client.json', $params['redirect_uri'] ) ), 'Unknown client denied without fetching' );
must( ! internal( 'client_valid', array( $params['client_id'], 'https://evil.invalid/redirect' ) ), 'Redirect swap denied' );
must( internal( 'client_valid', array( $params['client_id'], $params['redirect_uri'] ) ), 'Verified OpenAI CIMD is accepted' );
must( count( $GLOBALS['http_calls'] ) === 1
	&& $GLOBALS['http_calls'][0]['url'] === $params['client_id']
	&& $GLOBALS['http_calls'][0]['args']['sslverify'] === true
	&& $GLOBALS['http_calls'][0]['args']['redirection'] === 0, 'CIMD fetch must be pinned, HTTPS and no redirects' );

// Pre-registration never enables wildcards or unapproved callback substitutions.
$registered_callback = 'https://chatgpt.com/connector/oauth/SyntheticCallbackId123';
$custom_request = $params;
$custom_request['redirect_uri'] = $registered_callback;
must( null === internal( 'authorization_params', array( $custom_request ) ), 'Unregistered callback is rejected by default' );
foreach ( array(
	'https://chatgpt.com/connector/oauth/small',
	'https://evil.invalid/connector/oauth/SyntheticCallbackId123',
	'https://chatgpt.com/connector/oauth/SyntheticCallbackId123/extra',
	'https://chatgpt.com/connector/oauth/SyntheticCallbackId123?query=1',
	'https://chatgpt.com.evil.invalid/connector/oauth/SyntheticCallbackId123',
) as $bad_callback ) {
	must( ! internal( 'is_registered_callback', array( $bad_callback ) ), 'Malformed and untrusted callback cannot be pre-registered' );
}
must( internal( 'is_registered_callback', array( $registered_callback ) ), 'Exact ChatGPT callback format is accepted for registration' );
update_option( 'wpconnector_native_oauth_registered_redirect', $registered_callback );
must( NativeOAuthServer::registered_redirect() === $registered_callback, 'Stored registration is an exact URL' );
must( internal( 'authorization_params', array( $custom_request ) ) === $custom_request, 'Admin-approved pre-registered callback accepted' );
must( internal( 'client_valid', array( $params['client_id'], $registered_callback ) ), 'CIMD identity verified for the one pre-registered callback' );
must( ! internal( 'client_valid', array( $params['client_id'], 'https://chatgpt.com/connector/oauth/UnregisteredCallbackId' ) ), 'Other ChatGPT callback identities remain forbidden' );
delete_option( 'wpconnector_native_oauth_registered_redirect' );
must( null === internal( 'authorization_params', array( $custom_request ) ), 'Removing registration immediately blocks old callback' );

$owner = array(
	'user_id' => 123,
	'client_id' => $params['client_id'],
	'redirect_uri' => $params['redirect_uri'],
	'code_challenge' => $challenge,
	'resource' => $resource,
	'scope' => 'wpconnector:read',
);

// Emulate an interleaved admin callback change after a code was consumed
// but before grant issuance. The new generation must never adopt old codes.
$original_generation = get_option( 'wpconnector_native_oauth_installation' );
$race_code            = internal( 'issue', array( 'code', $owner, 300 ) );
$race_owner           = internal( 'stored', array( 'code', $race_code, true ) );
must( is_array( $race_owner ) && $race_owner['_oauth_installation'] === $original_generation, 'Consumed code retains original generation' );
update_option( 'wpconnector_native_oauth_installation', str_repeat( 'b', 64 ) );
$race_grant = internal( 'issue', array(
	'grant',
	array(
		'user_id' => 123,
		'client_id' => $params['client_id'],
		'resource' => $resource,
		'scope' => 'wpconnector:read',
		'_oauth_installation' => $race_owner['_oauth_installation'],
	),
	300,
) );
must( null === $race_grant, 'Consumed old callback code cannot mint a grant in new generation' );
must( null === internal( 'issue', array( 'refresh', $race_owner, 300 ) ), 'Old exchange cannot mint refresh after callback rotation' );
update_option( 'wpconnector_native_oauth_installation', $original_generation );
must( is_string( $original_generation ) && strlen( $original_generation ) === 64, 'Original generation restored for later contract tests' );

$code = internal( 'issue', array( 'code', $owner, 300 ) );
must( is_string( $code ) && strlen( $code ) === 43, '256-bit code generated' );
must( ! in_array( $code, array_keys( $GLOBALS['options'] ), true ), 'Bearer secrets never become option names' );
$issued = $server->token( token_request( base_token( 'authorization_code', $code, $verifier ) ) );
must( 200 === $issued->get_status(), 'Code exchange accepted with PKCE S256' );
$pair = $issued->get_data();
must( strlen( $pair['access_token'] ) === 43 && strlen( $pair['refresh_token'] ) === 43, 'Opaque token pair returned' );
must( $pair['expires_in'] === 900 && $pair['scope'] === 'wpconnector:read', 'Least privilege, short expiry' );
must( $issued->get_headers()['Cache-Control'] === 'no-store', 'OAuth response is noncacheable' );
$claims = NativeOAuthServer::introspect( $pair['access_token'] );
must( is_array( $claims ) && $claims['sub'] === 'wp:123' && $claims['aud'] === array( $resource ), 'Native bearer bound to origin and account' );
must( $claims['iss'] === NativeOAuthServer::issuer(), 'Token issuer binding' );

// Authorization codes are bound to the exact administrator-registered redirect.
update_option( 'wpconnector_native_oauth_registered_redirect', $registered_callback );
$custom_owner = $owner;
$custom_owner['redirect_uri'] = $registered_callback;
$custom_code = internal( 'issue', array( 'code', $custom_owner, 300 ) );
$custom_body = base_token( 'authorization_code', $custom_code, $verifier );
$custom_body['redirect_uri'] = $registered_callback;
$custom_pair = $server->token( token_request( $custom_body ) );
must( 200 === $custom_pair->get_status(), 'Authorization code exchange succeeds for exact pre-registered redirect' );
$swapped_code = internal( 'issue', array( 'code', $custom_owner, 300 ) );
$swapped_body = base_token( 'authorization_code', $swapped_code, $verifier );
must( 'invalid_grant' === $server->token( token_request( $swapped_body ) )->get_data()['error'], 'Code cannot be exchanged using another registered redirect' );
delete_option( 'wpconnector_native_oauth_registered_redirect' );
must( null === internal( 'authorization_params', array( $custom_request ) ), 'Removed callback is denied again' );

// Issue an actual reduced-scope token from an optional file request.
$GLOBALS['environment'] = 'staging';
$GLOBALS['file_edit_allowed'] = false;
$downscopedOwner = $owner;
$downscopedOwner['scope'] = internal( 'consent_scope', array( $filesParams['scope'], 'approve', false ) );
$downscopedCode = internal( 'issue', array( 'code', $downscopedOwner, 300 ) );
$downscopedToken = $server->token( token_request( base_token( 'authorization_code', $downscopedCode, $verifier ) ) );
must( 200 === $downscopedToken->get_status() && 'wpconnector:read' === $downscopedToken->get_data()['scope'],
    'OAuth token response reports reduced read-only scope instead of silently granting file rights.' );
$downscopedResource = new OAuthResourceServer();
must( true === $downscopedResource->authenticate( new WP_REST_Request( array( 'authorization' => 'Bearer ' . $downscopedToken->get_data()['access_token'] ) ) )
    && ! $downscopedResource->can_edit_staging_files(),
    'Downscoped bearer can authenticate without file-editor rights but cannot access files.' );
$GLOBALS['environment'] = 'production';

$authenticated = new OAuthResourceServer();
$GLOBALS['environment'] = 'staging';
$GLOBALS['file_edit_allowed'] = true;
$filesOwner = $owner;
$filesOwner['scope'] = 'wpconnector:read wpconnector:files';
$filesCode = internal( 'issue', array( 'code', $filesOwner, 300 ) );
$filesPair = $server->token( token_request( base_token( 'authorization_code', $filesCode, $verifier ) ) );
must( 200 === $filesPair->get_status()
    && 'wpconnector:read wpconnector:files' === $filesPair->get_data()['scope'],
    'Opted-in staging admin can issue separately consented file scope.' );
$fileBearer = $filesPair->get_data()['access_token'];
$filesResource = new OAuthResourceServer();
must( true === $filesResource->authenticate( new WP_REST_Request( array( 'authorization' => 'Bearer ' . $fileBearer ) ) )
    && $filesResource->can_edit_staging_files(), 'File scope enables ONLY separately consented staging file operations.' );
$GLOBALS['environment'] = 'production';
must( null === NativeOAuthServer::introspect( $fileBearer ),
    'Existing staging file token is revoked immediately when runtime changes to production.' );
$GLOBALS['environment'] = 'staging';
$GLOBALS['file_edit_allowed'] = false;
must( null === NativeOAuthServer::introspect( $fileBearer ),
    'Removing WordPress file edit permission revokes the file grant.' );
$GLOBALS['file_edit_allowed'] = true;
$filesReadOnlyResource = new OAuthResourceServer();
must( true === $filesReadOnlyResource->authenticate( new WP_REST_Request( array( 'authorization' => 'Bearer ' . $pair['access_token'] ) ) )
    && ! $filesReadOnlyResource->can_edit_staging_files(),
    'An original read-only token cannot inherit staging file permission.' );
$GLOBALS['environment'] = 'production';
$GLOBALS['file_edit_allowed'] = false;
$result = $authenticated->authenticate( new WP_REST_Request( array( 'authorization' => 'Bearer ' . $pair['access_token'] ) ) );
must( true === $result && $authenticated->isAuthenticated() && $GLOBALS['current_user'] === 123, 'Existing MCP resource server accepts native token' );
must( ! $authenticated->canSelfUpdate(), 'Native token cannot update WordPress' );
// Native local lookups must never consume the shared external-introspection rate quota.
for ( $i = 0; $i < 75; ++$i ) {
	$bad_bearer = ( new OAuthResourceServer() )->authenticate( new WP_REST_Request( array( 'authorization' => 'Bearer ' . str_repeat( 'z', 43 ) ) ) );
	must( $bad_bearer instanceof WP_Error && 'wpconnector_oauth_invalid_token' === $bad_bearer->get_error_code(), 'Native invalid bearer stays a 401, not a shared 429' );
}
$still_authenticated = new OAuthResourceServer();
must( true === $still_authenticated->authenticate( new WP_REST_Request( array( 'authorization' => 'Bearer ' . $pair['access_token'] ) ) ), 'Valid native bearer survives 75 invalid submissions' );

$replay = $server->token( token_request( base_token( 'authorization_code', $code, $verifier ) ) );
must( 400 === $replay->get_status() && $replay->get_data()['error'] === 'invalid_grant', 'Code replay denied' );
$wrong_code = internal( 'issue', array( 'code', $owner, 300 ) );
$wrong = $server->token( token_request( base_token( 'authorization_code', $wrong_code, str_repeat( 'z', 50 ) ) ) );
must( $wrong->get_data()['error'] === 'invalid_grant', 'Wrong PKCE verifier rejected' );
$wrong_again = $server->token( token_request( base_token( 'authorization_code', $wrong_code, $verifier ) ) );
must( $wrong_again->get_data()['error'] === 'invalid_grant', 'Failed PKCE cannot be retried' );
$expired_code = internal( 'issue', array( 'code', $owner, -5 ) );
$expired = $server->token( token_request( base_token( 'authorization_code', $expired_code, $verifier ) ) );
must( $expired->get_data()['error'] === 'invalid_grant', 'Expired authorization code denied' );
$expiring = internal( 'issue', array( 'access', array(
	'user_id' => 123, 'client_id' => $params['client_id'], 'resource' => $resource,
	'scope' => 'wpconnector:read', 'issued_at' => time() - 1000, 'expires_at' => time() - 100,
), -100 ) );
must( null === NativeOAuthServer::introspect( $expiring ), 'Expired access token denied' );

$refresh_request = array(
	'grant_type' => 'refresh_token',
	'client_id' => $params['client_id'],
	'resource' => $resource,
	'refresh_token' => $pair['refresh_token'],
);
$rotated = $server->token( token_request( $refresh_request ) );
must( 200 === $rotated->get_status(), 'Refresh grant accepted' );
$rotated_data = $rotated->get_data();
must( $rotated_data['refresh_token'] !== $pair['refresh_token'], 'Refresh token rotated' );
$reused = $server->token( token_request( $refresh_request ) );
must( $reused->get_data()['error'] === 'invalid_grant', 'Old refresh cannot be replayed' );
must( null === NativeOAuthServer::introspect( $rotated_data['access_token'] ), 'Refresh replay revokes entire active grant, including access token' );
$after_replay = $server->token( token_request( array(
	'grant_type' => 'refresh_token', 'client_id' => $params['client_id'],
	'resource' => $resource, 'refresh_token' => $rotated_data['refresh_token'],
) ) );
must( $after_replay->get_data()['error'] === 'invalid_grant', 'Refresh replay revokes rotated descendant' );
$revoke = $server->revoke( token_request( array(
	'client_id' => $params['client_id'],
	'token' => $rotated_data['access_token'],
	'token_type_hint' => 'access_token',
) ) );
must( 200 === $revoke->get_status(), 'RFC 7009 revocation succeeded' );
must( null === NativeOAuthServer::introspect( $rotated_data['access_token'] ), 'Revoked access token refused' );
$revoke_without_hint = $server->revoke( token_request( array(
	'client_id' => $params['client_id'],
	'token' => $rotated_data['refresh_token'],
) ) );
must( 200 === $revoke_without_hint->get_status(), 'Revocation without hint accepted' );
$reusing_revoked = $server->token( token_request( array(
	'grant_type' => 'refresh_token', 'client_id' => $params['client_id'],
	'resource' => $resource, 'refresh_token' => $rotated_data['refresh_token'],
) ) );
must( $reusing_revoked->get_data()['error'] === 'invalid_grant', 'Revoked refresh token denied' );

$GLOBALS['user_allowed'] = false;
must( null === NativeOAuthServer::introspect( $pair['access_token'] ), 'Role downgrade blocks access immediately' );
$GLOBALS['user_allowed'] = true;
$noresource = base_token( 'authorization_code', 'invalid', $verifier );
$noresource['resource'] = 'https://evil.invalid/mcp';
must( $server->token( token_request( $noresource ) )->get_status() === 400, 'Foreign resource rejected' );
must( $server->token( token_request( array(), 'application/json' ) )->get_status() === 400, 'JSON token request rejected' );
must( $server->token( new WP_REST_Request( array( 'content-type' => 'application/x-www-form-urlencoded', 'authorization' => 'Basic synthetic' ), base_token( 'authorization_code', 'invalid', $verifier ) ) )->get_data()['error'] === 'invalid_client', 'No Basic token endpoint authentication supported' );
must( $server->token( token_request( array( 'client_id' => 'https://evil.invalid/client.json' ) ) )->get_status() === 400, 'Unknown client rejected' );
must( $server->token( token_request( array(
	'grant_type' => 'client_credentials', 'client_id' => $params['client_id'],
) ) )->get_data()['error'] === 'unsupported_grant_type', 'Machine-to-machine grant rejected' );

// Cleanup must remove more than 100 expired token rows in one pass.
for ( $i = 0; $i < 225; ++$i ) {
	set_transient( 'wpconnector_native_oauth_fixture_' . $i, array( 'expired' => true ), -5 );
}
set_transient( 'wpconnector_native_oauth_fixture_live', array( 'live' => true ), 600 );
$server->cleanup();
for ( $i = 0; $i < 225; ++$i ) {
	must( ! isset( $GLOBALS['transients']['wpconnector_native_oauth_fixture_' . $i] ), 'Expired transient fixture removed' );
}
must( false !== get_transient( 'wpconnector_native_oauth_fixture_live' ), 'Valid transient is kept' );

// Simulate uninstall/reinstall with external Redis transients surviving the plugin.
$before_reinstall_code = internal( 'issue', array( 'code', $owner, 300 ) );
$before_reinstall = $server->token( token_request( base_token( 'authorization_code', $before_reinstall_code, $verifier ) ) );
must( 200 === $before_reinstall->get_status(), 'Issue cached grant before simulated reinstall' );
$cached_pair = $before_reinstall->get_data();
$cached_access_key = internal( 'option_key', array( 'access', $cached_pair['access_token'] ) );
$cached_refresh_key = internal( 'option_key', array( 'refresh', $cached_pair['refresh_token'] ) );
must( isset( $GLOBALS['transients'][ $cached_access_key ], $GLOBALS['transients'][ $cached_refresh_key ] ), 'Opaque token entries exist in synthetic persistent cache' );
delete_option( 'wpconnector_native_oauth_installation' );
delete_option( 'wpconnector_native_oauth_revision' );
update_option( 'wpconnector_native_oauth_enabled', '0' );
must( ! NativeOAuthServer::is_enabled(), 'Uninstall disables native OAuth even if external cache survives' );
must( isset( $GLOBALS['transients'][ $cached_access_key ], $GLOBALS['transients'][ $cached_refresh_key ] ), 'Uninstall left external cache token entries in place' );
update_option( 'wpconnector_native_oauth_installation', str_repeat( 'b', 64 ) );
update_option( 'wpconnector_native_oauth_enabled', array( 'site' => NativeOAuthServer::issuer(), 'approved_by' => 123 ) );
must( NativeOAuthServer::is_enabled(), 'Fresh installation generation permits a new opt-in' );
must( null === NativeOAuthServer::introspect( $cached_pair['access_token'] ), 'Old access token is denied after reinstall despite cached entry and reset revision' );
$old_refresh = $server->token( token_request( array(
    'grant_type' => 'refresh_token',
    'client_id' => $params['client_id'],
    'resource' => $resource,
    'refresh_token' => $cached_pair['refresh_token'],
) ) );
must( 400 === $old_refresh->get_status() && 'invalid_grant' === $old_refresh->get_data()['error'], 'Old refresh token cannot be replayed after reinstall' );
must( isset( $GLOBALS['transients'][ $cached_access_key ], $GLOBALS['transients'][ $cached_refresh_key ] ), 'Denial is due to installation identity, not cache removal' );
$new_install_code = internal( 'issue', array( 'code', $owner, 300 ) );
$new_install_pair = $server->token( token_request( base_token( 'authorization_code', $new_install_code, $verifier ) ) );
must( 200 === $new_install_pair->get_status() && is_array( NativeOAuthServer::introspect( $new_install_pair->get_data()['access_token'] ) ), 'New installation issues independent valid tokens' );

update_option( 'wpconnector_native_oauth_revision', 2 );
must( null === NativeOAuthServer::introspect( $pair['access_token'] ), 'Owner revocation generation invalidates tokens' );
update_option( 'wpconnector_native_oauth_enabled', '0' );
must( ! NativeOAuthServer::has_prior_approval(), 'Disabling native OAuth removes prior approval' );
must( ! OAuthResourceServer::isConfigured() && array() === OAuthResourceServer::metadata(), 'Owner disable revokes OAuth discovery' );
must( null === NativeOAuthServer::introspect( $pair['access_token'] ), 'Disabled server refuses all token validation' );
$GLOBALS['ssl_ready'] = false;
update_option( 'wpconnector_native_oauth_enabled', array( 'site' => 'https://example.invalid', 'approved_by' => 123 ) );
must( ! NativeOAuthServer::is_enabled(), 'Plain HTTP never enables OAuth' );
must( NativeOAuthServer::has_prior_approval(), 'Stored approval remains visible for revocation even when native mode is blocked' );
$GLOBALS['ssl_ready'] = true;
define( 'WPCONNECTOR_OAUTH_ISSUER', 'https://external-issuer.example.invalid' );
must( ! NativeOAuthServer::is_enabled(), 'External OAuth temporarily suppresses native mode' );
must( NativeOAuthServer::has_prior_approval(), 'Old native approval remains revocable while external mode is selected' );

echo "WordPress-hosted ChatGPT OAuth contract OK: opt-in, discovery, CIMD, PKCE, expiry, refresh rotation, role downgrade, replay, scope, revocation.\n";
