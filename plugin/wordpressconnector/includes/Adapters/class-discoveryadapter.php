<?php
/**
 * Registers and handles Discovery connector actions.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

require_once __DIR__ . '/trait-discoveryadapteractions.php';

/**
 * Registers and handles Discovery connector actions.
 */
final class DiscoveryAdapter {
	use DiscoveryAdapterActions {
		rest_catalog as restCatalog;
	}

	/**
	 * Register the Discovery adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
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

	/**
	 * Process the discover operation in the Discovery adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 */
	public function discover( array $payload, array $context ): array {
		global $wp_version;

		$post_types = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			$post_types[ $name ] = array(
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

		$image_sizes = array();
		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			$image_sizes[ $name ] = array(
				'width'  => (int) $size['width'],
				'height' => (int) $size['height'],
				'crop'   => $size['crop'],
			);
		}

		$yoast_version = defined( 'WPSEO_VERSION' )
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
			'yoast'                 => null !== $yoast_version || class_exists( 'WPSEO_Options' ),
			'yoast_version'         => $yoast_version,
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
			'post_types'             => $post_types,
			'taxonomies'             => $taxonomies,
			'post_statuses'          => get_post_stati( array(), 'objects' ),
			'image_sizes'            => $image_sizes,
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
	 * Process the rest catalog positive integer operation in the Discovery adapter.
	 *
	 * @param mixed  $value Input for the operation.
	 * @param int    $maximum Input for the operation.
	 * @param string $field Input for the operation.
	 *
	 * @return int Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function rest_catalog_positive_integer( $value, int $maximum, string $field ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) ||
			1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
			throw new \RuntimeException( esc_html( $field ) . ' must be a positive integer.' );
		}
		$number = (int) $value;
		if ( $number <= 0 || $number > $maximum ) {
			throw new \RuntimeException( esc_html( $field ) . ' exceeds the supported limit.' );
		}
		return $number;
	}
}
