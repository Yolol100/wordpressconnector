<?php
/**
 * Provides Fingerprint utilities shared by connector services.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

/**
 * Provides Fingerprint utilities shared by connector services.
 */
final class Fingerprint
{
	/**
	 * Create a stable SHA-256 fingerprint of normalized data.
	 *
	 * @param mixed $value Data to fingerprint.
	 *
	 * @return string Hexadecimal SHA-256 digest.
	 */
	public static function make($value): string
	{
		return hash( 'sha256', Json::encode( self::normalize( $value ), false ) );
	}

	/**
	 * Derive a site-scoped token from the normalized state.
	 *
	 * @param mixed $value Site state to bind to a token.
	 *
	 * @return string Site-bound token digest.
	 */
	public static function siteToken($value): string
	{
		return self::siteTokenFromFingerprint( self::make( $value ) );
	}

	/**
	 * Bind an existing fingerprint to the site's authentication secret.
	 *
	 * @param string $fingerprint SHA-256 fingerprint or value to hash.
	 *
	 * @return string Site-scoped HMAC digest.
	 */
	public static function siteTokenFromFingerprint(string $fingerprint): string
	{
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

	/**
	 * Normalize associative arrays for deterministic fingerprinting.
	 *
	 * @param mixed $value Data to normalize recursively.
	 *
	 * @return mixed Normalized data with sorted associative array keys.
	 */
	private static function normalize($value)
	{
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[$key] = self::normalize( $item );
		}

		return $value;
	}
}
