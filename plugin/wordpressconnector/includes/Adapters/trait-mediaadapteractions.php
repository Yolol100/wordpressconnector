<?php
/**
 * Canonical public method implementations for MediaAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait MediaAdapterActions {
	/**
	 * Process the media list operation in the Media adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Operation result.
	 */
	public function media_list( array $payload ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => isset( $payload['per_page'] ) ? max( 1, min( 100, (int) $payload['per_page'] ) ) : 50,
				'paged'          => isset( $payload['page'] ) ? max( 1, (int) $payload['page'] ) : 1,
				'post_mime_type' => isset( $payload['mime_type'] ) ? sanitize_mime_type( (string) $payload['mime_type'] ) : '',
				's'              => isset( $payload['search'] ) ? sanitize_text_field( (string) $payload['search'] ) : '',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			try {
				Policy::assertPostReadable( $post );
				$items[] = $this->snapshot( (int) $post->ID );
			} catch ( RuntimeException $error ) {
				continue;
			}
		}
		return array(
			'media' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Process the media get operation in the Media adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Operation result.
	 */
	public function media_get( array $payload ): array {
		$id   = $this->attachment_id( $payload );
		$post = get_post( $id );
		Policy::assertPostReadable( $post );
		$snapshot = $this->snapshot( $id );
		return array(
			'media'       => $snapshot,
			'fingerprint' => Fingerprint::make( $snapshot ),
		);
	}

	/**
	 * Process the media import operation in the Media adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function media_import( array $payload, array $context ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new RuntimeException( 'Uploading media requires upload_files permission.' );
		}
		$parent     = isset( $payload['parent'] )
			? $this->validated_parent_id( $payload['parent'], true )
			: 0;
		$asset_root = isset( $context['asset_root'] ) ? (string) $context['asset_root'] : '';
		if ( '' === $asset_root ) {
			throw new RuntimeException( 'media.import requires --asset-root in the CLI context.' );
		}
		$source = isset( $payload['source_path'] ) ? (string) $payload['source_path'] : '';
		if ( '' === $source ) {
			throw new RuntimeException( 'source_path is required.' );
		}
		$candidate = $asset_root . DIRECTORY_SEPARATOR . ltrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $source ), DIRECTORY_SEPARATOR );
		$real      = Policy::assertLocalAssetPath( $candidate, $asset_root );

		$configured_limit = getenv( 'WPCONNECTOR_MAX_MEDIA_BYTES' );
		$max_bytes        = (int) ( $configured_limit ? $configured_limit : 20971520 );
		$size             = filesize( $real );
		if ( false === $size || $size <= 0 || $size > $max_bytes ) {
			throw new RuntimeException( 'Media file size is invalid or exceeds the configured limit.' );
		}

		$checked = wp_check_filetype_and_ext( $real, basename( $real ) );
		if ( empty( $checked['type'] ) || empty( $checked['ext'] ) ) {
			throw new RuntimeException( 'WordPress rejected the media MIME type or extension.' );
		}

		$planned = array(
			'source_path' => $source,
			'filename'    => basename( $real ),
			'mime_type'   => $checked['type'],
			'bytes'       => (int) $size,
			'parent'      => $parent,
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_import'         => $planned,
				'_current_fingerprint' => Fingerprint::make(
					array(
						'source' => $source,
						'size'   => $size,
						'mtime'  => filemtime( $real ),
					)
				),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( basename( $real ) );
		if ( ! $tmp || ! copy( $real, $tmp ) ) {
			throw new RuntimeException( 'Could not prepare temporary media file.' );
		}

		$file_array    = array(
			'name'     => basename( $real ),
			'tmp_name' => $tmp,
		);
		$attachment_id = media_handle_sideload( $file_array, $planned['parent'], isset( $payload['description'] ) ? (string) $payload['description'] : null );
		if ( is_wp_error( $attachment_id ) || ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
			wp_delete_file( $tmp );
			throw new RuntimeException( 'WordPress media import failed.' );
		}

		$update = array( 'id' => (int) $attachment_id );
		foreach ( array( 'title', 'alt', 'caption', 'description', 'parent' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$update[ $field ] = $payload[ $field ];
			}
		}
		if ( count( $update ) > 1 ) {
			$this->apply_update( $update );
		}

		return array(
			'media'     => $this->snapshot( (int) $attachment_id ),
			'_rollback' => array(
				'action'  => 'post.trash',
				'payload' => array( 'id' => (int) $attachment_id ),
			),
		);
	}

	/**
	 * Process the media update operation in the Media adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function media_update( array $payload, array $context ): array {
		$id = $this->attachment_id( $payload );
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			throw new RuntimeException( 'You cannot edit this media attachment.' );
		}
		$before = $this->snapshot( $id );
		$after  = $before;
		foreach ( array( 'title', 'alt', 'caption', 'description' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$after[ $field ] = $payload[ $field ];
			}
		}
		if ( array_key_exists( 'parent', $payload ) ) {
			$after['parent'] = $this->validated_parent_id( $payload['parent'], false );
		}
		$result = array(
			'before'               => $before,
			'after'                => $after,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$this->apply_update( $payload );
		$result['after']     = $this->snapshot( $id );
		$result['_rollback'] = array(
			'action'  => 'media.update',
			'payload' => array(
				'id'          => $id,
				'title'       => $before['title'],
				'alt'         => $before['alt'],
				'caption'     => $before['caption'],
				'description' => $before['description'],
				'parent'      => $before['parent'],
			),
		);
		return $result;
	}


	/**
	 * Process the media assign operation in the Media adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function media_assign( array $payload, array $context ): array {
		$target_type = isset( $payload['target_type'] ) ? sanitize_key( (string) $payload['target_type'] ) : '';
		$target_id   = 0;
		// A zero attachment ID can only clear a featured image during a
		// connector-owned rollback. Normal callers cannot bypass attachment checks.
		$clear_featured = 'featured_image' === $target_type
			&& ! empty( $context['rollback_mode'] )
			&& isset( $payload['id'] ) && 0 === (int) $payload['id'];
		$attachment_id  = $clear_featured ? 0 : $this->attachment_id( $payload );

		if ( ! current_user_can( 'upload_files' )
			|| ( $attachment_id > 0 && ! current_user_can( 'edit_post', $attachment_id ) ) ) {
			throw new RuntimeException( 'You cannot assign this media attachment.' );
		}

		// Only featured images and galleries use a post/product target ID.
		// Validate it without coercion after the existing media permission check.
		if ( in_array( $target_type, array( 'featured_image', 'product_gallery' ), true ) ) {
			try {
				$target_id = Input::positive_id( $payload['target_id'] ?? null );
			} catch ( RuntimeException $error ) {
				throw new RuntimeException(
					'featured_image' === $target_type
						? 'You cannot edit the featured-image target.'
						: 'You cannot edit the product gallery target.'
				);
			}
		}

		switch ( $target_type ) {
			case 'featured_image':
				if ( $target_id <= 0 || ! get_post( $target_id ) || ! current_user_can( 'edit_post', $target_id ) ) {
					throw new RuntimeException( 'You cannot edit the featured-image target.' );
				}
				$before = (int) get_post_thumbnail_id( $target_id );
				$result = array(
					'before'               => $before,
					'after'                => $attachment_id,
					'_current_fingerprint' => Fingerprint::make( $before ),
				);
				if ( ! empty( $context['dry_run'] ) ) {
					return $result;
				}
				if ( $before === $attachment_id ) {
					$result['unchanged'] = true;
					return $result;
				}
				if ( $clear_featured ) {
					if ( ! delete_post_thumbnail( $target_id ) ) {
						throw new RuntimeException( 'Could not clear featured image during rollback.' );
					}
				} elseif ( ! set_post_thumbnail( $target_id, $attachment_id ) ) {
					throw new RuntimeException( 'Could not assign featured image.' );
				}
				if ( (int) get_post_thumbnail_id( $target_id ) !== $attachment_id ) {
					throw new RuntimeException( 'Featured-image assignment readback failed.' );
				}
				if ( empty( $context['rollback_mode'] ) ) {
					$result['_rollback'] = array(
						'action'  => 'media.assign',
						'payload' => array(
							'id'          => $before,
							'target_type' => 'featured_image',
							'target_id'   => $target_id,
						),
					);
				}
				return $result;

			case 'product_gallery':
				if ( $target_id <= 0 || ! get_post( $target_id ) || ! current_user_can( 'edit_post', $target_id ) ) {
					throw new RuntimeException( 'You cannot edit the product gallery target.' );
				}
				if ( ! function_exists( 'wc_get_product' ) ) {
					throw new RuntimeException( 'WooCommerce is not active.' );
				}
				$product = wc_get_product( $target_id );
				if ( ! $product instanceof \WC_Product ) {
					throw new RuntimeException( 'Product not found.' );
				}
				$before = array_map( 'intval', $product->get_gallery_image_ids() );
				$mode   = isset( $payload['mode'] ) ? (string) $payload['mode'] : 'append';
				if ( ! in_array( $mode, array( 'append', 'replace' ), true ) ) {
					throw new RuntimeException( 'Gallery mode must be append or replace.' );
				}
				$after  = 'replace' === $mode ? array( $attachment_id ) : array_values( array_unique( array_merge( $before, array( $attachment_id ) ) ) );
				$result = array(
					'before'               => $before,
					'after'                => $after,
					'_current_fingerprint' => Fingerprint::make( $before ),
				);
				if ( ! empty( $context['dry_run'] ) ) {
					return $result;
				}
				$product->set_gallery_image_ids( $after );
				$product->save();
				if ( array_map( 'intval', $product->get_gallery_image_ids() ) !== $after ) {
					throw new RuntimeException( 'Product gallery assignment readback failed.' );
				}
				$result['_rollback'] = array(
					'action'  => 'woocommerce.product.update',
					'payload' => array(
						'id'                => $target_id,
						'gallery_image_ids' => $before,
					),
				);
				return $result;

			case 'custom_logo':
				if ( ! current_user_can( 'edit_theme_options' ) ) {
					throw new RuntimeException( 'Changing the custom logo requires edit_theme_options permission.' );
				}
				$before = (int) get_theme_mod( 'custom_logo', 0 );
				if ( ! empty( $context['dry_run'] ) ) {
					return array(
						'before'               => $before,
						'after'                => $attachment_id,
						'_current_fingerprint' => Fingerprint::make( $before ),
					);
				}
				set_theme_mod( 'custom_logo', $attachment_id );
				return array(
					'after'     => $attachment_id,
					'_rollback' => array(
						'action'  => 'theme_mod.update',
						'payload' => array(
							'name'  => 'custom_logo',
							'value' => $before,
						),
					),
				);

			case 'site_icon':
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new RuntimeException( 'Changing the site icon requires manage_options permission.' );
				}
				$before = (int) get_option( 'site_icon', 0 );
				if ( ! empty( $context['dry_run'] ) ) {
					return array(
						'before'               => $before,
						'after'                => $attachment_id,
						'_current_fingerprint' => Fingerprint::make( $before ),
					);
				}
				update_option( 'site_icon', $attachment_id, false );
				return array(
					'after'     => $attachment_id,
					'_rollback' => array(
						'action'  => 'option.update',
						'payload' => array(
							'name'  => 'site_icon',
							'value' => $before,
						),
					),
				);

			default:
				throw new RuntimeException( 'target_type must be featured_image, product_gallery, custom_logo or site_icon. Use acf.update, elementor.patch_element or gutenberg.patch for builder-specific assignments.' );
		}
	}

	/**
	 * Process the media regenerate operation in the Media adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function media_regenerate( array $payload, array $context ): array {
		$id = $this->attachment_id( $payload );
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			throw new RuntimeException( 'You cannot regenerate this media attachment.' );
		}
		$before = wp_get_attachment_metadata( $id );
		$file   = get_attached_file( $id );
		if ( ! $file || ! is_file( $file ) ) {
			throw new RuntimeException( 'Attachment file is missing.' );
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			throw new RuntimeException( 'Attachment is not an image.' );
		}
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_regenerate'     => $id,
				'before'               => $before,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $file );
		if ( ! is_array( $metadata ) || ! wp_update_attachment_metadata( $id, $metadata ) ) {
			throw new RuntimeException( 'Image metadata regeneration failed.' );
		}
		return array(
			'attachment_id'      => $id,
			'metadata'           => wp_get_attachment_metadata( $id ),
			'rollback_supported' => false,
		);
	}

	/**
	 * Preserve the legacy PHP entrypoint; delegate to the canonical implementation.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 * @return array Operation result.
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function media_rename_compat( array $payload, array $context ): array {
		return $this->media_rename( $payload, $context );
	}
}
