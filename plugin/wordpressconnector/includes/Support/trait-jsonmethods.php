<?php
/**
 * Backward-compatible method aliases for Json.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

/**
 * Delegation-only legacy entrypoints; implementations remain in the host.
 */
trait JsonMethods {

	/**
	 * Preserve the legacy PHP entrypoint; delegate to the canonical implementation.
	 *
	 * @param string $path Input for the operation.
	 * @return array Operation result.
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private static function read_file_compat( string $path ): array {
		return self::read_file( $path );
	}

	/**
	 * Preserve the legacy PHP entrypoint; delegate to the canonical implementation.
	 *
	 * @param string $path Input for the operation.
	 * @param array  $data Input for the operation.
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private static function write_file_atomic_compat( string $path, array $data ): void {
		self::write_file_atomic( $path, $data );
	}
}
