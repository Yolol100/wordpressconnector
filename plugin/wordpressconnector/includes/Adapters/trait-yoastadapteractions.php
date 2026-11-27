<?php
/**
 * Canonical public method implementations for YoastAdapter.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait YoastAdapterActions {
	/**
	 * Update site representation for the Yoast adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function update_site_representation( array $payload, array $context ): array {
		$updates = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		if ( ! $updates || count( $updates ) > 3 ) {
			throw new RuntimeException( 'Site representation requires 1-3 allowlisted fields.' );
		}

		$before = $this->site_representation_snapshot();
		if ( 'company' !== $before['company_or_person'] ) {
			throw new RuntimeException( 'Site representation is not set to Organization in Yoast.' );
		}

		$options       = get_option( 'wpseo_titles', null );
		$after_options = $options;
		foreach ( $updates as $key => $value ) {
			if ( 'logo_attachment_id' === $key ) {
				if ( ! is_int( $value ) || $value < 1 ) {
					throw new RuntimeException( 'Organization logo requires a positive WordPress media attachment ID.' );
				}
				$attachment = get_post( $value );
				if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $value ) ) {
					throw new RuntimeException( 'Organization logo must reference a WordPress image attachment.' );
				}
				$meta = wp_get_attachment_metadata( $value );
				if ( ! is_array( $meta ) || (int) ( $meta['width'] ?? 0 ) < 112 || (int) ( $meta['height'] ?? 0 ) < 112 ) {
					throw new RuntimeException( 'Organization logo image must be at least 112x112 pixels.' );
				}
				$url = wp_get_attachment_url( $value );
				if ( ! is_string( $url ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
					throw new RuntimeException( 'Organization logo must have a valid HTTPS media URL.' );
				}
				$after_options['company_logo_id']   = $value;
				$after_options['company_logo']      = esc_url_raw( $url );
				$after_options['company_logo_meta'] = false;
			} elseif ( 'company_name' === $key || 'org_description' === $key ) {
				if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 500 ) {
					throw new RuntimeException( 'Organization text must be a nonempty string up to 500 bytes.' );
				}
				$after_options[ 'company_name' === $key ? 'company_name' : 'org-description' ] = sanitize_text_field( $value );
			} else {
				throw new RuntimeException( 'Unsupported site representation field.' );
			}
		}

		$after  = $this->site_representation_fields( $after_options );
		$result = array(
			'before'               => $before,
			'after'                => $after,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		update_option( 'wpseo_titles', $after_options );
		$readback = $this->site_representation_snapshot();
		if ( Fingerprint::make( $readback ) !== Fingerprint::make( $after ) ) {
			throw new RuntimeException( 'Yoast organization settings readback failed.' );
		}
		$result['after']     = $readback;
		$result['_rollback'] = array(
			'action'  => 'yoast.site_representation.restore',
			'payload' => array(
				'fields'                     => $before,
				'expected_after_fingerprint' => Fingerprint::make( $readback ),
			),
		);
		return $result;
	}

	/**
	 * Internal compensation only. Direct requests must never overwrite an arbitrary snapshot.
	 *
	 * @param array $payload Validated action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	public function restore_site_representation( array $payload, array $context ): array {
		if ( empty( $context['rollback_mode'] ) ) {
			throw new RuntimeException( 'Organization settings restore is reserved for connector rollback.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new RuntimeException( 'Organization restore requires manage_options.' );
		}
		$before   = $this->site_representation_snapshot();
		$expected = isset( $payload['expected_after_fingerprint'] ) ? (string) $payload['expected_after_fingerprint'] : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, Fingerprint::make( $before ) ) ) {
			throw new RuntimeException( 'Organization settings changed after update; rollback is unsafe.' );
		}
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_restore'        => true,
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		if ( array_keys( $fields ) !== array_keys( $before ) ) {
			throw new RuntimeException( 'Organization rollback snapshot has unexpected fields.' );
		}
		$options                      = get_option( 'wpseo_titles', null );
		$options['company_or_person'] = $fields['company_or_person'];
		$options['company_name']      = $fields['company_name'];
		$options['company_logo']      = $fields['company_logo'];
		$options['company_logo_id']   = $fields['company_logo_id'];
		$options['company_logo_meta'] = $fields['company_logo_meta'];
		$options['org-description']   = $fields['org_description'];
		update_option( 'wpseo_titles', $options );
		if ( Fingerprint::make( $this->site_representation_snapshot() ) !== Fingerprint::make( $fields ) ) {
			throw new RuntimeException( 'Yoast organization rollback readback failed.' );
		}
		return array(
			'restored' => true,
			'fields'   => $fields,
		);
	}
}
