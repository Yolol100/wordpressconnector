<?php
/**
 * Provides Json utilities shared by connector services.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

use RuntimeException;

require_once __DIR__ . '/trait-jsonmethods.php';
require_once __DIR__ . '/class-localfilesystem.php';
require_once __DIR__ . '/class-localfileidentity.php';

/**
 * Provides Json utilities shared by connector services.
 */
final class Json {
	use JsonMethods {
		read_file_compat as public readFile;
		write_file_atomic_compat as public writeFileAtomic;
	}

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
			return json_encode( $value, $flags | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON_THROW_ON_ERROR guarantees failure before any atomic file write; the WordPress wrapper changes that behavior.
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
	public static function read_file( string $path ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'JSON file is not readable.' );
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validated local readable JSON file; wp_remote_get() only accepts a URL and changes failure semantics.
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read JSON file.' );
		}

		return self::decode( $contents );
	}

	/**
	 * Write file atomic for the Json component.
	 * Native storage exceptions may also propagate to the caller.
	 *
	 * @param string $path Input for the operation.
	 * @param array  $data Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function write_file_atomic( string $path, array $data ): void {
		self::publish_atomic(
			$path,
			self::encode( $data ) . PHP_EOL,
			static function ( string $temporary ): \SplFileObject {
				return new \SplFileObject( $temporary, 'x+b' );
			}
		);
	}

	/**
	 * Publish complete JSON using an exclusively owned local handle.
	 *
	 * The private opener is an I/O test seam, not a connector API.
	 * Locking the temporary handle does not serialize unrelated writers.
	 *
	 * @param string   $path    Output path.
	 * @param string   $encoded Fully encoded content.
	 * @param callable $open    Exclusive local file opener.
	 * @throws \RuntimeException When publication cannot complete safely.
	 * @throws \Throwable When native storage fails.
	 */
	private static function publish_atomic( string $path, string $encoded, callable $open ): void {
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( 'Could not create output directory.' );
		}
		$resolved = realpath( $directory );
		if ( false === $resolved ) {
			throw new RuntimeException( 'Could not resolve output directory.' );
		}
		$target = $resolved . DIRECTORY_SEPARATOR . basename( $path );
		clearstatcache( true, $target );
		if ( is_link( $target ) || ( file_exists( $target ) && ! is_file( $target ) ) ) {
			throw new RuntimeException( 'Output path must be a regular file or absent.' );
		}
		$prior      = is_file( $target ) ? new \SplFileObject( $target, 'rb' ) : null;
		$prior_stat = null === $prior ? null : $prior->fstat();
		if ( null !== $prior && ( ! is_array( $prior_stat ) || ! LocalFileIdentity::matches( $target, $prior_stat ) ) ) {
			throw new RuntimeException( 'Existing output identity could not be verified.' );
		}
		$temporary    = $resolved . DIRECTORY_SEPARATOR . '.wpconnector-' . bin2hex( random_bytes( 16 ) );
		$handle       = null;
		$created_stat = null;
		try {
			// The CLI output path needs a private, exclusive temporary file.
			// Restore the process umask on every exit from creation.
			$previous_mask = umask( 0077 );
			try {
				$handle = $open( $temporary );
			} finally {
				umask( $previous_mask );
			}
			$created_stat = $handle->fstat();
			if ( ! is_array( $created_stat ) || ! LocalFileIdentity::matches( $temporary, $created_stat ) ||
				( '/' === DIRECTORY_SEPARATOR && 0600 !== ( $created_stat['mode'] & 07777 ) ) ) {
				throw new RuntimeException( 'Temporary output file identity or private mode could not be verified.' );
			}
			if ( ! $handle->flock( LOCK_EX ) ) {
				throw new RuntimeException( 'Could not lock temporary output file.' );
			}
			$bytes = $handle->fwrite( $encoded );
			if ( ! $handle->fflush() || ! LocalFileIdentity::matches( $temporary, $created_stat ) ) {
				throw new RuntimeException( 'Could not write complete output file.' );
			}
			// Revalidate the destination immediately before native rename.
			// Portable PHP cannot make this observation and rename a CAS.
			clearstatcache( true, $target );
			if ( realpath( $directory ) !== $resolved ||
				( null === $prior ? ( file_exists( $target ) || is_link( $target ) ) :
					! LocalFileIdentity::matches( $target, $prior_stat ) ) ) {
				throw new RuntimeException( 'Output destination changed before publication.' );
			}
			// Core move may unlink the destination and fall back to copy.
			// Keep the native same-filesystem atomic rename.
			$path = $target;
			if ( strlen( $encoded ) !== $bytes || ! rename( $temporary, $path ) ) {
				throw new RuntimeException( 'Could not write complete output file.' );
			}
			if ( ! LocalFileIdentity::matches( $target, $created_stat ) ) {
				throw new RuntimeException( 'Output identity changed after publication; reconcile before retrying.' );
			}
		} catch ( \Throwable $error ) {
			$removed = false;
			clearstatcache( true, $temporary );
			if ( ! file_exists( $temporary ) && ! is_link( $temporary ) ) {
				$removed = true;
			} elseif ( LocalFileIdentity::matches( $temporary, $created_stat ) ) {
				$removed = LocalFilesystem::remove_file( $temporary, $handle );
			}
			// Never delete a replacement or infer recovery from a return code.
			clearstatcache( true, $temporary );
			if ( file_exists( $temporary ) || is_link( $temporary ) ) {
				if ( ! $removed ) {
					throw new RuntimeException( 'Temporary output file removal failed; manual cleanup required.', 0, $error );
				}
				throw new RuntimeException( 'Temporary output file cleanup readback failed; manual cleanup required.' );
			}
			throw $error;
		} finally {
			$handle = null;
			$prior  = null;
		}
	}
}
