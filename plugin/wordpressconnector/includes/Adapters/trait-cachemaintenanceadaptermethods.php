<?php
/**
 * Canonical cache maintenance callback implementation.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

/**
 * Native method alias preserves the existing hook callback identity.
 */
trait CacheMaintenanceAdapterMethods {
	/**
	 * Process the handle asset cleanup loopback operation in the CacheMaintenance adapter.
	 */
	public function handle_asset_cleanup_loopback(): void {
		if ( '' === $this->validated_server_text( 'REQUEST_METHOD', '/\APOST\z/i' ) ) {
			wp_send_json_error( array( 'code' => 'method_not_allowed' ), 405 );
			return;
		}

		$key   = $this->loopback_hex_header( 'HTTP_X_WPCONNECTOR_CACHE_KEY', 32 );
		$token = $this->loopback_hex_header( 'HTTP_X_WPCONNECTOR_CACHE_TOKEN', 64 );

		if ( '' === $key || '' === $token ) {
			wp_send_json_error( array( 'code' => 'invalid_authorization' ), 403 );
			return;
		}

		$transient = self::ASSET_CLEANUP_TOKEN_PREFIX . $key;
		$expected  = get_transient( $transient );
		delete_transient( $transient );

		if ( ! is_string( $expected ) || ! hash_equals( $expected, hash( 'sha256', $token ) ) ) {
			wp_send_json_error( array( 'code' => 'invalid_authorization' ), 403 );
			return;
		}

		try {
			$verified = $this->flush_asset_cleanup_direct();
		} catch ( \Throwable $error ) {
			wp_send_json_error( array( 'code' => 'asset_cleanup_flush_failed' ), 500 );
			return;
		}

		if ( ! $verified ) {
			wp_send_json_error( array( 'code' => 'asset_cleanup_verification_failed' ), 500 );
			return;
		}

		nocache_headers();
		wp_send_json_success(
			array(
				'verified' => true,
				'provider' => 'Asset CleanUp',
				'version'  => defined( 'WPACU_PLUGIN_VERSION' ) ? (string) WPACU_PLUGIN_VERSION : '',
			)
		);
	}
}
