<?php
/**
 * Canonical public method implementations for WooCommerceSettingsAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait WooCommerceSettingsAdapterActions {
	/**
	 * Inspect section for the WooCommerceSettings adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function inspect_section( array $payload, array $context = array() ): array {
		// Registry supplies execution context; this read does not consume it.
		unset( $context );

		$this->reject_extra( $payload, array( 'tab', 'section', 'offset', 'limit' ) );
		$tab     = $payload['tab'] ?? null;
		$section = $payload['section'] ?? '';
		if ( ! is_string( $tab ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $tab )
			|| ! is_string( $section ) || ! preg_match( '/^[a-z0-9_-]{0,63}$/D', $section ) ) {
			throw new RuntimeException( 'WooCommerce Settings tab/section requires canonical IDs.' );
		}
		$offset = $this->bounded_int( $payload['offset'] ?? 0, 0, 2000, 'offset' );
		$limit  = $this->bounded_int( $payload['limit'] ?? 25, 1, 50, 'limit' );
		$page   = null;
		foreach ( $this->settings_pages() as $candidate ) {
			if ( is_object( $candidate ) && method_exists( $candidate, 'get_id' )
				&& $candidate->get_id() === $tab ) {
				$page = $candidate;
				break;
			}
		}
		if ( ! is_object( $page ) || ! method_exists( $page, 'get_sections' )
			|| ! method_exists( $page, 'get_settings_for_section' ) ) {
			throw new RuntimeException( 'WooCommerce Settings tab has no inspectable provider definitions.' );
		}
		$sections = $page->get_sections();
		if ( '' !== $section && ( ! is_array( $sections ) || ! array_key_exists( $section, $sections ) ) ) {
			throw new RuntimeException( 'WooCommerce Settings subtab is not registered.' );
		}
		try {
			$definitions = $page->get_settings_for_section( $section );
		} catch ( Throwable $error ) {
			throw new RuntimeException( 'WooCommerce settings provider did not expose this subtab.' );
		}
		if ( ! is_array( $definitions ) || count( $definitions ) > 2000 ) {
			throw new RuntimeException( 'WooCommerce Settings section definition is unavailable or too large.' );
		}
		$fields = array();
		foreach ( array_slice( $definitions, $offset, $limit ) as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$id   = isset( $definition['id'] ) && is_scalar( $definition['id'] ) ? (string) $definition['id'] : '';
			$type = isset( $definition['type'] ) && is_string( $definition['type'] ) ? $definition['type'] : '';
			// Structural headings are not editable controls.
			$provider_field = (bool) preg_match( '/^woocommerce_[a-z0-9_]{1,100}$/D', $id );
			$private        = $this->is_private( $id, $type );
			$editable       = $provider_field && ! $private && $this->editable( $tab, $id );
			$entry          = array(
				'id'          => $this->short_text( $id ),
				'label'       => $this->short_text( $definition['title'] ?? $definition['label'] ?? '' ),
				'type'        => $this->short_text( $type ),
				'mode'        => $private ? 'secret_blocked' : ( $editable ? 'editable_via_rest' : 'read_only_provider_specific' ),
				'value_state' => $private ? 'withheld' : ( $provider_field ? 'visible_if_simple' : 'provider_value_withheld' ),
			);
			if ( $provider_field && ! $private && ! in_array( $type, array( 'title', 'sectionend', 'info', 'html' ), true ) ) {
				$stored         = get_option( $id, $definition['default'] ?? null );
				$entry['value'] = $this->safe_value( $stored );
			}
			$fields[] = $entry;
		}
		return array(
			'tab'      => $tab,
			'section'  => $section,
			'source'   => 'woocommerce_admin_settings_page',
			'total'    => count( $definitions ),
			'offset'   => $offset,
			'limit'    => $limit,
			'has_more' => $offset + $limit < count( $definitions ),
			'fields'   => $fields,
		);
	}
}
