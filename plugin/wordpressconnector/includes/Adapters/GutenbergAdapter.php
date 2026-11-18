<?php
/**
 * Registers and handles Gutenberg connector actions.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

/**
 * Registers and handles Gutenberg connector actions.
 */
final class GutenbergAdapter {
	/**
	 * Register the Gutenberg adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register( 'gutenberg.inspect', array( $this, 'inspect' ), array( 'description' => 'Parse Gutenberg/block content into a block tree.' ) );
		$registry->register(
			'gutenberg.replace',
			array( $this, 'replace' ),
			array(
				'mutation'    => true,
				'description' => 'Replace the entire serialized block document.',
			)
		);
		$registry->register(
			'gutenberg.patch',
			array( $this, 'patch' ),
			array(
				'mutation'    => true,
				'description' => 'Patch one block by nested numeric path.',
			)
		);
	}

	/**
	 * Process the inspect operation in the Gutenberg adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Operation result.
	 */
	public function inspect( array $payload ): array {
		$post = $this->post( $payload );
		Policy::assertPostReadable( $post );
		$blocks = parse_blocks( (string) $post->post_content );
		return array(
			'post_id'     => (int) $post->ID,
			'blocks'      => $blocks,
			'fingerprint' => Fingerprint::make( $blocks ),
		);
	}

	/**
	 * Process the replace operation in the Gutenberg adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function replace( array $payload, array $context ): array {
		$post           = $this->editable_post( $payload );
		$before_content = (string) $post->post_content;
		$before_blocks  = parse_blocks( $before_content );

		if ( isset( $payload['blocks'] ) && is_array( $payload['blocks'] ) ) {
			$after_content = serialize_blocks( $payload['blocks'] );
		} elseif ( isset( $payload['content'] ) ) {
			if ( ! is_string( $payload['content'] ) ) {
				throw new RuntimeException( 'gutenberg.replace requires payload.content to be a string.' );
			}
			$after_content = $payload['content'];
			parse_blocks( $after_content );
		} else {
			throw new RuntimeException( 'gutenberg.replace requires payload.blocks or payload.content.' );
		}

		$result = array(
			'post_id'              => (int) $post->ID,
			'before_blocks'        => $before_blocks,
			'after_blocks'         => parse_blocks( $after_content ),
			'_current_fingerprint' => Fingerprint::make( $before_blocks ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) $post->ID,
					'post_content' => $after_content,
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			throw new RuntimeException( $updated->get_error_message() );
		}

		$result['_rollback'] = array(
			'action'  => 'gutenberg.replace',
			'payload' => array(
				'id'      => (int) $post->ID,
				'content' => $before_content,
			),
		);
		return $result;
	}

	/**
	 * Process the patch operation in the Gutenberg adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function patch( array $payload, array $context ): array {
		$post           = $this->editable_post( $payload );
		$before_content = (string) $post->post_content;
		$blocks         = parse_blocks( $before_content );
		$path           = isset( $payload['path'] ) && is_array( $payload['path'] ) ? $payload['path'] : array();
		if ( ! $path || array_keys( $path ) !== range( 0, count( $path ) - 1 ) ) {
			throw new RuntimeException( 'gutenberg.patch requires a non-empty numeric payload.path.' );
		}
		// Do not silently coerce a malformed index (for example "abc" or
		// false) into block zero. Canonical digit strings remain supported.
		foreach ( $path as $depth => $index ) {
			if ( is_int( $index ) && $index >= 0 ) {
				continue;
			}
			if ( ! is_string( $index ) ||
				! preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $index ) ||
				(string) (int) $index !== $index ) {
				throw new RuntimeException( 'gutenberg.patch path indexes must be non-negative integers.' );
			}
			$path[ $depth ] = (int) $index;
		}

		$patched      = $blocks;
		$block        =& $this->block_by_path( $patched, $path );
		$before_block = $block;

		if ( isset( $payload['replace_block'] ) && is_array( $payload['replace_block'] ) ) {
			$block = $payload['replace_block'];
		} else {
			if ( isset( $payload['attrs'] ) && is_array( $payload['attrs'] ) ) {
				$replace_attrs  = Input::bool( $payload, 'replace_attrs' );
				$block['attrs'] = $replace_attrs ? $payload['attrs'] : array_merge( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(), $payload['attrs'] );
			}
			if ( array_key_exists( 'innerHTML', $payload ) ) {
				if ( ! is_string( $payload['innerHTML'] ) ) {
					throw new RuntimeException( 'gutenberg.patch requires payload.innerHTML to be a string.' );
				}
				$block['innerHTML'] = $payload['innerHTML'];
				if ( empty( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
					$block['innerContent'] = array( $payload['innerHTML'] );
				} elseif ( 1 === count( $block['innerContent'] ) && is_string( $block['innerContent'][0] ) ) {
					$block['innerContent'][0] = $payload['innerHTML'];
				} else {
					throw new RuntimeException( 'innerHTML patch is ambiguous for blocks with nested innerContent. Use replace_block.' );
				}
			}
		}

		$after_content = serialize_blocks( $patched );
		$result        = array(
			'post_id'              => (int) $post->ID,
			'path'                 => $path,
			'before_block'         => $before_block,
			'after_block'          => $block,
			'_current_fingerprint' => Fingerprint::make( $blocks ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) $post->ID,
					'post_content' => $after_content,
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			throw new RuntimeException( $updated->get_error_message() );
		}

		$result['_rollback'] = array(
			'action'  => 'gutenberg.replace',
			'payload' => array(
				'id'      => (int) $post->ID,
				'content' => $before_content,
			),
		);
		return $result;
	}

	/**
	 * Require per-document editing permission before computing a Gutenberg
	 * mutation preview or performing a write. Read-only inspect remains separate.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return \WP_Post Editable document.
	 *
	 * @throws \RuntimeException When the current user cannot edit the post.
	 */
	private function editable_post( array $payload ): \WP_Post {
		$post = $this->post( $payload );
		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			throw new RuntimeException( 'Current user cannot edit this Gutenberg document.' );
		}
		return $post;
	}

	/**
	 * Process the post operation in the Gutenberg adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return \WP_Post Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function post( array $payload ): \WP_Post {
		$id = $payload['id'] ?? null;
		// Numeric request fields may arrive as strings. Accept decimal digits
		// (including harmless leading zeroes), never truncated or coerced IDs.
		if ( is_string( $id ) && preg_match( '/\A[0-9]+\z/', $id ) ) {
			$digits = ltrim( $id, '0' );
			if ( '' !== $digits && (string) (int) $digits === $digits ) {
				$id = (int) $digits;
			}
		}
		if ( ! is_int( $id ) || $id < 1 ) {
			throw new RuntimeException( 'Gutenberg post id must be a positive integer.' );
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Post not found.' );
		}
		Policy::assertReadablePostType( (string) $post->post_type );
		return $post;
	}

	/**
	 * Process the block by path operation in the Gutenberg adapter.
	 *
	 * @param array $blocks Value updated by reference.
	 * @param array $path Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function &block_by_path( array &$blocks, array $path ): array {
		$current =& $blocks;
		foreach ( $path as $depth => $index ) {
			if ( ! isset( $current[ $index ] ) || ! is_array( $current[ $index ] ) ) {
				throw new RuntimeException( 'Block path does not exist at depth ' . $depth . '.' );
			}
			$block =& $current[ $index ];
			if ( count( $path ) - 1 === $depth ) {
				return $block;
			}
			if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
				throw new RuntimeException( 'Block path enters a block without innerBlocks.' );
			}
			$current =& $block['innerBlocks'];
		}

		throw new RuntimeException( 'Invalid block path.' );
	}
}
