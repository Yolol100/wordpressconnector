<?php
/**
 * Canonical public method implementations for ElementorAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Json;
use Webactueel\WordPressConnector\Support\Input;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait ElementorAdapterActions {
	/**
	 * Create document for the Elementor adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 * @throws \Throwable If the operation cannot be completed safely.
	 */
	public function create_document( array $payload, array $context ): array {
		$post_type = isset( $payload['post_type'] ) ? sanitize_key( (string) $payload['post_type'] ) : 'page';
		if ( ! post_type_exists( $post_type ) ) {
			throw new RuntimeException( 'Unknown post type.' );
		}
		Policy::assertReadablePostType( $post_type );

		$data          = $this->normalize_data( $payload['data'] ?? array() );
		$page_settings = isset( $payload['page_settings'] ) && is_array( $payload['page_settings'] ) ? $payload['page_settings'] : array();
		$post_fields   = array(
			'post_type'    => $post_type,
			'post_status'  => isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : 'draft',
			'post_title'   => isset( $payload['title'] ) ? (string) $payload['title'] : 'Elementor document',
			'post_name'    => isset( $payload['slug'] ) ? sanitize_title( (string) $payload['slug'] ) : '',
			'post_content' => isset( $payload['content'] ) ? (string) $payload['content'] : '',
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_create'         => array(
					'post'      => $post_fields,
					'elementor' => $this->requested_meta( $payload, $data ),
				),
				'_current_fingerprint' => Fingerprint::make(
					array(
						'new'       => true,
						'post_type' => $post_type,
					)
				),
			);
		}

		$id = wp_insert_post( wp_slash( $post_fields ), true );
		if ( is_wp_error( $id ) || ! is_int( $id ) || $id <= 0 ) {
			throw new RuntimeException( 'WordPress Elementor document creation failed.' );
		}

		try {
			$this->prepare_document_meta( (int) $id, $payload );
			$saved_elements = $this->save_document_via_api( (int) $id, $data, $page_settings );
			$this->write_supplemental_meta( (int) $id, $payload );
			$this->clear_cache();
			$after = $this->snapshot( (int) $id );
			$this->assert_document_readback( $after, $saved_elements, $page_settings );
		} catch ( Throwable $error ) {
			wp_delete_post( (int) $id, true );
			throw $error;
		}

		return array(
			'document'  => $after,
			'_rollback' => array(
				'action'  => 'post.trash',
				'payload' => array( 'id' => (int) $id ),
			),
		);
	}

	/**
	 * Process the replace document operation in the Elementor adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 */
	public function replace_document( array $payload, array $context ): array {
		$post          = $this->post( $payload );
		$before        = $this->snapshot( (int) $post->ID );
		$data          = array_key_exists( 'data', $payload ) ? $this->normalize_data( $payload['data'] ) : $before['data'];
		$page_settings = array_key_exists( 'page_settings', $payload )
			? ( is_array( $payload['page_settings'] ) ? $payload['page_settings'] : array() )
			: $before['page_settings'];

		$after                  = $before;
		$after['data']          = $data;
		$after['page_settings'] = $page_settings;
		foreach ( array( 'template_type', 'conditions', 'edit_mode' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$after[ $field ] = $payload[ $field ];
			}
		}

		$result = array(
			'before'               => $before,
			'after'                => $after,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$result['after']     = $this->apply_existing_document_mutation(
			(int) $post->ID,
			$payload,
			$data,
			$page_settings,
			$before
		);
		$result['_rollback'] = array(
			'action'  => 'elementor.replace_document',
			'payload' => $this->rollback_payload( $before ),
		);
		return $result;
	}

	/**
	 * Process the patch element operation in the Elementor adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function patch_element( array $payload, array $context ): array {
		$post       = $this->post( $payload );
		$before     = $this->snapshot( (int) $post->ID );
		$element_id = isset( $payload['element_id'] ) ? (string) $payload['element_id'] : '';
		if ( '' === $element_id ) {
			throw new RuntimeException( 'element_id is required.' );
		}

		$data           = $before['data'];
		$element        =& $this->find_element( $data, $element_id );
		$before_element = $element;

		if ( isset( $payload['replace'] ) && is_array( $payload['replace'] ) ) {
			$replacement = $payload['replace'];
			if ( ! isset( $replacement['id'] ) ) {
				$replacement['id'] = $element_id;
			}
			$element = $replacement;
		} else {
			if ( isset( $payload['settings'] ) && is_array( $payload['settings'] ) ) {
				$replace_settings    = Input::bool( $payload, 'replace_settings' );
				$element['settings'] = $replace_settings
					? $payload['settings']
					: array_replace_recursive( isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array(), $payload['settings'] );
			}
			if ( array_key_exists( 'widgetType', $payload ) ) {
				$element['widgetType'] = (string) $payload['widgetType'];
			}
			if ( array_key_exists( 'elType', $payload ) ) {
				$element['elType'] = (string) $payload['elType'];
			}
		}

		$requested_element = $element;
		$result            = array(
			'post_id'              => (int) $post->ID,
			'element_id'           => $element_id,
			'before_element'       => $before_element,
			'after_element'        => $requested_element,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$readback         = $this->apply_existing_document_mutation(
			(int) $post->ID,
			array(),
			$data,
			$before['page_settings'],
			$before
		);
		$readback_data    = $readback['data'];
		$readback_element =& $this->find_element( $readback_data, $element_id );
		if ( ! $this->matches_requested_element_patch( $requested_element, $readback_element, $payload ) ) {
			$this->restore_snapshot( $before );
			throw new RuntimeException( 'Elementor element readback verification failed; previous snapshot restored.' );
		}

		$result['after_element'] = $readback_element;
		$result['_rollback']     = array(
			'action'  => 'elementor.replace_document',
			'payload' => $this->rollback_payload( $before ),
		);
		return $result;
	}

	/**
	 * Inspect header links for the Elementor adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function inspect_header_links( array $payload ): array {
		$id     = isset( $payload['post_id'] ) && is_int( $payload['post_id'] ) ? $payload['post_id'] : 0;
		$checks = isset( $payload['checks'] ) && is_array( $payload['checks'] ) ? $payload['checks'] : array();
		if ( $id < 1 || ! $checks || count( $checks ) > 4 ) {
			throw new RuntimeException( 'Header diagnostics require an exact target and 1-4 checks.' );
		}
		$seen = array();
		foreach ( $checks as $check ) {
			$element_id = is_array( $check ) ? ( $check['element_id'] ?? null ) : null;
			$url        = is_array( $check ) ? ( $check['from'] ?? null ) : null;
			if ( ! is_array( $check ) || array_diff( array_keys( $check ), array( 'element_id', 'from' ) )
				|| ! is_string( $element_id ) || ! preg_match( '/^[A-Za-z0-9_-]{5,64}\\z/', $element_id )
				|| isset( $seen[ $element_id ] ) || ! is_string( $url )
				|| ! $this->same_site_header_url( $url, false ) ) {
				throw new RuntimeException( 'Header diagnostic widget or URL is not allowed.' );
			}
			$seen[ $element_id ] = true;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'elementor_library' !== $post->post_type
			|| 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return array(
				'post_id'     => $id,
				'eligible'    => false,
				'reason_code' => 'not_published_elementor_library',
				'checks'      => array(),
			);
		}
		$can_edit = current_user_can( 'edit_post', $id );
		$snapshot = $this->snapshot( $id );
		if ( 'header' !== $snapshot['template_type'] ) {
			return array(
				'post_id'     => $id,
				'eligible'    => false,
				'reason_code' => 'not_header_template',
				'checks'      => array(),
			);
		}
		$matches = array();
		foreach ( $checks as $check ) {
			$element_id    = $check['element_id'];
			$matches_count = 0;
			$is_icon_list  = false;
			try {
				$element      = $this->find_element( $snapshot['data'], $element_id );
				$is_icon_list = ( $element['widgetType'] ?? '' ) === 'icon-list'
					&& isset( $element['settings']['icon_list'] ) && is_array( $element['settings']['icon_list'] );
				if ( $is_icon_list ) {
					foreach ( $element['settings']['icon_list'] as $item ) {
						if ( is_array( $item ) && ( $item['link']['url'] ?? null ) === $check['from'] ) {
							++$matches_count;
						}
					}
				}
			} catch ( RuntimeException $error ) {
				// A missing element contributes zero matches and does not affect other checks.
			}
			$matches[] = array(
				'element_id'        => $element_id,
				'is_icon_list'      => $is_icon_list,
				'exact_url_matches' => min( 2, $matches_count ),
			);
		}
		$eligible = $can_edit;
		foreach ( $matches as $match ) {
			$eligible = $eligible && $match['is_icon_list'] && 1 === $match['exact_url_matches'];
		}
		return array(
			'post_id'              => $id,
			'eligible'             => $eligible,
			'reason_code'          => ! $can_edit ? 'edit_post_denied' : ( ! $eligible ? 'widget_or_source_mismatch' : 'ready' ),
			'checks'               => $matches,
			'document_fingerprint' => Fingerprint::make( $snapshot ),
		);
	}

	/**
	 * Process the replace header links operation in the Elementor adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function replace_header_links( array $payload, array $context ): array {
		$id   = isset( $payload['post_id'] ) && is_int( $payload['post_id'] ) ? $payload['post_id'] : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || 'elementor_library' !== $post->post_type
			|| 'publish' !== $post->post_status || '' !== (string) $post->post_password
			|| ! current_user_can( 'edit_post', $id ) ) {
			throw new RuntimeException( 'Elementor header target is unavailable or not editable.' );
		}
		$before = $this->snapshot( $id );
		if ( 'header' !== $before['template_type'] ) {
			throw new RuntimeException( 'Only an existing published Elementor header template may be repaired.' );
		}
		$changes = $payload['changes'] ?? null;
		if ( ! is_array( $changes ) || ! $changes || count( $changes ) > 4 ) {
			throw new RuntimeException( 'Header link repair requires one to four exact link replacements.' );
		}

		$data    = $before['data'];
		$results = array();
		$seen    = array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || array_diff( array_keys( $change ), array( 'element_id', 'from', 'to' ) ) ) {
				throw new RuntimeException( 'Invalid Elementor header link replacement keys.' );
			}
			$element_id = $change['element_id'] ?? '';
			$from       = $change['from'] ?? null;
			$to         = $change['to'] ?? null;
			if ( ! is_string( $element_id ) || ! preg_match( '/^[A-Za-z0-9_-]{5,64}\\z/', $element_id )
				|| isset( $seen[ $element_id ] ) || ! is_string( $from ) || ! is_string( $to )
				|| $from === $to || ! $this->same_site_header_url( $from, false )
				|| ! $this->same_site_header_url( $to, true ) ) {
				throw new RuntimeException( 'Header link target, source or destination is not allowed.' );
			}
			$seen[ $element_id ] = true;
			$element             =& $this->find_element( $data, $element_id );
			if ( ( $element['widgetType'] ?? '' ) !== 'icon-list'
				|| ! isset( $element['settings']['icon_list'] )
				|| ! is_array( $element['settings']['icon_list'] ) ) {
				throw new RuntimeException( 'Header link target is not an Icon List widget.' );
			}
			$matches = 0;
			foreach ( $element['settings']['icon_list'] as &$item ) {
				if ( is_array( $item ) && isset( $item['link']['url'] ) && $item['link']['url'] === $from ) {
					$item['link']['url'] = $to;
					++$matches;
				}
			}
			unset( $item, $element );
			if ( 1 !== $matches ) {
				throw new RuntimeException( 'Header link source URL must appear exactly once in the requested widget.' );
			}
			$results[] = array(
				'element_id' => $element_id,
				'from'       => $from,
				'to'         => $to,
			);
		}
		$result = array(
			'post_id'              => $id,
			'changes'              => $results,
			'readback_verified'    => null,
			'before_fingerprint'   => Fingerprint::make( $before ),
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$after                       = $this->apply_existing_document_mutation(
			$id,
			array(),
			$data,
			$before['page_settings'],
			$before
		);
		$result['readback_verified'] = true;
		$result['after_fingerprint'] = Fingerprint::make( $after );
		return $result;
	}
}
