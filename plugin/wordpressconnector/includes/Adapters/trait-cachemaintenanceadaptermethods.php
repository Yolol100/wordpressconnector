<?php
/**
 * Canonical methods for CacheMaintenanceAdapter.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

/**
 * Native aliases preserve the legacy public method contracts.
 */
trait CacheMaintenanceAdapterMethods {
	/**
	 * Process the handle asset cleanup loopback operation in the CacheMaintenance adapter.
	 */
	public function handle_asset_cleanup_loopback(): void {
		$method = $_SERVER['REQUEST_METHOD'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HTTP method is checked case-insensitively against POST and revalidated after sanitization; token authorization is separate.
		if ( ! is_string( $method ) || 'POST' !== strtoupper( $method )
			|| 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $method ) ) ) ) {
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
