<?php
/**
 * Applies Site Pairing safeguards to connector operations.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

/**
 * Ordinary WordPress admin activation pairs this site with the pinned GitHub
 * OIDC control plane, without a second approval screen. A database clone or
 * WordPress URL change never inherits authorization.
 * Direct authenticated WordPress REST/MCP is intentionally unaffected.
 */
final class SitePairing {
	private const OPTION    = 'wpconnector_github_oidc_site_pairing_v1';
	private const NAMESPACE = 'webactueel-wordpress-connector/v1';

	/**
	 * Process the audience operation in the SitePairing component.
	 *
	 * @return string Operation result.
	 */
	public static function audience(): string {
		return rtrim( (string) rest_url( self::NAMESPACE ), '/' );
	}

	/**
	 * Process the fingerprint operation in the SitePairing component.
	 *
	 * @return string Operation result.
	 */
	private static function fingerprint(): string {
		return hash( 'sha256', self::audience() );
	}

	/**
	 * Process the paired administrator id operation in the SitePairing component.
	 *
	 * @return int Operation result.
	 */
	public static function pairedAdministratorId(): int {
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
	public static function approveOnActivation( bool $network_wide = false ): void {
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
	 * Process the enable for administrator operation in the SitePairing component.
	 *
	 * @param int $administratorId Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function enableForAdministrator( int $administratorId ): void {
		if ( $administratorId <= 0 || ! user_can( $administratorId, 'manage_options' ) ) {
			throw new RuntimeException( 'Only a current site administrator may approve GitHub OIDC.' );
		}
		$state = array(
			'enabled'          => 1,
			'audience_sha256'  => self::fingerprint(),
			'administrator_id' => $administratorId,
		);
		update_option( self::OPTION, $state, false );
		// A WordPress option update can be unchanged, fail, or be filtered.
		// Only the exact stored approval and current administrator rights count.
		if ( get_option( self::OPTION, null ) !== $state
			|| self::pairedAdministratorId() !== $state['administrator_id'] ) {
			throw new RuntimeException( 'Could not store site-specific GitHub OIDC approval.' );
		}
	}

	/**
	 * Revoke the stored GitHub OIDC pairing; never report a failed delete as success.
	 *
	 * @throws \RuntimeException If the approval is still stored.
	 */
	public static function disable(): void {
		delete_option( self::OPTION );
		// A failed option deletion must never report a successful revocation.
		if ( null !== get_option( self::OPTION, null ) ) {
			throw new RuntimeException( 'Could not revoke site-specific GitHub OIDC approval.' );
		}
	}
}
