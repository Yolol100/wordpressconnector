<?php
/**
 * Registers and handles Site Editor connector actions.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Canonical implementations of the public methods.
 *
 * The host class retains legacy PHP method aliases for compatibility.
 */
trait SiteEditorActions {
	/**
	 * List templates for the SiteEditor adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function list_templates( array $payload = array() ): array {
		$this->keys( $payload, array( 'kind', 'page' ) );
		$kind = $this->kind( $payload );
		$page = $payload['page'] ?? 1;
		if ( ! is_int( $page ) || $page < 1 || $page > 100 ) {
			throw new RuntimeException( 'Site Editor page must be an integer between 1 and 100.' );
		}
		$response = $this->request(
			'GET',
			$this->route( $kind ),
			array(
				'context'  => 'edit',
				'per_page' => 20,
				'page'     => $page,
			)
		);
		$data     = $this->data( $response );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'WordPress did not return template records.' );
		}
		$rows = array();
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || ( $item['theme'] ?? null ) !== get_stylesheet() ) {
				continue;
			}
			$rows[] = array(
				'id'         => (string) ( $item['id'] ?? '' ),
				'slug'       => (string) ( $item['slug'] ?? '' ),
				'title'      => $this->title( $item['title'] ?? '' ),
				'source'     => (string) ( $item['source'] ?? '' ),
				'customized' => 'custom' === ( $item['source'] ?? '' ) && (int) ( $item['wp_id'] ?? 0 ) > 0,
			);
		}
		return array(
			'kind'     => $kind,
			'theme'    => get_stylesheet(),
			'page'     => $page,
			'items'    => $rows,
			'count'    => count( $rows ),
			'per_page' => 20,
		);
	}

	/**
	 * Process the global styles operation in the SiteEditor adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function global_styles( array $payload = array() ): array {
		$this->keys( $payload, array() );
		$theme = get_stylesheet();
		if ( ! is_string( $theme ) || ! preg_match( '/^[A-Za-z0-9_-]{1,80}\z/', $theme ) ) {
			throw new RuntimeException( 'Global styles for this theme need a dedicated supported theme identity.' );
		}
		$result = $this->data( $this->request( 'GET', '/wp/v2/global-styles/themes/' . $theme ) );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( 'WordPress did not return global styles.' );
		}
		$settings = $result['settings'] ?? array();
		$styles   = $result['styles'] ?? array();
		if ( ! is_array( $settings ) || ! is_array( $styles ) ) {
			throw new RuntimeException( 'WordPress global styles format is unsupported.' );
		}
		$json = wp_json_encode( array( $settings, $styles ) );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_CONTENT_BYTES ) {
			throw new RuntimeException( 'Global styles exceed the bounded response size.' );
		}
		return array(
			'theme'       => $theme,
			'settings'    => $settings,
			'styles'      => $styles,
			'fingerprint' => Fingerprint::make( array( $settings, $styles ) ),
		);
	}
}
