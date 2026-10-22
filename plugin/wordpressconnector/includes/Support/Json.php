<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

use RuntimeException;

final class Json {

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

	public static function writeFileAtomic( string $path, array $data ): void {
		// Encoding can throw: do it before creating any directory or temp file.
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
			// tempnam() may silently fall back to another filesystem. That is
			// not a valid source for the atomic same-directory replacement.
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
