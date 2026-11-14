<?php
/**
 * Registers and handles Optimizer Settings connector actions.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Installed-version contracts for EWWW and Asset CleanUp, designed to keep
 * WP Rocket as the only CSS/JS minifier and unused CSS engine. This adapter
 * does not unload assets, trigger image re-optimization, or export credentials.
 */
final class OptimizerSettingsAdapter {
	private const ASSET_FIELDS = array(
		'minify_loaded_css',
		'minify_loaded_js',
		'combine_loaded_css',
		'combine_loaded_js',
		'inline_css_files',
		'critical_css_status',
	);
	private const EWWW_FIELDS  = array(
		'ewww_image_optimizer_lazy_load',
		'ewww_image_optimizer_ll_autoscale',
		'ewww_image_optimizer_ll_abovethefold',
		'ewww_image_optimizer_add_missing_dims',
		'ewww_image_optimizer_maxmediawidth',
		'ewww_image_optimizer_maxmediaheight',
		'ewww_image_optimizer_metadata_remove',
		'ewww_image_optimizer_webp',
		'ewww_image_optimizer_backup_files',
		'ewww_image_optimizer_webp_conversion_method',
	);
	private const EWWW_WRITE   = array(
		'ewww_image_optimizer_lazy_load',
		'ewww_image_optimizer_ll_autoscale',
		'ewww_image_optimizer_ll_abovethefold',
		'ewww_image_optimizer_add_missing_dims',
	);

	/**
	 * Register the OptimizerSettings adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$read  = array(
			'privileged' => true,
			'capability' => 'manage_options',
		);
		$write = array_merge( $read, array( 'mutation' => true ) );
		$registry->register( 'optimizer.settings.inspect', array( $this, 'inspect' ), $read );
		$registry->register( 'optimizer.settings.update', array( $this, 'update' ), $write );
		$registry->register( 'optimizer.settings.restore', array( $this, 'restore' ), $write );
	}

	/**
	 * Process the inspect operation in the OptimizerSettings adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 */
	public function inspect( array $payload, array $context = array() ): array {
		$this->keys( $payload, array( 'provider' ) );
		$provider = $this->provider( $payload );
		$state    = $this->state( $provider );
		return array(
			'provider'                 => $provider,
			'fields'                   => $this->publicFields( $provider, $state ),
			'write_scope'              => 'asset_cleanup' === $provider ? 'read_only_provider_api_required' : 'image_display_options_only',
			'fingerprint'              => Fingerprint::make( $state ),
			'wp_rocket'                => $this->rocket_summary(),
			'effective_state_verified' => 'ewww' === $provider,
			'settings_source'          => 'ewww' === $provider ? 'ewww_provider_accessor' : 'stored_option_only',
		);
	}

	/**
	 * Process the update operation in the OptimizerSettings adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function update( array $payload, array $context ): array {
		$this->keys( $payload, array( 'provider', 'fields', 'expected_before_fingerprint' ) );
		$provider = $this->provider( $payload );
		// Raw Asset CleanUp options bypass its filtered and cached effective settings.
		// Until an installed-version provider API is verified, writes fail closed.
		if ( 'asset_cleanup' === $provider ) {
			throw new RuntimeException( 'Asset CleanUp settings writes require a verified provider API.' );
		}
		$fields = $payload['fields'] ?? null;
		if ( ! is_array( $fields ) || count( $fields ) !== 1 ) {
			throw new RuntimeException( 'Optimizer updates must change exactly one approved setting.' );
		}
		$key = array_key_first( $fields );
		if ( ! is_string( $key ) ) {
			throw new RuntimeException( 'Invalid optimizer setting field.' );
		}
		$value         = $this->validate( $provider, $key, $fields[ $key ] );
		$old           = $this->state( $provider );
		$fingerprint   = Fingerprint::make( $old );
		$before        = $this->publicFields( $provider, $old );
		$after         = $before;
		$after[ $key ] = $value;
		$this->conflicts( $provider, $key, $value );
		$out = array(
			'provider'             => $provider,
			'before'               => $before,
			'after'                => $after,
			'_current_fingerprint' => $fingerprint,
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $out;
		}
		// Revalidate the snapshot used by Runner at the actual adapter baseline.
		// Passing a boolean here would permit a change between preview and write.
		if ( isset( $context['expected_prewrite_fingerprint'] ) ) {
			$this->expected( $context['expected_prewrite_fingerprint'], $fingerprint );
		} else {
			$this->expected( $payload['expected_before_fingerprint'] ?? null, $fingerprint );
		}
		if ( isset( $payload['expected_before_fingerprint'] ) ) {
			$this->expected( $payload['expected_before_fingerprint'], $fingerprint );
		}
		if ( 'ewww' === $provider && ( null === $old[ $key ] || ! $this->ewww_stored_option_exists( $key ) ) ) {
			throw new RuntimeException( 'EWWW option must exist before a guarded write.' );
		}
		if ( 'ewww' === $provider ) {
			$this->assert_ewww_write_capability();
		}
		$previous_exists = array_key_exists( $key, $old );
		$previous_value  = $previous_exists ? $old[ $key ] : null;
		try {
			$this->write( $provider, $old, $key, $value );
			$next = $this->state( $provider );
			if ( ! array_key_exists( $key, $next ) || (string) $next[ $key ] !== $value ) {
				throw new RuntimeException( 'Optimizer settings readback did not match.' );
			}
			foreach ( $old as $other => $old_value ) {
				if ( $other !== $key && ( ! array_key_exists( $other, $next ) || $next[ $other ] !== $old_value ) ) {
					throw new RuntimeException( 'Optimizer changed an unrelated provider setting.' );
				}
			}
		} catch ( Throwable $error ) {
			try {
				$this->write( $provider, $this->state( $provider ), $key, $previous_exists ? $previous_value : null, $previous_exists );
			} catch ( Throwable $recover_error ) {
				throw new RuntimeException( 'Optimizer write failed; previous state restoration not verified.' );
			}
			if ( $this->state( $provider ) !== $old ) {
				throw new RuntimeException( 'Optimizer write failed and prior setting was not restored.' );
			}
			throw new RuntimeException( 'Optimizer did not retain requested setting; previous state restored.' );
		}
		$out['after']     = $this->publicFields( $provider, $next );
		$out['_rollback'] = array(
			'action'  => 'optimizer.settings.restore',
			'payload' => array(
				'provider'                   => $provider,
				'field'                      => $key,
				'exists'                     => $previous_exists,
				'value'                      => $previous_value,
				'expected_after_fingerprint' => Fingerprint::make( $next ),
			),
		);
		return $out;
	}

	/**
	 * Process the restore operation in the OptimizerSettings adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function restore( array $payload, array $context ): array {
		if ( empty( $context['rollback_mode'] ) ) {
			throw new RuntimeException( 'Optimizer restoration is rollback-only.' );
		}
		$this->keys( $payload, array( 'provider', 'field', 'exists', 'value', 'expected_after_fingerprint' ) );
		$provider = $this->provider( $payload );
		// Old rollback snapshots are not permission to bypass the provider API gate.
		if ( 'asset_cleanup' === $provider ) {
			throw new RuntimeException( 'Asset CleanUp rollback is blocked until provider API verification.' );
		}
		$key = $payload['field'] ?? null;
		if ( ! is_string( $key ) || ! $this->is_writable( $provider, $key )
		|| ! array_key_exists( 'exists', $payload ) || ! is_bool( $payload['exists'] ) ) {
			throw new RuntimeException( 'Optimizer rollback field is invalid.' );
		}
		if ( true === $payload['exists'] ) {
			$this->validate( $provider, $key, $payload['value'] ?? null, true );
		}
		$current = $this->state( $provider );
		$this->expected( $payload['expected_after_fingerprint'] ?? null, Fingerprint::make( $current ) );
		if ( ! empty( $context['dry_run'] ) ) {
			return array( 'would_restore' => $key );
		}
		if ( 'ewww' === $provider ) {
			$this->assert_ewww_write_capability();
		}
		$this->write( $provider, $current, $key, $payload['value'] ?? null, $payload['exists'] );
		$readback = $this->state( $provider );
		if ( true === $payload['exists'] ) {
			if ( ! array_key_exists( $key, $readback )
			|| $readback[ $key ] !== $payload['value'] ) {
				throw new RuntimeException( 'Optimizer rollback mismatch.' );
			}
		} elseif ( array_key_exists( $key, $readback ) ) {
			throw new RuntimeException( 'Optimizer rollback failed to remove the new field.' );
		}
		return array(
			'restored' => true,
			'provider' => $provider,
			'field'    => $key,
		);
	}

	/**
	 * Process the state operation in the OptimizerSettings adapter.
	 *
	 * @param string $provider Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function state( string $provider ): array {
		$this->version( $provider );
		if ( 'asset_cleanup' === $provider ) {
			$stored = get_option( 'wpassetcleanup_settings', null );
			if ( is_array( $stored ) ) {
				if ( count( $stored ) > 250 ) {
					throw new RuntimeException( 'Asset CleanUp settings have unexpected size.' );
				}
				return $stored;
			}
			if ( ! is_string( $stored ) || strlen( $stored ) > 250000 || '' === $stored ) {
				throw new RuntimeException( 'Asset CleanUp has no supported serialized settings baseline.' );
			}
			$data = json_decode( $stored, true );
			if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE || count( $data ) > 250 ) {
				throw new RuntimeException( 'Asset CleanUp stored settings are not valid JSON.' );
			}
			return $data;
		}
		if ( ! function_exists( 'ewww_image_optimizer_get_option' ) ) {
			throw new RuntimeException( 'EWWW provider option accessor is unavailable.' );
		}
		$fields = array();
		foreach ( self::EWWW_FIELDS as $key ) {
			$value = ewww_image_optimizer_get_option( $key, null );
			if ( null !== $value && ! is_scalar( $value ) ) {
				throw new RuntimeException( 'EWWW option has an unsupported provider value.' );
			}
			$fields[ $key ] = $value;
		}
		return $fields;
	}

	/**
	 * Process the write operation in the OptimizerSettings adapter.
	 *
	 * @param string $provider Input for the operation.
	 * @param array  $state Input for the operation.
	 * @param string $key Input for the operation.
	 * @param mixed  $value Input for the operation.
	 * @param bool   $exists Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function write( string $provider, array $state, string $key, $value, bool $exists = true ): void {
		if ( 'asset_cleanup' === $provider ) {
			throw new RuntimeException( 'Asset CleanUp settings writes require a verified provider API.' );
		}
		if ( ! $exists ) {
			throw new RuntimeException( 'EWWW provider option removal is not supported.' );
		}
		if ( ! function_exists( 'ewww_image_optimizer_set_option' ) ) {
			throw new RuntimeException( 'EWWW provider option writer is unavailable.' );
		}
		ewww_image_optimizer_set_option( $key, $value );
	}

	/**
	 * Process the public fields operation in the OptimizerSettings adapter.
	 *
	 * @param string $provider Input for the operation.
	 * @param array  $state Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function publicFields( string $provider, array $state ): array {
		$keys = 'asset_cleanup' === $provider ? self::ASSET_FIELDS : self::EWWW_FIELDS;
		$out  = array();
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $state ) ) {
				$out[ $key ] = null;
			} else {
				$raw         = $state[ $key ];
				$out[ $key ] = is_scalar( $raw ) || null === $raw ? $raw : '[unsupported_value_withheld]';
			}
		}
		return $out;
	}

	/**
	 * Process the validate operation in the OptimizerSettings adapter.
	 *
	 * @param string $provider Input for the operation.
	 * @param string $key Input for the operation.
	 * @param mixed  $value Input for the operation.
	 * @param bool   $restore Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function validate( string $provider, string $key, $value, bool $restore = false ): string {
		if ( ! $this->is_writable( $provider, $key ) || ( ! is_string( $value ) && ! is_int( $value ) && ! is_bool( $value ) ) ) {
			throw new RuntimeException( 'Optimizer setting is not in the safe write allowlist.' );
		}
		$value = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
		if ( 'asset_cleanup' === $provider ) {
			if ( $restore ) {
				if ( 'critical_css_status' === $key && in_array( $value, array( 'on', 'off' ), true ) ) {
					return $value;
				}
				if ( 'critical_css_status' !== $key && in_array( $value, array( '0', '1' ), true ) ) {
					return $value;
				}
			}
			if ( 'critical_css_status' === $key && 'off' === $value ) {
				return $value;
			}
			if ( 'critical_css_status' !== $key && '0' === $value ) {
				return $value;
			}
			throw new RuntimeException( 'Asset CleanUp may only disable duplicate CSS/JS optimization.' );
		}
		if ( 'ewww_image_optimizer_ll_abovethefold' === $key ) {
			if ( preg_match( '/^[0-6]$/D', $value ) ) {
				return $value;
			}
			throw new RuntimeException( 'EWWW above-fold count must be between 0 and 6.' );
		}
		if ( $restore && '' === $value ) {
			return $value;
		}
		if ( '0' !== $value && '1' !== $value ) {
			throw new RuntimeException( 'EWWW toggle accepts only zero or one.' );
		}
		return $value;
	}

	/**
	 * Process the ewww stored option exists operation in the OptimizerSettings adapter.
	 *
	 * @param string $key Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function ewww_stored_option_exists( string $key ): bool {
		$missing = new \stdClass();
		$network = function_exists( 'is_multisite' ) && is_multisite()
		&& function_exists( 'is_plugin_active_for_network' )
		&& is_plugin_active_for_network( 'ewww-image-optimizer/ewww-image-optimizer.php' )
		&& ! get_site_option( 'ewww_image_optimizer_allow_multisite_override' );
		return ( $network ? get_site_option( $key, $missing ) : get_option( $key, $missing ) ) !== $missing;
	}

	/**
	 * Ensure ewww write capability for the OptimizerSettings adapter.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_ewww_write_capability(): void {
		if ( function_exists( 'is_multisite' ) && is_multisite()
		&& function_exists( 'is_plugin_active_for_network' )
		&& is_plugin_active_for_network( 'ewww-image-optimizer/ewww-image-optimizer.php' )
		&& ! get_site_option( 'ewww_image_optimizer_allow_multisite_override' )
		&& ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_network_options' ) ) ) {
			throw new RuntimeException( 'Network-active EWWW requires manage_network_options for writes.' );
		}
	}

	/**
	 * Process the conflicts operation in the OptimizerSettings adapter.
	 *
	 * @param string $provider Input for the operation.
	 * @param string $key Input for the operation.
	 * @param string $value Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function conflicts( string $provider, string $key, string $value ): void {
		$rocket = $this->rocket_summary();
		if ( 'ewww' !== $provider || '1' !== $value ) {
			return;
		}
		if ( 'ewww_image_optimizer_lazy_load' === $key
		&& ( $rocket['lazyload'] ?? null ) === '1' ) {
			throw new RuntimeException( 'WP Rocket image LazyLoad is already active.' );
		}
		if ( 'ewww_image_optimizer_add_missing_dims' === $key
		&& ( $rocket['image_dimensions'] ?? null ) === '1' ) {
			throw new RuntimeException( 'WP Rocket already owns missing image dimensions.' );
		}
	}

	/**
	 * Process the rocket summary operation in the OptimizerSettings adapter.
	 *
	 * @return array Operation result.
	 */
	private function rocket_summary(): array {
		if ( ! function_exists( 'get_rocket_option' ) ) {
			return array( 'available' => false );
		}
		return array(
			'available'         => true,
			'lazyload'          => (string) get_rocket_option( 'lazyload', 0 ),
			'image_dimensions'  => (string) get_rocket_option( 'image_dimensions', 0 ),
			'remove_unused_css' => (string) get_rocket_option( 'remove_unused_css', 0 ),
		);
	}

	/**
	 * Check whether writable for the OptimizerSettings adapter.
	 *
	 * @param string $provider Input for the operation.
	 * @param string $key Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function is_writable( string $provider, string $key ): bool {
		return in_array( $key, 'asset_cleanup' === $provider ? self::ASSET_FIELDS : self::EWWW_WRITE, true );
	}

	/**
	 * Process the version operation in the OptimizerSettings adapter.
	 *
	 * @param string $provider Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function version( string $provider ): void {
		if ( ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) )
		&& defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			throw new RuntimeException( 'WordPress plugin inventory is unavailable.' );
		}
		$file    = 'asset_cleanup' === $provider ? 'wp-asset-clean-up/wpacu.php' : 'ewww-image-optimizer/ewww-image-optimizer.php';
		$version = get_plugins()[ $file ]['Version'] ?? '';
		$pattern = 'asset_cleanup' === $provider ? '/^1\\.4\\.0\\.[0-9]+$/D' : '/^8\\.8\\.[0-9]+$/D';
		if ( ! is_plugin_active( $file ) || ! is_string( $version ) || ! preg_match( $pattern, $version ) ) {
			throw new RuntimeException( 'Unsupported or inactive optimizer plugin version.' );
		}
	}

	/**
	 * Process the provider operation in the OptimizerSettings adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function provider( array $payload ): string {
		$provider = $payload['provider'] ?? null;
		if ( 'asset_cleanup' !== $provider && 'ewww' !== $provider ) {
			throw new RuntimeException( 'Unsupported optimizer provider.' );
		}
		return $provider;
	}

	/**
	 * Process the expected operation in the OptimizerSettings adapter.
	 *
	 * @param mixed  $expected Input for the operation.
	 * @param string $current Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function expected( $expected, string $current ): void {
		if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected )
		|| ! hash_equals( $current, $expected ) ) {
			throw new RuntimeException( 'Optimizer state changed since dry-run.' );
		}
	}

	/**
	 * Process the keys operation in the OptimizerSettings adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $allowed Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function keys( array $payload, array $allowed ): void {
		if ( array_diff( array_keys( $payload ), $allowed ) ) {
			throw new RuntimeException( 'Unexpected optimizer settings request fields.' );
		}
	}
}
