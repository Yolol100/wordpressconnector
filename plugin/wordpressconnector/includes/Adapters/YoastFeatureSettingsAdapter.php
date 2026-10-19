<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Typed Yoast switches; no arbitrary option access or provider credentials.
 */
final class YoastFeatureSettingsAdapter {

	private const FIELDS = array(
		'seo_analysis_enabled'         => array( 'wpseo', 'keyword_analysis_active' ),
		'readability_analysis_enabled' => array( 'wpseo', 'content_analysis_active' ),
		'inclusive_language_enabled'   => array( 'wpseo', 'inclusive_language_analysis_active' ),
		'cornerstone_content_enabled'  => array( 'wpseo', 'enable_cornerstone_content' ),
		'xml_sitemaps_enabled'         => array( 'wpseo', 'enable_xml_sitemap' ),
		'text_link_counter_enabled'    => array( 'wpseo', 'enable_text_link_counter' ),
		'indexnow_enabled'             => array( 'wpseo', 'enable_index_now' ),
		'headless_rest_enabled'        => array( 'wpseo', 'enable_headless_rest_endpoints' ),
		'admin_bar_enabled'            => array( 'wpseo', 'enable_admin_bar_menu' ),
		'slack_sharing_enabled'        => array( 'wpseo', 'enable_enhanced_slack_sharing' ),
		'semrush_integration_enabled'  => array( 'wpseo', 'semrush_integration_active' ),
		'wincher_integration_enabled'  => array( 'wpseo', 'wincher_integration_active' ),
		'schema_enabled'               => array( 'wpseo', 'enable_schema' ),
		'schema_aggregation_enabled'   => array( 'wpseo', 'enable_schema_aggregation_endpoint' ),
		'task_list_enabled'            => array( 'wpseo', 'enable_task_list' ),
		'llms_txt_enabled'             => array( 'wpseo', 'enable_llms_txt' ),
		'breadcrumbs_enabled'          => array( 'wpseo_titles', 'breadcrumbs-enable' ),
		'breadcrumbs_bold_last'        => array( 'wpseo_titles', 'breadcrumbs-boldlast' ),
		'breadcrumbs_show_blog_page'   => array( 'wpseo_titles', 'breadcrumbs-display-blog-page' ),
		'author_archive_disabled'      => array( 'wpseo_titles', 'disable-author' ),
		'date_archive_disabled'        => array( 'wpseo_titles', 'disable-date' ),
		'post_format_archive_disabled' => array( 'wpseo_titles', 'disable-post_format' ),
		'attachment_pages_disabled'    => array( 'wpseo_titles', 'disable-attachment' ),
		'author_archive_noindex'       => array( 'wpseo_titles', 'noindex-author-wpseo' ),
		'date_archive_noindex'         => array( 'wpseo_titles', 'noindex-archive-wpseo' ),
		'category_noindex'             => array( 'wpseo_titles', 'noindex-tax-category' ),
		'tag_noindex'                  => array( 'wpseo_titles', 'noindex-tax-post_tag' ),
		'post_noindex'                 => array( 'wpseo_titles', 'noindex-post' ),
		'page_noindex'                 => array( 'wpseo_titles', 'noindex-page' ),
	);

	private const HIGH_IMPACT = array(
		'xml_sitemaps_enabled'         => true,
		'headless_rest_enabled'        => true,
		'schema_enabled'               => true,
		'schema_aggregation_enabled'   => true,
		'llms_txt_enabled'             => true,
		'author_archive_disabled'      => true,
		'date_archive_disabled'        => true,
		'post_format_archive_disabled' => true,
		'attachment_pages_disabled'    => true,
		'author_archive_noindex'       => true,
		'date_archive_noindex'         => true,
		'category_noindex'             => true,
		'tag_noindex'                  => true,
		'post_noindex'                 => true,
		'page_noindex'                 => true,
	);

	public function register( Registry $registry ): void {
		$security = array(
			'privileged'  => true,
			'capability'  => 'manage_options',
			'description' => 'Read or change specifically allowlisted Yoast site feature booleans; private transport only.',
		);
		$registry->register( 'yoast.features.inspect', array( $this, 'inspect' ), $security );
		$registry->register( 'yoast.features.update', array( $this, 'update' ), array_merge( $security, array( 'mutation' => true ) ) );
		$registry->register( 'yoast.features.restore', array( $this, 'restore' ), array_merge( $security, array( 'mutation' => true ) ) );
	}

	public function inspect( array $payload = array(), array $context = array() ): array {
		$this->assertPrivateProvider();
		if ( $payload ) {
			throw new RuntimeException( 'Yoast feature inspection does not accept arbitrary option keys.' );
		}
		$fields = $this->snapshot();
		return array(
			'fields'      => $fields,
			'fingerprint' => Fingerprint::make( $fields ),
		);
	}

	public function update( array $payload, array $context ): array {
		$this->assertPrivateProvider();
		$fields = $this->validateFields( $payload['fields'] ?? null, false );
		if ( array_diff( array_keys( $payload ), array( 'fields', 'risk_acknowledged' ) ) ) {
			throw new RuntimeException( 'Yoast feature update accepts only fields and risk_acknowledged.' );
		}
		$riskAcknowledged = $payload['risk_acknowledged'] ?? false;
		if ( ! is_bool( $riskAcknowledged ) ) {
			throw new RuntimeException( 'risk_acknowledged must be boolean.' );
		}
		$group = $this->singleGroup( $fields );
		$this->assertProviderSupportsFields( $group, $fields );
		$before     = $this->snapshot();
		$after      = array_replace( $before, $fields );
		$highImpact = array_values( array_intersect( array_keys( $fields ), array_keys( self::HIGH_IMPACT ) ) );
		$result     = array(
			'before'                        => $before,
			'after'                         => $after,
			'high_impact_fields'            => $highImpact,
			'requires_risk_acknowledgement' => ! empty( $highImpact ),
			'_current_fingerprint'          => Fingerprint::make( $before ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		if ( $highImpact && ! $riskAcknowledged ) {
			throw new RuntimeException( 'High-impact indexing, rendering and archive changes require risk_acknowledged=true.' );
		}
		if ( $before === $after ) {
			$result['no_change'] = true;
			return $result;
		}

		$existingOptions = $this->options( $group );
		$newOptions      = $existingOptions;
		foreach ( $fields as $name => $value ) {
			$newOptions[ self::FIELDS[ $name ][1] ] = $value;
		}
		update_option( $group, $newOptions );
		$readback = $this->snapshot();
		if ( $readback !== $after ) {
			update_option( $group, $existingOptions );
			if ( Fingerprint::make( $this->snapshot() ) !== Fingerprint::make( $before ) ) {
				throw new RuntimeException( 'Yoast feature readback mismatch; rollback also failed.' );
			}
			throw new RuntimeException( 'Yoast feature readback mismatch; original options restored.' );
		}

		$result['after']     = $readback;
		$result['_rollback'] = array(
			'action'  => 'yoast.features.restore',
			'payload' => array(
				'fields'                     => array_intersect_key( $before, $fields ),
				'expected_after_fingerprint' => Fingerprint::make( $readback ),
			),
		);
		return $result;
	}

	public function restore( array $payload, array $context ): array {
		$this->assertPrivateProvider();
		if ( empty( $context['rollback_mode'] ) ) {
			throw new RuntimeException( 'Yoast feature restore is rollback-only.' );
		}
		if ( array_diff( array_keys( $payload ), array( 'fields', 'expected_after_fingerprint' ) ) ) {
			throw new RuntimeException( 'Unexpected Yoast feature rollback fields.' );
		}
		$before      = $this->snapshot();
		$fingerprint = $payload['expected_after_fingerprint'] ?? '';
		if ( ! is_string( $fingerprint ) || ! hash_equals( Fingerprint::make( $before ), $fingerprint ) ) {
			throw new RuntimeException( 'Yoast features changed since the original write.' );
		}
		$previous = $this->validateFields( $payload['fields'] ?? null, true );
		$group    = $this->singleGroup( $previous );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_restore'        => array_keys( $previous ),
				'_current_fingerprint' => Fingerprint::make( $before ),
			);
		}
		$oldOptions = $this->options( $group );
		$options    = $oldOptions;
		foreach ( $previous as $name => $value ) {
			$key = self::FIELDS[ $name ][1];
			if ( $value === null ) {
				unset( $options[ $key ] );
			} else {
				$options[ $key ] = $value;
			}
		}
		update_option( $group, $options );
		$restored = $this->snapshot();
		foreach ( $previous as $name => $value ) {
			if ( $restored[ $name ] !== $value ) {
				update_option( $group, $oldOptions );
				throw new RuntimeException( 'Yoast feature rollback readback mismatch.' );
			}
		}
		return array(
			'restored' => true,
			'fields'   => array_keys( $previous ),
		);
	}

	/**
	 * Feature toggles are only meaningful when the installed Yoast version
	 * recognizes their exact option keys. Never create phantom settings.
	 */
	private function assertProviderSupportsFields( string $group, array $fields ): void {
		if ( ! class_exists( 'WPSEO_Options' ) || ! method_exists( 'WPSEO_Options', 'get_option_instance' ) ) {
			throw new RuntimeException( 'Yoast option schema API is unavailable.' );
		}
		$provider = \WPSEO_Options::get_option_instance( $group );
		if ( ! is_object( $provider ) || ! method_exists( $provider, 'get_defaults' ) ) {
			throw new RuntimeException( 'Installed Yoast version has no verified option schema for this group.' );
		}
		$defaults = $provider->get_defaults();
		if ( ! is_array( $defaults ) ) {
			throw new RuntimeException( 'Installed Yoast option schema was not an array.' );
		}
		foreach ( $fields as $name => $value ) {
			if ( ! array_key_exists( self::FIELDS[ $name ][1], $defaults ) ) {
				throw new RuntimeException( 'Installed Yoast version does not recognize the requested feature.' );
			}
		}
	}

	private function singleGroup( array $fields ): string {
		$groups = array();
		foreach ( $fields as $name => $value ) {
			$groups[ self::FIELDS[ $name ][0] ] = true;
		}
		if ( count( $groups ) !== 1 ) {
			throw new RuntimeException( 'A single Yoast feature mutation may target only one option group.' );
		}
		return (string) array_keys( $groups )[0];
	}

	private function validateFields( $fields, bool $restore ): array {
		if ( ! is_array( $fields ) || ! $fields || count( $fields ) > 8 ) {
			throw new RuntimeException( 'Yoast feature update requires 1-8 allowlisted booleans.' );
		}
		foreach ( $fields as $name => $value ) {
			if ( ! is_string( $name ) || ! isset( self::FIELDS[ $name ] )
				|| ( ! is_bool( $value ) && ! ( $restore && $value === null ) ) ) {
				throw new RuntimeException( 'Unsupported Yoast feature field or value.' );
			}
		}
		return $fields;
	}

	private function options( string $group ): array {
		$options = get_option( $group, null );
		if ( ! is_array( $options ) ) {
			throw new RuntimeException( 'Yoast settings group is not available.' );
		}
		return $options;
	}

	private function snapshot(): array {
		$groups = array(
			'wpseo'        => $this->options( 'wpseo' ),
			'wpseo_titles' => $this->options( 'wpseo_titles' ),
		);
		$fields = array();
		foreach ( self::FIELDS as $name => $reference ) {
			list($group, $key) = $reference;
			if ( ! array_key_exists( $key, $groups[ $group ] ) ) {
				$fields[ $name ] = null;
				continue;
			}
			$value = $groups[ $group ][ $key ];
			if ( ! is_bool( $value ) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1' ) {
				throw new RuntimeException( 'Yoast feature stored value has an unexpected type.' );
			}
			$fields[ $name ] = $value === true || $value === 1 || $value === '1';
		}
		return $fields;
	}

	private function assertPrivateProvider(): void {
		if ( Policy::publicRepositoryContext() ) {
			throw new RuntimeException( 'Yoast feature settings are not exposed in the public GitHub runtime.' );
		}
		if ( ! defined( 'WPSEO_VERSION' ) && ! defined( 'YOAST_SEO_VERSION' ) ) {
			throw new RuntimeException( 'Active Yoast SEO installation is required.' );
		}
	}
}
