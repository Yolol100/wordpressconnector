<?php
/**
 * Provides Fingerprint utilities shared by connector services.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

require_once __DIR__ . '/trait-fingerprintmethods.php';

/**
 * Provides Fingerprint utilities shared by connector services.
 */
final class Fingerprint {
	use FingerprintMethods {
		site_token as siteToken;
		site_token_from_fingerprint as siteTokenFromFingerprint;
	}


	/**
	 * Create a stable SHA-256 fingerprint of normalized data.
	 *
	 * @param mixed $value Data to fingerprint.
	 *
	 * @return string Hexadecimal SHA-256 digest.
	 */
	public static function make( $value ): string {
		return hash( 'sha256', Json::encode( self::normalize( $value ), false ) );
	}

	/**
	 * Normalize associative arrays for deterministic fingerprinting.
	 *
	 * @param mixed $value Data to normalize recursively.
	 *
	 * @return mixed Normalized data with sorted associative array keys.
	 */
	private static function normalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::normalize( $item );
		}

		return $value;
	}
}
