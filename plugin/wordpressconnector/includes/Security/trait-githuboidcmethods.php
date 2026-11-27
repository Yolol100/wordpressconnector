<?php
/**
 * Canonical public method implementations for GitHubOidc.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait GitHubOidcMethods {
	/**
	 * Process the expected audience operation in the GitHubOidc component.
	 *
	 * @return string Operation result.
	 */
	public static function expected_audience(): string {
		return rtrim( (string) rest_url( 'webactueel-wordpress-connector/v1' ), '/' );
	}

	/**
	 * Bounded cleanup for the privileged connector.cleanup action.
	 * Only claims older than both the retention window and their expiry
	 * are removed. Never delete a recently issued or malformed claim.
	 *
	 * @param int $olderThanSeconds Minimum age in seconds.
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	public static function cleanup_replay_claims( int $olderThanSeconds ): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' )
		|| ! method_exists( $wpdb, 'esc_like' ) ) {
			throw new RuntimeException( 'OIDC replay cleanup requires WordPress options storage.' );
		}
		$cutoff = time() - max( self::MAX_TOKEN_AGE + 2 * self::CLOCK_SKEW, $olderThanSeconds );
		$like   = $wpdb->esc_like( self::REPLAY_OPTION_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded replay-claim cleanup needs a fresh prepared options read; do not cache security state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
				$like,
				500
			)
		);
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'OIDC replay cleanup could not inspect WordPress options storage.' );
		}
		$deleted = 0;
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! is_string( $row->option_name ?? null )
			|| 0 !== strpos( $row->option_name, self::REPLAY_OPTION_PREFIX ) ) {
				continue;
			}
			$record = maybe_unserialize( $row->option_value ?? null );
			if ( ! is_array( $record ) || ! is_int( $record['created_at'] ?? null )
			|| ! is_int( $record['expires_at'] ?? null )
			|| $record['created_at'] >= $cutoff || $record['expires_at'] >= $cutoff ) {
				continue;
			}
			delete_option( $row->option_name );
			if ( null === get_option( $row->option_name, null ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}
}
