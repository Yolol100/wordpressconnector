<?php
/**
 * Canonical public method implementations for ElementorJsonExport.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Admin;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Support\Input;
/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait ElementorJsonExportMethods {
	/**
	 * Process the row actions operation in the ElementorJsonExport component.
	 *
	 * @param array    $actions Input for the operation.
	 * @param \WP_Post $post Input for the operation.
	 *
	 * @return array Operation result.
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
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
}
