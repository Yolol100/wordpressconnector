<?php
/**
 * Registers and handles Elementor Capabilities connector actions.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use ReflectionClass;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

/**
 * Registers and handles Elementor Capabilities connector actions.
 */
final class ElementorCapabilitiesAdapter {
	public function register( Registry $registry ): void {
		$registry->register( 'elementor.capabilities', array( $this, 'capabilities' ), array(
			'description' => 'Inspect active Elementor document types, widgets, elements, dynamic tags and responsive capabilities.',
		) );
		$registry->register( 'elementor.inventory', array( $this, 'inventory' ), array(
			'description' => 'Inventory registered Elementor widgets and actual widget/element usage across Elementor documents.',
		) );
	}

	public function capabilities( array $payload, array $context ): array {
		$environment = $this->environment();

		if ( ! class_exists( 'Elementor\\Plugin' ) ) {
			return array(
				'available'      => false,
				'environment'    => $environment,
				'document_types' => array(),
				'widgets'        => array(),
				'elements'       => array(),
				'dynamic_tags'   => array(),
				'breakpoints'    => array(),
				'warnings'       => array( 'elementor_not_active' ),
			);
		}

		$elementor = \Elementor\Plugin::$instance;
		$warnings  = array();

		return array(
			'available'      => true,
			'environment'    => $environment,
			'document_types' => $this->documentTypes( $elementor, $warnings ),
			'widgets'        => $this->widgets( $elementor, $warnings ),
			'elements'       => $this->elements( $elementor, $warnings ),
			'dynamic_tags'   => $this->dynamicTags( $elementor, $warnings ),
			'breakpoints'    => $this->breakpoints( $elementor, $warnings ),
			'warnings'       => array_values( array_unique( $warnings ) ),
		);
	}

	public function inventory( array $payload, array $context ): array {
		$environment = $this->environment();
		if ( ! class_exists( 'Elementor\\Plugin' ) ) {
			return array(
				'available'       => false,
				'environment'     => $environment,
				'scan'            => array(),
				'summary'         => array(),
				'widgets'         => array(),
				'used_widgets'    => array(),
				'missing_widgets' => array(),
				'element_types'   => array(),
				'documents'       => array(),
				'warnings'        => array( 'elementor_not_active' ),
			);
		}

		$warnings          = array();
		$elementor         = \Elementor\Plugin::$instance;
		$registeredWidgets = $this->widgets( $elementor, $warnings );
		$post_types         = $this->inventoryPostTypes( $payload, $warnings );
		$statuses          = $this->inventoryStatuses( $payload );
		$limit             = isset( $payload['limit'] ) ? max( 1, min( 5000, (int) $payload['limit'] ) ) : 1000;
		$offset            = isset( $payload['offset'] ) ? max( 0, (int) $payload['offset'] ) : 0;

		if ( empty( $post_types ) ) {
			$warnings[] = 'no_readable_elementor_post_types';
			return array(
				'available'       => true,
				'environment'     => $environment,
				'scan'            => array(
					'post_types'    => array(),
					'post_statuses' => $statuses,
					'limit'         => $limit,
					'offset'        => $offset,
					'has_more'      => false,
				),
				'summary'         => array(
					'documents_scanned'        => 0,
					'widget_instances'         => 0,
					'unique_widget_types_used' => 0,
					'missing_widget_types'     => 0,
				),
				'widgets'         => $this->mergeUsageIntoWidgets( $registeredWidgets, array() ),
				'used_widgets'    => array(),
				'missing_widgets' => array(),
				'element_types'   => array(),
				'documents'       => array(),
				'warnings'        => array_values( array_unique( $warnings ) ),
			);
		}

		$ids = get_posts( array(
			'post_type'        => $post_types,
			'post_status'      => $statuses,
			'fields'           => 'ids',
			'posts_per_page'   => $limit + 1,
			'offset'           => $offset,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'meta_query'       => array(
				array(
					'key'     => '_elementor_data',
					'compare' => 'EXISTS',
				),
			),
			'no_found_rows'    => true,
			'suppress_filters' => false,
		) );

		$has_more = count( $ids ) > $limit;
		if ( $has_more ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		$usedWidgets        = array();
		$elementTypes       = array();
		$documents          = array();
		$architecture_totals = array( 'legacy' => 0, 'container' => 0, 'atomic' => 0, 'mixed' => 0, 'widget-only' => 0 );
		$widget_instances    = 0;

		foreach ( $ids as $postId ) {
			$post = get_post( (int) $postId );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			try {
				Policy::assertPostReadable( $post );
			} catch ( Throwable $error ) {
				$warnings[] = 'document_skipped_by_policy:' . (int) $postId;
				continue;
			}

			$data = $this->elementorData( (int) $postId, $warnings );
			if ( $data === null ) {
				continue;
			}

			$document_widgets      = array();
			$document_element_types = array();
			$families             = array( 'legacy' => false, 'container' => false, 'atomic' => false );
			$this->collectElementUsage( $data, $document_widgets, $document_element_types, $families );

			$document_widget_instances = array_sum( $document_widgets );
			$widget_instances += $document_widget_instances;
			foreach ( $document_widgets as $widget_name => $count ) {
				if ( ! isset( $usedWidgets[ $widget_name ] ) ) {
					$usedWidgets[ $widget_name ] = array(
						'instances'    => 0,
						'documents'    => 0,
						'document_ids' => array(),
					);
				}
				$usedWidgets[ $widgetName ]['instances'] += $count;
				$usedWidgets[ $widgetName ]['documents']++;
				$usedWidgets[ $widgetName ]['document_ids'][] = (int) $postId;
			}

			foreach ( $document_element_types as $element_type => $count ) {
				if ( ! isset( $elementTypes[ $element_type ] ) ) {
					$elementTypes[ $elementType ] = array( 'instances' => 0, 'documents' => 0 );
				}
				$elementTypes[ $elementType ]['instances'] += $count;
				$elementTypes[ $elementType ]['documents']++;
			}

			$architecture = $this->architectureLabel( $families );
			if ( isset( $architecture_totals[ $architecture ] ) ) {
				$architecture_totals[ $architecture ]++;
			}

			$documents[] = array(
				'id'               => (int) $postId,
				'post_type'        => (string) $post->post_type,
				'status'           => (string) $post->post_status,
				'title'            => wp_strip_all_tags( (string) get_the_title( $post ) ),
				'slug'             => (string) $post->post_name,
				'architecture'     => $architecture,
				'families'         => array_values( array_keys( array_filter( $families ) ) ),
				'widget_instances' => $document_widget_instances,
				'widgets'          => $document_widgets,
				'element_types'    => $document_element_types,
			);
		}

		ksort( $usedWidgets, SORT_STRING );
		ksort( $elementTypes, SORT_STRING );
		$missing_widgets = array();
		foreach ( $usedWidgets as $widget_name => $usage ) {
			if ( ! isset( $registeredWidgets[ $widget_name ] ) ) {
				$missing_widgets[ $widget_name ] = $usage;
			}
		}

		return array(
			'available'       => true,
			'environment'     => $environment,
			'scan'            => array(
				'post_types'    => $post_types,
				'post_statuses' => $statuses,
				'limit'         => $limit,
				'offset'        => $offset,
				'has_more'      => $has_more,
				'next_offset'   => $has_more ? $offset + $limit : null,
			),
			'summary'         => array(
				'documents_scanned'        => count( $documents ),
				'widget_instances'         => $widget_instances,
				'unique_widget_types_used' => count( $usedWidgets ),
				'missing_widget_types'     => count( $missingWidgets ),
				'architecture_documents'   => $architecture_totals,
			),
			'widgets'         => $this->mergeUsageIntoWidgets( $registeredWidgets, $usedWidgets ),
			'used_widgets'    => $usedWidgets,
			'missing_widgets' => $missing_widgets,
			'element_types'   => $elementTypes,
			'documents'       => $documents,
			'warnings'        => array_values( array_unique( $warnings ) ),
		);
	}

	private function environment(): array {
		return array(
			'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'theme'         => (string) wp_get_theme()->get( 'Name' ),
			'theme_version' => (string) wp_get_theme()->get( 'Version' ),
		);
	}

	private function documentTypes( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->documents ) || ! is_object( $elementor->documents ) || ! method_exists( $elementor->documents, 'get_document_types' ) ) {
			$warnings[] = 'documents_manager_unavailable';
			return array();
		}

		try {
			$types = $elementor->documents->get_document_types();
		} catch ( Throwable $error ) {
			$warnings[] = 'document_types_inventory_failed';
			return array();
		}

		$result = array();
		foreach ( (array) $types as $type => $className ) {
			if ( ! is_string( $className ) || ! class_exists( $className ) ) {
				continue;
			}

			try {
				$properties               = method_exists( $className, 'get_properties' ) ? (array) $className::get_properties() : array();
				$result[(string) $type] = array(
					'title'           => method_exists( $className, 'get_title' ) ? wp_strip_all_tags( (string) $className::get_title() ) : (string) $type,
					'cpt'             => isset( $properties['cpt'] ) ? array_values( array_map( 'strval', (array) $properties['cpt'] ) ) : array(),
					'show_in_library' => isset( $properties['show_in_library'] ) ? (bool) $properties['show_in_library'] : null,
					'is_editable'     => isset( $properties['is_editable'] ) ? (bool) $properties['is_editable'] : null,
				);
			} catch ( Throwable $error ) {
				$warnings[] = 'document_type_skipped:' . sanitize_key( (string) $type );
			}
		}

		ksort( $result, SORT_STRING );
		return $result;
	}

	private function widgets( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->widgets_manager ) || ! is_object( $elementor->widgets_manager ) || ! method_exists( $elementor->widgets_manager, 'get_widget_types' ) ) {
			$warnings[] = 'widgets_manager_unavailable';
			return array();
		}

		try {
			$widgets = $elementor->widgets_manager->get_widget_types();
		} catch ( Throwable $error ) {
			$warnings[] = 'widgets_inventory_failed';
			return array();
		}

		$result = array();
		foreach ( (array) $widgets as $name => $widget ) {
			if ( ! is_object( $widget ) ) {
				continue;
			}
			try {
				$controls                 = method_exists( $widget, 'get_controls' ) ? (array) $widget->get_controls() : array();
				$categories               = method_exists( $widget, 'get_categories' ) ? array_values( array_map( 'strval', (array) $widget->get_categories() ) ) : array();
				$result[(string) $name] = array(
					'name'          => method_exists( $widget, 'get_name' ) ? (string) $widget->get_name() : (string) $name,
					'title'         => method_exists( $widget, 'get_title' ) ? wp_strip_all_tags( (string) $widget->get_title() ) : (string) $name,
					'categories'    => $categories,
					'control_count' => count( $controls ),
					'source'        => $this->widgetSource( $widget ),
				);
			} catch ( Throwable $error ) {
				$warnings[] = 'widget_skipped:' . sanitize_key( (string) $name );
			}
		}

		ksort( $result, SORT_STRING );
		return $result;
	}

	private function elements( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->elements_manager ) || ! is_object( $elementor->elements_manager ) || ! method_exists( $elementor->elements_manager, 'get_element_types' ) ) {
			$warnings[] = 'elements_manager_unavailable';
			return array();
		}

		try {
			$elements = $elementor->elements_manager->get_element_types();
		} catch ( Throwable $error ) {
			$warnings[] = 'elements_inventory_failed';
			return array();
		}

		$result = array();
		foreach ( (array) $elements as $name => $element ) {
			if ( ! is_object( $element ) ) {
				continue;
			}
			try {
				$result[(string) $name] = array(
					'name'       => method_exists( $element, 'get_name' ) ? (string) $element->get_name() : (string) $name,
					'title'      => method_exists( $element, 'get_title' ) ? wp_strip_all_tags( (string) $element->get_title() ) : (string) $name,
					'categories' => method_exists( $element, 'get_categories' ) ? array_values( array_map( 'strval', (array) $element->get_categories() ) ) : array(),
				);
			} catch ( Throwable $error ) {
				$warnings[] = 'element_skipped:' . sanitize_key( (string) $name );
			}
		}

		ksort( $result, SORT_STRING );
		return $result;
	}

	private function dynamicTags( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->dynamic_tags ) || ! is_object( $elementor->dynamic_tags ) || ! method_exists( $elementor->dynamic_tags, 'get_tags' ) ) {
			return array();
		}

		try {
			$tags = $elementor->dynamic_tags->get_tags();
		} catch ( Throwable $error ) {
			$warnings[] = 'dynamic_tags_inventory_failed';
			return array();
		}

		$result = array();
		foreach ( (array) $tags as $name => $tag_info ) {
			$tag = is_array( $tag_info ) && isset( $tag_info['instance'] ) && is_object( $tag_info['instance'] ) ? $tag_info['instance'] : null;
			if ( ! $tag ) {
				continue;
			}
			try {
				$result[(string) $name] = array(
					'name'       => method_exists( $tag, 'get_name' ) ? (string) $tag->get_name() : (string) $name,
					'title'      => method_exists( $tag, 'get_title' ) ? wp_strip_all_tags( (string) $tag->get_title() ) : (string) $name,
					'group'      => method_exists( $tag, 'get_group' ) && is_scalar( $tag->get_group() ) ? (string) $tag->get_group() : null,
					'categories' => method_exists( $tag, 'get_categories' ) ? array_values( array_map( 'strval', (array) $tag->get_categories() ) ) : array(),
				);
			} catch ( Throwable $error ) {
				$warnings[] = 'dynamic_tag_skipped:' . sanitize_key( (string) $name );
			}
		}

		ksort( $result, SORT_STRING );
		return $result;
	}

	private function breakpoints( object $elementor, array &$warnings ): array {
		if ( ! isset( $elementor->breakpoints ) || ! is_object( $elementor->breakpoints ) || ! method_exists( $elementor->breakpoints, 'get_active_breakpoints' ) ) {
			return array();
		}

		try {
			$breakpoints = $elementor->breakpoints->get_active_breakpoints();
		} catch ( Throwable $error ) {
			$warnings[] = 'breakpoints_inventory_failed';
			return array();
		}

		$result = array();
		foreach ( (array) $breakpoints as $name => $breakpoint ) {
			if ( ! is_object( $breakpoint ) ) {
				continue;
			}
			$result[(string) $name] = array(
				'label'     => method_exists( $breakpoint, 'get_label' ) ? (string) $breakpoint->get_label() : (string) $name,
				'value'     => method_exists( $breakpoint, 'get_value' ) ? (int) $breakpoint->get_value() : null,
				'direction' => method_exists( $breakpoint, 'get_direction' ) ? (string) $breakpoint->get_direction() : null,
			);
		}

		ksort( $result, SORT_STRING );
		return $result;
	}

	private function widgetSource( object $widget ): array {
		$className = get_class( $widget );
		$file      = '';
		try {
			$reflection = new ReflectionClass( $widget );
			$file_name   = $reflection->getFileName();
			$file       = is_string( $file_name ) ? $file_name : '';
		} catch ( Throwable $error ) {
			$file = '';
		}

		if ( $this->pathWithinDefinedRoot( $file, 'ELEMENTOR_PRO_PATH' ) ) {
			return $this->source( 'elementor-pro', 'elementor-pro', 'Elementor Pro', defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : null, 'runtime_file', $className );
		}
		if ( $this->pathWithinDefinedRoot( $file, 'ELEMENTOR_PATH' ) ) {
			return $this->source( 'elementor-core', 'elementor', 'Elementor', defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : null, 'runtime_file', $className );
		}

		$plugin = $this->pluginForFile( $file );
		if ( $plugin !== null ) {
			return $this->source( 'addon', $plugin['slug'], $plugin['name'], $plugin['version'], 'runtime_file', $className );
		}

		$theme = $this->themeForFile( $file );
		if ( $theme !== null ) {
			return $this->source( 'theme', $theme['slug'], $theme['name'], $theme['version'], 'runtime_file', $className );
		}

		if ( 0 === strpos( $className, 'ElementorPro\\' ) ) {
			return $this->source( 'elementor-pro', 'elementor-pro', 'Elementor Pro', defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : null, 'namespace', $className );
		}
		if ( 0 === strpos( $className, 'Elementor\\' ) ) {
			return $this->source( 'elementor-core', 'elementor', 'Elementor', defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : null, 'namespace', $className );
		}

		return $this->source( 'unknown', null, null, null, 'unknown', $className );
	}

	private function source( string $family, ?string $slug, ?string $name, ?string $version, string $confidence, string $className ): array {
		return array(
			'family'     => $family,
			'slug'       => $slug,
			'name'       => $name,
			'version'    => $version,
			'confidence' => $confidence,
			'class'      => $className,
		);
	}

	private function pathWithinDefinedRoot( string $file, string $constant ): bool {
		return '' !== $file && defined( $constant ) && $this->pathWithin( $file, (string) constant( $constant ) );
	}

	private function pathWithin( string $file, string $root ): bool {
		if ( '' === $file || '' === $root ) {
			return false;
		}
		$file = str_replace( '\\', '/', $file );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		return 0 === strpos( $file, $root );
	}

	private function pluginForFile( string $file ): ?array {
		if ( '' === $file ) {
			return null;
		}

		$roots = array();
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$roots[] = array( 'path' => (string) WP_PLUGIN_DIR, 'mu' => false );
		}
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$roots[] = array( 'path' => (string) WPMU_PLUGIN_DIR, 'mu' => true );
		}

		foreach ( $roots as $root ) {
			if ( ! $this->pathWithin( $file, $root['path'] ) ) {
				continue;
			}
			$relative = ltrim( substr( str_replace( '\\', '/', $file ), strlen( rtrim( str_replace( '\\', '/', $root['path'] ), '/' ) ) ), '/' );
			$parts    = explode( '/', $relative );
			$slug     = count( $parts ) > 1 ? $parts[0] : preg_replace( '/\.php$/', '', $parts[0] );
			$plugins  = $this->installedPlugins( (bool) $root['mu'] );
			foreach ( $plugins as $plugin_file => $data ) {
				$plugin_file = str_replace( '\\', '/', (string) $pluginFile );
				if ( $plugin_file !== $slug . '.php' && 0 !== strpos( $plugin_file, $slug . '/' ) ) {
					continue;
				}
				return array(
					'slug'    => (string) $slug,
					'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : (string) $slug,
					'version' => isset( $data['Version'] ) && '' !== (string) $data['Version'] ? (string) $data['Version'] : null,
				);
			}
			return array( 'slug' => (string) $slug, 'name' => (string) $slug, 'version' => null );
		}

		return null;
	}

	private function installedPlugins( bool $mu ): array {
		static $regular = null;
		static $must_use = null;

		if ( $mu ) {
			if ( is_array( $must_use ) ) {
				return $mustUse;
			}
			if ( ! function_exists( 'get_mu_plugins' ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$must_use = function_exists( 'get_mu_plugins' ) ? (array) get_mu_plugins() : array();
			return $must_use;
		}

		if ( is_array( $regular ) ) {
			return $regular;
		}
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$regular = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
		return $regular;
	}

	private function themeForFile( string $file ): ?array {
		if ( '' === $file || ! function_exists( 'get_theme_root' ) ) {
			return null;
		}
		$root = (string) get_theme_root();
		if ( ! $this->pathWithin( $file, $root ) ) {
			return null;
		}
		$relative = ltrim( substr( str_replace( '\\', '/', $file ), strlen( rtrim( str_replace( '\\', '/', $root ), '/' ) ) ), '/' );
		$parts    = explode( '/', $relative );
		$slug     = (string) $parts[0];
		$theme    = wp_get_theme( $slug );
		return array(
			'slug'    => $slug,
			'name'    => (string) $theme->get( 'Name' ),
			'version' => (string) $theme->get( 'Version' ),
		);
	}

	private function inventoryPostTypes( array $payload, array &$warnings ): array {
		$requested = isset( $payload['post_types'] ) ? (array) $payload['post_types'] : array();
		if ( empty( $requested ) ) {
			$requested = array_values( (array) get_post_types( array( 'show_ui' => true ), 'names' ) );
		}

		$result = array();
		foreach ( $requested as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( '' === $post_type || 'attachment' === $post_type || ! post_type_exists( $post_type ) ) {
				continue;
			}
			try {
				Policy::assertReadablePostType( $post_type );
			} catch ( Throwable $error ) {
				$warnings[] = 'post_type_skipped_by_policy:' . $post_type;
				continue;
			}
			$result[] = $post_type;
		}

		$result = array_values( array_unique( $result ) );
		sort( $result, SORT_STRING );
		return $result;
	}

	private function inventoryStatuses( array $payload ): array {
		$requested = isset( $payload['post_statuses'] ) ? (array) $payload['post_statuses'] : array( 'publish', 'draft', 'pending', 'private', 'future' );
		$result    = array();
		foreach ( $requested as $status ) {
			$status = sanitize_key( (string) $status );
			if ( '' !== $status && get_post_status_object( $status ) ) {
				$result[] = $status;
			}
		}
		return ! empty( $result ) ? array_values( array_unique( $result ) ) : array( 'publish' );
	}

	private function elementorData( int $postId, array &$warnings ): ?array {
		$raw = get_post_meta( $postId, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$warnings[] = 'invalid_elementor_data:' . $postId;
			return null;
		}
		return $decoded;
	}

	private function collectElementUsage( array $elements, array &$widgets, array &$elementTypes, array &$families ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$element_type = isset( $element['elType'] ) ? (string) $element['elType'] : '';
			if ( 'widget' === $elementType && isset( $element['widgetType'] ) && '' !== (string) $element['widgetType'] ) {
				$widget_name           = (string) $element['widgetType'];
				$widgets[ $widget_name ] = isset( $widgets[ $widget_name ] ) ? $widgets[ $widget_name ] + 1 : 1;
			} elseif ( '' !== $element_type ) {
				$elementTypes[ $element_type ] = isset( $elementTypes[ $element_type ] ) ? $elementTypes[ $elementType ] + 1 : 1;
			}

			if ( 'section' === $element_type || 'column' === $element_type ) {
				$families['legacy'] = true;
			} elseif ( 'container' === $element_type ) {
				$families['container'] = true;
			} elseif ( 0 === strpos( $elementType, 'e-' ) ) {
				$families['atomic'] = true;
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collectElementUsage( $element['elements'], $widgets, $elementTypes, $families );
			}
		}

		ksort( $widgets, SORT_STRING );
		ksort( $elementTypes, SORT_STRING );
	}

	private function architectureLabel( array $families ): string {
		$active = array_keys( array_filter( $families ) );
		if ( count( $active ) > 1 ) {
			return 'mixed';
		}
		return isset( $active[0] ) ? (string) $active[0] : 'widget-only';
	}

	private function mergeUsageIntoWidgets( array $registeredWidgets, array $usedWidgets ): array {
		foreach ( $registeredWidgets as $name => &$widget ) {
			$widget['usage'] = isset( $usedWidgets[ $name ] )
				? $usedWidgets[ $name ]
				: array( 'instances' => 0, 'documents' => 0, 'document_ids' => array() );
		}
		unset( $widget );
		return $registeredWidgets;
	}
}
