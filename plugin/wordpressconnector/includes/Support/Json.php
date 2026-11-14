<?php
/**
 * Provides Json utilities shared by connector services.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

use RuntimeException;

/**
 * Provides Json utilities shared by connector services.
 */
final class Json {
	/**
	 * Process the decode operation in the Json component.
	 *
	 * @param string $json Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function decode( string $json ): array {
		try {
			$value = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new RuntimeException( 'Invalid JSON: ' . $error->getMessage(), 0, $error );
		}

		if ( ! is_array( $value ) ) {
			throw new RuntimeException( 'JSON root must be an object or array.' );
		}

		return $value;
	}

	/**
	 * Process the encode operation in the Json component.
	 *
	 * @param mixed $value Input for the operation.
	 * @param bool  $pretty Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function encode( $value, bool $pretty = true ): string {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		try {
			return json_encode( $value, $flags | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new RuntimeException( 'Could not encode JSON: ' . $error->getMessage(), 0, $error );
		}
	}

	/**
	 * Read file for the Json component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function readFile( string $path ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'JSON file is not readable: ' . $path );
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read JSON file: ' . $path );
		}

		return self::decode( $contents );
	}

	/**
	 * Write file atomic for the Json component.
	 *
	 * @param string $path Input for the operation.
	 * @param array  $data Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 * @throws \Throwable If the operation cannot be completed safely.
	 */
	public static function writeFileAtomic( string $path, array $data ): void {
		// Encode before any side effects; never overwrite after partial writes.
		$encoded   = self::encode( $data ) . PHP_EOL;
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( 'Could not create output directory: ' . $directory );
		}

		$temporary = tempnam( $directory, '.wpconnector-' );
		if ( false === $temporary ) {
			throw new RuntimeException( 'Could not create temporary output file.' );
		}
		try {
			// tempnam() may fall back to another filesystem; no cross-volume rename.
			if ( realpath( dirname( $temporary ) ) !== realpath( $directory ) ) {
				throw new RuntimeException( 'Temporary output file is outside the destination directory.' );
			}
			$bytes = file_put_contents( $temporary, $encoded, LOCK_EX );
			if ( strlen( $encoded ) !== $bytes || ! rename( $temporary, $path ) ) {
				throw new RuntimeException( 'Could not write complete output file.' );
			}
		} catch ( \Throwable $error ) {
			if ( is_file( $temporary ) && ! @unlink( $temporary ) ) {
				throw new RuntimeException( 'Temporary output file removal failed; manual cleanup required.', 0, $error );
			}
			throw $error;
		}
	}
}
