<?php
/**
 * Canonical public method implementations for DiscoveryAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait DiscoveryAdapterActions {
	/**
	 * Inspect only REST routing metadata. This is not an arbitrary REST proxy.
	 * Native rest_endpoints filters may run during get_routes() as in Core.
	 *
	 * @param array $payload Validated action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	public function rest_catalog( array $payload, array $context = array() ): array {
		// Registry supplies execution context; this read does not consume it.
		unset( $context );

		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( $key, array( 'namespace', 'page', 'per_page' ), true ) ) {
				throw new \RuntimeException( 'Unsupported REST discovery filter.' );
			}
		}

		$page             = $this->rest_catalog_positive_integer( $payload['page'] ?? 1, 10000, 'page' );
		$per_page         = $this->rest_catalog_positive_integer( $payload['per_page'] ?? 20, 25, 'per_page' );
		$namespace_filter = $payload['namespace'] ?? null;
		if ( null !== $namespace_filter &&
			( ! is_string( $namespace_filter ) || strlen( $namespace_filter ) > 120 ||
			1 !== preg_match( '~^[a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*){1,4}$~D', $namespace_filter ) ) ) {
			throw new \RuntimeException( 'Invalid REST namespace filter.' );
		}

		$result = array(
			'available'         => false,
			'discovery_only'    => true,
			'execution_enabled' => false,
			'page'              => $page,
			'per_page'          => $per_page,
			'pages'             => 1,
			'total'             => 0,
			'namespaces'        => array(),
			'routes'            => array(),
		);
		if ( ! function_exists( 'rest_get_server' ) ) {
			return $result;
		}

		$server = rest_get_server();
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) ||
			! method_exists( $server, 'get_namespaces' ) ) {
			return $result;
		}
		$registered = $server->get_routes();
		$namespaces = $server->get_namespaces();
		if ( ! is_array( $registered ) || ! is_array( $namespaces ) ||
			count( $registered ) > 15000 || count( $namespaces ) > 15000 ) {
			throw new \RuntimeException( 'REST discovery registry is invalid or exceeds the limit.' );
		}

		$namespaces = array_values(
			array_filter(
				$namespaces,
				static function ( $name ): bool {
					return is_string( $name ) && strlen( $name ) <= 120 &&
					1 === preg_match( '~^[a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*){1,4}$~D', $name );
				}
			)
		);
		usort(
			$namespaces,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$safe_routes        = array();
		$visible_namespaces = array();
		foreach ( $registered as $route => $endpoints ) {
			if ( ! is_string( $route ) || 1 !== preg_match( '/^[\x21-\x7e]{1,240}$/D', $route ) ||
				preg_match( '/[a-f0-9]{24,}/i', $route ) ||
				preg_match( '~(?:^|[/._-])(?:auth|oauth|tokens?|secrets?|passwords?|credentials?|api[_-]?keys?|licenses?|payments?|gateways?|customers?|users?|orders?|refunds?|checkout|carts?|webhooks?|sessions?|submissions?|login|logout|private|logs?)(?:[/._-]|$)~i', $route ) ||
				preg_match( '~(?:token|secret|password|credential)~i', $route ) ) {
				continue;
			}
			$route_namespace = null;
			foreach ( $namespaces as $name ) {
				if ( '/' . $name === $route || 0 === strpos( $route, '/' . $name . '/' ) ) {
					$route_namespace = $name;
					break;
				}
			}
			if ( null === $route_namespace ||
				( null !== $namespace_filter && $namespace_filter !== $route_namespace ) ||
				'/' . $route_namespace === $route ||
				'/' . $route_namespace . '/' === $route ) {
				// WordPress generates namespace index routes; they are not plugin actions.
				continue;
			}
			if ( ! is_array( $endpoints ) ) {
				continue;
			}

			$verbs = array();
			foreach ( $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) || ! isset( $endpoint['methods'] ) ) {
					continue;
				}
				$methods = $endpoint['methods'];
				if ( ! is_array( $methods ) ) {
					if ( ! is_string( $methods ) ) {
						continue;
					}
					$methods = array( $methods );
				}
				foreach ( $methods as $key => $enabled ) {
					if ( is_string( $key ) ) {
						if ( ! $enabled ) {
							continue;
						}
						$method = $key;
					} else {
						$method = is_string( $enabled ) ? $enabled : '';
					}
					foreach ( preg_split( '/[\s,|]+/', strtoupper( $method ) ) as $verb ) {
						if ( in_array( $verb, array( 'GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
							$verbs[ $verb ] = true;
						}
					}
				}
			}
			if ( ! $verbs ) {
				continue;
			}
			$methods = array_keys( $verbs );
			sort( $methods, SORT_STRING );
			$safe_routes[]                          = array(
				'namespace'          => $route_namespace,
				'route_pattern'      => $route,
				'advertised_methods' => $methods,
				'execution_enabled'  => false,
			);
			$visible_namespaces[ $route_namespace ] = true;
		}

		usort(
			$safe_routes,
			static function ( array $a, array $b ): int {
				return strcmp( $a['route_pattern'], $b['route_pattern'] );
			}
		);
		$namespace_list = array_keys( $visible_namespaces );
		sort( $namespace_list, SORT_STRING );
		$result['available']  = true;
		$result['namespaces'] = $namespace_list;
		$result['total']      = count( $safe_routes );
		$result['pages']      = max( 1, (int) ceil( $result['total'] / $per_page ) );
		$result['routes']     = array_slice( $safe_routes, ( $page - 1 ) * $per_page, $per_page );
		$encoded              = wp_json_encode( $result );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 131072 ) {
			throw new \RuntimeException( 'REST discovery output exceeds its transport budget.' );
		}
		return $result;
	}
}
