<?php
/**
 * Registers and handles Media connector actions.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\ChainedFailure;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;
use Webactueel\WordPressConnector\Support\LocalFileIdentity;
use Webactueel\WordPressConnector\Support\LocalFilesystem;

require_once __DIR__ . '/../Support/class-chainedfailure.php';

require_once __DIR__ . '/trait-mediaadapteractions.php';
require_once __DIR__ . '/../Support/class-localfileidentity.php';
require_once __DIR__ . '/../Support/class-localfilesystem.php';

/**
 * Registers and handles Media connector actions.
 */
final class MediaAdapter {
	use MediaAdapterActions {
		media_rename_compat as public mediaRename;
		media_list as mediaList;
		media_get as mediaGet;
		media_import as mediaImport;
		media_update as mediaUpdate;
		media_assign as mediaAssign;
		media_regenerate as mediaRegenerate;
	}

	/**
	 * Register the Media adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register( 'media.list', array( $this, 'mediaList' ), array( 'description' => 'List media attachments.' ) );
		$registry->register( 'media.get', array( $this, 'mediaGet' ), array( 'description' => 'Read one media attachment and metadata.' ) );
		$registry->register(
			'media.import',
			array( $this, 'mediaImport' ),
			array(
				'mutation'    => true,
				'description' => 'Import a file from the trusted request asset root into the media library.',
			)
		);
		$registry->register(
			'media.rename',
			array( $this, 'mediaRename' ),
			array(
				'mutation'    => true,
				'capability'  => 'manage_options',
				'description' => 'Safely rename an image with retained old-URL compatibility files, dry-run, and exact metadata readback.',
			)
		);
		$registry->register(
			'media.update',
			array( $this, 'mediaUpdate' ),
			array(
				'mutation'    => true,
				'description' => 'Update attachment title, alt text, caption, description and parent.',
			)
		);
		$registry->register(
			'media.assign',
			array( $this, 'mediaAssign' ),
			array(
				'mutation'    => true,
				'description' => 'Assign media as featured image, Woo gallery image, custom logo or site icon.',
			)
		);
		$registry->register(
			'media.regenerate',
			array( $this, 'mediaRegenerate' ),
			array(
				'mutation'    => true,
				'description' => 'Regenerate attachment metadata and image subsizes.',
			)
		);
	}

	/**
	 * Process the media rename operation in the Media adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function media_rename( array $payload, array $context ): array {
		$id = $this->attachment_id( $payload );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'upload_files' ) ) {
			throw new RuntimeException( 'Administrator media permissions are required.' );
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			throw new RuntimeException( 'Only WordPress image attachments can be renamed.' );
		}
		$requested = isset( $payload['filename'] ) && is_string( $payload['filename'] ) ? $payload['filename'] : '';
		if ( strlen( $requested ) < 5 || strlen( $requested ) > 150 || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*\\.(?:jpg|jpeg|png|webp|gif)$/D', $requested ) ) {
			throw new RuntimeException( 'Use a lowercase descriptive hyphen-separated image filename with the original extension.' );
		}

		$source      = get_attached_file( $id );
		$uploads     = wp_get_upload_dir();
		$root        = isset( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		$source_real = is_string( $source ) ? realpath( $source ) : false;
		if ( ! is_string( $root ) || ! is_string( $source_real ) || ! is_file( $source_real ) ||
			0 !== strpos( $source_real, $root . DIRECTORY_SEPARATOR ) || is_link( $source ) ) {
			throw new RuntimeException( 'Image must be a regular file inside WordPress uploads.' );
		}
		$directory = dirname( $source_real );
		$extension = strtolower( (string) pathinfo( $source_real, PATHINFO_EXTENSION ) );
		if ( strtolower( (string) pathinfo( $requested, PATHINFO_EXTENSION ) ) !== $extension ) {
			throw new RuntimeException( 'Image format changes require a separate conversion.' );
		}
		$old_name = basename( $source_real );
		if ( $requested === $old_name ) {
			return array(
				'unchanged'            => true,
				'attachment_id'        => $id,
				'_current_fingerprint' => Fingerprint::make( $this->snapshot( $id ) ),
			);
		}
		$metadata = wp_get_attachment_metadata( $id );
		if ( ! is_array( $metadata ) || ! isset( $metadata['file'] ) || ! is_string( $metadata['file'] ) ||
			basename( $metadata['file'] ) !== $old_name ) {
			throw new RuntimeException( 'Image metadata does not match the attachment file.' );
		}
		$old_stem = pathinfo( $old_name, PATHINFO_FILENAME );
		$new_stem = pathinfo( $requested, PATHINFO_FILENAME );
		$copies   = array( $old_name => $requested );
		foreach ( ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! is_array( $size ) || ! isset( $size['file'] ) || ! is_string( $size['file'] ) ) {
				throw new RuntimeException( 'Invalid image size metadata.' );
			}
			$child = $size['file'];
			if ( ! preg_match( '/^' . preg_quote( $old_stem, '/' ) . '(-[0-9]+x[0-9]+(?:-[a-z0-9-]+)?)\\.([a-z0-9]+)$/iD', $child, $match ) ) {
				throw new RuntimeException( 'Image derivative naming is unsupported; refusing unsafe rename.' );
			}
			$copies[ $child ] = $new_stem . $match[1] . '.' . $match[2];
		}
		$original = $metadata['original_image'] ?? null;
		if ( is_string( $original ) && '' !== $original ) {
			throw new RuntimeException( 'Images with a separate original_image require manual migration.' );
		}
		foreach ( $copies as $from => $to ) {
			if ( basename( $from ) !== $from || basename( $to ) !== $to || is_link( $directory . DIRECTORY_SEPARATOR . $from ) ||
				! is_file( $directory . DIRECTORY_SEPARATOR . $from ) || file_exists( $directory . DIRECTORY_SEPARATOR . $to ) ) {
				throw new RuntimeException( 'Image derivative missing, symlinked, or filename collision.' );
			}
		}
		$before = $this->snapshot( $id );
		$plan   = array(
			'attachment_id'      => $id,
			'old_filename'       => $old_name,
			'new_filename'       => $requested,
			'copies'             => count( $copies ),
			'old_urls_preserved' => true,
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_rename'         => $plan,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		$created = array();
		try {
			foreach ( $copies as $from => $to ) {
				$old_path = $directory . DIRECTORY_SEPARATOR . $from;
				$new_path = $directory . DIRECTORY_SEPARATOR . $to;
				try {
					$input = new \SplFileObject( $old_path, 'rb' );
				} catch ( \Throwable $error ) {
					throw new RuntimeException( 'Image copy source is unavailable.' );
				}
				try {
					$stat = $input->fstat();
					if ( ! is_array( $stat ) || ( $stat['mode'] & 0170000 ) !== 0100000 || is_link( $old_path ) ) {
						throw new RuntimeException( 'Image copy source is unavailable.' );
					}
					// Open exclusively after validating the source, never clobbering
					// another upload or leaving an untracked empty destination.
					try {
						$output = new \SplFileObject( $new_path, 'xb' );
					} catch ( \Throwable $error ) {
						throw new RuntimeException( 'New filename already exists or cannot be created.' );
					}
					$created[ $new_path ] = array(
						'handle' => $output,
						'stat'   => null,
					);
					try {
						$created_stat = $output->fstat();
						if ( ! is_array( $created_stat ) || 0 === $created_stat['ino'] ) {
							throw new RuntimeException( 'New image file identity could not be verified.' );
						}
						$created[ $new_path ]['stat'] = $created_stat;

						$remaining = $stat['size'];
						while ( $remaining > 0 ) {
							$chunk = $input->fread( min( 65536, $remaining ) );
							if ( ! is_string( $chunk ) || '' === $chunk ) {
								throw new RuntimeException( 'Image file copy was incomplete.' );
							}
							$length = strlen( $chunk );
							$offset = 0;
							while ( $offset < $length ) {
								$written = $output->fwrite( substr( $chunk, $offset ) );
								if ( ! is_int( $written ) || $written <= 0 ) {
									throw new RuntimeException( 'Image file copy was incomplete.' );
								}
								$offset += $written;
							}
							$remaining -= $length;
						}
						// Detect growth as well as truncation and buffered write failure.
						if ( '' !== $input->fread( 1 ) || ! $output->fflush() ) {
							throw new RuntimeException( 'Image file copy was incomplete.' );
						}
					} catch ( \Throwable $error ) {
						throw new RuntimeException( 'Image file copy was incomplete.' );
					} finally {
						unset( $output );
					}
				} finally {
					unset( $input );
				}
			}
			$this->assert_created_copies( $created );
			$after_metadata         = $metadata;
			$after_metadata['file'] = dirname( $metadata['file'] ) === '.' ? $requested : dirname( $metadata['file'] ) . '/' . $requested;
			foreach ( ( $after_metadata['sizes'] ?? array() ) as $size_name => $size ) {
				$after_metadata['sizes'][ $size_name ]['file'] = $copies[ $size['file'] ];
			}
			if ( ! update_attached_file( $id, $directory . DIRECTORY_SEPARATOR . $requested ) ) {
				throw new RuntimeException( 'WordPress could not update the attachment path.' );
			}
			if ( ! wp_update_attachment_metadata( $id, $after_metadata ) ) {
				throw new RuntimeException( 'WordPress could not update image metadata.' );
			}
			clean_post_cache( $id );
			$readback = $this->snapshot( $id );
			$this->assert_created_copies( $created );
			if ( realpath( (string) $readback['file'] ) !== realpath( $directory . DIRECTORY_SEPARATOR . $requested ) ||
				Fingerprint::make( $readback['metadata'] ) !== Fingerprint::make( $after_metadata ) ||
				! is_file( $source_real ) ) {
				throw new RuntimeException( 'Image rename readback verification failed.' );
			}
			return array(
				'renamed'            => $plan,
				'after'              => $readback,
				'rollback_supported' => false,
				'old_urls_preserved' => true,
			);
		} catch ( \Throwable $error ) {
			// A metadata write can fail after another part already committed.
			// Never delete new files unless BOTH original WordPress pointers
			// were independently read back as restored.
			update_attached_file( $id, $source_real );
			wp_update_attachment_metadata( $id, $metadata );
			clean_post_cache( $id );
			$restored_path     = get_attached_file( $id, true );
			$restored_metadata = wp_get_attachment_metadata( $id, true );
			$restored          = is_string( $restored_path )
				&& realpath( $restored_path ) === $source_real
				&& is_array( $restored_metadata )
				&& hash_equals( Fingerprint::make( $metadata ), Fingerprint::make( $restored_metadata ) );
			if ( ! $restored ) {
				$failure = ChainedFailure::create(
					'Image rename aborted and original WordPress attachment state could not be verified. '
					. 'New image copies have been retained to prevent broken references; restore from backup.',
					$error
				);
				throw $failure;
			}
			if ( ! $this->remove_created_copies( $created ) ) {
				throw new RuntimeException( 'Image rename aborted; original attachment state verified as restored, but a new path changed or could not be removed; manual recovery required.' );
			}
			$failure = ChainedFailure::create( 'Image rename aborted; original attachment state verified as restored: ' . $error->getMessage(), $error );
			throw $failure;
		} finally {
			// Retain exclusive handles until the final identity/cleanup checks.
			$created = array();
		}
	}

	/**
	 * Verify that all new image paths still refer to the created files.
	 *
	 * @param array $created Exclusive handles and their original identities.
	 * @throws \RuntimeException When a destination identity has changed.
	 */
	private function assert_created_copies( array $created ): void {
		foreach ( $created as $path => $entry ) {
			if ( ! LocalFileIdentity::matches( $path, $entry['stat'] ) ) {
				throw new RuntimeException( 'New image file identity changed before verification.' );
			}
		}
	}

	/**
	 * Remove only the copies still owned by this failed operation.
	 *
	 * Use the direct core backend, never a path-filtered or recursive delete.
	 * Refuse POSIX filenames that the backend would reinterpret as separators.
	 *
	 * @param array $created Exclusive handles and identities.
	 * @return bool Whether every intended destination is now absent.
	 */
	private function remove_created_copies( array &$created ): bool {
		$clean = true;
		foreach ( array_reverse( array_keys( $created ) ) as $created_file ) {
			clearstatcache( true, $created_file );
			if ( ! file_exists( $created_file ) && ! is_link( $created_file ) ) {
				$created[ $created_file ]['handle'] = null;
				continue;
			}
			if ( ! LocalFileIdentity::matches( $created_file, $created[ $created_file ]['stat'] ) ||
				! LocalFilesystem::remove_file( $created_file, $created[ $created_file ]['handle'] ) ) {
				$clean = false;
			}
		}
		return $clean;
	}

	/**
	 * Process the attachment id operation in the Media adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return int Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function attachment_id( array $payload ): int {
		try {
			$id = Input::positive_id( $payload['id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'Attachment not found.' );
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			throw new RuntimeException( 'Attachment not found.' );
		}
		return $id;
	}


	/**
	 * Validate a media parent before previewing or changing its attachment.
	 *
	 * @param mixed $value   Untrusted parent identifier.
	 * @param bool  $for_import Whether validating the media import action.
	 *
	 * @return int Validated parent ID, or zero for a detached attachment.
	 *
	 * @throws \RuntimeException When the parent is invalid or not editable.
	 */
	private function validated_parent_id( $value, bool $for_import ): int {
		try {
			$parent_id = Input::non_negative_id( $value );
			if ( $parent_id > 0 && ( ! get_post( $parent_id ) || ! current_user_can( 'edit_post', $parent_id ) ) ) {
				throw new RuntimeException( 'Invalid media parent.' );
			}
			return $parent_id;
		} catch ( RuntimeException $error ) {
			if ( $for_import ) {
				throw new RuntimeException( 'You cannot attach media to this parent item.' );
			}
			throw new RuntimeException( 'You cannot assign this media attachment to the requested parent.' );
		}
	}

	/**
	 * Process the snapshot operation in the Media adapter.
	 *
	 * @param int $id Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function snapshot( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Attachment not found.' );
		}
		return array(
			'id'          => $id,
			'title'       => (string) $post->post_title,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => (string) $post->post_excerpt,
			'description' => (string) $post->post_content,
			'parent'      => (int) $post->post_parent,
			'mime_type'   => (string) $post->post_mime_type,
			'url'         => wp_get_attachment_url( $id ),
			'file'        => get_attached_file( $id ),
			'metadata'    => wp_get_attachment_metadata( $id ),
		);
	}

	/**
	 * Process the apply update operation in the Media adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function apply_update( array $payload ): void {
		$id          = $this->attachment_id( $payload );
		$post_fields = array( 'ID' => $id );
		if ( array_key_exists( 'title', $payload ) ) {
			$post_fields['post_title'] = sanitize_text_field( (string) $payload['title'] );
		}
		if ( array_key_exists( 'caption', $payload ) ) {
			$post_fields['post_excerpt'] = (string) $payload['caption'];
		}
		if ( array_key_exists( 'description', $payload ) ) {
			$post_fields['post_content'] = (string) $payload['description'];
		}
		if ( array_key_exists( 'parent', $payload ) ) {
			$post_fields['post_parent'] = $this->validated_parent_id( $payload['parent'], false );
		}
		if ( count( $post_fields ) > 1 ) {
			$updated = wp_update_post( wp_slash( $post_fields ), true );
			if ( is_wp_error( $updated ) || $id !== $updated ) {
				throw new RuntimeException( 'WordPress media attachment update failed.' );
			}
		}
		if ( array_key_exists( 'alt', $payload ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $payload['alt'] ) );
		}
	}
}
