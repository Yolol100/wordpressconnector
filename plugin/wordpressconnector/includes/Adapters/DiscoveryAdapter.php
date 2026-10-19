<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

final class DiscoveryAdapter {

	public function register( Registry $registry ): void {
		$registry->register(
			'connector.discover',
			array( $this, 'discover' ),
			array(
				'description' => 'Discover WordPress, plugins, post types, taxonomies, builders, media sizes and connector capabilities.',
			)
		);
		$registry->register(
			'connector.rest_catalog',
			array( $this, 'restCatalog' ),
			array(
				'privileged'  => true,
				'capability'  => 'manage_options',
				'description' => 'Discover bounded WordPress REST namespaces, route patterns and methods without calling endpoints.',
			)
		);
	}

	public function discover( array $payload, array $context ): array {
		global $wp_version;

		$postTypes = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			$postTypes[ $name ] = array(
				'label'        => $object->label,
				'public'       => (bool) $object->public,
				'show_ui'      => (bool) $object->show_ui,
				'show_in_rest' => (bool) $object->show_in_rest,
				'hierarchical' => (bool) $object->hierarchical,
				'supports'     => get_all_post_type_supports( $name ),
				'taxonomies'   => get_object_taxonomies( $name ),
			);
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $name => $object ) {
			$taxonomies[ $name ] = array(
				'label'        => $object->label,
				'public'       => (bool) $object->public,
				'show_ui'      => (bool) $object->show_ui,
				'show_in_rest' => (bool) $object->show_in_rest,
				'hierarchical' => (bool) $object->hierarchical,
				'object_type'  => array_values( (array) $object->object_type ),
			);
		}

		$plugins = array();
		$theme   = wp_get_theme();
		if ( ! Policy::publicRepositoryContext() ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( get_plugins() as $file => $data ) {
				$plugins[ $file ] = array(
					'name'           => $data['Name'] ?? $file,
					'version'        => $data['Version'] ?? '',
					'active'         => is_plugin_active( $file ),
					'network_active' => is_multisite() ? is_plugin_active_for_network( $file ) : false,
				);
			}
		}

		$imageSizes = array();
		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			$imageSizes[ $name ] = array(
				'width'  => (int) $size['width'],
				'height' => (int) $size['height'],
				'crop'   => $size['crop'],
			);
		}

		$yoastVersion = defined( 'WPSEO_VERSION' )
			? WPSEO_VERSION
			: ( defined( 'YOAST_SEO_VERSION' ) ? YOAST_SEO_VERSION : null );

		$builders = array(
			'gutenberg'             => function_exists( 'parse_blocks' ),
			'elementor'             => class_exists( 'Elementor\\Plugin' ),
			'elementor_version'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementor_pro'         => defined( 'ELEMENTOR_PRO_VERSION' ),
			'elementor_pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'woocommerce'           => class_exists( 'WooCommerce' ),
			'woocommerce_version'   => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'acf'                   => function_exists( 'get_fields' ),
			'acf_version'           => defined( 'ACF_VERSION' ) ? ACF_VERSION : null,
			'yoast'                 => null !== $yoastVersion || class_exists( 'WPSEO_Options' ),
			'yoast_version'         => $yoastVersion,
			'wordpress_abilities'   => function_exists( 'wp_get_abilities' ),
		);

		return array(
			'wordpress'              => array(
				'version'          => (string) $wp_version,
				'php_version'      => PHP_VERSION,
				'multisite'        => is_multisite(),
				'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : null,
				'site_url'         => Policy::publicRepositoryContext() ? null : site_url(),
				'home_url'         => Policy::publicRepositoryContext() ? null : home_url(),
			),
			'theme'                  => array(
				'stylesheet' => (string) $theme->get_stylesheet(),
				'template'   => (string) $theme->get_template(),
				'name'       => (string) $theme->get( 'Name' ),
				'version'    => (string) $theme->get( 'Version' ),
			),
			'plugins'                => $plugins,
			'post_types'             => $postTypes,
			'taxonomies'             => $taxonomies,
			'post_statuses'          => get_post_stati( array(), 'objects' ),
			'image_sizes'            => $imageSizes,
			'builders'               => $builders,
			'rest_api_discovery'     => array(
				'available'         => function_exists( 'rest_get_server' ),
				'action'            => 'connector.rest_catalog',
				'execution_enabled' => false,
			),
			'menus'                  => array(
				'registered_locations' => get_registered_nav_menus(),
				'assigned_locations'   => get_nav_menu_locations(),
			),
			'actions'                => isset( $context['registry'] ) && $context['registry'] instanceof Registry ? $context['registry']->catalog() : array(),
			'public_repository_mode' => Policy::publicRepositoryContext(),
			'connector_mode'         => 'single_canonical_bridge',
		);
	}

	/**
	 * Inspect only REST routing metadata. This is not an arbitrary REST proxy.
	 * Native rest_endpoints filters may run during get_routes() as in Core.
	 */
	public function restCatalog( array $payload, array $context = array() ): array {
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( $key, array( 'namespace', 'page', 'per_page' ), true ) ) {
				throw new \RuntimeException( 'Unsupported REST discovery filter.' );
			}
		}

		$page            = $this->restCatalogPositiveInteger( $payload['page'] ?? 1, 10000, 'page' );
		$perPage         = $this->restCatalogPositiveInteger( $payload['per_page'] ?? 20, 25, 'per_page' );
		$namespaceFilter = $payload['namespace'] ?? null;
		if ( null !== $namespaceFilter &&
			( ! is_string( $namespaceFilter ) || strlen( $namespaceFilter ) > 120 ||
			1 !== preg_match( '~^[a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*){1,4}$~D', $namespaceFilter ) ) ) {
			throw new \RuntimeException( 'Invalid REST namespace filter.' );
		}

		$result = array(
			'available'         => false,
			'discovery_only'    => true,
			'execution_enabled' => false,
			'page'              => $page,
			'per_page'          => $perPage,
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

		$safeRoutes        = array();
		$visibleNamespaces = array();
		foreach ( $registered as $route => $endpoints ) {
			if ( ! is_string( $route ) || 1 !== preg_match( '/^[\x21-\x7e]{1,240}$/D', $route ) ||
				preg_match( '/[a-f0-9]{24,}/i', $route ) ||
				preg_match( '~(?:^|[/._-])(?:auth|oauth|tokens?|secrets?|passwords?|credentials?|api[_-]?keys?|licenses?|payments?|gateways?|customers?|users?|orders?|refunds?|checkout|carts?|webhooks?|sessions?|submissions?|login|logout|private|logs?)(?:[/._-]|$)~i', $route ) ||
				preg_match( '~(?:token|secret|password|credential)~i', $route ) ) {
				continue;
			}
			$routeNamespace = null;
			foreach ( $namespaces as $name ) {
				if ( $route === '/' . $name || 0 === strpos( $route, '/' . $name . '/' ) ) {
					$routeNamespace = $name;
					break;
				}
			}
			if ( null === $routeNamespace ||
				( null !== $namespaceFilter && $namespaceFilter !== $routeNamespace ) ||
				$route === '/' . $routeNamespace ||
				$route === '/' . $routeNamespace . '/' ) {
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
			$safeRoutes[]                         = array(
				'namespace'          => $routeNamespace,
				'route_pattern'      => $route,
				'advertised_methods' => $methods,
				'execution_enabled'  => false,
			);
			$visibleNamespaces[ $routeNamespace ] = true;
		}

		usort(
			$safeRoutes,
			static function ( array $a, array $b ): int {
				return strcmp( $a['route_pattern'], $b['route_pattern'] );
			}
		);
		$namespaceList = array_keys( $visibleNamespaces );
		sort( $namespaceList, SORT_STRING );
		$result['available']  = true;
		$result['namespaces'] = $namespaceList;
		$result['total']      = count( $safeRoutes );
		$result['pages']      = max( 1, (int) ceil( $result['total'] / $perPage ) );
		$result['routes']     = array_slice( $safeRoutes, ( $page - 1 ) * $perPage, $perPage );
		$encoded              = function_exists( 'wp_json_encode' ) ? wp_json_encode( $result ) : json_encode( $result );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 131072 ) {
			throw new \RuntimeException( 'REST discovery output exceeds its transport budget.' );
		}
		return $result;
	}

	private function restCatalogPositiveInteger( $value, int $maximum, string $field ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) ||
			1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
			throw new \RuntimeException( $field . ' must be a positive integer.' );
		}
		$number = (int) $value;
		if ( $number <= 0 || $number > $maximum ) {
			throw new \RuntimeException( $field . ' exceeds the supported limit.' );
		}
		return $number;
	}
}
