<?php
/**
 * Registers and handles Yoast connector actions.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

require_once __DIR__ . '/trait-yoastadapteractions.php';

/**
 * Registers and handles Yoast connector actions.
 */
final class YoastAdapter {
	use YoastAdapterActions {
		inspect_site_representation as inspectSiteRepresentation;
		update_site_representation as updateSiteRepresentation;
		restore_site_representation as restoreSiteRepresentation;
	}

	private const FIELDS = array(
		'title'                    => '_yoast_wpseo_title',
		'description'              => '_yoast_wpseo_metadesc',
		'focus_keyphrase'          => '_yoast_wpseo_focuskw',
		'canonical'                => '_yoast_wpseo_canonical',
		'robots_noindex'           => '_yoast_wpseo_meta-robots-noindex',
		'robots_nofollow'          => '_yoast_wpseo_meta-robots-nofollow',
		'robots_advanced'          => '_yoast_wpseo_meta-robots-adv',
		'breadcrumb_title'         => '_yoast_wpseo_bctitle',
		'cornerstone'              => '_yoast_wpseo_is_cornerstone',
		'schema_page_type'         => '_yoast_wpseo_schema_page_type',
		'schema_article_type'      => '_yoast_wpseo_schema_article_type',
		'redirect'                 => '_yoast_wpseo_redirect',
		'primary_category_term_id' => '_yoast_wpseo_primary_category',
		'opengraph_title'          => '_yoast_wpseo_opengraph-title',
		'opengraph_description'    => '_yoast_wpseo_opengraph-description',
		'opengraph_image'          => '_yoast_wpseo_opengraph-image',
		'opengraph_image_id'       => '_yoast_wpseo_opengraph-image-id',
		'twitter_title'            => '_yoast_wpseo_twitter-title',
		'twitter_description'      => '_yoast_wpseo_twitter-description',
		'twitter_image'            => '_yoast_wpseo_twitter-image',
		'twitter_image_id'         => '_yoast_wpseo_twitter-image-id',
	);

	/**
	 * Register the Yoast adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register(
			'yoast.inspect',
			array( $this, 'inspect' ),
			array(
				'privileged'  => true,
				'description' => 'Read supported Yoast SEO and Yoast SEO Premium fields for one WordPress content object.',
			)
		);
		$registry->register(
			'yoast.update',
			array( $this, 'update' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Update supported Yoast SEO and Premium post fields with dry-run, fingerprint, readback and rollback support.',
			)
		);
		$registry->register(
			'yoast.site_representation.inspect',
			array( $this, 'inspectSiteRepresentation' ),
			array(
				'privileged'             => true,
				'public_repository_safe' => true,
				'capability'             => 'manage_options',
				'description'            => 'Read allowlisted Yoast Organization name, logo and description fields.',
			)
		);
		$registry->register(
			'yoast.site_representation.update',
			array( $this, 'updateSiteRepresentation' ),
			array(
				'mutation'               => true,
				'privileged'             => true,
				'public_repository_safe' => true,
				'capability'             => 'manage_options',
				'description'            => 'Patch 1-3 allowlisted Yoast Organization fields using validated media ID with dry-run, readback and rollback.',
			)
		);
		$registry->register(
			'yoast.site_representation.restore',
			array( $this, 'restoreSiteRepresentation' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'capability'  => 'manage_options',
				'description' => 'Internal rollback only for a prior Yoast Organization change.',
			)
		);
	}

	/**
	 * Process the inspect operation in the Yoast adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 */
	public function inspect( array $payload, array $context ): array {
		// Registry supplies execution context; this read does not consume it.
		unset( $context );

		$post   = $this->post( $payload );
		$fields = $this->snapshot( (int) $post->ID );

		return array(
			'post_id'               => (int) $post->ID,
			'yoast_active'          => defined( 'WPSEO_VERSION' ) || defined( 'YOAST_SEO_VERSION' ),
			'yoast_version'         => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : ( defined( 'YOAST_SEO_VERSION' ) ? YOAST_SEO_VERSION : null ),
			'yoast_premium_active'  => defined( 'WPSEO_PREMIUM_VERSION' ),
			'yoast_premium_version' => defined( 'WPSEO_PREMIUM_VERSION' ) ? WPSEO_PREMIUM_VERSION : null,
			'fields'                => $fields,
			'fingerprint'           => Fingerprint::make( $fields ),
		);
	}

	/**
	 * Process the update operation in the Yoast adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function update( array $payload, array $context ): array {
		$post    = $this->post( $payload );
		$updates = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		if ( ! $updates ) {
			throw new RuntimeException( 'yoast.update requires payload.fields.' );
		}

		foreach ( $updates as $field => $value ) {
			if ( ! isset( self::FIELDS[ $field ] ) ) {
				throw new RuntimeException( 'Unsupported Yoast field.' );
			}
			if ( null !== $value && ! is_scalar( $value ) ) {
				throw new RuntimeException( 'Yoast field values must be scalar or null.' );
			}
		}

		$before = $this->snapshot( (int) $post->ID );
		$after  = $before;
		foreach ( $updates as $field => $value ) {
			$after[ $field ] = null === $value ? null : $this->sanitize_field( (string) $field, $value );
		}

		$result = array(
			'post_id'              => (int) $post->ID,
			'before'               => $before,
			'after'                => $after,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		foreach ( $updates as $field => $value ) {
			$meta_key = self::FIELDS[ $field ];
			if ( null === $value || '' === (string) $value ) {
				delete_post_meta( (int) $post->ID, $meta_key );
			} else {
				update_post_meta( (int) $post->ID, $meta_key, $this->sanitize_field( (string) $field, $value ) );
			}
		}

		clean_post_cache( (int) $post->ID );
		$readback = $this->snapshot( (int) $post->ID );
		foreach ( $updates as $field => $value ) {
			$expected = null === $value || '' === (string) $value ? null : $this->sanitize_field( (string) $field, $value );
			if ( $readback[ $field ] !== $expected ) {
				throw new RuntimeException( 'Yoast readback verification failed for field: ' . esc_html( (string) $field ) );
			}
		}

		$result['after']     = $readback;
		$result['_rollback'] = array(
			'action'  => 'yoast.update',
			'payload' => array(
				'id'     => (int) $post->ID,
				'fields' => $before,
			),
		);

		return $result;
	}

	/**
	 * Process the post operation in the Yoast adapter.
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
		Policy::assertPostReadable( $post );
		return $post;
	}

	/**
	 * Process the snapshot operation in the Yoast adapter.
	 *
	 * @param int $post_id Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function snapshot( int $post_id ): array {
		$result = array();
		foreach ( self::FIELDS as $field => $meta_key ) {
			$value            = get_post_meta( $post_id, $meta_key, true );
			$result[ $field ] = '' === $value ? null : (string) $value;
		}
		return $result;
	}

	/**
	 * Sanitize field for the Yoast adapter.
	 *
	 * @param string $field Input for the operation.
	 * @param mixed  $value Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function sanitize_field( string $field, $value ): string {
		$value = (string) $value;

		if ( in_array( $field, array( 'canonical', 'redirect', 'opengraph_image', 'twitter_image' ), true ) ) {
			$clean = esc_url_raw( $value );
			if ( '' !== $value && '' === $clean ) {
				throw new RuntimeException( 'Yoast field requires a valid URL: ' . esc_html( $field ) );
			}
			return $clean;
		}

		if ( in_array( $field, array( 'opengraph_image_id', 'twitter_image_id', 'primary_category_term_id' ), true ) ) {
			$id = (int) $value;
			if ( $id < 1 ) {
				throw new RuntimeException( 'Yoast field requires a positive integer ID: ' . esc_html( $field ) );
			}
			return (string) $id;
		}

		if ( 'robots_noindex' === $field && ! in_array( $value, array( '0', '1', '2' ), true ) ) {
			throw new RuntimeException( 'robots_noindex must be 0 (default), 1 (noindex), or 2 (index).' );
		}
		if ( 'robots_nofollow' === $field && ! in_array( $value, array( '0', '1' ), true ) ) {
			throw new RuntimeException( 'robots_nofollow must be 0 (follow) or 1 (nofollow).' );
		}
		if ( 'robots_advanced' === $field ) {
			$allowed = array( 'noimageindex', 'noarchive', 'nosnippet' );
			$parts   = array_filter( array_map( 'trim', explode( ',', $value ) ) );
			foreach ( $parts as $part ) {
				if ( ! in_array( $part, $allowed, true ) ) {
					throw new RuntimeException( 'Unsupported advanced robots directive.' );
				}
			}
			return implode( ',', array_values( array_unique( $parts ) ) );
		}
		if ( 'cornerstone' === $field ) {
			if ( ! in_array( strtolower( $value ), array( 'true', 'false' ), true ) ) {
				throw new RuntimeException( 'cornerstone must be true or false.' );
			}
			return strtolower( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Process the site representation snapshot operation in the Yoast adapter.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function site_representation_snapshot(): array {
		if ( ! defined( 'WPSEO_VERSION' ) && ! defined( 'YOAST_SEO_VERSION' ) ) {
			throw new RuntimeException( 'Yoast SEO is not active.' );
		}
		$options = get_option( 'wpseo_titles', null );
		if ( ! is_array( $options ) ) {
			throw new RuntimeException( 'Yoast SEO site representation options are not available.' );
		}
		return $this->site_representation_fields( $options );
	}

	/**
	 * Process the site representation fields operation in the Yoast adapter.
	 *
	 * @param array $options Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function site_representation_fields( array $options ): array {
		return array(
			'company_or_person' => (string) ( $options['company_or_person'] ?? '' ),
			'company_name'      => (string) ( $options['company_name'] ?? '' ),
			'company_logo'      => (string) ( $options['company_logo'] ?? '' ),
			'company_logo_id'   => (int) ( $options['company_logo_id'] ?? 0 ),
			'company_logo_meta' => $options['company_logo_meta'] ?? false,
			'org_description'   => (string) ( $options['org-description'] ?? '' ),
		);
	}
}
