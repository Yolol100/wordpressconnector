<?php
/**
 * Native implementations of the Core connector action methods.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

/**
 * Native action implementations shared with the Core adapter's legacy PHP aliases.
 *
 * The host adapter owns the registry and validation helpers.
 */
trait CoreAdapterActions {
	/**
	 * Handle the post list connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function post_list( array $payload ): array {
		$post_type = isset( $payload['post_type'] ) ? $payload['post_type'] : 'post';
		$status    = isset( $payload['status'] ) ? $payload['status'] : ( Policy::publicRepositoryContext() ? 'publish' : 'any' );
		$per_page  = isset( $payload['per_page'] ) ? max( 1, min( 100, (int) $payload['per_page'] ) ) : 50;
		$page      = isset( $payload['page'] ) ? max( 1, (int) $payload['page'] ) : 1;

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				's'              => isset( $payload['search'] ) ? sanitize_text_field( (string) $payload['search'] ) : '',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => isset( $payload['orderby'] ) ? sanitize_key( (string) $payload['orderby'] ) : 'ID',
				'order'          => isset( $payload['order'] ) && 'ASC' === strtoupper( (string) $payload['order'] ) ? 'ASC' : 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			try {
				Policy::assertPostReadable( $post );
				$items[] = $this->post_snapshot( $post );
			} catch ( RuntimeException $error ) {
				continue;
			}
		}

		return array(
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
		);
	}

	/**
	 * Handle the post get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function post_get( array $payload ): array {
		$post = $this->require_post( $payload );
		Policy::assertPostReadable( $post );
		return array(
			'post'        => $this->post_snapshot( $post ),
			'fingerprint' => Fingerprint::make( $this->post_snapshot( $post ) ),
		);
	}

	/**
	 * Handle the post create connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function post_create( array $payload, array $context ): array {
		$fields = $this->sanitize_post_fields( $payload, false );
		if ( empty( $fields['post_type'] ) ) {
			$fields['post_type'] = 'post';
		}

		if ( ! post_type_exists( $fields['post_type'] ) ) {
			throw new RuntimeException( 'Unknown post type.' );
		}
		Policy::assertReadablePostType( $fields['post_type'] );

		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_create'         => $fields,
				'_current_fingerprint' => Fingerprint::make(
					array(
						'new'       => true,
						'post_type' => $fields['post_type'],
					)
				),
			);
		}

		$id = wp_insert_post( wp_slash( $fields ), true );
		if ( is_wp_error( $id ) || ! is_int( $id ) || $id <= 0 ) {
			throw new RuntimeException( 'WordPress post creation failed.' );
		}

		$post = get_post( (int) $id );
		return array(
			'post'      => $this->post_snapshot( $post ),
			'_rollback' => array(
				'action'  => 'post.trash',
				'payload' => array( 'id' => (int) $id ),
			),
		);
	}

	/**
	 * Handle the post update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function post_update( array $payload, array $context ): array {
		$post = $this->require_post( $payload );
		Policy::assertReadablePostType( (string) $post->post_type );
		$before       = $this->post_snapshot( $post );
		$fields       = $this->sanitize_post_fields( $payload, true );
		$fields['ID'] = (int) $post->ID;

		$after  = array_merge( $before, $this->snapshot_from_update_fields( $fields ) );
		$result = array(
			'before'               => $before,
			'after'                => $after,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$updated = wp_update_post( wp_slash( $fields ), true );
		if ( is_wp_error( $updated ) || $fields['ID'] !== $updated ) {
			throw new RuntimeException( 'WordPress post update failed.' );
		}

		$result['after']     = $this->post_snapshot( get_post( (int) $updated ) );
		$result['_rollback'] = array(
			'action'  => 'post.update',
			'payload' => $this->post_rollback_payload( $before ),
		);
		return $result;
	}

	/**
	 * Surgical public page copy correction. The caller never receives raw page
	 * content, and the original is held only in the site's rollback snapshot.
	 *
	 * @param array $payload Validated action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	public function post_literal_replace( array $payload, array $context ): array {
		if ( array_diff( array_keys( $payload ), array( 'id', 'find', 'replace', 'expected_matches' ) ) ) {
			throw new RuntimeException( 'Literal replacement contains unsupported fields.' );
		}
		$post = $this->require_post( $payload );
		if ( 'page' !== (string) $post->post_type ) {
			throw new RuntimeException( 'Literal replacement is limited to existing pages.' );
		}
		Policy::assertPublicContentTarget( $post, true );
		$find    = isset( $payload['find'] ) && is_string( $payload['find'] ) ? $payload['find'] : '';
		$replace = isset( $payload['replace'] ) && is_string( $payload['replace'] ) ? $payload['replace'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 &.,_-]{2,79}$/D', $find )
		|| ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 &.,_-]{2,79}$/D', $replace )
		|| hash_equals( $find, $replace ) ) {
			throw new RuntimeException( 'Literal replacement requires distinct, safe plain-text phrases.' );
		}
		$expected = $payload['expected_matches'] ?? null;
		if ( null !== $expected && ( ! is_int( $expected ) || $expected < 1 || $expected > 25 ) ) {
			throw new RuntimeException( 'expected_matches must be an integer between 1 and 25.' );
		}
		if ( empty( $context['dry_run'] ) && null === $expected ) {
			throw new RuntimeException( 'Confirmed literal replacement requires an exact expected_matches value.' );
		}
		$original = (string) $post->post_content;
		if ( strlen( $original ) > 1048576 ) {
			throw new RuntimeException( 'Page content exceeds the bounded replacement limit.' );
		}
		$count = substr_count( $original, $find );
		if ( $count < 1 || $count > 25 || ( null !== $expected && $count !== $expected ) ) {
			throw new RuntimeException( 'Literal match count is missing, changed or outside the safe range.' );
		}
		$state              = array(
			'id'      => (int) $post->ID,
			'status'  => (string) $post->post_status,
			'content' => $original,
		);
		$before_fingerprint = Fingerprint::make( $state );
		$next               = str_replace( $find, $replace, $original );
		$result             = array(
			'post_id'               => (int) $post->ID,
			'match_count'           => $count,
			'before_fingerprint'    => $before_fingerprint,
			'before_content_sha256' => hash( 'sha256', $original ),
			'after_content_sha256'  => hash( 'sha256', $next ),
			'_current_fingerprint'  => $before_fingerprint,
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$guard = isset( $context['expected_prewrite_fingerprint'] ) ? (string) $context['expected_prewrite_fingerprint'] : '';
		if ( '' === $guard || ! hash_equals( $guard, $before_fingerprint ) ) {
			throw new RuntimeException( 'Literal replacement must match the confirmed dry-run fingerprint.' );
		}
		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) $post->ID,
					'post_content' => $next,
				)
			),
			true
		);
		if ( is_wp_error( $updated ) || (int) $post->ID !== $updated ) {
			throw new RuntimeException( 'WordPress literal post update failed.' );
		}
		$actual = get_post( (int) $post->ID );
		if ( ! $actual instanceof \WP_Post || (string) $actual->post_content !== $next ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => (int) $post->ID,
						'post_content' => $original,
					)
				),
				true
			);
			throw new RuntimeException( 'Literal replacement readback failed; prior content restore attempted.' );
		}
		$result['readback_verified'] = true;
		$result['_rollback']         = array(
			'action'  => 'post.update',
			'payload' => array(
				'id'      => (int) $post->ID,
				'content' => $original,
			),
		);
		return $result;
	}

	/**
	 * Handle the post trash connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function post_trash( array $payload, array $context ): array {
		$post   = $this->require_post( $payload );
		$before = $this->post_snapshot( $post );
		$result = array(
			'before'               => $before,
			'would_status'         => 'trash',
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$trashed = wp_trash_post( (int) $post->ID );
		if ( ! $trashed ) {
			throw new RuntimeException( 'Could not trash post.' );
		}
		$result['post']      = $this->post_snapshot( get_post( (int) $post->ID ) );
		$result['_rollback'] = array(
			'action'  => 'post.restore',
			'payload' => array( 'id' => (int) $post->ID ),
		);
		return $result;
	}

	/**
	 * Handle the post restore connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function post_restore( array $payload, array $context ): array {
		$post   = $this->require_post( $payload );
		$before = $this->post_snapshot( $post );
		$result = array(
			'before'               => $before,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			$result['would_restore'] = true;
			return $result;
		}

		$restored = wp_untrash_post( (int) $post->ID );
		if ( ! $restored ) {
			throw new RuntimeException( 'Could not restore post.' );
		}
		$result['post']      = $this->post_snapshot( get_post( (int) $post->ID ) );
		$result['_rollback'] = array(
			'action'  => 'post.trash',
			'payload' => array( 'id' => (int) $post->ID ),
		);
		return $result;
	}

	/**
	 * Handle the post revisions connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function post_revisions( array $payload ): array {
		$post = $this->require_post( $payload );
		Policy::assertPostReadable( $post );
		$items = array();
		foreach ( wp_get_post_revisions( (int) $post->ID ) as $revision ) {
			$items[] = $this->post_snapshot( $revision );
		}
		return array( 'revisions' => $items );
	}

	/**
	 * Handle the post restore revision connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function post_restore_revision( array $payload, array $context ): array {
		try {
			$revision_id = Input::positive_id( $payload['revision_id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'Revision not found.' );
		}
		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision ) {
			throw new RuntimeException( 'Revision not found.' );
		}
		$post = get_post( (int) $revision->post_parent );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Revision parent not found.' );
		}
		$before = $this->post_snapshot( $post );
		$result = array(
			'before'               => $before,
			'revision'             => $this->post_snapshot( $revision ),
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$restored = wp_restore_post_revision( $revision_id );
		if ( ! $restored ) {
			throw new RuntimeException( 'Could not restore revision.' );
		}
		$result['after']     = $this->post_snapshot( get_post( (int) $post->ID ) );
		$result['_rollback'] = array(
			'action'  => 'post.update',
			'payload' => $this->post_rollback_payload( $before ),
		);
		return $result;
	}

	/**
	 * Handle the meta get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function meta_get( array $payload ): array {
		list( $type, $id, $key ) = $this->meta_target( $payload );
		Policy::assertMetaKeyAllowed( $key );
		return array( 'value' => get_metadata( $type, $id, $key, true ) );
	}

	/**
	 * Handle the meta update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function meta_update( array $payload, array $context ): array {
		list( $type, $id, $key ) = $this->meta_target( $payload );
		Policy::assertMetaKeyAllowed( $key );
		$before = get_metadata( $type, $id, $key, true );
		$after  = array_key_exists( 'value', $payload ) ? $payload['value'] : null;
		$result = array(
			'before'               => $before,
			'after'                => $after,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		if ( false === update_metadata( $type, $id, $key, $after ) ) {
			$current = get_metadata( $type, $id, $key, true );
			if ( $current !== $after ) {
				throw new RuntimeException( 'Metadata update failed.' );
			}
		}
		$result['_rollback'] = array(
			'action'  => 'meta.update',
			'payload' => array(
				'object_type' => $type,
				'object_id'   => $id,
				'key'         => $key,
				'value'       => $before,
			),
		);
		return $result;
	}

	/**
	 * Handle the meta delete connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 */
	public function meta_delete( array $payload, array $context ): array {
		list( $type, $id, $key ) = $this->meta_target( $payload );
		Policy::assertMetaKeyAllowed( $key );
		$before = get_metadata( $type, $id, $key, true );
		$result = array(
			'before'               => $before,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		delete_metadata( $type, $id, $key );
		$result['_rollback'] = array(
			'action'  => 'meta.update',
			'payload' => array(
				'object_type' => $type,
				'object_id'   => $id,
				'key'         => $key,
				'value'       => $before,
			),
		);
		return $result;
	}

	/**
	 * Handle the term list connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function term_list( array $payload ): array {
		$taxonomy = isset( $payload['taxonomy'] ) ? sanitize_key( (string) $payload['taxonomy'] ) : 'category';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			throw new RuntimeException( 'Unknown taxonomy.' );
		}
		$taxonomy_object = get_taxonomy( $taxonomy );
		if ( Policy::publicRepositoryContext() && ( ! $taxonomy_object || ! $taxonomy_object->public ) ) {
			throw new RuntimeException( 'Non-public taxonomies cannot be exported in public-repository mode.' );
		}
		$args  = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => Input::bool( $payload, 'hide_empty' ),
			'number'     => isset( $payload['per_page'] ) ? max( 1, min( 200, (int) $payload['per_page'] ) ) : 100,
			'offset'     => isset( $payload['offset'] ) ? max( 0, (int) $payload['offset'] ) : 0,
			'search'     => isset( $payload['search'] ) ? sanitize_text_field( (string) $payload['search'] ) : '',
		);
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			throw new RuntimeException( 'WordPress term listing failed.' );
		}
		return array( 'terms' => array_map( array( $this, 'term_snapshot' ), $terms ) );
	}

	/**
	 * Handle the term get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function term_get( array $payload ): array {
		$taxonomy        = isset( $payload['taxonomy'] ) ? sanitize_key( (string) $payload['taxonomy'] ) : '';
		$term            = $this->require_term( $payload, $taxonomy );
		$taxonomy_object = get_taxonomy( (string) $term->taxonomy );
		if ( Policy::publicRepositoryContext() && ( ! $taxonomy_object || ! $taxonomy_object->public ) ) {
			throw new RuntimeException( 'Non-public taxonomies cannot be exported in public-repository mode.' );
		}
		$snapshot = $this->term_snapshot( $term );
		return array(
			'term'        => $snapshot,
			'fingerprint' => Fingerprint::make( $snapshot ),
		);
	}

	/**
	 * Handle the term create connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function term_create( array $payload, array $context ): array {
		$taxonomy = isset( $payload['taxonomy'] ) ? sanitize_key( (string) $payload['taxonomy'] ) : '';
		$name     = isset( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : '';
		if ( ! taxonomy_exists( $taxonomy ) || '' === $name ) {
			throw new RuntimeException( 'taxonomy and name are required.' );
		}
		$args = array();
		foreach ( array( 'slug', 'description' ) as $field ) {
			if ( isset( $payload[ $field ] ) ) {
				$args[ $field ] = 'slug' === $field ? sanitize_title( (string) $payload[ $field ] ) : sanitize_textarea_field( (string) $payload[ $field ] );
			}
		}
		if ( isset( $payload['parent'] ) ) {
			$args['parent'] = $this->term_parent_id( $payload['parent'] );
		}
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_create'         => array(
					'taxonomy' => $taxonomy,
					'name'     => $name,
					'args'     => $args,
				),
				'_current_fingerprint' => Fingerprint::make(
					array(
						'new'      => true,
						'taxonomy' => $taxonomy,
					)
				),
			);
		}
		$created = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $created ) ) {
			throw new RuntimeException( 'WordPress term creation failed.' );
		}
		$id = (int) $created['term_id'];
		return array(
			'term'      => $this->term_snapshot( get_term( $id, $taxonomy ) ),
			'_rollback' => array(
				'action'  => 'term.delete',
				'payload' => array(
					'taxonomy' => $taxonomy,
					'id'       => $id,
				),
			),
		);
	}

	/**
	 * Handle the term update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function term_update( array $payload, array $context ): array {
		$taxonomy = isset( $payload['taxonomy'] ) ? sanitize_key( (string) $payload['taxonomy'] ) : '';
		$term     = $this->require_term( $payload, $taxonomy );
		$id       = (int) $term->term_id;
		$before   = $this->term_snapshot( $term );
		$args     = array();
		if ( isset( $payload['name'] ) ) {
			$args['name'] = sanitize_text_field( (string) $payload['name'] );
		}
		if ( isset( $payload['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $payload['slug'] );
		}
		if ( isset( $payload['description'] ) ) {
			$args['description'] = sanitize_textarea_field( (string) $payload['description'] );
		}
		if ( isset( $payload['parent'] ) ) {
			$args['parent'] = $this->term_parent_id( $payload['parent'] );
		}
		$result = array(
			'before'               => $before,
			'changes'              => $args,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$updated = wp_update_term( $id, $taxonomy, $args );
		if ( is_wp_error( $updated ) ) {
			throw new RuntimeException( 'WordPress term update failed.' );
		}
		$result['after']     = $this->term_snapshot( get_term( $id, $taxonomy ) );
		$result['_rollback'] = array(
			'action'  => 'term.update',
			'payload' => array_merge(
				array(
					'taxonomy' => $taxonomy,
					'id'       => $id,
				),
				$before
			),
		);
		return $result;
	}

	/**
	 * Handle the term delete connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function term_delete( array $payload, array $context ): array {
		$taxonomy = isset( $payload['taxonomy'] ) ? sanitize_key( (string) $payload['taxonomy'] ) : '';
		$term     = $this->require_term( $payload, $taxonomy );
		$id       = (int) $term->term_id;
		$before   = $this->term_snapshot( $term );
		$result   = array(
			'before'               => $before,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$deleted = wp_delete_term( $id, $taxonomy );
		if ( 0 === $deleted ) {
			throw new RuntimeException( 'Default taxonomy term cannot be deleted.' );
		}
		if ( true !== $deleted ) {
			throw new RuntimeException( 'Term delete failed.' );
		}
		$result['_rollback'] = array(
			'action'  => 'term.create',
			'payload' => array_merge( array( 'taxonomy' => $taxonomy ), $before ),
		);
		return $result;
	}

	/**
	 * Handle the term assign connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function term_assign( array $payload, array $context ): array {
		try {
			$object_id = Input::positive_id( $payload['object_id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'object_id and valid taxonomy are required.' );
		}
		$taxonomy = isset( $payload['taxonomy'] ) ? sanitize_key( (string) $payload['taxonomy'] ) : '';
		if ( ! array_key_exists( 'terms', $payload ) || ! is_array( $payload['terms'] ) ) {
			throw new RuntimeException( 'terms must be an array of positive term IDs.' );
		}
		$terms  = $payload['terms'];
		$append = Input::bool( $payload, 'append' );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			throw new RuntimeException( 'object_id and valid taxonomy are required.' );
		}
		$term_ids = array();
		foreach ( $terms as $key => $term_id ) {
			try {
				$term_ids[ $key ] = Input::positive_id( $term_id );
			} catch ( RuntimeException $error ) {
				throw new RuntimeException( 'terms must contain positive term IDs.' );
			}
		}
		$before = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $before ) ) {
			throw new RuntimeException( 'WordPress term assignment lookup failed.' );
		}
		$result = array(
			'before'               => $before,
			'after'                => $append ? array_values( array_unique( array_merge( $before, $term_ids ) ) ) : $term_ids,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$assigned = wp_set_object_terms( $object_id, $term_ids, $taxonomy, $append );
		if ( is_wp_error( $assigned ) ) {
			throw new RuntimeException( 'WordPress term assignment failed.' );
		}
		$result['_rollback'] = array(
			'action'  => 'term.assign',
			'payload' => array(
				'object_id' => $object_id,
				'taxonomy'  => $taxonomy,
				'terms'     => array_map( 'intval', $before ),
				'append'    => false,
			),
		);
		return $result;
	}

	/**
	 * Handle the menu list connector action.
	 *
	 * @return array Result of this operation.
	 */
	public function menu_list(): array {
		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = array(
				'term_id' => (int) $menu->term_id,
				'name'    => (string) $menu->name,
				'slug'    => (string) $menu->slug,
				'count'   => (int) $menu->count,
			);
		}
		return array(
			'menus'                => $menus,
			'locations'            => get_nav_menu_locations(),
			'registered_locations' => get_registered_nav_menus(),
		);
	}

	/**
	 * Handle the menu get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function menu_get( array $payload ): array {
		$selector = isset( $payload['id'] ) ? $this->require_menu_id( $payload ) : ( $payload['name'] ?? 0 );
		$menu     = wp_get_nav_menu_object( $selector );
		if ( ! $menu ) {
			throw new RuntimeException( 'Menu not found.' );
		}
		$items = array();
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
			$items[] = array(
				'ID'               => (int) $item->ID,
				'title'            => (string) $item->title,
				'url'              => (string) $item->url,
				'menu_item_parent' => (int) $item->menu_item_parent,
				'object'           => (string) $item->object,
				'object_id'        => (int) $item->object_id,
				'type'             => (string) $item->type,
				'target'           => (string) $item->target,
				'classes'          => array_values( array_filter( (array) $item->classes ) ),
				'description'      => (string) $item->description,
			);
		}
		return array(
			'menu'  => array(
				'term_id' => (int) $menu->term_id,
				'name'    => (string) $menu->name,
				'slug'    => (string) $menu->slug,
			),
			'items' => $items,
		);
	}

	/**
	 * Handle the menu create connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function menu_create( array $payload, array $context ): array {
		$name = isset( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : '';
		if ( '' === $name ) {
			throw new RuntimeException( 'name is required.' );
		}
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_create'         => $name,
				'_current_fingerprint' => Fingerprint::make( array( 'new_menu' => $name ) ),
			);
		}
		$id = wp_create_nav_menu( $name );
		if ( is_wp_error( $id ) || ! is_int( $id ) || $id <= 0 ) {
			throw new RuntimeException( 'WordPress menu creation failed.' );
		}
		return array(
			'menu_id'   => (int) $id,
			'_rollback' => array(
				'action'  => 'menu.delete',
				'payload' => array( 'id' => (int) $id ),
			),
		);
	}

	/**
	 * Handle the menu update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function menu_update( array $payload, array $context ): array {
		$id   = $this->require_menu_id( $payload );
		$menu = wp_get_nav_menu_object( $id );
		if ( ! $menu ) {
			throw new RuntimeException( 'Menu not found.' );
		}
		$name   = isset( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : (string) $menu->name;
		$before = array(
			'id'   => $id,
			'name' => (string) $menu->name,
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'after'                => array(
					'id'   => $id,
					'name' => $name,
				),
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		$updated = wp_update_nav_menu_object( $id, array( 'menu-name' => $name ) );
		if ( is_wp_error( $updated ) || $id !== $updated ) {
			throw new RuntimeException( 'WordPress menu update failed.' );
		}
		return array(
			'menu_id'   => (int) $updated,
			'_rollback' => array(
				'action'  => 'menu.update',
				'payload' => $before,
			),
		);
	}

	/**
	 * Handle the menu delete connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function menu_delete( array $payload, array $context ): array {
		$id       = $this->require_menu_id( $payload );
		$snapshot = $this->menu_get( array( 'id' => $id ) );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $snapshot,
				'_current_fingerprint' => Fingerprint::make( $snapshot ),
			);
		}
		$deleted = wp_delete_nav_menu( $id );
		if ( true !== $deleted ) {
			throw new RuntimeException( 'WordPress menu deletion failed.' );
		}
		return array(
			'deleted'            => $id,
			'rollback_supported' => false,
		);
	}

	/**
	 * Handle the menu item upsert connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function menu_item_upsert( array $payload, array $context ): array {
		$menu_id = $this->require_menu_id( array( 'id' => $payload['menu_id'] ?? null ) );
		try {
			$item_id = isset( $payload['item_id'] ) ? Input::non_negative_id( $payload['item_id'] ) : 0;
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'item_id must be a non-negative integer.' );
		}
		$numeric_fields = array(
			'object_id' => 'menu-item-object-id',
			'parent'    => 'menu-item-parent-id',
			'position'  => 'menu-item-position',
		);
		$numeric_args   = array();
		foreach ( $numeric_fields as $source => $target ) {
			if ( isset( $payload[ $source ] ) ) {
				try {
					$numeric_args[ $target ] = Input::non_negative_id( $payload[ $source ] );
				} catch ( RuntimeException $error ) {
					throw new RuntimeException( 'object_id, parent and position must be non-negative integers.' );
				}
			}
		}
		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			throw new RuntimeException( 'Menu not found.' );
		}
		$before = $item_id ? get_post( $item_id ) : null;
		$args   = array();
		$map    = array(
			'title'       => 'menu-item-title',
			'url'         => 'menu-item-url',
			'description' => 'menu-item-description',
			'attr_title'  => 'menu-item-attr-title',
			'target'      => 'menu-item-target',
			'xfn'         => 'menu-item-xfn',
			'status'      => 'menu-item-status',
			'type'        => 'menu-item-type',
			'object'      => 'menu-item-object',
		);
		foreach ( $map as $source => $target ) {
			if ( isset( $payload[ $source ] ) ) {
				$args[ $target ] = sanitize_text_field( (string) $payload[ $source ] );
			}
		}
		foreach ( $numeric_args as $field => $id ) {
			$args[ $field ] = $id;
		}
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_upsert'         => array(
					'menu_id' => $menu_id,
					'item_id' => $item_id,
					'args'    => $args,
				),
				'_current_fingerprint' => Fingerprint::make( $before ? get_post_meta( $item_id ) : array( 'new' => true ) ),
			);
		}
		$updated = wp_update_nav_menu_item( $menu_id, $item_id, $args );
		if ( is_wp_error( $updated ) || ! is_int( $updated ) || $updated <= 0 || ( 0 !== $item_id && $item_id !== $updated ) ) {
			throw new RuntimeException( 'WordPress menu item update failed.' );
		}
		return array(
			'item_id'            => (int) $updated,
			'rollback_supported' => false,
		);
	}

	/**
	 * Handle the menu location set connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function menu_location_set( array $payload, array $context ): array {
		$location = isset( $payload['location'] ) ? sanitize_key( (string) $payload['location'] ) : '';
		try {
			$menu_id = isset( $payload['menu_id'] ) ? Input::non_negative_id( $payload['menu_id'] ) : 0;
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'menu_id must be a non-negative integer.' );
		}
		$registered = get_registered_nav_menus();
		if ( ! isset( $registered[ $location ] ) ) {
			throw new RuntimeException( 'Unknown menu location.' );
		}
		$locations = get_nav_menu_locations();
		$before    = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'after'                => $menu_id,
				'_current_fingerprint' => Fingerprint::make( $locations ),
			);
		}
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return array(
			'location'  => $location,
			'menu_id'   => $menu_id,
			'_rollback' => array(
				'action'  => 'menu.location_set',
				'payload' => array(
					'location' => $location,
					'menu_id'  => $before,
				),
			),
		);
	}

	/**
	 * Handle the option get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function option_get( array $payload ): array {
		$name = $this->require_key( $payload, 'name' );
		Policy::assertOptionKeyAllowed( $name );
		return array(
			'name'  => $name,
			'value' => get_option( $name, null ),
		);
	}

	/**
	 * Handle the option update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 */
	public function option_update( array $payload, array $context ): array {
		$name = $this->require_key( $payload, 'name' );
		Policy::assertOptionKeyAllowed( $name );
		$before = get_option( $name, null );
		$value  = $payload['value'] ?? null;
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'after'                => $value,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		update_option( $name, $value, array_key_exists( 'autoload', $payload ) ? Input::bool( $payload, 'autoload' ) : null );
		return array(
			'name'      => $name,
			'value'     => get_option( $name, null ),
			'_rollback' => array(
				'action'  => 'option.update',
				'payload' => array(
					'name'  => $name,
					'value' => $before,
				),
			),
		);
	}

	/**
	 * Handle the option delete connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 */
	public function option_delete( array $payload, array $context ): array {
		$name = $this->require_key( $payload, 'name' );
		Policy::assertOptionKeyAllowed( $name );
		$before = get_option( $name, null );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		delete_option( $name );
		return array(
			'deleted'   => $name,
			'_rollback' => array(
				'action'  => 'option.update',
				'payload' => array(
					'name'  => $name,
					'value' => $before,
				),
			),
		);
	}

	/**
	 * Handle the network option get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function network_option_get( array $payload ): array {
		if ( ! is_multisite() ) {
			throw new RuntimeException( 'Not a multisite installation.' );
		}
		$name = $this->require_key( $payload, 'name' );
		Policy::assertOptionKeyAllowed( $name );
		return array(
			'name'  => $name,
			'value' => get_site_option( $name, null ),
		);
	}

	/**
	 * Handle the network option update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function network_option_update( array $payload, array $context ): array {
		if ( ! is_multisite() ) {
			throw new RuntimeException( 'Not a multisite installation.' );
		}
		$name = $this->require_key( $payload, 'name' );
		Policy::assertOptionKeyAllowed( $name );
		$before = get_site_option( $name, null );
		$value  = $payload['value'] ?? null;
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'after'                => $value,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		update_site_option( $name, $value );
		return array(
			'name'      => $name,
			'value'     => get_site_option( $name, null ),
			'_rollback' => array(
				'action'  => 'network_option.update',
				'payload' => array(
					'name'  => $name,
					'value' => $before,
				),
			),
		);
	}

	/**
	 * Handle the network option delete connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function network_option_delete( array $payload, array $context ): array {
		if ( ! is_multisite() ) {
			throw new RuntimeException( 'Not a multisite installation.' );
		}
		$name = $this->require_key( $payload, 'name' );
		Policy::assertOptionKeyAllowed( $name );
		$before = get_site_option( $name, null );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		delete_site_option( $name );
		return array(
			'deleted'   => $name,
			'_rollback' => array(
				'action'  => 'network_option.update',
				'payload' => array(
					'name'  => $name,
					'value' => $before,
				),
			),
		);
	}

	/**
	 * Handle the theme mod get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function theme_mod_get( array $payload ): array {
		$name = $this->require_key( $payload, 'name' );
		Policy::assertKeyAllowed( $name );
		return array(
			'name'  => $name,
			'value' => get_theme_mod( $name, null ),
		);
	}

	/**
	 * Handle the theme mod update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 */
	public function theme_mod_update( array $payload, array $context ): array {
		$name = $this->require_key( $payload, 'name' );
		Policy::assertKeyAllowed( $name );
		$before = get_theme_mod( $name, null );
		$value  = $payload['value'] ?? null;
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'after'                => $value,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		set_theme_mod( $name, $value );
		return array(
			'name'      => $name,
			'value'     => get_theme_mod( $name, null ),
			'_rollback' => array(
				'action'  => 'theme_mod.update',
				'payload' => array(
					'name'  => $name,
					'value' => $before,
				),
			),
		);
	}

	/**
	 * Handle the theme mod remove connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 */
	public function theme_mod_remove( array $payload, array $context ): array {
		$name = $this->require_key( $payload, 'name' );
		Policy::assertKeyAllowed( $name );
		$before = get_theme_mod( $name, null );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		remove_theme_mod( $name );
		return array(
			'removed'   => $name,
			'_rollback' => array(
				'action'  => 'theme_mod.update',
				'payload' => array(
					'name'  => $name,
					'value' => $before,
				),
			),
		);
	}

	/**
	 * Handle the comment list connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When the optional post ID is invalid.
	 */
	public function comment_list( array $payload ): array {
		try {
			$post_id = isset( $payload['post_id'] ) ? Input::non_negative_id( $payload['post_id'] ) : 0;
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'post_id must be a non-negative integer.' );
		}
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : 'all',
				'number'  => isset( $payload['per_page'] ) ? max( 1, min( 100, (int) $payload['per_page'] ) ) : 50,
				'offset'  => isset( $payload['offset'] ) ? max( 0, (int) $payload['offset'] ) : 0,
			)
		);
		return array( 'comments' => array_map( array( $this, 'comment_snapshot' ), $comments ) );
	}

	/**
	 * Handle the comment get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function comment_get( array $payload ): array {
		$comment = $this->require_comment( $payload );
		return array( 'comment' => $this->comment_snapshot( $comment ) );
	}

	/**
	 * Handle the comment update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function comment_update( array $payload, array $context ): array {
		$comment = $this->require_comment( $payload );
		$before  = $this->comment_snapshot( $comment );
		$fields  = array( 'comment_ID' => (int) $comment->comment_ID );
		$map     = array(
			'content'      => 'comment_content',
			'author'       => 'comment_author',
			'author_email' => 'comment_author_email',
			'author_url'   => 'comment_author_url',
			'approved'     => 'comment_approved',
		);
		foreach ( $map as $source => $target ) {
			if ( isset( $payload[ $source ] ) ) {
				$fields[ $target ] = (string) $payload[ $source ];
			}
		}
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'changes'              => $fields,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		$updated = wp_update_comment( wp_slash( $fields ), true );
		if ( is_wp_error( $updated ) || ! in_array( $updated, array( 0, 1 ), true ) ) {
			throw new RuntimeException( 'WordPress comment update failed.' );
		}
		return array(
			'comment'   => $this->comment_snapshot( get_comment( (int) $comment->comment_ID ) ),
			'_rollback' => array(
				'action'  => 'comment.update',
				'payload' => array(
					'id'           => (int) $comment->comment_ID,
					'content'      => $before['content'],
					'author'       => $before['author'],
					'author_email' => $before['author_email'],
					'author_url'   => $before['author_url'],
					'approved'     => $before['approved'],
				),
			),
		);
	}

	/**
	 * Handle the comment trash connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function comment_trash( array $payload, array $context ): array {
		$comment = $this->require_comment( $payload );
		$id      = (int) $comment->comment_ID;
		$before  = $this->comment_snapshot( $comment );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'before'               => $before,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		if ( ! wp_trash_comment( $id ) ) {
			throw new RuntimeException( 'Could not trash comment.' );
		}
		return array(
			'trashed'            => $id,
			'rollback_supported' => false,
		);
	}
}
