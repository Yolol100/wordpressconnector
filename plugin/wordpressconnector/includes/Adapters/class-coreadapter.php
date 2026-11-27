<?php
/**
 * Registers and handles Core connector actions.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

require_once __DIR__ . '/trait-coreadapteractions.php';

/**
 * Registers and handles Core connector actions.
 */
final class CoreAdapter {
	use CoreAdapterActions {
		post_list as postList;
		post_get as postGet;
		post_create as postCreate;
		post_update as postUpdate;
		post_literal_replace as postLiteralReplace;
		post_trash as postTrash;
		post_restore as postRestore;
		post_revisions as postRevisions;
		post_restore_revision as postRestoreRevision;
		meta_get as metaGet;
		meta_update as metaUpdate;
		meta_delete as metaDelete;
		term_list as termList;
		term_get as termGet;
		term_create as termCreate;
		term_update as termUpdate;
		term_delete as termDelete;
		term_assign as termAssign;
		menu_list as menuList;
		menu_get as menuGet;
		menu_create as menuCreate;
		menu_update as menuUpdate;
		menu_delete as menuDelete;
		menu_item_upsert as menuItemUpsert;
		menu_location_set as menuLocationSet;
		option_get as optionGet;
		option_update as optionUpdate;
		option_delete as optionDelete;
		network_option_get as networkOptionGet;
		network_option_update as networkOptionUpdate;
		network_option_delete as networkOptionDelete;
		theme_mod_get as themeModGet;
		theme_mod_update as themeModUpdate;
		theme_mod_remove as themeModRemove;
		comment_list as commentList;
		comment_get as commentGet;
		comment_update as commentUpdate;
		comment_trash as commentTrash;
	}

	/**
	 * Register connector actions and their handlers.
	 *
	 * @param Registry $registry Action registry to extend.
	 */
	public function register( Registry $registry ): void {
		$registry->register( 'post.list', array( $this, 'post_list' ), array( 'description' => 'List posts, pages or custom post types.' ) );
		$registry->register( 'post.get', array( $this, 'post_get' ), array( 'description' => 'Read one post object.' ) );
		$registry->register(
			'post.create',
			array( $this, 'post_create' ),
			array(
				'mutation'    => true,
				'description' => 'Create a post, page or custom post type object.',
			)
		);
		$registry->register(
			'post.update',
			array( $this, 'post_update' ),
			array(
				'mutation'    => true,
				'description' => 'Update a post, page or custom post type object.',
			)
		);
		$registry->register(
			'post.literal_replace',
			array( $this, 'post_literal_replace' ),
			array(
				'mutation'    => true,
				'description' => 'Replace one literal phrase in an existing published page with bounded readback and rollback.',
			)
		);
		$registry->register(
			'post.trash',
			array( $this, 'post_trash' ),
			array(
				'mutation'    => true,
				'description' => 'Move a post object to Trash.',
			)
		);
		$registry->register(
			'post.restore',
			array( $this, 'post_restore' ),
			array(
				'mutation'    => true,
				'description' => 'Restore a trashed post object.',
			)
		);
		$registry->register( 'post.revisions', array( $this, 'post_revisions' ), array( 'description' => 'List WordPress revisions for a post.' ) );
		$registry->register(
			'post.restore_revision',
			array( $this, 'post_restore_revision' ),
			array(
				'mutation'    => true,
				'description' => 'Restore a WordPress post revision.',
			)
		);

		$registry->register(
			'meta.get',
			array( $this, 'meta_get' ),
			array(
				'privileged'  => true,
				'description' => 'Read generic post, term, user or comment metadata.',
			)
		);
		$registry->register(
			'meta.update',
			array( $this, 'meta_update' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Update generic post, term, user or comment metadata.',
			)
		);
		$registry->register(
			'meta.delete',
			array( $this, 'meta_delete' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Delete generic post, term, user or comment metadata.',
			)
		);

		$registry->register( 'term.list', array( $this, 'term_list' ), array( 'description' => 'List terms in any registered taxonomy.' ) );
		$registry->register( 'term.get', array( $this, 'term_get' ), array( 'description' => 'Read one taxonomy term.' ) );
		$registry->register(
			'term.create',
			array( $this, 'term_create' ),
			array(
				'mutation'    => true,
				'description' => 'Create a taxonomy term.',
			)
		);
		$registry->register(
			'term.update',
			array( $this, 'term_update' ),
			array(
				'mutation'    => true,
				'description' => 'Update a taxonomy term.',
			)
		);
		$registry->register(
			'term.delete',
			array( $this, 'term_delete' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Delete a taxonomy term.',
			)
		);
		$registry->register(
			'term.assign',
			array( $this, 'term_assign' ),
			array(
				'mutation'    => true,
				'description' => 'Assign taxonomy terms to an object.',
			)
		);

		$registry->register( 'menu.list', array( $this, 'menu_list' ), array( 'description' => 'List classic navigation menus and locations.' ) );
		$registry->register( 'menu.get', array( $this, 'menu_get' ), array( 'description' => 'Read a classic navigation menu and items.' ) );
		$registry->register(
			'menu.create',
			array( $this, 'menu_create' ),
			array(
				'mutation'    => true,
				'description' => 'Create a classic navigation menu.',
			)
		);
		$registry->register(
			'menu.update',
			array( $this, 'menu_update' ),
			array(
				'mutation'    => true,
				'description' => 'Rename a classic navigation menu.',
			)
		);
		$registry->register(
			'menu.delete',
			array( $this, 'menu_delete' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Delete a classic navigation menu.',
			)
		);
		$registry->register(
			'menu.item_upsert',
			array( $this, 'menu_item_upsert' ),
			array(
				'mutation'    => true,
				'description' => 'Create or update a classic navigation menu item.',
			)
		);
		$registry->register(
			'menu.location_set',
			array( $this, 'menu_location_set' ),
			array(
				'mutation'    => true,
				'description' => 'Assign a classic menu to a theme location.',
			)
		);

		$registry->register(
			'option.get',
			array( $this, 'option_get' ),
			array(
				'privileged'  => true,
				'description' => 'Read a WordPress option.',
			)
		);
		$registry->register(
			'option.update',
			array( $this, 'option_update' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Update a WordPress option.',
			)
		);
		$registry->register(
			'option.delete',
			array( $this, 'option_delete' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Delete a WordPress option.',
			)
		);
		$registry->register(
			'network_option.get',
			array( $this, 'network_option_get' ),
			array(
				'privileged'  => true,
				'description' => 'Read a multisite network option.',
			)
		);
		$registry->register(
			'network_option.update',
			array( $this, 'network_option_update' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Update a multisite network option.',
			)
		);
		$registry->register(
			'network_option.delete',
			array( $this, 'network_option_delete' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Delete a multisite network option.',
			)
		);
		$registry->register(
			'theme_mod.get',
			array( $this, 'theme_mod_get' ),
			array(
				'privileged'  => true,
				'description' => 'Read a theme modification.',
			)
		);
		$registry->register(
			'theme_mod.update',
			array( $this, 'theme_mod_update' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Update a theme modification.',
			)
		);
		$registry->register(
			'theme_mod.remove',
			array( $this, 'theme_mod_remove' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Remove a theme modification.',
			)
		);

		$registry->register(
			'comment.list',
			array( $this, 'comment_list' ),
			array(
				'privileged'  => true,
				'sensitive'   => true,
				'description' => 'List comments including private author metadata.',
			)
		);
		$registry->register(
			'comment.get',
			array( $this, 'comment_get' ),
			array(
				'privileged'  => true,
				'sensitive'   => true,
				'description' => 'Read one comment.',
			)
		);
		$registry->register(
			'comment.update',
			array( $this, 'comment_update' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'sensitive'   => true,
				'description' => 'Update a comment.',
			)
		);
		$registry->register(
			'comment.trash',
			array( $this, 'comment_trash' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'sensitive'   => true,
				'description' => 'Trash a comment.',
			)
		);
	}

	/**
	 * Validate an exact classic menu ID before a lookup or mutation.
	 *
	 * @param array $payload Requested menu identifier.
	 *
	 * @return int Exact positive menu ID.
	 *
	 * @throws \RuntimeException If the menu identifier is invalid.
	 */
	private function require_menu_id( array $payload ): int {
		try {
			return Input::positive_id( $payload['id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'Menu not found.' );
		}
	}

	/**
	 * Resolve a comment using its exact positive ID; never cast untrusted
	 * content into the ID of a different comment.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return \WP_Comment Validated comment.
	 *
	 * @throws \RuntimeException When the ID is invalid or absent.
	 */
	private function require_comment( array $payload ): \WP_Comment {
		try {
			$id = Input::positive_id( $payload['id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'Comment not found.' );
		}
		$comment = get_comment( $id );
		if ( ! $comment instanceof \WP_Comment ) {
			throw new RuntimeException( 'Comment not found.' );
		}
		return $comment;
	}

	/**
	 * Resolve and validate the requested WordPress post.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return \WP_Post Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	private function require_post( array $payload ): \WP_Post {
		try {
			$id = Input::positive_id( $payload['id'] ?? null );
		} catch ( RuntimeException $error ) {
			// Keep the historical public error contract for missing/invalid IDs.
			throw new RuntimeException( 'Post not found.' );
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Post not found.' );
		}
		return $post;
	}

	/**
	 * Validate and sanitize post fields for the requested operation.
	 *
	 * @param array $payload Request parameters.
	 * @param bool  $partial Whether partial updates are allowed.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When author or parent identifiers are invalid.
	 */
	private function sanitize_post_fields( array $payload, bool $partial ): array {
		$fields     = array();
		$string_map = array(
			'title'          => 'post_title',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'status'         => 'post_status',
			'slug'           => 'post_name',
			'type'           => 'post_type',
			'password'       => 'post_password',
			'comment_status' => 'comment_status',
			'ping_status'    => 'ping_status',
			'date'           => 'post_date',
			'date_gmt'       => 'post_date_gmt',
		);
		foreach ( $string_map as $source => $target ) {
			if ( array_key_exists( $source, $payload ) ) {
				$fields[ $target ] = (string) $payload[ $source ];
			}
		}
		if ( isset( $payload['author'] ) ) {
			try {
				$fields['post_author'] = Input::non_negative_id( $payload['author'] );
			} catch ( RuntimeException $error ) {
				throw new RuntimeException( 'author must be a non-negative integer.' );
			}
		}
		if ( isset( $payload['parent'] ) ) {
			try {
				$fields['post_parent'] = Input::non_negative_id( $payload['parent'] );
			} catch ( RuntimeException $error ) {
				throw new RuntimeException( 'parent must be a non-negative integer.' );
			}
		}
		if ( isset( $payload['menu_order'] ) ) {
			$fields['menu_order'] = (int) $payload['menu_order'];
		}
		if ( ! $partial && ! isset( $fields['post_status'] ) ) {
			$fields['post_status'] = 'draft';
		}
		return $fields;
	}

	/**
	 * Build a stable snapshot of a WordPress post.
	 *
	 * @param \WP_Post $post WordPress post to inspect.
	 *
	 * @return array Result of this operation.
	 */
	private function post_snapshot( \WP_Post $post ): array {
		return array(
			'id'                 => (int) $post->ID,
			'type'               => (string) $post->post_type,
			'status'             => (string) $post->post_status,
			'title'              => (string) $post->post_title,
			'slug'               => (string) $post->post_name,
			'content'            => (string) $post->post_content,
			'excerpt'            => (string) $post->post_excerpt,
			'author'             => (int) $post->post_author,
			'parent'             => (int) $post->post_parent,
			'menu_order'         => (int) $post->menu_order,
			'date'               => (string) $post->post_date,
			'date_gmt'           => (string) $post->post_date_gmt,
			'modified'           => (string) $post->post_modified,
			'modified_gmt'       => (string) $post->post_modified_gmt,
			'comment_status'     => (string) $post->comment_status,
			'ping_status'        => (string) $post->ping_status,
			'password_protected' => '' !== (string) $post->post_password,
			'featured_media'     => (int) get_post_thumbnail_id( (int) $post->ID ),
			'permalink'          => get_permalink( (int) $post->ID ),
		);
	}

	/**
	 * Build payload data for a guarded post rollback.
	 *
	 * @param array $snapshot Previously captured state.
	 *
	 * @return array Result of this operation.
	 */
	private function post_rollback_payload( array $snapshot ): array {
		return array(
			'id'             => $snapshot['id'],
			'title'          => $snapshot['title'],
			'content'        => $snapshot['content'],
			'excerpt'        => $snapshot['excerpt'],
			'status'         => $snapshot['status'],
			'slug'           => $snapshot['slug'],
			'author'         => $snapshot['author'],
			'parent'         => $snapshot['parent'],
			'menu_order'     => $snapshot['menu_order'],
			'comment_status' => $snapshot['comment_status'],
			'ping_status'    => $snapshot['ping_status'],
		);
	}

	/**
	 * Build a snapshot from accepted update fields.
	 *
	 * @param array $fields Input fields to inspect.
	 *
	 * @return array Result of this operation.
	 */
	private function snapshot_from_update_fields( array $fields ): array {
		$reverse = array(
			'post_title'     => 'title',
			'post_content'   => 'content',
			'post_excerpt'   => 'excerpt',
			'post_status'    => 'status',
			'post_name'      => 'slug',
			'post_author'    => 'author',
			'post_parent'    => 'parent',
			'menu_order'     => 'menu_order',
			'comment_status' => 'comment_status',
			'ping_status'    => 'ping_status',
		);
		$result  = array();
		foreach ( $reverse as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				$result[ $key ] = $fields[ $field ];
			}
		}
		return $result;
	}

	/**
	 * Resolve the requested WordPress metadata target.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	private function meta_target( array $payload ): array {
		$type = isset( $payload['object_type'] ) ? sanitize_key( (string) $payload['object_type'] ) : '';
		if ( ! in_array( $type, array( 'post', 'term', 'user', 'comment' ), true ) ) {
			throw new RuntimeException( 'object_type must be post, term, user or comment.' );
		}
		try {
			$id = Input::positive_id( $payload['object_id'] ?? null );
		} catch ( RuntimeException $error ) {
			// Keep the established error wording for invalid or missing targets.
			throw new RuntimeException( 'object_id is required.' );
		}
		$key = $this->require_key( $payload, 'key' );
		return array( $type, $id, $key );
	}

	/**
	 * Resolve an existing taxonomy term without coercing an untrusted ID.
	 *
	 * @param array  $payload  Requested term identifier.
	 * @param string $taxonomy Optional taxonomy constraint.
	 *
	 * @return \WP_Term Exact target term.
	 *
	 * @throws \RuntimeException When the requested term is invalid or absent.
	 */
	private function require_term( array $payload, string $taxonomy ): \WP_Term {
		try {
			$id = Input::positive_id( $payload['id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'Term not found.' );
		}
		$term = get_term( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			throw new RuntimeException( 'Term not found.' );
		}
		return $term;
	}

	/**
	 * Validate an explicit taxonomy parent without converting malformed IDs.
	 *
	 * Parent zero selects the taxonomy root; other IDs must be exact integers.
	 *
	 * @param mixed $parent_id Untrusted parent term ID.
	 *
	 * @return int Valid parent term ID.
	 *
	 * @throws \RuntimeException When the parent ID is malformed.
	 */
	private function term_parent_id( $parent_id ): int {
		try {
			return Input::non_negative_id( $parent_id );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'parent must be a non-negative term ID.' );
		}
	}

	/**
	 * Build a snapshot of a taxonomy term.
	 *
	 * @param \WP_Term $term Taxonomy term to inspect.
	 *
	 * @return array Result of this operation.
	 */
	private function term_snapshot( \WP_Term $term ): array {
		return array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		);
	}

	/**
	 * Build a snapshot of a WordPress comment.
	 *
	 * @param \WP_Comment $comment WordPress comment to inspect.
	 *
	 * @return array Result of this operation.
	 */
	private function comment_snapshot( \WP_Comment $comment ): array {
		return array(
			'id'           => (int) $comment->comment_ID,
			'post_id'      => (int) $comment->comment_post_ID,
			'author'       => (string) $comment->comment_author,
			'author_email' => (string) $comment->comment_author_email,
			'author_url'   => (string) $comment->comment_author_url,
			'author_ip'    => (string) $comment->comment_author_IP,
			'date'         => (string) $comment->comment_date,
			'content'      => (string) $comment->comment_content,
			'approved'     => (string) $comment->comment_approved,
			'type'         => (string) $comment->comment_type,
			'parent'       => (int) $comment->comment_parent,
			'user_id'      => (int) $comment->user_id,
		);
	}

	/**
	 * Handle internal require key logic.
	 *
	 * @param array  $payload Request parameters.
	 * @param string $field Value used by this operation.
	 *
	 * @return string Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	private function require_key( array $payload, string $field ): string {
		$value = isset( $payload[ $field ] ) ? (string) $payload[ $field ] : '';
		if ( '' === $value || strlen( $value ) > 191 ) {
			throw new RuntimeException( esc_html( $field ) . ' is required and must be <= 191 characters.' );
		}
		return $value;
	}
}
