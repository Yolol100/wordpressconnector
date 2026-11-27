<?php
/**
 * Canonical public method implementations for OAuthResourceServer.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait OAuthResourceServerMethods {
	/** Whether the site owner has configured (not proven) an OAuth provider. */
	public static function is_configured(): bool {
		return null !== self::config();
	}

	/**
	 * Site-owner opt-in for ChatGPT-initiated updates of this Connector only.
	 * This never enables arbitrary PHP/filesystem modifications.
	 */
	public static function self_update_configured(): bool {
		return self::isConfigured()
			&& ! ( class_exists( NativeOAuthServer::class ) && NativeOAuthServer::is_enabled() )
			&& defined( 'WPCONNECTOR_OAUTH_ALLOW_SELF_UPDATE' )
			&& true === constant( 'WPCONNECTOR_OAUTH_ALLOW_SELF_UPDATE' );
	}

	/**
	 * RFC 9728 well-known discovery URL for the exact protected MCP URI.
	 * The host's web server must forward this route to WordPress.
	 */
	public static function well_known_url(): string {
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

	/** REST routes preserve the plugin's existing canonical namespace. */
	public function register_routes(): void {
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
	public function resource_metadata() {
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
	public static function challenge_header( bool $include_update = false ): string {
		return self::build_challenge_header( $include_update, false );
	}

	/**
	 * Access to the release updater requires the separate OAuth update scope,
	 * the owner's explicit opt-in and the linked user's update_plugins right.
	 */
	public function can_self_update(): bool {
		return $this->authenticated && $this->update_authorized;
	}

	/** Did this request actually authenticate using a valid external bearer? */
	public function is_authenticated(): bool {
		return $this->authenticated;
	}

}
