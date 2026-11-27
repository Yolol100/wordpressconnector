<?php
/**
 * Canonical public method implementations for WooCommerceFulfillmentAdapter.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );
namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait WooCommerceFulfillmentAdapterActions {
	/**
	 * Update locations for the WooCommerceFulfillment adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function update_locations( array $payload, array $context ): array {
		$this->keys( $payload, array( 'zone_id', 'locations', 'critical_confirm', 'restore_verified', 'expected_before_fingerprint' ) );
		$path        = $this->location_path( $payload );
		$new         = $this->normalize_locations( $payload['locations'] ?? null, false );
		$before      = $this->location_snapshot( $this->api( 'GET', $path ) );
		$fingerprint = Fingerprint::make( $before );
		$result      = array(
			'zone_id'              => $payload['zone_id'],
			'before'               => $before,
			'after'                => $new,
			'_current_fingerprint' => $fingerprint,
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$this->gate( $payload, $fingerprint );
		$this->api( 'PUT', $path, $new, true );
		$after = $this->location_snapshot( $this->api( 'GET', $path ) );
		if ( $after !== $new ) {
			$this->api( 'PUT', $path, $before, true );
			if ( $this->location_snapshot( $this->api( 'GET', $path ) ) !== $before ) {
				throw new RuntimeException( 'Shipping location readback failed and restore was not verified.' );
			}
			throw new RuntimeException( 'Shipping location update rejected; previous locations restored.' );
		}
		$result['_rollback'] = array(
			'action'  => 'woocommerce.fulfillment.locations.restore',
			'payload' => array(
				'zone_id'                    => $payload['zone_id'],
				'locations'                  => $before,
				'expected_after_fingerprint' => Fingerprint::make( $after ),
			),
		);
		return $result;
	}

	/**
	 * Restore locations for the WooCommerceFulfillment adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function restore_locations( array $payload, array $context ): array {
		if ( empty( $context['rollback_mode'] ) ) {
			throw new RuntimeException( 'Location restoration is rollback-only.' );
		}
		$this->keys( $payload, array( 'zone_id', 'locations', 'expected_after_fingerprint' ) );
		$path     = $this->location_path( $payload );
		$current  = $this->location_snapshot( $this->api( 'GET', $path ) );
		$expected = $payload['expected_after_fingerprint'] ?? null;
		if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected )
		|| ! hash_equals( Fingerprint::make( $current ), $expected ) ) {
			throw new RuntimeException( 'Shipping locations changed after the original write.' );
		}
		$previous = $this->normalize_locations( $payload['locations'] ?? null, true );
		if ( ! empty( $context['dry_run'] ) ) {
			return array( 'would_restore' => $previous );
		}
		$this->api( 'PUT', $path, $previous, true );
		if ( $this->location_snapshot( $this->api( 'GET', $path ) ) !== $previous ) {
			throw new RuntimeException( 'Shipping location rollback readback failed.' );
		}
		return array(
			'restored' => true,
			'zone_id'  => $payload['zone_id'],
		);
	}

	/**
	 * Restore created for the WooCommerceFulfillment adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function restore_created( array $payload, array $context ): array {
		if ( empty( $context['rollback_mode'] ) ) {
			throw new RuntimeException( 'Created resources may only be restored through connector rollback.' );
		}
		$this->keys( $payload, array( 'type', 'zone_id', 'id', 'expected_after_fingerprint' ) );
		$type     = $this->type( $payload );
		$id       = $this->positive( $payload['id'] ?? null, 'id' );
		$path     = $this->collection_path( $type, $payload ) . '/' . $id;
		$current  = $this->api( 'GET', $path );
		$snapshot = $this->resource_snapshot( $type, $current );
		$expected = $payload['expected_after_fingerprint'] ?? null;
		if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected )
		|| ! hash_equals( Fingerprint::make( $snapshot ), $expected ) ) {
			throw new RuntimeException( 'Created WooCommerce resource has changed; automatic removal unsafe.' );
		}
		if ( 'shipping_zone' === $type ) {
			$methods   = $this->api( 'GET', $path . '/methods' );
			$locations = $this->api( 'GET', $path . '/locations' );
			if ( $methods || $locations ) {
				throw new RuntimeException( 'Created zone now has methods or locations; cannot automatically remove.' );
			}
		}
		if ( ! empty( $context['dry_run'] ) ) {
			return array( 'would_remove_created_id' => $id );
		}
		$this->api( 'DELETE', $path, array( 'force' => true ) );
		return array(
			'restored_created_resource' => true,
			'type'                      => $type,
			'id'                        => $id,
		);
	}

	/**
	 * Inspect locations for the WooCommerceFulfillment adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 */
	public function inspect_locations( array $payload, array $context = array() ): array {
		// Registry supplies execution context; this read does not consume it.
		unset( $context );

		$this->keys( $payload, array( 'zone_id' ) );
		$path      = $this->location_path( $payload );
		$locations = $this->location_snapshot( $this->api( 'GET', $path ) );
		return array(
			'zone_id'     => $payload['zone_id'],
			'locations'   => $locations,
			'fingerprint' => Fingerprint::make( $locations ),
		);
	}
}
