<?php
/**
 * Canonical public method implementations for ConnectorUpdateAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\ReleaseApproval;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait ConnectorUpdateAdapterActions {
	/**
	 * WordPress admin actions must return void while direct refresh callers
	 * retain the explicit boolean result of refreshNativeUpdateNotice().
	 */
	public function refresh_native_update_notice_action(): void {
		$this->refreshNativeUpdateNotice();
	}

	/**
	 * WordPress Core may keep plugin update results for an hour on Plugins.
	 * Refresh only this plugin's trusted GitHub release when an administrator
	 * actually visits Plugins or Updates. Do not invalidate other plugins or
	 * run an unauthenticated global update scan.
	 *
	 * @param bool $force Whether to force a refresh.
	 */
	public function refresh_native_update_notice( bool $force = false ): bool {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return false;
		}
		if ( ! $force && false !== get_site_transient( self::NOTICE_REFRESH_TRANSIENT ) ) {
			return true;
		}

		// Throttle even failed requests; hosting firewalls and GitHub rate
		// limits must not turn every admin page view into an API retry.
		set_site_transient( self::NOTICE_REFRESH_TRANSIENT, 1, $force ? 30 : 120 );
		try {
			$release   = $this->release( 4 );
			$installed = defined( 'WPCONNECTOR_VERSION' ) ? (string) WPCONNECTOR_VERSION : '';
			if ( '' === $installed ) {
				return false;
			}
			$updates = get_site_transient( 'update_plugins' );
			if ( ! is_object( $updates ) ) {
				return false;
			}
			if ( ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
				$updates->response = array();
			}
			if ( ! isset( $updates->no_update ) || ! is_array( $updates->no_update ) ) {
				$updates->no_update = array();
			}
			if ( version_compare( $release['version'], $installed, '>' ) ) {
				$offer = $this->native_offer_from_release( $release, $installed );
				if ( ! is_array( $offer ) ) {
					return false;
				}
				$offer['new_version']                   = $offer['version'];
				$offer['plugin']                        = self::PLUGIN_FILE;
				$updates->response[ self::PLUGIN_FILE ] = (object) $offer;
				unset( $updates->no_update[ self::PLUGIN_FILE ] );
			} elseif ( isset( $updates->response[ self::PLUGIN_FILE ] ) ) {
				// Only discard an obsolete update which this connector owns.
				$existing = $updates->response[ self::PLUGIN_FILE ];
				$identity = is_object( $existing ) ? ( $existing->id ?? null ) :
					( is_array( $existing ) ? ( $existing['id'] ?? null ) : null );
				if ( self::UPDATE_URI === $identity ) {
					unset( $updates->response[ self::PLUGIN_FILE ] );
				}
			}
			set_site_transient( 'update_plugins', $updates );
			return true;
		} catch ( \Throwable $error ) {
			// Leave the last known Core update result intact on network errors.
			// The guarded downloader will still refuse a stale/bad package.
			return false;
		}
	}

	/**
	 * Process the plugin action links operation in the ConnectorUpdate adapter.
	 *
	 * @param array $links Input for the operation.
	 *
	 * @return array Operation result.
	 */
	public function plugin_action_links( array $links ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$url                          = wp_nonce_url(
			admin_url( 'admin-post.php?action=wpconnector_check_updates' ),
			'wpconnector_check_updates'
		);
		$links['wpconnector-refresh'] = '<a href="' . esc_url( $url ) . '">' .
			esc_html__( 'Check GitHub update now', 'wordpressconnector' ) . '</a>';
		return $links;
	}

	/**
	 * Process the force native update check operation in the ConnectorUpdate adapter.
	 */
	public function force_native_update_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Plugin update permission is required.', 'wordpressconnector' ) );
		}
		check_admin_referer( 'wpconnector_check_updates' );
		$ok = $this->refreshNativeUpdateNotice( true );
		wp_safe_redirect(
			add_query_arg(
				array(
					'wpconnector_update_checked' => $ok ? '1' : '0',
					'wpconnector_notice_nonce'   => wp_create_nonce( 'wpconnector_checked_notice' ),
				),
				admin_url( 'plugins.php' )
			)
		);
		exit;
	}

	/**
	 * WordPress checksums are not verified by Core for third-party packages.
	 * Intercept only our own update and pass a verified temporary ZIP to Core.
	 *
	 * @param false|string|\WP_Error $reply     Existing pre-download result or false.
	 * @param string                 $package   Canonical package URL or verified local path.
	 * @param mixed                  $upgrader  WordPress upgrader instance.
	 * @param array                  $hook_extra Upgrader hook metadata.
	 *
	 * @return false|string|\WP_Error
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	public function verified_native_download( $reply, string $package, $upgrader, array $hook_extra ) {
		// Core passes only "plugin" (and temp_backup) to bulk_upgrade(),
		// while a single-plugin upgrade also provides "type" and "action".
		// Accept both documented shapes, but never an explicitly conflicting
		// type or action: every connector ZIP must pass integrity verification.
		if ( self::PLUGIN_FILE !== ( $hook_extra['plugin'] ?? '' )
			|| ( isset( $hook_extra['type'] ) && 'plugin' !== $hook_extra['type'] )
			|| ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) ) {
			return $reply;
		}

		if ( false !== $reply ) {
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}
			return new \WP_Error( 'wordpressconnector_update_source', 'WordPress Connector requires its verified canonical release package.' );
		}

		// The guarded REST self-updater already validated this exact local ZIP.
		// Let Core use it as a local path; do not force a second remote download.
		// Every ordinary single/bulk dashboard update still verifies the release.
		if ( null !== $this->verified_self_update_package_path
			&& hash_equals( $this->verified_self_update_package_path, $package ) ) {
			return false;
		}

		try {
			$release   = $this->release();
			$installed = defined( 'WPCONNECTOR_VERSION' ) ? (string) WPCONNECTOR_VERSION : '';
			if ( '' === $installed || ! version_compare( $release['version'], $installed, '>' )
				|| ! hash_equals( $release['package_url'], $package ) ) {
				throw new RuntimeException( 'The offered connector package is stale or not canonical.' );
			}

			return $this->download_protected_source_package( $release );
		} catch ( \Throwable $error ) {
			// Core aborts before replacing any plugin file when given WP_Error.
			return new \WP_Error( 'wordpressconnector_update_integrity', 'WordPress Connector could not verify this update. No files were changed.' );
		}
	}

	/**
	 * Offer only this plugin's canonical release to WordPress's native updater.
	 * Other plugins using GitHub's update hostname must remain untouched.
	 *
	 * @param array|false $update     Existing native offer or false.
	 * @param array       $plugin_data Validated plugin metadata.
	 * @param string      $plugin_file Validated plugin file value.
	 * @param array       $locales    Validated locales value.
	 *
	 * @return array|false
	 */
	public function native_update_offer( $update, array $plugin_data, string $plugin_file, array $locales ) {
		// WordPress supplies locales to this filter; release identity is locale-independent.
		unset( $locales );

		if ( self::PLUGIN_FILE !== $plugin_file || self::UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
			return $update;
		}

		try {
			return $this->native_offer_from_release(
				$this->release(),
				isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : ''
			);
		} catch ( \Throwable $error ) {
			// A missing or invalid canonical release must never create an update offer.
			return false;
		}
	}
}
