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

}
