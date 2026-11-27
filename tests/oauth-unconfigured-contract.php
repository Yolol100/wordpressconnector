<?php
/**
 * Ensure OAuth does not silently enable itself on ordinary WordPress sites.
 */
declare(strict_types=1);

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
final class WP_REST_Request {
	private string $header;
	public function __construct( string $header ) { $this->header = $header; }
	public function get_header( string $key ): string { return 'authorization' === $key ? $this->header : ''; }
}
function rest_url( string $path ): string { return 'https://site.example.invalid/wp-json/' . $path; }
function is_ssl(): bool { return true; }
function wp_parse_url( string $uri, int $component = -1 ) { return -1 === $component ? parse_url( $uri ) : parse_url( $uri, $component ); }
function wp_safe_remote_post(): void { throw new RuntimeException( 'Unconfigured OAuth must never contact a provider.' ); }

require dirname( __DIR__ ) . '/plugin/wordpressconnector/includes/Security/class-oauthresourceserver.php';

use Webactueel\WordPressConnector\Security\OAuthResourceServer;

if ( OAuthResourceServer::isConfigured() || OAuthResourceServer::selfUpdateConfigured() || OAuthResourceServer::metadata() !== array() ) {
	throw new RuntimeException( 'No OAuth provider may be inferred from WordPress or GitHub credentials.' );
}
$auth = new OAuthResourceServer();
if ( null !== $auth->authenticate( new WP_REST_Request( '' ) ) ) {
	throw new RuntimeException( 'Unauthenticated browsing must use the existing WordPress permission callback.' );
}
if ( null !== $auth->authenticate( new WP_REST_Request( 'Basic YWJjOmRlZg==' ) ) ) {
	throw new RuntimeException( 'Application Password support must remain available.' );
}
$denied = $auth->authenticate( new WP_REST_Request( 'Bearer ' . str_repeat( 'A', 48 ) ) );
if ( ! $denied instanceof WP_Error || 401 !== $denied->get_error_data()['status'] || $auth->isAuthenticated() ) {
	throw new RuntimeException( 'Unconfigured bearer access must be denied without contacting a provider.' );
}
echo "unconfigured OAuth fail-closed contract OK\n";
