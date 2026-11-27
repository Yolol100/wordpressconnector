<?php
/**
 * Provides Fingerprint utilities shared by connector services.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

/**
 * Canonical implementations of the public methods.
 *
 * The host class retains legacy PHP method aliases for compatibility.
 */
trait FingerprintMethods {
	/**
	 * Derive a site-scoped token from the normalized state.
	 *
	 * @param mixed $value Site state to bind to a token.
	 *
	 * @return string Site-bound token digest.
	 */
	public static function site_token( $value ): string {
		return self::site_token_from_fingerprint( self::make( $value ) );
	}

	/**
	 * Bind an existing fingerprint to the site's authentication secret.
	 *
	 * @param string $fingerprint SHA-256 fingerprint or value to hash.
	 *
	 * @return string Site-scoped HMAC digest.
	 */
	public static function site_token_from_fingerprint( string $fingerprint ): string {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			$fingerprint = self::make( $fingerprint );
		}

		if ( function_exists( 'wp_salt' ) ) {
			$secret = (string) wp_salt( 'auth' );
		} elseif ( defined( 'AUTH_SALT' ) ) {
			$secret = (string) AUTH_SALT;
		} else {
			$secret = 'wordpressconnector-test-state-token';
		}

		return hash_hmac( 'sha256', $fingerprint, $secret );
	}
}
