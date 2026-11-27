<?php
/**
 * Applies Site Pairing safeguards to connector operations.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

require_once __DIR__ . '/trait-sitepairingmethods.php';

/**
 * Ordinary WordPress admin activation pairs this site with the pinned GitHub
 * OIDC control plane, without a second approval screen. A database clone or
 * WordPress URL change never inherits authorization.
 * Direct authenticated WordPress REST/MCP is intentionally unaffected.
 */
final class SitePairing {
	use SitePairingMethods {
		enable_for_administrator as enableForAdministrator;
		paired_administrator_id as pairedAdministratorId;
		approve_on_activation as approveOnActivation;
	}

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
