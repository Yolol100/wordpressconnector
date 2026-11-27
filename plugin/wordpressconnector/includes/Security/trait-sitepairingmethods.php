<?php
/**
 * Canonical public method implementations for SitePairing.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait SitePairingMethods {
	/**
	 * Process the paired administrator id operation in the SitePairing component.
	 *
	 * @return int Operation result.
	 */
	public static function paired_administrator_id(): int {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) ||
			1 !== ( $state['enabled'] ?? null ) ||
			! is_string( $state['audience_sha256'] ?? null ) ||
			! preg_match( '/^[a-f0-9]{64}\z/', $state['audience_sha256'] ) ||
			! hash_equals( self::fingerprint(), $state['audience_sha256'] ) ) {
			return 0;
		}
		$id = $state['administrator_id'] ?? null;
		if ( ! is_int( $id ) || $id <= 0 || ! user_can( $id, 'manage_options' ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * WordPress plugin activation is an explicit administrator action guarded by
	 * Core's own activation permissions and nonce. Reuse that single step for
	 * this trusted, pinned GitHub control plane; never pair a mere upload,
	 * an automated CLI install, or a network-wide activation.
	 *
	 * Existing installations are NOT silently re-paired during upgrades.
	 *
	 * @param bool $network_wide Whether the operation is network-wide.
	 */
	public static function approve_on_activation( bool $network_wide = false ): void {
		if ( $network_wide || ! is_admin() ||
			! current_user_can( 'activate_plugins' ) ||
			! current_user_can( 'manage_options' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! is_int( $user_id ) || $user_id <= 0 ) {
			return;
		}
		self::enableForAdministrator( $user_id );
	}

	/**
	 * Validate the exact administrator before writing site-bound approval.
	 *
	 * @param int $administrator_id Administrator to validate.
	 * @return int Validated administrator id.
	 * @throws RuntimeException If the administrator cannot approve pairing.
	 */
	private static function validated_administrator_id( int $administrator_id ): int {
		if ( $administrator_id <= 0 || ! user_can( $administrator_id, 'manage_options' ) ) {
			throw new RuntimeException( 'Only a current site administrator may approve GitHub OIDC.' );
		}
		return $administrator_id;
	}
}
