<?php
/**
 * Verifies ownership of local file paths against open file identities.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

use Throwable;

/**
 * Shared identity check for exclusive-create recovery.
 *
 * Callers keep the original handle alive. This detects observed replacement;
 * portable PHP cannot make a subsequent path operation compare-and-swap.
 */
final class LocalFileIdentity {
	/**
	 * Verify a still-open file identity against a canonical local path.
	 *
	 * Native stat warnings must not escape with a server path if a file
	 * disappears between path validation and the local stat call.
	 *
	 * @param string     $absolute Previously resolved local file path.
	 * @param array|null $created_stat Identity captured from the exclusive handle.
	 * @return bool Whether the current regular non-link path matches.
	 */
	public static function matches( string $absolute, ?array $created_stat ): bool {
		if ( null === $created_stat || 0 === $created_stat['ino'] ) {
			return false;
		}
		clearstatcache( true, $absolute );
		if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
			return false;
		}
		try {
			$current_handle = new \SplFileObject( $absolute, 'rb' );
			$current        = $current_handle->fstat();
			clearstatcache( true, $absolute );
			return is_array( $current )
				&& 0100000 === ( $current['mode'] & 0170000 )
				&& $current['dev'] === $created_stat['dev']
				&& $current['ino'] === $created_stat['ino']
				&& ! is_link( $absolute )
				&& realpath( $absolute ) === $absolute
				&& ( new \SplFileInfo( $absolute ) )->getInode() === $current['ino'];
		} catch ( Throwable $error ) {
			return false;
		}
	}
}
