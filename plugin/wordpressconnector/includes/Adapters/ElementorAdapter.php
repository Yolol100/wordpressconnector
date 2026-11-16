<?php
/**
 * Registers and handles Elementor connector actions.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Json;
use Webactueel\WordPressConnector\Support\Input;

/**
 * Registers and handles Elementor connector actions.
 */
final class ElementorAdapter {
	/**
	 * Register the Elementor adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register( 'elementor.inspect', array( $this, 'inspect' ), array( 'description' => 'Read Elementor document JSON and document metadata.' ) );
		$registry->register(
			'elementor.create_document',
			array( $this, 'createDocument' ),
			array(
				'mutation'    => true,
				'description' => 'Create a page, post, product or Elementor library document through the Elementor document API.',
			)
		);
		$registry->register(
			'elementor.replace_document',
			array( $this, 'replaceDocument' ),
			array(
				'mutation'    => true,
				'description' => 'Replace Elementor elements/settings through the Elementor document API.',
			)
		);
		$registry->register(
			'elementor.patch_element',
			array( $this, 'patchElement' ),
			array(
				'mutation'    => true,
				'description' => 'Patch an Elementor element by stable element id and save through the Elementor document API.',
			)
		);
		$registry->register(
			'elementor.regenerate',
			array( $this, 'regenerate' ),
			array(
				'mutation'    => true,
				'description' => 'Clear Elementor generated CSS/files cache.',
			)
		);
		$registry->register(
			'elementor.header_links.replace',
			array( $this, 'replaceHeaderLinks' ),
			array(
				'mutation'    => true,
				'capability'  => 'edit_theme_options',
				'description' => 'Replace exact same-site Icon List links in one published Elementor header.',
			)
		);
		$registry->register( 'elementor.header_links.inspect', array( $this, 'inspectHeaderLinks' ), array( 'description' => 'Read non-sensitive eligibility and exact-match counts for header Icon List links.' ) );
	}

	/**
	 * Process the inspect operation in the Elementor adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Operation result.
	 */
	public function inspect( array $payload ): array {
		$post = $this->post( $payload );
		Policy::assertPostReadable( $post );
		$snapshot = $this->snapshot( (int) $post->ID );
		return array(
			'document'    => $snapshot,
			'fingerprint' => Fingerprint::make( $snapshot ),
		);
	}

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
	public function createDocument( array $payload, array $context ): array {
		$post_type = isset( $payload['post_type'] ) ? sanitize_key( (string) $payload['post_type'] ) : 'page';
		if ( ! post_type_exists( $post_type ) ) {
			throw new RuntimeException( 'Unknown post type: ' . $post_type );
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
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
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
	public function replaceDocument( array $payload, array $context ): array {
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
	public function patchElement( array $payload, array $context ): array {
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
	 * Process the regenerate operation in the Elementor adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function regenerate( array $payload, array $context ): array {
		if ( ! class_exists( 'Elementor\\Plugin' ) ) {
			throw new RuntimeException( 'Elementor is not active.' );
		}
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_clear_elementor_cache' => true,
				'_current_fingerprint'        => Fingerprint::make( array( 'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'active' ) ),
			);
		}
		$this->clear_cache();
		return array(
			'cleared'            => true,
			'rollback_supported' => false,
		);
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
	public function inspectHeaderLinks( array $payload ): array {
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
				$element      =& $this->find_element( $snapshot['data'], $element_id );
				$is_icon_list = ( $element['widgetType'] ?? '' ) === 'icon-list'
					&& isset( $element['settings']['icon_list'] ) && is_array( $element['settings']['icon_list'] );
				if ( $is_icon_list ) {
					foreach ( $element['settings']['icon_list'] as $item ) {
						if ( is_array( $item ) && ( $item['link']['url'] ?? null ) === $check['from'] ) {
							++$matches_count;
						}
					}
				}
				unset( $element );
			} catch ( RuntimeException $error ) {
				unset( $element ); // Avoid retaining a by-reference handle after an unsuccessful lookup.
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
	public function replaceHeaderLinks( array $payload, array $context ): array {
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

	/**
	 * Process the same site header url operation in the Elementor adapter.
	 *
	 * @param string $url Input for the operation.
	 * @param bool   $destination Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function same_site_header_url( string $url, bool $destination ): bool {
		if ( strlen( $url ) > 500 || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home )
			|| ( $parts['scheme'] ?? '' ) !== 'https'
			|| strtolower( (string) ( $parts['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) )
			|| isset( $parts['user'] ) || isset( $parts['pass'] )
			|| ! isset( $parts['path'] ) || 0 !== strpos( (string) $parts['path'], '/' ) ) {
			return false;
		}
		return ! $destination || ( ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) );
	}

	/**
	 * Process the post operation in the Elementor adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return \WP_Post Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function post( array $payload ): \WP_Post {
		$post = get_post( isset( $payload['id'] ) ? (int) $payload['id'] : 0 );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Post not found.' );
		}
		Policy::assertReadablePostType( (string) $post->post_type );
		return $post;
	}

	/**
	 * Process the snapshot operation in the Elementor adapter.
	 *
	 * @param int $post_id Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function snapshot( int $post_id ): array {
		$document = $this->document_or_null( $post_id );
		if ( $document ) {
			// Verify the committed document, not the editor document instance which may
			// keep an earlier in-memory elements tree after save() in this request.
			$stored_data   = get_post_meta( $post_id, '_elementor_data', true );
			$data          = $this->normalize_data( $stored_data ? $stored_data : array() );
			$settings      = method_exists( $document, 'get_db_document_settings' ) ? $document->get_db_document_settings() : array();
			$settings      = is_array( $settings ) ? $settings : array();
			$document_type = method_exists( $document, 'get_name' ) ? (string) $document->get_name() : '';
		} else {
			$raw           = get_post_meta( $post_id, '_elementor_data', true );
			$data          = $this->normalize_data( $raw ? $raw : array() );
			$settings      = $this->normalize_meta_array( get_post_meta( $post_id, '_elementor_page_settings', true ) );
			$document_type = '';
		}

		// Elementor stores the WordPress page layout outside _elementor_page_settings.
		// Retain it in snapshots so a failed import can restore that layout too.
		$saved_page_template = (string) get_post_meta( $post_id, '_wp_page_template', true );
		if ( '' !== $saved_page_template || 'page' === get_post_type( $post_id ) ) {
			$settings['template'] = '' !== $saved_page_template ? $saved_page_template : 'default';
		}

		return array(
			'post_id'           => $post_id,
			'data'              => $data,
			'document_type'     => $document_type,
			'edit_mode'         => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
			'template_type'     => (string) get_post_meta( $post_id, '_elementor_template_type', true ),
			'page_settings'     => $settings,
			'conditions'        => $this->normalize_meta_array( get_post_meta( $post_id, '_elementor_conditions', true ) ),
			'elementor_version' => (string) get_post_meta( $post_id, '_elementor_version', true ),
		);
	}

	/**
	 * Process the requested meta operation in the Elementor adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $data Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function requested_meta( array $payload, array $data ): array {
		return array(
			'data'          => $data,
			'document_type' => isset( $payload['document_type'] ) ? (string) $payload['document_type'] : '',
			'edit_mode'     => isset( $payload['edit_mode'] ) ? (string) $payload['edit_mode'] : 'builder',
			'template_type' => isset( $payload['template_type'] ) ? (string) $payload['template_type'] : '',
			'page_settings' => isset( $payload['page_settings'] ) && is_array( $payload['page_settings'] ) ? $payload['page_settings'] : array(),
			'conditions'    => isset( $payload['conditions'] ) && is_array( $payload['conditions'] ) ? $payload['conditions'] : array(),
		);
	}

	/**
	 * Prepare document meta for the Elementor adapter.
	 *
	 * @param int   $post_id Input for the operation.
	 * @param array $payload Requested action payload.
	 */
	private function prepare_document_meta( int $post_id, array $payload ): void {
		if ( array_key_exists( 'template_type', $payload ) ) {
			update_post_meta( $post_id, '_elementor_template_type', (string) $payload['template_type'] );
		}
		update_post_meta( $post_id, '_elementor_edit_mode', isset( $payload['edit_mode'] ) ? (string) $payload['edit_mode'] : 'builder' );
	}

	/**
	 * Write supplemental meta for the Elementor adapter.
	 *
	 * @param int   $post_id Input for the operation.
	 * @param array $payload Requested action payload.
	 */
	private function write_supplemental_meta( int $post_id, array $payload ): void {
		if ( array_key_exists( 'template_type', $payload ) ) {
			update_post_meta( $post_id, '_elementor_template_type', (string) $payload['template_type'] );
		}
		if ( array_key_exists( 'conditions', $payload ) ) {
			update_post_meta( $post_id, '_elementor_conditions', is_array( $payload['conditions'] ) ? $payload['conditions'] : array() );
		}
		if ( array_key_exists( 'edit_mode', $payload ) ) {
			update_post_meta( $post_id, '_elementor_edit_mode', (string) $payload['edit_mode'] );
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
	}

	/**
	 * Process the save document via api operation in the Elementor adapter.
	 *
	 * @param int   $post_id Input for the operation.
	 * @param array $data Input for the operation.
	 * @param array $page_settings Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function save_document_via_api( int $post_id, array $data, array $page_settings ): array {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && ! current_user_can( 'edit_post', $post_id ) ) {
			throw new RuntimeException( 'You are not allowed to edit this Elementor document.' );
		}

		$document            = $this->document( $post_id );
		$normalized_elements = null;
		// Elementor normalizes widgets/containers through get_data_for_save().
		// Its documented after_save event carries the canonical data submitted
		// to update_metadata(). Compare that against the separately read DB data.
		$listener = static function ( $saved_id, $elements ) use ( $post_id, &$normalized_elements ): void {
			if ( (int) $saved_id === $post_id && is_array( $elements ) ) {
				$normalized_elements = $elements;
			}
		};
		add_action( 'elementor/editor/after_save', $listener, 10, 2 );
		try {
			$result = $document->save(
				array(
					'elements' => $data,
					'settings' => $page_settings,
				)
			);
		} finally {
			remove_action( 'elementor/editor/after_save', $listener, 10 );
		}

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		if ( false === $result || null === $result ) {
			throw new RuntimeException( 'Elementor rejected the document save.' );
		}
		if ( ! is_array( $normalized_elements ) ) {
			throw new RuntimeException( 'Elementor did not expose normalized saved elements for verification.' );
		}

		clean_post_cache( $post_id );
		return $normalized_elements;
	}

	/**
	 * Process the matches requested element patch operation in the Elementor adapter.
	 *
	 * @param array $requested Input for the operation.
	 * @param array $actual Input for the operation.
	 * @param array $payload Requested action payload.
	 *
	 * @return bool Operation result.
	 */
	private function matches_requested_element_patch( array $requested, array $actual, array $payload ): bool {
		foreach ( array( 'id', 'elType', 'widgetType' ) as $field ) {
			if ( isset( $requested[ $field ] ) && ( ! isset( $actual[ $field ] ) || (string) $requested[ $field ] !== (string) $actual[ $field ] ) ) {
				return false;
			}
		}
		$settings = isset( $payload['replace'] ) && is_array( $payload['replace'] )
			? ( $payload['replace']['settings'] ?? array() )
			: ( $payload['settings'] ?? array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		foreach ( $settings as $key => $value ) {
			if ( ! isset( $actual['settings'] ) || ! is_array( $actual['settings'] )
				|| ! array_key_exists( $key, $actual['settings'] )
				|| ! hash_equals( Fingerprint::make( $value ), Fingerprint::make( $actual['settings'][ $key ] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Process the apply existing document mutation operation in the Elementor adapter.
	 *
	 * @param int   $post_id Input for the operation.
	 * @param array $payload Requested action payload.
	 * @param array $data Input for the operation.
	 * @param array $page_settings Input for the operation.
	 * @param array $before Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function apply_existing_document_mutation( int $post_id, array $payload, array $data, array $page_settings, array $before ): array {
		try {
			$saved_elements = $this->save_document_via_api( $post_id, $data, $page_settings );
			$this->write_supplemental_meta( $post_id, $payload );
			$this->clear_cache();
			$after = $this->snapshot( $post_id );
			$this->assert_document_readback( $after, $saved_elements, $page_settings );
			return $after;
		} catch ( Throwable $error ) {
			try {
				$this->restore_snapshot( $before );
			} catch ( Throwable $rollback_error ) {
				throw new RuntimeException(
					'Elementor mutation failed and automatic rollback also failed: ' . $error->getMessage() . ' | rollback: ' . $rollback_error->getMessage(),
					0,
					$error
				);
			}
			throw new RuntimeException( 'Elementor mutation failed; previous snapshot restored: ' . $error->getMessage(), 0, $error );
		}
	}

	/**
	 * Restore snapshot for the Elementor adapter.
	 *
	 * @param array $snapshot Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function restore_snapshot( array $snapshot ): void {
		$post_id = isset( $snapshot['post_id'] ) ? (int) $snapshot['post_id'] : 0;
		if ( $post_id < 1 ) {
			throw new RuntimeException( 'Rollback snapshot is missing post_id.' );
		}

		$payload = $this->rollback_payload( $snapshot );
		$this->prepare_document_meta( $post_id, $payload );
		$restored_elements = $this->save_document_via_api( $post_id, $snapshot['data'], $snapshot['page_settings'] );
		$this->write_supplemental_meta( $post_id, $payload );
		$this->clear_cache();

		$restored = $this->snapshot( $post_id );
		$this->assert_document_readback( $restored, $restored_elements, $snapshot['page_settings'] );
		foreach ( array( 'edit_mode', 'template_type', 'conditions' ) as $field ) {
			if ( ! hash_equals( Fingerprint::make( $snapshot[ $field ] ), Fingerprint::make( $restored[ $field ] ) ) ) {
				throw new RuntimeException( 'Elementor rollback readback failed for: ' . $field );
			}
		}
	}

	/**
	 * Process the document operation in the Elementor adapter.
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
		if ( ! is_object( $document ) || ! method_exists( $document, 'save' ) || ! method_exists( $document, 'get_elements_data' ) ) {
			throw new RuntimeException( 'This item is not an editable Elementor document.' );
		}

		return $document;
	}

	/**
	 * Process the document or null operation in the Elementor adapter.
	 *
	 * @param int $post_id Input for the operation.
	 *
	 * @return object|null Operation result.
	 */
	private function document_or_null( int $post_id ): ?object {
		try {
			return $this->document( $post_id );
		} catch ( Throwable $error ) {
			return null;
		}
	}

	/**
	 * Ensure document readback for the Elementor adapter.
	 *
	 * @param array $actual Input for the operation.
	 * @param array $expected_data Input for the operation.
	 * @param array $expected_settings Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_document_readback( array $actual, array $expected_data, array $expected_settings ): void {
		if ( ! hash_equals( Fingerprint::make( $expected_data ), Fingerprint::make( $actual['data'] ) ) ) {
			throw new RuntimeException( 'Elementor document readback verification failed for elements.' );
		}

		foreach ( $expected_settings as $key => $expected_value ) {
			if ( 'template' === $key ) {
				// Elementor's Page Settings Manager saves this field in WordPress'
				// _wp_page_template meta, not in _elementor_page_settings.
				$saved = (string) get_post_meta( (int) $actual['post_id'], '_wp_page_template', true );
				$saved = '' !== $saved ? $saved : 'default';
				if ( ! is_string( $expected_value ) || ! hash_equals( $expected_value, $saved ) ) {
					throw new RuntimeException( 'Elementor page template readback verification failed.' );
				}
				continue;
			}
			if ( ! array_key_exists( $key, $actual['page_settings'] ) ) {
				throw new RuntimeException( 'Elementor document readback is missing page setting: ' . (string) $key );
			}
			if ( ! hash_equals( Fingerprint::make( $expected_value ), Fingerprint::make( $actual['page_settings'][ $key ] ) ) ) {
				throw new RuntimeException( 'Elementor page setting readback verification failed: ' . (string) $key );
			}
		}
	}

	/**
	 * Normalize data for the Elementor adapter.
	 *
	 * @param mixed $data Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function normalize_data( $data ): array {
		if ( is_string( $data ) ) {
			$trimmed = trim( $data );
			if ( '' === $trimmed ) {
				return array();
			}
			return Json::decode( $trimmed );
		}
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Elementor data must be an array or JSON string.' );
		}
		return $data;
	}

	/**
	 * Normalize meta array for the Elementor adapter.
	 *
	 * @param mixed $value Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function normalize_meta_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * Find element for the Elementor adapter.
	 *
	 * @param array  $elements Value updated by reference.
	 * @param string $element_id Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function &find_element( array &$elements, string $element_id ): array {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && (string) $element['id'] === $element_id ) {
				return $element;
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				try {
					return $this->find_element( $element['elements'], $element_id );
				} catch ( RuntimeException $error ) {
					continue; // Descendant not found; inspect the next sibling.
				}
			}
		}
		unset( $element );
		throw new RuntimeException( 'Elementor element not found: ' . $element_id );
	}

	/**
	 * Process the rollback payload operation in the Elementor adapter.
	 *
	 * @param array $snapshot Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function rollback_payload( array $snapshot ): array {
		return array(
			'id'            => $snapshot['post_id'],
			'data'          => $snapshot['data'],
			'edit_mode'     => $snapshot['edit_mode'],
			'template_type' => $snapshot['template_type'],
			'page_settings' => $snapshot['page_settings'],
			'conditions'    => $snapshot['conditions'],
		);
	}

	/**
	 * Process the clear cache operation in the Elementor adapter.
	 */
	private function clear_cache(): void {
		if ( ! class_exists( 'Elementor\\Plugin' ) ) {
			return;
		}

		$plugin = \Elementor\Plugin::$instance;
		if ( isset( $plugin->files_manager ) && is_object( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
			$plugin->files_manager->clear_cache();
		}
	}
}
