<?php
/**
 * Canonical methods for SnapshotStore.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Runtime;

/**
 * Native aliases preserve the legacy public method contracts.
 */
trait SnapshotStoreMethods {
	/**
	 * Retrieve record for the SnapshotStore component.
	 *
	 * @param string $requestId Input for the operation.
	 *
	 * @return array|null Operation result.
	 */
	public function get_record( string $requestId ): ?array {
		$value = get_option( self::key( $requestId ), null );
		if ( ! is_array( $value ) || ! isset( $value['rollback'] ) || ! is_array( $value['rollback'] ) ) {
			return null;
		}
		return array(
			'created_at'             => isset( $value['created_at'] ) ? (int) $value['created_at'] : 0,
			'rollback'               => $value['rollback'],
			'source_action'          => isset( $value['source_action'] ) ? (string) $value['source_action'] : '',
			'public_repository_mode' => ! empty( $value['public_repository_mode'] ),
		);
	}
}
