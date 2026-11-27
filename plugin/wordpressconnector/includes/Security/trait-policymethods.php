<?php
/**
 * Canonical public method implementations for Policy.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait PolicyMethods {
	/**
	 * Ensure public action target for the Policy component.
	 *
	 * @param string $action Input for the operation.
	 * @param array  $payload Requested action payload.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assert_public_action_target( string $action, array $payload ): void {
		if ( ! self::publicRepositoryContext() || ! in_array( $action, array( 'post.update', 'post.literal_replace', 'acf.update', 'elementor.inspect', 'elementor.patch_element', 'yoast.snippet.inspect', 'yoast.snippet.update', 'yoast.snippet.restore' ), true ) ) {
			return;
		}

		if ( 'acf.update' === $action ) {
			$post_id = isset( $payload['post_id'] ) && is_int( $payload['post_id'] ) ? $payload['post_id'] : ( isset( $payload['target'] ) && is_int( $payload['target'] ) ? $payload['target'] : 0 );
		} else {
			$post_id = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
		}
		if ( $post_id <= 0 ) {
			throw new RuntimeException( 'Public ' . esc_html( $action ) . ' requires a positive post id.' );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Public ' . esc_html( $action ) . ' target post was not found.' );
		}
		$requires_edit = in_array( $action, array( 'post.update', 'post.literal_replace', 'acf.update', 'elementor.patch_element', 'yoast.snippet.inspect', 'yoast.snippet.update', 'yoast.snippet.restore' ), true );
		self::assertPublicContentTarget( $post, $requires_edit );
	}

	/**
	 * Ensure post readable for the Policy component.
	 *
	 * @param \WP_Post $post Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assert_post_readable( \WP_Post $post ): void {
		self::assertReadablePostType( (string) $post->post_type );
		if ( ! self::publicRepositoryContext() ) {
			return; }
		if ( 'attachment' === $post->post_type ) {
			if ( (int) $post->post_parent > 0 ) {
				$parent = get_post( (int) $post->post_parent );
				if ( $parent instanceof \WP_Post && 'publish' !== $parent->post_status ) {
					throw new RuntimeException( 'Attachment parent is not public.' );
				}
			}
			return;
		}
		$post_type = get_post_type_object( (string) $post->post_type );
		if ( ! $post_type || ! $post_type->public ) {
			throw new RuntimeException( 'Non-public post types cannot be exported in public-repository mode.' );
		}
		if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			throw new RuntimeException( 'Only public, non-password-protected content may be exported in public-repository mode.' );
		}
	}

	/**
	 * Ensure key allowed for the Policy component.
	 *
	 * @param string $key Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assert_key_allowed( string $key ): void {
		if ( (bool) preg_match( '/(password|passwd|secret|token|api[_-]?key|private[_-]?key|consumer_secret|authorization|cookie|auth_key|secure_auth|logged_in_key|nonce_key|salt)/i', $key ) ) {
			throw new RuntimeException( 'Secret-like keys cannot be read or written through the connector.' );
		}
	}

	/**
	 * Ensure meta key allowed for the Policy component.
	 *
	 * @param string $key Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assert_meta_key_allowed( string $key ): void {
		self::assertKeyAllowed( $key );
		if ( isset( $key[0] ) && '_' === $key[0] ) {
			throw new RuntimeException( 'Protected/internal metadata must use its dedicated semantic adapter.' );
		}
	}

	/**
	 * Ensure option key allowed for the Policy component.
	 *
	 * @param string $key Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assert_option_key_allowed( string $key ): void {
		self::assertKeyAllowed( $key );
		if ( in_array( strtolower( $key ), self::BLOCKED_OPTION_KEYS, true ) ) {
			throw new RuntimeException( 'System-owned option must use its dedicated semantic action instead of generic option access.' );
		}
	}

	/**
	 * Process the public repository context operation in the Policy component.
	 *
	 * @return bool Operation result.
	 */
	public static function public_repository_context(): bool {
		if ( null !== self::$runtime_public_repository ) {
			return self::$runtime_public_repository; }
		return self::flag( 'WPCONNECTOR_PUBLIC_REPOSITORY' );
	}
}
