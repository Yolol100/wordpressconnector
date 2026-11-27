<?php
/**
 * Registers and handles Custom Css connector actions.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Registers and handles Custom Css connector actions.
 */
final class CustomCssAdapter {
	private const MAX_CSS_BYTES   = 524288;
	private const MAX_PATCH_BYTES = 65536;
	private const PATCH_PREFIX    = 'wpconnector:';

	/**
	 * Register the CustomCss adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register(
			'custom_css.inspect',
			array( $this, 'inspect' ),
			array(
				'privileged'  => true,
				'capability'  => 'edit_css',
				'description' => 'Read WordPress Additional CSS for one installed theme stylesheet.',
			)
		);

		$registry->register(
			'custom_css.update',
			array( $this, 'update' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'capability'  => 'edit_css',
				'description' => 'Replace WordPress Additional CSS with stale-state protection, readback and rollback.',
			)
		);

		$registry->register(
			'custom_css.patch',
			array( $this, 'patch' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'capability'  => 'edit_css',
				'description' => 'Upsert or remove one named managed Additional CSS block while preserving unrelated CSS, with stale-state protection, readback and rollback.',
			)
		);
	}

	/**
	 * Process the inspect operation in the CustomCss adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 */
	public function inspect( array $payload, array $context ): array {
		$snapshot = $this->snapshot( $this->stylesheet( $payload ) );

		return array(
			'custom_css'  => $snapshot,
			'fingerprint' => Fingerprint::make( $snapshot ),
		);
	}

	/**
	 * Process the update operation in the CustomCss adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function update( array $payload, array $context ): array {
		if ( ! array_key_exists( 'css', $payload ) || ! is_string( $payload['css'] ) ) {
			throw new RuntimeException( 'custom_css.update requires payload.css as a string.' );
		}

		$css = $payload['css'];
		$this->assert_css( $css, self::MAX_CSS_BYTES, 'Additional CSS' );

		$stylesheet = $this->stylesheet( $payload );
		$before     = $this->snapshot( $stylesheet );
		$result     = array(
			'before'               => $before,
			'after'                => array(
				'stylesheet' => $stylesheet,
				'css'        => $css,
				'post_id'    => $before['post_id'],
			),
			'readback_verified'    => null,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$updated = wp_update_custom_css_post( $css, array( 'stylesheet' => $stylesheet ) );
		if ( is_wp_error( $updated ) ) {
			throw new RuntimeException( 'WordPress Additional CSS update failed.' );
		}
		if ( ! $updated instanceof \WP_Post ) {
			throw new RuntimeException( 'WordPress did not return an Additional CSS post after update.' );
		}

		clean_post_cache( (int) $updated->ID );
		$readback = $this->snapshot( $stylesheet );

		if ( $readback['css'] !== $css ) {
			wp_update_custom_css_post( (string) $before['css'], array( 'stylesheet' => $stylesheet ) );
			throw new RuntimeException( 'Additional CSS readback verification failed; the previous CSS was restored.' );
		}

		$result['after']             = $readback;
		$result['readback_verified'] = true;
		$result['_rollback']         = array(
			'action'  => 'custom_css.update',
			'payload' => array(
				'stylesheet' => $stylesheet,
				'css'        => (string) $before['css'],
			),
		);

		return $result;
	}

	/**
	 * Process the patch operation in the CustomCss adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function patch( array $payload, array $context ): array {
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'stylesheet', 'patch_id', 'operation', 'css' ), true ) ) {
				throw new RuntimeException( 'custom_css.patch contains unsupported payload key.' );
			}
		}

		$patch_id = isset( $payload['patch_id'] ) && is_string( $payload['patch_id'] ) ? $payload['patch_id'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}\z/', $patch_id ) ) {
			throw new RuntimeException( 'custom_css.patch requires patch_id with 3-64 safe characters.' );
		}

		$operation = isset( $payload['operation'] ) && is_string( $payload['operation'] ) ? strtolower( $payload['operation'] ) : '';
		if ( ! in_array( $operation, array( 'upsert', 'remove' ), true ) ) {
			throw new RuntimeException( 'custom_css.patch operation must be upsert or remove.' );
		}

		$patch_css = null;
		if ( 'upsert' === $operation ) {
			if ( ! array_key_exists( 'css', $payload ) || ! is_string( $payload['css'] ) || '' === trim( $payload['css'] ) ) {
				throw new RuntimeException( 'custom_css.patch upsert requires non-empty payload.css.' );
			}
			$patch_css = $payload['css'];
			$this->assert_css( $patch_css, self::MAX_PATCH_BYTES, 'Managed CSS patch' );
			if ( false !== stripos( $patch_css, self::PATCH_PREFIX ) ) {
				throw new RuntimeException( 'Managed CSS patch may not contain connector patch markers.' );
			}
		} elseif ( array_key_exists( 'css', $payload ) ) {
			throw new RuntimeException( 'custom_css.patch remove must not include payload.css.' );
		}

		$stylesheet = $this->stylesheet( $payload );
		$before     = $this->snapshot( $stylesheet );
		$after_css  = $this->apply_patch( (string) $before['css'], $patch_id, $operation, $patch_css );
		$this->assert_css( $after_css, self::MAX_CSS_BYTES, 'Additional CSS after managed patch' );

		$after  = array(
			'stylesheet' => $stylesheet,
			'css'        => $after_css,
			'post_id'    => $before['post_id'],
		);
		$result = array(
			'patch_id'             => $patch_id,
			'operation'            => $operation,
			'changed'              => $after_css !== (string) $before['css'],
			'before'               => $before,
			'after'                => $after,
			'before_css_bytes'     => strlen( (string) $before['css'] ),
			'after_css_bytes'      => strlen( $after_css ),
			'before_css_sha256'    => hash( 'sha256', (string) $before['css'] ),
			'after_css_sha256'     => hash( 'sha256', $after_css ),
			'readback_verified'    => null,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		if ( ! $result['changed'] ) {
			$result['readback_verified'] = true;
			return $result;
		}

		$updated = wp_update_custom_css_post( $after_css, array( 'stylesheet' => $stylesheet ) );
		if ( is_wp_error( $updated ) ) {
			throw new RuntimeException( 'WordPress Additional CSS patch failed.' );
		}
		if ( ! $updated instanceof \WP_Post ) {
			throw new RuntimeException( 'WordPress did not return an Additional CSS post after managed patch.' );
		}

		clean_post_cache( (int) $updated->ID );
		$readback = $this->snapshot( $stylesheet );
		if ( $readback['css'] !== $after_css ) {
			wp_update_custom_css_post( (string) $before['css'], array( 'stylesheet' => $stylesheet ) );
			throw new RuntimeException( 'Managed Additional CSS patch readback failed; the previous CSS was restored.' );
		}

		$result['after']             = $readback;
		$result['readback_verified'] = true;
		$result['_rollback']         = array(
			'action'  => 'custom_css.update',
			'payload' => array(
				'stylesheet' => $stylesheet,
				'css'        => (string) $before['css'],
			),
		);

		return $result;
	}

	/**
	 * Process the apply patch operation in the CustomCss adapter.
	 *
	 * @param string      $current_css Input for the operation.
	 * @param string      $patch_id Input for the operation.
	 * @param string      $operation Input for the operation.
	 * @param string|null $patch_css Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function apply_patch( string $current_css, string $patch_id, string $operation, ?string $patch_css ): string {
		$start       = '/* ' . self::PATCH_PREFIX . $patch_id . ' */';
		$end         = '/* /' . self::PATCH_PREFIX . $patch_id . ' */';
		$start_count = substr_count( $current_css, $start );
		$end_count   = substr_count( $current_css, $end );

		if ( $start_count > 1 || $end_count > 1 || $start_count !== $end_count ) {
			throw new RuntimeException( 'Managed CSS patch markers are duplicated or incomplete for patch_id: ' . $patch_id );
		}

		$start_pos = strpos( $current_css, $start );
		$end_pos   = false !== $start_pos ? strpos( $current_css, $end, $start_pos + strlen( $start ) ) : false;
		if ( false !== $start_pos && ( false === $end_pos || $end_pos < $start_pos ) ) {
			throw new RuntimeException( 'Managed CSS patch markers are malformed for patch_id: ' . $patch_id );
		}

		if ( 'remove' === $operation ) {
			if ( false === $start_pos || false === $end_pos ) {
				return $current_css;
			}
			$end_after = $end_pos + strlen( $end );
			return substr( $current_css, 0, $start_pos ) . substr( $current_css, $end_after );
		}

		$block = $start . "\n" . rtrim( (string) $patch_css ) . "\n" . $end;
		if ( false !== $start_pos && false !== $end_pos ) {
			$end_after = $end_pos + strlen( $end );
			return substr( $current_css, 0, $start_pos ) . $block . substr( $current_css, $end_after );
		}

		if ( '' === $current_css ) {
			return $block . "\n";
		}

		$separator = preg_match( '/(?:\r\n|\r|\n)\z/', $current_css ) ? "\n" : "\n\n";
		return $current_css . $separator . $block . "\n";
	}

	/**
	 * Process the snapshot operation in the CustomCss adapter.
	 *
	 * @param string $stylesheet Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function snapshot( string $stylesheet ): array {
		$post = wp_get_custom_css_post( $stylesheet );

		return array(
			'stylesheet' => $stylesheet,
			'css'        => (string) wp_get_custom_css( $stylesheet ),
			'post_id'    => $post instanceof \WP_Post ? (int) $post->ID : null,
		);
	}

	/**
	 * Process the stylesheet operation in the CustomCss adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function stylesheet( array $payload ): string {
		$stylesheet = isset( $payload['stylesheet'] )
			? sanitize_text_field( (string) $payload['stylesheet'] )
			: (string) get_stylesheet();

		if ( '' === $stylesheet ) {
			throw new RuntimeException( 'Theme stylesheet is required.' );
		}

		$themes = wp_get_themes();
		if ( ! isset( $themes[ $stylesheet ] ) ) {
			throw new RuntimeException( 'Theme stylesheet is not installed: ' . $stylesheet );
		}

		return $stylesheet;
	}

	/**
	 * Ensure css for the CustomCss adapter.
	 *
	 * @param string $css Input for the operation.
	 * @param int    $max_bytes Input for the operation.
	 * @param string $label Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_css( string $css, int $max_bytes, string $label ): void {
		if ( strlen( $css ) > $max_bytes ) {
			throw new RuntimeException( $label . ' exceeds the connector size limit.' );
		}
		if ( preg_match( '/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $css ) ) {
			throw new RuntimeException( $label . ' contains unsupported control characters.' );
		}
		if ( false !== stripos( $css, '</style' ) ) {
			throw new RuntimeException( $label . ' may not contain a closing style tag.' );
		}
		if ( preg_match( '/<\/s(?:t(?:y(?:l(?:e)?)?)?)?\s*\z/i', $css ) ) {
			throw new RuntimeException( $label . ' may not end with a partial closing style tag.' );
		}
	}
}
