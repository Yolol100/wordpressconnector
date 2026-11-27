<?php
/**
 * Canonical public method implementations for ProcessedStore.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Runtime;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait ProcessedStoreMethods {
	/**
	 * Process the release mutation lock operation in the ProcessedStore component.
	 *
	 * @param string $token Input for the operation.
	 */
	public function release_mutation_lock( string $token ): void {
		$existing = get_option( self::LOCK_KEY, '' );
		$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( ! is_string( $existing ) || ! is_array( $data ) || ! hash_equals( (string) ( $data['token'] ?? '' ), $token ) ) {
			return;
		}

		$this->delete_lock_if_value( $existing );
	}

}
