<?php
/**
 * Canonical public method implementations for ReleaseApproval.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

use Throwable;
use Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait ReleaseApprovalMethods {
	/**
	 * Bind the locked preflight to the exact one-time approval record.
	 *
	 * @param string $fingerprint Exact release fingerprint.
	 */
	public static function id_for_fingerprint( string $fingerprint ): ?string {
		$record = self::current_approval( $fingerprint );
		return null === $record ? null : $record['id'];
	}

	/**
	 * Preserve the legacy PHP entrypoint; delegate to the canonical implementation.
	 */
	private function render_form_compat(): void {
		$this->render_form();
	}
}
