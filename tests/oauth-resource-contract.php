<?php
/**
 * Standalone fail-closed RFC 7662/OAuth protected-resource contract.
 * Synthetic issuer, synthetic subject, and a fake provider: no real secrets.
 */
declare(strict_types=1);

define( 'WPCONNECTOR_OAUTH_ISSUER', 'https://identity.example.invalid/realms/synthetic' );
define( 'WPCONNECTOR_OAUTH_INTROSPECTION_URL', 'https://identity.example.invalid/realms/synthetic/protocol/openid-connect/token/introspect' );
define( 'WPCONNECTOR_OAUTH_CLIENT_ID', 'synthetic-introspection-client' );
define( 'WPCONNECTOR_OAUTH_CLIENT_SECRET', 'synthetic-secret-never-use-on-a-real-site' );
define( 'WPCONNECTOR_OAUTH_EXPECTED_TOKEN_CLIENT_ID', 'synthetic-chatgpt-oauth-client' );
define( 'WPCONNECTOR_OAUTH_ACCOUNT_SUBJECT', 'synthetic-subject' );
define( 'WPCONNECTOR_OAUTH_WORDPRESS_USER_ID', 123 );
define( 'WPCONNECTOR_OAUTH_ACCOUNT_LINK_APPROVED', true );
define( 'WPCONNECTOR_OAUTH_ALLOW_SELF_UPDATE', true );

$GLOBALS['oauth_case'] = array(
	'ssl' => true,
	'user_exists' => true,
	'admin' => true,
	'can_update' => true,
	'calls' => array(),
	'http' => 200,
	'error' => false,
	'claims' => array(),
	'current_user' => 0,
	'transients' => array(),
	'reset_rate_limit' => true,
	'stringify_transients' => false,
	'body_override' => null,
);

final class WP_Error {
	private string $code;
	private string $message;
	private array $data;
	public function __construct( string $code, string $message, array $data = array() ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): array { return $this->data; }
}

final class WP_REST_Server {
	public const READABLE = 'GET';
}
final class WP_REST_Response {
	private array $data;
	private int $status;
	public function __construct( array $data, int $status ) {
		$this->data = $data;
		$this->status = $status;
	}
	public function get_data(): array { return $this->data; }
	public function get_status(): int { return $this->status; }
}
final class WP_REST_Request {
	private array $headers;
	public function __construct( array $headers ) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}
	public function get_header( string $name ): string {
		return (string) ( $this->headers[ strtolower( $name ) ] ?? '' );
	}
}
function wp_parse_url( string $url, int $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function rest_url( string $path ): string {
	if ( ! empty( $GLOBALS['oauth_case']['plain_rest'] ) ) {
		return 'https://example.invalid/?rest_route=%2F' . rawurlencode( $path );
	}
	return 'https://example.invalid/wp-json/' . $path;
}
function is_ssl(): bool { return $GLOBALS['oauth_case']['ssl']; }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function add_action( string $name, $callback, int $priority = 10 ): void {
	$GLOBALS['oauth_case']['hooks'][ $name ] = $callback;
}
function get_transient( string $name ) {
	$value = $GLOBALS['oauth_case']['transients'][ $name ] ?? false;
	return false !== $value && $GLOBALS['oauth_case']['stringify_transients'] ? (string) $value : $value;
}
function set_transient( string $name, $value, int $duration ): bool {
	$GLOBALS['oauth_case']['transients'][ $name ] = $value;
	return true;
}
function register_rest_route( string $namespace, string $route, array $args ): void {
	$GLOBALS['oauth_case']['route'] = array( $namespace, $route, $args );
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_safe_remote_post( string $url, array $args ) {
	$GLOBALS['oauth_case']['calls'][] = array( 'url' => $url, 'args' => $args );
	if ( $GLOBALS['oauth_case']['error'] ) {
		return new WP_Error( 'http_failure', 'synthetic provider unavailable' );
	}
	return array(
		'response' => array( 'code' => $GLOBALS['oauth_case']['http'] ),
		'body' => is_string( $GLOBALS['oauth_case']['body_override'] )
			? $GLOBALS['oauth_case']['body_override']
			: json_encode( $GLOBALS['oauth_case']['claims'] ),
	);
}
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) $response['response']['code'];
}
function wp_remote_retrieve_body( array $response ): string {
	return (string) $response['body'];
}
function get_userdata( int $user_id ) {
	return $user_id === 123 && $GLOBALS['oauth_case']['user_exists'] ? (object) array( 'ID' => $user_id ) : false;
}
function user_can( int $user_id, string $capability ): bool {
	if ( 123 !== $user_id ) {
		return false;
	}
	if ( 'manage_options' === $capability ) {
		return $GLOBALS['oauth_case']['admin'];
	}
	return 'update_plugins' === $capability && $GLOBALS['oauth_case']['can_update'];
}
function wp_set_current_user( int $user_id ): void {
	$GLOBALS['oauth_case']['current_user'] = $user_id;
}
function __return_true(): bool { return true; }

require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/OAuthResourceServer.php';

use Webactueel\WordPressConnector\Security\OAuthResourceServer;

function assert_oauth( bool $result, string $message ): void {
	if ( ! $result ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
		exit(1);
	}
}
function oauth_base_claims(): array {
	return array(
		'active' => true,
		'iss' => WPCONNECTOR_OAUTH_ISSUER,
		'sub' => WPCONNECTOR_OAUTH_ACCOUNT_SUBJECT,
		'client_id' => WPCONNECTOR_OAUTH_EXPECTED_TOKEN_CLIENT_ID,
		'aud' => array( OAuthResourceServer::resource() ),
		'scope' => 'openid wpconnector:read',
		'iat' => time() - 60,
		'exp' => time() + 600,
		'nbf' => time() - 30,
		'token_type' => 'Bearer',
	);
}
function oauth_probe( $headers ) {
	if ( $GLOBALS['oauth_case']['reset_rate_limit'] ) {
		$GLOBALS['oauth_case']['transients'] = array();
	}
	$client = new OAuthResourceServer();
	$result = $client->authenticate( new WP_REST_Request( $headers ) );
	return array( $result, $client->isAuthenticated(), $client->canSelfUpdate() );
}

$assertion_count = 0;
assert_oauth( OAuthResourceServer::isConfigured(), 'Owner approval and all provider constants must be required.' );
$metadata = OAuthResourceServer::metadata();
assert_oauth( OAuthResourceServer::resource() === $metadata['resource'], 'OAuth resource metadata needs exact MCP audience.' );
assert_oauth( array( WPCONNECTOR_OAUTH_ISSUER ) === $metadata['authorization_servers'], 'MCP discovers the issuer, not an assumed OAuth provider.' );
assert_oauth( array( 'wpconnector:read', 'wpconnector:update' ) === $metadata['scopes_supported'], 'Owner-approved OAuth must advertise read and release-update scopes.' );
assert_oauth( OAuthResourceServer::selfUpdateConfigured(), 'Owner-signed update opt-in should be visible only when explicitly enabled.' );
assert_oauth( false !== strpos( OAuthResourceServer::challengeHeader( true ), 'scope="wpconnector:read wpconnector:update"' ), 'Update challenge must specify both scopes.' );
assert_oauth( false === strpos( json_encode( $metadata ), WPCONNECTOR_OAUTH_CLIENT_SECRET ), 'Never expose the introspection secret through metadata.' );
assert_oauth( false !== strpos( OAuthResourceServer::challengeHeader(), 'resource_metadata="https://example.invalid/.well-known/oauth-protected-resource/wp-json/webactueel-wordpress-connector/v1/mcp"' ), 'Bearer challenge must advertise the canonical RFC 9728 protected-resource URL.' );
// WordPress plain permalinks use ?rest_route= instead of /wp-json/... .
$GLOBALS['oauth_case']['plain_rest'] = true;
$plainResource = 'https://example.invalid/?rest_route=%2Fwebactueel-wordpress-connector%2Fv1%2Fmcp';
$plainMetadata = 'https://example.invalid/.well-known/oauth-protected-resource?rest_route=%2Fwebactueel-wordpress-connector%2Fv1%2Fmcp';
assert_oauth( OAuthResourceServer::resource() === $plainResource, 'Test uses a realistic query-form WordPress REST URL.' );
assert_oauth( OAuthResourceServer::wellKnownUrl() === $plainMetadata, 'RFC 9728 discovery must preserve the full query-form resource URL.' );
assert_oauth( OAuthResourceServer::metadata()['resource'] === $plainResource, 'Resource metadata must preserve the exact WordPress query-form audience.' );
assert_oauth( false !== strpos( OAuthResourceServer::challengeHeader(), 'resource_metadata="' . $plainMetadata . '"' ), 'OAuth challenge must work with plain WordPress permalinks.' );
$GLOBALS['oauth_case']['plain_rest'] = false;

$resource = new OAuthResourceServer();
$resource->register();
assert_oauth( isset( $GLOBALS['oauth_case']['hooks']['init'] ), 'Well-known routing must be registered during WordPress init.' );
$resource->registerRoutes();
assert_oauth( 'webactueel-wordpress-connector/v1' === $GLOBALS['oauth_case']['route'][0] && '/oauth-resource' === $GLOBALS['oauth_case']['route'][1], 'Resource metadata belongs to the existing REST namespace.' );
assert_oauth( 'GET' === $GLOBALS['oauth_case']['route'][2]['methods'], 'Metadata is GET only.' );
assert_oauth( 200 === $resource->resourceMetadata()->get_status(), 'Valid configured HTTPS metadata must work.' );

list( $missing, $authed ) = oauth_probe( array() );
assert_oauth( null === $missing && ! $authed, 'Missing bearer must delegate to existing browser/OIDC auth.' );
list( $basic, $authed ) = oauth_probe( array( 'authorization' => 'Basic dGVzdA==' ) );
assert_oauth( null === $basic && ! $authed, 'Existing Application Passwords must retain their own authentication.' );
assert_oauth( array() === $GLOBALS['oauth_case']['calls'], 'Non-bearer requests must not call the OAuth provider.' );

$token = str_repeat( 'a', 48 );
list( $bad, $authed ) = oauth_probe( array( 'authorization' => 'Bearer not valid' ) );
assert_oauth( $bad instanceof WP_Error && 401 === $bad->get_error_data()['status'] && ! $authed, 'Malformed bearer must fail before network.' );
assert_oauth( array() === $GLOBALS['oauth_case']['calls'], 'Malformed bearer must not make outbound requests.' );

$GLOBALS['oauth_case']['claims'] = oauth_base_claims();
list( $good, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( true === $good && $authed, 'Only valid introspection can authenticate.' );
assert_oauth( 123 === $GLOBALS['oauth_case']['current_user'], 'OAuth must bind to the exact approved WordPress user.' );
$calls = $GLOBALS['oauth_case']['calls'];
assert_oauth( 1 === count( $calls ), 'OAuth must introspect once.' );
$last = $calls[0];
assert_oauth( WPCONNECTOR_OAUTH_INTROSPECTION_URL === $last['url'], 'Introspection endpoint must be pinned.' );
assert_oauth( true === $last['args']['sslverify'] && 0 === $last['args']['redirection'], 'Introspection must verify TLS and reject redirects.' );
assert_oauth( $token === $last['args']['body']['token']
	&& ! isset( $last['args']['body']['resource'] )
	&& 'access_token' === $last['args']['body']['token_type_hint'],
	'Introspection must send only RFC 7662 parameters; exact MCP audience is checked in the response.' );
assert_oauth( 16385 === $last['args']['limit_response_size'],
	'WordPress must enforce a response byte cap during HTTP transport, not only after download.' );
assert_oauth( false === strpos( json_encode( $metadata ), $token ), 'Tokens must not leak into public metadata.' );
// The opt-in update scope is not inherited by normal read tokens.
$GLOBALS['oauth_case']['claims'] = oauth_base_claims();
list( $readonly, $authed, $mayUpdate ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( true === $readonly && $authed && ! $mayUpdate, 'A read-only token never grants self-update.' );
$GLOBALS['oauth_case']['claims']['scope'] = 'openid wpconnector:read wpconnector:update';
list( $privileged, $authed, $mayUpdate ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( true === $privileged && $authed && $mayUpdate, 'Exactly scoped update token with the linked update_plugins capability is eligible.' );
$GLOBALS['oauth_case']['can_update'] = false;
list( $demotedUpdate, $authed, $mayUpdate ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( true === $demotedUpdate && $authed && ! $mayUpdate, 'Losing update_plugins must revoke only elevated release access.' );
$GLOBALS['oauth_case']['can_update'] = true;
$GLOBALS['oauth_case']['claims']['scope'] = 'openid wpconnector:read wpconnector:update_extra';
list( $wrongScope, $authed, $mayUpdate ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( true === $wrongScope && $authed && ! $mayUpdate, 'Update permission must compare the complete scope, not a prefix.' );
$GLOBALS['oauth_case']['claims']['scope'] = 'wpconnector:update';
list( $missingRead, $authed, $mayUpdate ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( $missingRead instanceof WP_Error && ! $authed && ! $mayUpdate, 'Update scope alone cannot bypass mandatory read identity checks.' );
$GLOBALS['oauth_case']['claims'] = oauth_base_claims();

list( $short, $authed ) = oauth_probe( array( 'authorization' => 'Bearer abc' ) );
assert_oauth( true === $short && $authed, 'RFC 6750 permits opaque bearer tokens shorter than 32 characters.' );
list( $spaced, $authed ) = oauth_probe( array( 'authorization' => 'Bearer  abc' ) );
assert_oauth( true === $spaced && $authed, 'RFC 6750 accepts one or more spaces after Bearer.' );
list( $tabbed, $authed ) = oauth_probe( array( 'authorization' => "Bearer\tabc" ) );
assert_oauth( $tabbed instanceof WP_Error && ! $authed, 'Bearer token must not permit tab or newline whitespace.' );
$GLOBALS['oauth_case']['claims'] = array_replace( oauth_base_claims(), array( 'token_type' => 'bearer' ) );
list( $lower, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( true === $lower && $authed, 'RFC 6750 token types are case-insensitive.' );
$GLOBALS['oauth_case']['body_override'] = str_repeat( 'z', 16385 );
list( $huge, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( $huge instanceof WP_Error && ! $authed
	&& 'wpconnector_oauth_invalid_token' === $huge->get_error_code(),
	'An overlarge introspection response must be rejected.' );
$GLOBALS['oauth_case']['body_override'] = null;

$reject = static function (string $label, array $changes, int $expected_status = 401 ) use ( $token ): void {
	$GLOBALS['oauth_case']['claims'] = array_replace( oauth_base_claims(), $changes );
	list( $result, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
	assert_oauth( $result instanceof WP_Error && $expected_status === $result->get_error_data()['status'] && ! $authed, $label );
};
$reject( 'Inactive token', array( 'active' => false ) );
$reject( 'Wrong issuer', array( 'iss' => 'https://attacker.example.invalid/' ) );
$reject( 'Wrong account subject', array( 'sub' => 'other-account' ) );
$reject( 'Wrong authorized OAuth client', array( 'client_id' => 'other-oauth-client' ) );
$reject( 'Missing OAuth client binding claim', array( 'client_id' => null ) );
$reject( 'Invalid OAuth client binding type', array( 'client_id' => array('synthetic-chatgpt-oauth-client') ) );
$reject( 'Wrong audience', array( 'aud' => array( 'https://another-site.example.invalid/mcp' ) ) );
$reject( 'Missing audience', array( 'aud' => null ) );
$reject( 'Multiple audiences including another resource', array( 'aud' => array( OAuthResourceServer::resource(), 'https://other.example.invalid/' ) ) );
$reject( 'Missing read scope', array( 'scope' => 'openid profile' ), 403 );
$reject( 'Scope prefix confusion', array( 'scope' => 'wpconnector:readonly' ), 403 );
$reject( 'Expired token', array( 'exp' => time() - 10 ) );
$reject( 'Overlong token lifetime', array( 'exp' => time() + 7200 ) );
$reject( 'Invalid future not-before', array( 'nbf' => time() + 360 ) );
$reject( 'Untrusted token type', array( 'token_type' => 'MAC' ) );
$reject( 'Wrong activation type', array( 'active' => 1 ) );

$GLOBALS['oauth_case']['claims'] = oauth_base_claims();
$GLOBALS['oauth_case']['admin'] = false;
list( $demoted, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( $demoted instanceof WP_Error && 403 === $demoted->get_error_data()['status'] && ! $authed, 'Revoked administrator rights must deny OAuth.' );
$GLOBALS['oauth_case']['admin'] = true;
$GLOBALS['oauth_case']['user_exists'] = false;
list( $deleted, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( $deleted instanceof WP_Error && 403 === $deleted->get_error_data()['status'] && ! $authed, 'Deleted WordPress identity must be denied.' );
$GLOBALS['oauth_case']['user_exists'] = true;

$GLOBALS['oauth_case']['error'] = true;
list( $down, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( $down instanceof WP_Error && 503 === $down->get_error_data()['status'] && ! $authed, 'Provider timeouts must fail closed.' );
$GLOBALS['oauth_case']['error'] = false;
$GLOBALS['oauth_case']['http'] = 401;
list( $down, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( $down instanceof WP_Error && 503 === $down->get_error_data()['status'] && ! $authed, 'Failed introspection must fail closed.' );
$GLOBALS['oauth_case']['http'] = 200;

$GLOBALS['oauth_case']['ssl'] = false;
list( $plain, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( $plain instanceof WP_Error && 403 === $plain->get_error_data()['status'] && ! $authed, 'HTTP bearer authentication must be refused.' );
assert_oauth( $resource->resourceMetadata() instanceof WP_Error, 'Resource discovery must also require HTTPS.' );

$GLOBALS['oauth_case']['ssl'] = true;
$GLOBALS['oauth_case']['reset_rate_limit'] = false;
$GLOBALS['oauth_case']['stringify_transients'] = true;
$GLOBALS['oauth_case']['transients'] = array();
$GLOBALS['oauth_case']['claims'] = oauth_base_claims();
$_SERVER['REMOTE_ADDR'] = '192.0.2.12';
for ( $n = 0; $n < 12; ++$n ) {
	list( $allowed, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
	assert_oauth( true === $allowed && $authed, 'First twelve requests must pass synthetic rate limit.' );
}
$before = count( $GLOBALS['oauth_case']['calls'] );
list( $blocked, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( $blocked instanceof WP_Error
	&& 429 === $blocked->get_error_data()['status']
	&& ! $authed
	&& $before === count( $GLOBALS['oauth_case']['calls'] ),
	'Thirteenth call from the same verified remote address must be denied before provider introspection.' );
// Raw malformed IPs must never receive a separate valid-address bucket
// after unslashing. Valid IPv6 remains an exact, independent bucket.
foreach ( array( null, array( '192.0.2.12' ), '192.0.2.12 ', "\\192.0.2.12", '192.0.2.12' . "\n" ) as $bad_address ) {
	if ( null === $bad_address ) { unset( $_SERVER['REMOTE_ADDR'] ); }
	else { $_SERVER['REMOTE_ADDR'] = $bad_address; }
	$GLOBALS['oauth_case']['transients'] = array();
	list( $allowed, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
	assert_oauth( true === $allowed && $authed, 'Malformed addresses use the shared unknown bucket.' );
	$prefix = 'wpconnector_oauth_ip_' . substr( hash( 'sha256', 'unknown' ), 0, 24 ) . '_';
	assert_oauth( 1 === count( array_filter( array_keys( $GLOBALS['oauth_case']['transients'] ), static function ( $key ) use ( $prefix ) { return 0 === strpos( $key, $prefix ); } ) ), 'Malformed raw address must not be normalized into a valid IP bucket.' );
}
$_SERVER['REMOTE_ADDR'] = '2001:db8::12';
$GLOBALS['oauth_case']['transients'] = array();
list( $allowed, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( true === $allowed && $authed, 'Valid IPv6 must retain its own rate bucket.' );
$prefix = 'wpconnector_oauth_ip_' . substr( hash( 'sha256', $_SERVER['REMOTE_ADDR'] ), 0, 24 ) . '_';
assert_oauth( 1 === count( array_filter( array_keys( $GLOBALS['oauth_case']['transients'] ), static function ( $key ) use ( $prefix ) { return 0 === strpos( $key, $prefix ); } ) ), 'Valid IPv6 bucket bytes must stay unchanged.' );
$_SERVER['REMOTE_ADDR'] = '192.0.2.12';
// Simulate database-backed transient scalar rehydration and reject malformed counts.
foreach ( array( '01', '-1', '+1', '1e3', '99999', 'not-a-number' ) as $invalid_count ) {
	$GLOBALS['oauth_case']['transients'] = array( 'wpconnector_oauth_all_' . (string) floor( time() / 60 ) => $invalid_count );
	$before = count( $GLOBALS['oauth_case']['calls'] );
	list( $blocked, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
	assert_oauth( $blocked instanceof WP_Error && 429 === $blocked->get_error_data()['status']
		&& ! $authed && $before === count( $GLOBALS['oauth_case']['calls'] ),
		'Malformed stored rate-limit count must be refused before provider call.' );
}
$GLOBALS['oauth_case']['transients'] = array();
$GLOBALS['oauth_case']['transients']['wpconnector_oauth_all_' . (string) floor( time() / 60 )] = '59';
list( $last, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( true === $last && $authed, 'Canonical 59 must permit last request within global limit.' );
list( $full, $authed ) = oauth_probe( array( 'authorization' => 'Bearer ' . $token ) );
assert_oauth( $full instanceof WP_Error && 429 === $full->get_error_data()['status'] && ! $authed,
	'Canonical 60 must enforce site-wide rate limit.' );
echo "oauth protected resource fail-closed contract OK\n";
