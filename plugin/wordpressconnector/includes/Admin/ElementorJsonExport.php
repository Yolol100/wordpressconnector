<?php
/**
 * Supports Elementor Json Export workflows in WordPress administration.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Admin;

use RuntimeException;
use Throwable;
/**
 * Supports Elementor Json Export workflows in WordPress administration.
 */
final class ElementorJsonExport {
	private const ACTION         = 'wpconnector_export_elementor_json';
	private const POST_TYPES     = array( 'page', 'post', 'elementor_library' );
	private const BUNDLE_FORMAT  = 'wordpressconnector/elementor-site-parts-bundle';
	private const BUNDLE_VERSION = 1;

	/**
	 * Register the ElementorJsonExport component actions and handlers.
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'page_row_actions', array( $this, 'rowActions' ), 99, 2 );
		add_filter( 'post_row_actions', array( $this, 'rowActions' ), 99, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
	}

	/**
	 * Process the row actions operation in the ElementorJsonExport component.
	 *
	 * @param array    $actions Input for the operation.
	 * @param \WP_Post $post Input for the operation.
	 *
	 * @return array Operation result.
	 */
	public function rowActions( array $actions, \WP_Post $post ): array {
		if ( ! $this->supports_post_type( (string) $post->post_type ) || ! current_user_can( 'edit_post', (int) $post->ID ) || ! $this->is_elementor_document( (int) $post->ID ) ) {
			return $actions;
		}
		if ( 'elementor_library' === (string) $post->post_type && isset( $actions['export-template'] ) ) {
			return $actions;
		}
		$actions['wpconnector_export_elementor_json'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $this->export_url( (int) $post->ID, false ) ), esc_html__( 'Export Elementor JSON', 'wordpressconnector' ) );
		if ( in_array( (string) $post->post_type, array( 'page', 'post' ), true ) && class_exists( 'ElementorPro\\Modules\\ThemeBuilder\\Module' ) ) {
			$actions['wpconnector_export_elementor_site_parts'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $this->export_url( (int) $post->ID, true ) ), esc_html__( 'Export Elementor + Site Parts', 'wordpressconnector' ) );
		}
		return $actions;
	}

	/**
	 * Process the download operation in the ElementorJsonExport component.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function download(): void {
		$post_id            = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
		$include_site_parts = isset( $_GET['include_site_parts'] ) && is_string( $_GET['include_site_parts'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['include_site_parts'] ) );
		if ( $post_id < 1 ) {
			wp_die( esc_html__( 'Invalid Elementor document.', 'wordpressconnector' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( self::ACTION . '_' . $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! $this->supports_post_type( (string) $post->post_type ) ) {
			wp_die( esc_html__( 'This item cannot be exported as Elementor JSON.', 'wordpressconnector' ), '', array( 'response' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to export this Elementor document.', 'wordpressconnector' ), '', array( 'response' => 403 ) );
		}
		if ( $include_site_parts && ! in_array( (string) $post->post_type, array( 'page', 'post' ), true ) ) {
			wp_die( esc_html__( 'Site-parts export is available only for pages and posts.', 'wordpressconnector' ), '', array( 'response' => 400 ) );
		}
		try {
			$document_payload = $this->export_payload( $this->document( $post_id ), $post );
			$payload          = $include_site_parts ? $this->bundle_with_site_parts( $post, $document_payload ) : $document_payload;
			$json             = wp_json_encode( $payload );
			if ( ! is_string( $json ) || '' === $json ) {
				throw new RuntimeException( 'WordPress could not encode the Elementor export as JSON.' );
			}
		} catch ( RuntimeException $error ) {
			wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 400 ) );
		} catch ( Throwable $error ) {
			wp_die( esc_html__( 'The Elementor JSON export could not be completed.', 'wordpressconnector' ), '', array( 'response' => 500 ) );
		}
		$slug     = '' !== (string) $post->post_name ? (string) $post->post_name : (string) $post->post_type . '-' . $post_id;
		$filename = sanitize_file_name( $slug . ( $include_site_parts ? '-elementor-with-site-parts.json' : '-elementor.json' ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Content-Length: ' . strlen( $json ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json;
		exit;
	}

	/**
	 * Process the export url operation in the ElementorJsonExport component.
	 *
	 * @param int  $post_id Input for the operation.
	 * @param bool $include_site_parts Input for the operation.
	 *
	 * @return string Operation result.
	 */
	private function export_url( int $post_id, bool $include_site_parts ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'             => self::ACTION,
					'post_id'            => $post_id,
					'include_site_parts' => $include_site_parts ? 1 : 0,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $post_id
		);
	}

	/**
	 * Process the export payload operation in the ElementorJsonExport component.
	 *
	 * @param object   $document Input for the operation.
	 * @param \WP_Post $post Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function export_payload( object $document, \WP_Post $post ): array {
		if ( ! method_exists( $document, 'get_export_data' ) ) {
			throw new RuntimeException( 'This Elementor version does not expose document export data.' );
		}
		$template_data = $document->get_export_data();
		if ( ! is_array( $template_data ) ) {
			throw new RuntimeException( 'Elementor returned invalid export data.' );
		}
		$content  = isset( $template_data['content'] ) && is_array( $template_data['content'] ) ? $template_data['content'] : array();
		$settings = isset( $template_data['settings'] ) && is_array( $template_data['settings'] ) ? $template_data['settings'] : array();
		if ( empty( $content ) ) {
			throw new RuntimeException( 'The Elementor document is empty.' );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Elementor's existing export-elements filter must retain its hook name.
		$content = apply_filters( 'elementor/template_library/sources/local/export/elements', $content );
		if ( ! is_array( $content ) ) {
			throw new RuntimeException( 'Elementor export filters returned invalid document content.' );
		}
		$type = 'elementor_library' === (string) $post->post_type ? (string) get_post_meta( (int) $post->ID, '_elementor_template_type', true ) : '';
		if ( '' === $type && method_exists( $document, 'get_name' ) ) {
			$type = (string) $document->get_name();
		}
		if ( '' === $type ) {
			$type = 'page' === (string) $post->post_type ? 'wp-page' : ( 'post' === (string) $post->post_type ? 'wp-post' : 'page' );
		}
		$payload = array(
			'content'       => $content,
			'page_settings' => $settings,
			'version'       => class_exists( 'Elementor\\DB' ) ? (string) \Elementor\DB::DB_VERSION : '0.4',
			'title'         => (string) $post->post_title,
			'type'          => $type,
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Elementor's existing snapshot filter must retain its hook name.
		$snapshots = apply_filters( 'elementor/template_library/export/build_snapshots', array(), $content, (int) $post->ID, $payload );
		if ( is_array( $snapshots ) ) {
			if ( ! empty( $snapshots['global_classes'] ) ) {
				$payload['global_classes'] = $snapshots['global_classes'];
			}
			if ( ! empty( $snapshots['global_variables'] ) ) {
				$payload['global_variables'] = $snapshots['global_variables'];
			}
		}
		return $payload;
	}

	/**
	 * Process the bundle with site parts operation in the ElementorJsonExport component.
	 *
	 * @param \WP_Post $post Input for the operation.
	 * @param array    $document_payload Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function bundle_with_site_parts( \WP_Post $post, array $document_payload ): array {
		$parts = $this->site_parts_for_post( $post );
		return array(
			'format'         => self::BUNDLE_FORMAT,
			'bundle_version' => self::BUNDLE_VERSION,
			'source'         => array(
				'post_id'   => (int) $post->ID,
				'post_type' => (string) $post->post_type,
				'title'     => (string) $post->post_title,
			),
			'document'       => $document_payload,
			'header'         => isset( $parts['header']['payload'] ) ? $parts['header']['payload'] : null,
			'footer'         => isset( $parts['footer']['payload'] ) ? $parts['footer']['payload'] : null,
			'site_part_meta' => array(
				'header' => $this->site_part_meta( $parts['header'] ?? null ),
				'footer' => $this->site_part_meta( $parts['footer'] ?? null ),
			),
			'warnings'       => isset( $parts['warnings'] ) && is_array( $parts['warnings'] ) ? $parts['warnings'] : array(),
		);
	}

	/**
	 * Process the site parts for post operation in the ElementorJsonExport component.
	 *
	 * @param \WP_Post $source_post Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function site_parts_for_post( \WP_Post $source_post ): array {
		if ( ! class_exists( 'ElementorPro\\Modules\\ThemeBuilder\\Module' ) ) {
			return array(
				'header'   => null,
				'footer'   => null,
				'warnings' => array( 'Elementor Pro Theme Builder is not active.' ),
			);
		}
		try {
			$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			if ( ! is_object( $module ) || ! method_exists( $module, 'get_conditions_manager' ) ) {
				return $this->unsupported_site_parts();
			}
			$conditions = $module->get_conditions_manager();
			if ( ! is_object( $conditions ) || ! method_exists( $conditions, 'get_documents_for_location' ) ) {
				return $this->unsupported_site_parts();
			}
			$matches  = $this->with_singular_query(
				$source_post,
				static function () use ( $conditions ): array {
					return array(
						'header' => $conditions->get_documents_for_location( 'header' ),
						'footer' => $conditions->get_documents_for_location( 'footer' ),
					);
				}
			);
			$header   = $this->first_site_part( $matches['header'] ?? array(), 'header' );
			$footer   = $this->first_site_part( $matches['footer'] ?? array(), 'footer' );
			$warnings = array();
			if ( null === $header ) {
				$warnings[] = 'No matching Elementor Theme Builder header was found for this document.';
			}
			if ( null === $footer ) {
				$warnings[] = 'No matching Elementor Theme Builder footer was found for this document.';
			}
			return array(
				'header'   => $header,
				'footer'   => $footer,
				'warnings' => $warnings,
			);
		} catch ( Throwable $error ) {
			return $this->unsupported_site_parts( 'Elementor Pro Theme Builder could not resolve the matching site parts for this document.' );
		}
	}

	/**
	 * Process the first site part operation in the ElementorJsonExport component.
	 *
	 * @param mixed  $matches Input for the operation.
	 * @param string $location Input for the operation.
	 *
	 * @return array|null Operation result.
	 */
	private function first_site_part( $matches, string $location ): ?array {
		if ( ! is_array( $matches ) || empty( $matches ) ) {
			return null;
		}
		foreach ( $matches as $key => $document ) {
			$template_id = 0;
			$template    = null;
			if ( is_object( $document ) && method_exists( $document, 'get_post' ) ) {
				$template = $document->get_post();
				if ( $template instanceof \WP_Post ) {
					$template_id = (int) $template->ID;
				}
			} elseif ( is_numeric( $key ) ) {
				$template_id = (int) $key;
			} elseif ( is_numeric( $document ) ) {
				$template_id = (int) $document;
			}
			if ( $template_id < 1 ) {
				continue;
			}
			$template = $template instanceof \WP_Post ? $template : get_post( $template_id );
			if ( ! $template instanceof \WP_Post || ! current_user_can( 'edit_post', $template_id ) ) {
				continue;
			}
			$payload = $this->export_payload( $this->document( $template_id ), $template );
			if ( (string) ( $payload['type'] ?? '' ) !== $location ) {
				continue;
			}
			return array(
				'id'      => $template_id,
				'title'   => (string) $template->post_title,
				'payload' => $payload,
			);
		}
		return null;
	}

	/**
	 * Process the with singular query operation in the ElementorJsonExport component.
	 *
	 * @param \WP_Post $source_post Input for the operation.
	 * @param callable $callback Input for the operation.
	 *
	 * @return mixed Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function with_singular_query( \WP_Post $source_post, callable $callback ) {
		global $post, $wp_query, $wp_the_query;
		$previous_post      = $post ?? null;
		$previous_query     = $wp_query ?? null;
		$previous_the_query = $wp_the_query ?? null;
		$args               = array(
			'post_type'           => (string) $source_post->post_type,
			'post_status'         => 'any',
			'posts_per_page'      => 1,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( 'page' === (string) $source_post->post_type ) {
			$args['page_id'] = (int) $source_post->ID;
		} else {
			$args['p'] = (int) $source_post->ID;
		}
		$query = new \WP_Query( $args );
		if ( ! $query->have_posts() ) {
			throw new RuntimeException( 'The document context could not be prepared for Theme Builder conditions.' );
		}
		$wp_query     = $query;
		$wp_the_query = $query;
		$query->the_post();
		try {
			return $callback();
		} finally {
			$query->reset_postdata();
			$wp_query     = $previous_query;
			$wp_the_query = $previous_the_query;
			$post         = $previous_post;
			if ( $previous_post instanceof \WP_Post ) {
				setup_postdata( $previous_post );
			}
		}
	}

	/**
	 * Process the unsupported site parts operation in the ElementorJsonExport component.
	 *
	 * @param string $warning Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function unsupported_site_parts( string $warning = 'The active Elementor Pro version does not expose the Theme Builder condition API required for site-parts export.' ): array {
		return array(
			'header'   => null,
			'footer'   => null,
			'warnings' => array( $warning ),
		);
	}

	/**
	 * Process the site part meta operation in the ElementorJsonExport component.
	 *
	 * @param array|null $part Input for the operation.
	 *
	 * @return array|null Operation result.
	 */
	private function site_part_meta( ?array $part ): ?array {
		return null === $part ? null : array(
			'id'    => (int) ( $part['id'] ?? 0 ),
			'title' => (string) ( $part['title'] ?? '' ),
		);
	}

	/**
	 * Check whether elementor document for the ElementorJsonExport component.
	 *
	 * @param int $post_id Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function is_elementor_document( int $post_id ): bool {
		if ( 'builder' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return false;
		}
		try {
			$this->document( $post_id );
			return true;
		} catch ( RuntimeException $error ) {
			return false;
		}
	}

	/**
	 * Process the document operation in the ElementorJsonExport component.
	 *
	 * @param int $post_id Input for the operation.
	 *
	 * @return object Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function document( int $post_id ): object {
		if ( ! class_exists( 'Elementor\\Plugin' ) ) {
			throw new RuntimeException( 'Elementor is not active.' );
		}
		$plugin  = \Elementor\Plugin::$instance;
		$manager = isset( $plugin->documents ) ? $plugin->documents : null;
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get' ) ) {
			throw new RuntimeException( 'Elementor document manager is unavailable.' );
		}
		$document = $manager->get( $post_id );
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_elements_data' ) || ! method_exists( $document, 'get_export_data' ) ) {
			throw new RuntimeException( 'This item is not an exportable Elementor document.' );
		}
		return $document;
	}

	/**
	 * Check support for post type for the ElementorJsonExport component.
	 *
	 * @param string $post_type Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function supports_post_type( string $post_type ): bool {
		return in_array( $post_type, self::POST_TYPES, true );
	}
}
