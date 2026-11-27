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

	/**
	 * Read-only, sanitized observation for an authenticated recovery inspection.
	 * A missing option is not proof that a previous operation did not run.
	 *
	 * @param string $requestId Request identifier for durable execution state.
	 */
	public function mutation_lock_status( string $requestId ): string {
		$value = get_option( self::LOCK_KEY, null );
		if ( null === $value ) {
			return 'not_observed';
		}
		if ( ! is_string( $value ) ) {
			return 'unverifiable';
		}
		$lock = json_decode( $value, true );
		if ( ! is_array( $lock )
		|| ! is_string( $lock['token'] ?? null )
		|| '' === $lock['token']
		|| ! is_numeric( $lock['created_at'] ?? null ) ) {
			return 'unverifiable';
		}
		$owner = $lock['request_id'] ?? null;
		if ( ! is_string( $owner ) || '' === $owner ) {
			return 'legacy_or_unverifiable';
		}
		return hash_equals( $owner, $requestId ) ? 'held_for_request' : 'held_for_another_request';
	}

	/**
	 * Process the acquire mutation lock operation in the ProcessedStore component.
	 *
	 * @param string $requestId Input for the operation.
	 *
	 * @return string Operation result.
	 */
	public function acquire_mutation_lock( string $requestId = '' ): string {
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$value = wp_json_encode(
			array(
				'token'      => $token,
				'request_id' => $requestId,
				'created_at' => time(),
			)
		);
		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( add_option( self::LOCK_KEY, $value, '', false ) ) {
			return $token;
		}

		$existing = get_option( self::LOCK_KEY, '' );
		$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if (
			! is_string( $existing )
		|| ! is_array( $data )
		|| time() - (int) ( $data['created_at'] ?? time() ) <= self::LOCK_TTL
		) {
			return '';
		}

		// Time alone does not prove that the previous request finished.
		// A long-running upgrade may still be modifying the filesystem.
		// Reclaim only when its unique persisted request ID has an explicit,
		// successfully finalized outcome. Unknown/legacy locks are deliberately
		// left for administrator reconciliation rather than a second write.
		$owner_request_id = isset( $data['request_id'] ) && is_string( $data['request_id'] )
			? $data['request_id'] : '';
		if ( '' === $owner_request_id ) {
			return '';
		}
		$owner = $this->get( $owner_request_id );
		if ( ! is_array( $owner ) || ( $owner['state'] ?? null ) !== 'completed'
		|| ! is_string( $owner['result_hash'] ?? null )
		|| '' === $owner['result_hash'] ) {
			return '';
		}

		return $this->compare_and_swap_lock( $existing, $value ) ? $token : '';
	}
}
