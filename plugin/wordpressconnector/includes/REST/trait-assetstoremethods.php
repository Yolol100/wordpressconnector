<?php
/**
 * Canonical methods for AssetStore.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\REST;

use RuntimeException;

/**
 * Native aliases preserve the legacy public method contracts.
 */
trait AssetStoreMethods {
	/**
	 * Process the root for request operation in the AssetStore component.
	 *
	 * @param string $requestId Input for the operation.
	 * @param bool   $create Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function root_for_request( string $requestId, bool $create = false ): string {
		$request_id = $this->validated_request_id( $requestId );
		$base       = $this->base_root( $create );
		$directory  = $base . DIRECTORY_SEPARATOR . hash( 'sha256', home_url( '/' ) . '|' . $request_id );

		if ( is_link( $directory ) ) {
			throw new RuntimeException( 'Refusing symbolic link request asset root.' );
		}
		if ( $create && ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( 'Could not create request asset root.' );
		}
		if ( $create && ! $this->secure_directory( $directory ) ) {
			throw new RuntimeException( 'Could not secure request asset root directory.' );
		}

		return $directory;
	}

	/**
	 * Clean up expired for the AssetStore component.
	 *
	 * @param int $olderThanSeconds Input for the operation.
	 */
	public function cleanup_expired( int $olderThanSeconds = DAY_IN_SECONDS ): void {
		$base = $this->base_root( false );
		if ( ! is_dir( $base ) ) {
			return;
		}

		$cutoff  = time() - max( 3600, $olderThanSeconds );
		$entries = scandir( $base );
		foreach ( false === $entries ? array() : $entries as $item ) {
			// Only Connector's own SHA-256 request roots may be expired.
			if ( ! preg_match( '/^[a-f0-9]{64}\z/', $item ) ) {
				continue;
			}
			$path = $base . DIRECTORY_SEPARATOR . $item;
			if ( ! is_dir( $path ) || is_link( $path ) ) {
				continue;
			}
			try {
				$modified = ( new \SplFileInfo( $path ) )->getMTime();
			} catch ( RuntimeException $error ) {
				// A disappeared or unreadable root cannot be safely expired.
				continue;
			}
			if ( false !== $modified && $modified < $cutoff ) {
				$this->remove_tree( $path );
			}
		}
	}
}
