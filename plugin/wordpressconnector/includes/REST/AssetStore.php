<?php
/**
 * Implements Asset Store handling for authenticated REST requests.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\REST;

use RuntimeException;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\LocalFilesystem;
use Webactueel\WordPressConnector\Support\LocalFileIdentity;

require_once __DIR__ . '/../Support/class-localfilesystem.php';
require_once __DIR__ . '/../Support/class-localfileidentity.php';

/**
 * Implements Asset Store handling for authenticated REST requests.
 */
final class AssetStore {
	private const MAX_FILES       = 10;
	private const MAX_TOTAL_BYTES = 26214400;
	private const MAX_FILE_BYTES  = 20971520;

	/**
	 * Process the put operation in the AssetStore component.
	 *
	 * @param string $requestId Input for the operation.
	 * @param string $relativePath Input for the operation.
	 * @param array  $file Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function put( string $requestId, string $relativePath, array $file ): array {
		$this->assert_request_id( $requestId );
		$relativePath = $this->normalize_relative_path( $relativePath );

		// PHP upload fields must remain scalar. Never cast nested input into a valid status.
		if ( ! isset( $file['error'], $file['tmp_name'] ) || ! is_int( $file['error'] ) || ! is_string( $file['tmp_name'] ) ) {
			throw new RuntimeException( 'Invalid asset upload metadata.' );
		}
		$error = $file['error'];
		if ( UPLOAD_ERR_OK !== $error ) {
			throw new RuntimeException( 'Asset upload failed with PHP upload error ' . intval( $error ) . '.' );
		}

		$tmp_name = $file['tmp_name'];
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			throw new RuntimeException( 'Asset upload is not a valid HTTP upload.' );
		}
		$actual_size = filesize( $tmp_name );
		if ( false === $actual_size || $actual_size <= 0 || $actual_size > self::MAX_FILE_BYTES ) {
			throw new RuntimeException( 'Asset exceeds the per-file size limit.' );
		}
		$size = (int) $actual_size;

		$file_type = $this->allowed_file_type( $relativePath );

		$root  = $this->rootForRequest( $requestId, true );
		$usage = $this->usage( $root );
		if ( $usage['files'] >= self::MAX_FILES || ( $usage['bytes'] + $size ) > self::MAX_TOTAL_BYTES ) {
			throw new RuntimeException( 'Request asset limit exceeded (max 10 files / 25 MiB total).' );
		}

		$destination = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relativePath );
		$parent      = dirname( $destination );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			throw new RuntimeException( 'Could not create request asset directory.' );
		}
		// Validate symlinks before a chmod can follow an escaped path.
		Policy::assertLocalAssetPath( $parent, $root );
		if ( ! $this->secure_directory( $parent ) ) {
			throw new RuntimeException( 'Could not secure request asset directory.' );
		}

		if ( file_exists( $destination ) || is_link( $destination ) ) {
			throw new RuntimeException( 'Duplicate request asset path: ' . esc_html( $relativePath ) );
		}
		if ( ! move_uploaded_file( $tmp_name, $destination ) ) {
			throw new RuntimeException( 'Could not store uploaded request asset.' );
		}
		$resolved = realpath( $destination );
		if ( false === $resolved || is_link( $destination ) ) {
			throw new RuntimeException( 'Uploaded request asset identity changed; manual recovery is required.' );
		}
		$handle       = new \SplFileObject( $resolved, 'rb' );
		$created_stat = $handle->fstat();
		$identity     = LocalFilesystem::private_identity( $destination, 0600 );
		if ( ! is_array( $created_stat ) || ! LocalFileIdentity::matches( $resolved, $created_stat ) || null === $identity ) {
			throw new RuntimeException( 'Uploaded request asset identity changed; manual recovery is required.' );
		}
		$secured = $this->native_operation_succeeded(
			static function () use ( $destination ): bool {
				return chmod( $destination, 0600 );
			}
		);
		if ( ! $secured || ! LocalFilesystem::private_mode_matches( $destination, 0600, $identity ) ||
			! LocalFileIdentity::matches( $resolved, $created_stat ) ) {
			if ( ! LocalFileIdentity::matches( $resolved, $created_stat ) ||
				! LocalFilesystem::remove_file( $resolved, $handle ) ) {
				throw new RuntimeException( 'Could not secure or remove uploaded request asset; manual recovery is required.' );
			}
			throw new RuntimeException( 'Could not secure uploaded request asset.' );
		}
		// The request root's modification time is the cleanup expiration clock.
		// Nested uploads can update only their parent, so never report a fresh
		// asset as stored if this root timestamp could not be refreshed.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- This private root needs a checked, local timestamp operation; an unhandled PHP warning would expose filesystem details.
		if ( ! @touch( $root ) ) {
			if ( ! LocalFileIdentity::matches( $resolved, $created_stat ) ||
				! LocalFilesystem::remove_file( $resolved, $handle ) ) {
				throw new RuntimeException( 'Could not refresh uploaded asset retention or remove the new file; manual cleanup is required.' );
			}
			throw new RuntimeException( 'Could not refresh uploaded asset retention; the new upload was removed.' );
		}

		if ( ! LocalFileIdentity::matches( $resolved, $created_stat ) ||
			! LocalFilesystem::private_mode_matches( $destination, 0600, $identity ) ) {
			throw new RuntimeException( 'Uploaded request asset identity changed; manual recovery is required.' );
		}

		return array(
			'request_id' => $requestId,
			'asset_path' => $relativePath,
			'bytes'      => $size,
			'mime_type'  => (string) $file_type['type'],
		);
	}

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
	public function rootForRequest( string $requestId, bool $create = false ): string {
		$this->assert_request_id( $requestId );
		$base      = $this->base_root( $create );
		$directory = $base . DIRECTORY_SEPARATOR . hash( 'sha256', home_url( '/' ) . '|' . $requestId );

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
	 * Process the cleanup operation in the AssetStore component.
	 *
	 * @param string $requestId Input for the operation.
	 */
	public function cleanup( string $requestId ): void {
		$directory = $this->rootForRequest( $requestId, false );
		if ( is_dir( $directory ) ) {
			$this->remove_tree( $directory );
		}
	}

	/**
	 * Clean up expired for the AssetStore component.
	 *
	 * @param int $olderThanSeconds Input for the operation.
	 */
	public function cleanupExpired( int $olderThanSeconds = DAY_IN_SECONDS ): void {
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

	/**
	 * Process the allowed file type operation in the AssetStore component.
	 *
	 * @param string $relative_path Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function allowed_file_type( string $relative_path ): array {
		$basename  = basename( $relative_path );
		$file_type = wp_check_filetype( $basename, get_allowed_mime_types() );
		if ( ! empty( $file_type['ext'] ) && ! empty( $file_type['type'] ) ) {
			return $file_type;
		}

		if ( preg_match( '#^(?:plugin-packages|theme-packages)/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.zip\z#', $relative_path ) ) {
			$zip_type = wp_check_filetype( $basename, array( 'zip' => 'application/zip' ) );
			if ( ! empty( $zip_type['ext'] ) && ! empty( $zip_type['type'] ) ) {
				return $zip_type;
			}
		}

		throw new RuntimeException( 'Asset extension is not an allowed WordPress media type or connector plugin/theme package.' );
	}

	/**
	 * Process the base root operation in the AssetStore component.
	 *
	 * @param bool $create Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function base_root( bool $create ): string {
		$configured = defined( 'WPCONNECTOR_ASSET_ROOT' ) ? trim( (string) constant( 'WPCONNECTOR_ASSET_ROOT' ) ) : '';
		if ( '' !== $configured ) {
			if ( ! $this->is_absolute_path( $configured ) ) {
				throw new RuntimeException( 'WPCONNECTOR_ASSET_ROOT must be an absolute private filesystem path.' );
			}
			$base = rtrim( $configured, '/\\' );
		} else {
			$temp = rtrim( (string) sys_get_temp_dir(), '/\\' );
			if ( '' === $temp || ! $this->is_absolute_path( $temp ) ) {
				throw new RuntimeException( 'A private system temporary directory is unavailable.' );
			}
			$site_namespace = substr( hash( 'sha256', home_url( '/' ) ), 0, 20 );
			$base           = $temp . DIRECTORY_SEPARATOR . 'wpconnector-inbox-' . $site_namespace;
		}

		if ( is_link( $base ) ) {
			throw new RuntimeException( 'Refusing symbolic link connector asset base directory.' );
		}
		$this->assert_outside_web_root( $base );

		if ( $create && ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			throw new RuntimeException( 'Could not create connector asset base directory.' );
		}
		if ( $create ) {
			if ( ! $this->secure_directory( $base ) ) {
				throw new RuntimeException( 'Could not secure connector asset base directory.' );
			}
			$resolved = realpath( $base );
			if ( false === $resolved ) {
				throw new RuntimeException( 'Could not resolve connector asset base directory.' );
			}
			$this->assert_outside_web_root( $resolved );
		}
		return $base;
	}

	/**
	 * Ensure outside web root for the AssetStore component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_outside_web_root( string $path ): void {
		$web_root = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
		if ( false === $web_root ) {
			return;
		}

		$web_root = rtrim( $web_root, DIRECTORY_SEPARATOR );
		$resolved = realpath( $path );
		if ( false !== $resolved ) {
			$candidate = rtrim( $resolved, DIRECTORY_SEPARATOR );
		} else {
			$parent = realpath( dirname( $path ) );
			if ( false === $parent ) {
				throw new RuntimeException( 'Connector asset root parent cannot be resolved.' );
			}
			$candidate = rtrim( $parent, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . basename( $path );
		}

		if ( $candidate === $web_root || 0 === strpos( $candidate, $web_root . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'Connector request assets must be stored outside the public WordPress root.' );
		}
	}

	/**
	 * Check whether absolute path for the AssetStore component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function is_absolute_path( string $path ): bool {
		return '/' === substr( $path, 0, 1 ) || 1 === preg_match( '/^[A-Za-z]:[\\\\\/]/', $path );
	}

	/**
	 * Process the usage operation in the AssetStore component.
	 *
	 * @param string $root Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function usage( string $root ): array {
		$files = 0;
		$bytes = 0;
		if ( ! is_dir( $root ) ) {
			return array(
				'files' => 0,
				'bytes' => 0,
			);
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $entry ) {
			if ( $entry->isFile() && ! $entry->isLink() ) {
				++$files;
				$bytes += (int) $entry->getSize();
			}
		}
		return array(
			'files' => $files,
			'bytes' => $bytes,
		);
	}

	/**
	 * Normalize relative path for the AssetStore component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function normalize_relative_path( string $path ): string {
		if ( '' === $path || strlen( $path ) > 240 || preg_match( '/[\x00-\x20\x7F]/', $path ) || '/' === $path[0] || false !== strpos( $path, '\\' ) ) {
			throw new RuntimeException( 'asset_path must be a short relative path without whitespace or control characters.' );
		}

		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw new RuntimeException( 'asset_path contains an invalid path segment.' );
			}
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/', $segment ) ) {
				throw new RuntimeException( 'asset_path contains unsupported characters.' );
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Ensure request id for the AssetStore component.
	 *
	 * @param string $request_id Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_request_id( string $request_id ): void {
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $request_id ) ) {
			throw new RuntimeException( 'request_id must be 8-100 safe characters.' );
		}
	}

	/**
	 * Remove tree for the AssetStore component.
	 *
	 * @param string $directory Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function remove_tree( string $directory ): void {
		if ( is_link( $directory ) ) {
			throw new RuntimeException( 'Refusing symbolic link request asset root.' );
		}
		$base = realpath( $this->base_root( false ) );
		$real = realpath( $directory );
		if ( false === $base || false === $real ) {
			throw new RuntimeException( 'Could not resolve private request asset cleanup path.' );
		}

		$base = rtrim( $base, DIRECTORY_SEPARATOR );
		if ( $real === $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || is_link( $real ) ) {
			throw new RuntimeException( 'Refusing unsafe connector asset cleanup path.' );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				$this->remove_empty_directory( $entry->getPathname(), false );
			} elseif ( ! LocalFilesystem::remove_file( $entry->getPathname() ) ) {
				throw new RuntimeException( 'Could not remove private request asset.' );
			}
		}
		$this->remove_empty_directory( $real, true );
	}

	/**
	 * Secure an already validated private directory and verify its identity.
	 *
	 * Native chmod clears special bits as well as the ordinary permissions.
	 * Core's direct filesystem can skip chmod when the low nine bits match.
	 *
	 * @param string $base Validated private directory.
	 * @return bool Whether the same directory has the expected private mode.
	 */
	private function secure_directory( string $base ): bool {
		$identity = LocalFilesystem::private_identity( $base, 0700 );
		if ( null === $identity ) {
			return false;
		}
		if ( ! $this->native_operation_succeeded(
			static function () use ( $base ): bool {
				return chmod( $base, 0700 );
			}
		) ) {
			return false;
		}
		return LocalFilesystem::private_mode_matches( $base, 0700, $identity );
	}

	/**
	 * Treat a native filesystem warning as a failed private operation.
	 *
	 * Native warnings can include server paths. Handle them only during the
	 * operation and restore the caller's error handler even on exceptions.
	 * The caller independently verifies identity and the resulting state.
	 *
	 * @param callable $operation Native local operation returning success.
	 * @return bool Whether the operation succeeded without warnings.
	 */
	private function native_operation_succeeded( callable $operation ): bool {
		$warning = false;
		set_error_handler(
			static function () use ( &$warning ): bool {
				$warning = true;
				return true;
			},
			E_WARNING | E_USER_WARNING
		);
		try {
			$result = $operation();
		} finally {
			restore_error_handler();
		}
		return $result && ! $warning;
	}

	/**
	 * Remove exactly one empty directory, never a recursive replacement.
	 *
	 * Native rmdir cannot fall back to deleting a non-empty tree. Verify
	 * absence even if the native operation reports success.
	 *
	 * @param string $real Validated directory to remove.
	 * @param bool   $root Whether this is the request's root directory.
	 * @throws RuntimeException If the directory cannot be removed or verified.
	 */
	private function remove_empty_directory( string $real, bool $root ): void {
		clearstatcache( true, $real );
		if ( is_link( $real ) || ! is_dir( $real ) ) {
			if ( $root ) {
				throw new RuntimeException( 'Could not remove private request asset root.' );
			}
			throw new RuntimeException( 'Could not remove private request asset directory.' );
		}
		if ( ! $this->native_operation_succeeded(
			static function () use ( $real ): bool {
				return rmdir( $real );
			}
		) ) {
			if ( $root ) {
				throw new RuntimeException( 'Could not remove private request asset root.' );
			}
			throw new RuntimeException( 'Could not remove private request asset directory.' );
		}
		clearstatcache( true, $real );
		if ( file_exists( $real ) || is_link( $real ) ) {
			throw new RuntimeException( 'Could not verify removal of private request asset directory.' );
		}
	}
}
