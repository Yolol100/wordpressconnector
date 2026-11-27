<?php
/**
 * Canonical public method implementations for Controller.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\MCP;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Security\FilesystemPolicy;
use Webactueel\WordPressConnector\Security\NativeOAuthServer;
use Webactueel\WordPressConnector\Security\OAuthResourceServer;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait MCPControllerMethods {
	/**
	 * Register the supported REST and MCP routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}
}
