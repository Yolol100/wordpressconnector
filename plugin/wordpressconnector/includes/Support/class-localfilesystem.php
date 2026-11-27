<?php
/**
 * Exact local file cleanup without filtered paths or recursive fallback.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

use Throwable;

/**
 * Local filesystem operations for already validated, owned paths.
 */
final class LocalFilesystem {
	/**
	 * Remove one file or leaf symlink and verify absence independently.
	 *
	 * The caller must validate the parent and, when applicable, ownership.
	 * Core's direct backend rewrites backslashes, so reject literal POSIX
	 * backslashes rather than deleting a different file. Type 'f' is vital:
	 * the directory branch of Core delete may fall through to recursion.
	 *
	 * @param string              $path   Validated local file or leaf link.
	 * @param \SplFileObject|null $handle Optional owned handle to close after removal.
	 * @return bool Whether the exact path is now absent.
	 */
	public static function remove_file( string $path, ?\SplFileObject &$handle = null ): bool {
		if ( '' === $path || ( '/' === DIRECTORY_SEPARATOR && false !== strpos( $path, '\\' ) ) ) {
			return false;
		}
		if ( self::absent( $path ) ) {
			$handle = null;
			return true;
		}
		if ( ! is_file( $path ) && ! is_link( $path ) ) {
			return false;
		}
		try {
			if ( ! class_exists( '\\WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			$filesystem = new \WP_Filesystem_Direct( null );
			$filesystem->delete( $path, false, 'f' );
		} catch ( Throwable $error ) {
			// The backend can throw after actually deleting the file.
			$handle = null;
			return self::absent( $path );
		}
		// Windows may defer deletion until the original handle closes.
		$handle = null;
		return self::absent( $path );
	}

	/**
	 * Capture a validated local path's observable identity.
	 *
	 * @param string $path Validated local path.
	 * @param int    $mode Expected private mode.
	 * @return array|null Observed identity, or null on failure.
	 */
	public static function private_identity( string $path, int $mode ): ?array {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ( 0700 === $mode ? ! is_dir( $path ) : ! is_file( $path ) ) ) {
			return null;
		}
		try {
			$info     = new \SplFileInfo( $path );
			$resolved = $info->getRealPath();
			$inode    = $info->getInode();
			if ( false === $resolved || false === $inode || is_link( $path ) ) {
				return null;
			}
			return array( $resolved, $inode );
		} catch ( Throwable $error ) {
			return null;
		}
	}

	/**
	 * Verify exact POSIX mode and the observed path identity.
	 *
	 * Windows mode bits cannot establish the effective Windows ACL.
	 *
	 * @param string     $path     Validated local path.
	 * @param int        $mode     Expected private mode.
	 * @param array|null $identity Identity before the permission change.
	 * @return bool Whether observable state matches.
	 */
	public static function private_mode_matches( string $path, int $mode, ?array $identity ): bool {
		if ( null === $identity || self::private_identity( $path, $mode ) !== $identity ) {
			return false;
		}
		try {
			return '/' !== DIRECTORY_SEPARATOR || ( ( new \SplFileInfo( $path ) )->getPerms() & 07777 ) === $mode;
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/**
	 * Check actual absence, including dangling symbolic links.
	 *
	 * @param string $path Local path.
	 * @return bool Whether no file or link remains.
	 */
	private static function absent( string $path ): bool {
		clearstatcache( true, $path );
		return ! file_exists( $path ) && ! is_link( $path );
	}
}
