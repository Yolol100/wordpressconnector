<?php
/**
 * Registers and handles Connector Update connector actions.
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
 * Registers and handles Connector Update connector actions.
 */
final class ConnectorUpdateAdapter {
	private const RELEASE_API              = 'https://api.github.com/repos/Yolol100/wordpressconnector/releases/latest';
	private const SOURCE_API               = 'https://api.github.com/repos/Yolol100/wordpressconnector';
	private const PACKAGE_ASSET            = 'wordpressconnector.zip';
	private const CHECKSUM_ASSET           = 'wordpressconnector.zip.sha256';
	private const SBOM_ASSET               = 'wordpressconnector.spdx.json';
	private const PLUGIN_FILE              = 'wordpressconnector/wordpressconnector.php';
	private const UPDATE_URI               = 'https://github.com/Yolol100/wordpressconnector';
	private const MAX_PACKAGE_BYTES        = 20971520;
	private const MAX_UNCOMPRESSED_BYTES   = 104857600;
	private const MAX_ENTRIES              = 2500;
	private const NOTICE_REFRESH_TRANSIENT = 'wpconnector_github_update_notice_v1';
	/**
	 * Verified local ZIP path for the currently guarded REST self-update.
	 *
	 * @var string|null
	 */
	private $verified_self_update_package_path = null;
	/**
	 * True once WordPress Core upgrader may have touched plugin files.
	 *
	 * @var bool
	 */
	private bool $upgrader_started = false;

	/**
	 * Register the ConnectorUpdate adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register(
			'connector.update.check',
			array( $this, 'check' ),
			array(
				'privileged'             => true,
				'public_repository_safe' => true,
				'capability'             => 'update_plugins',
				'description'            => 'Check the canonical GitHub release for a newer verified WordPress Connector package.',
			)
		);
		$registry->register(
			'connector.update.apply',
			array( $this, 'apply' ),
			array(
				'mutation'               => true,
				'privileged'             => true,
				'system_update'          => true,
				'public_repository_safe' => true,
				'capability'             => 'update_plugins',
				'description'            => 'Update WordPress Connector from its canonical GitHub release after SHA-256 and ZIP identity verification.',
			)
		);
		// WordPress Core owns the dashboard upgrade; the existing adapter still
		// owns release identity, checksum and archive verification.
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'update_plugins_github.com', array( $this, 'nativeUpdateOffer' ), 10, 4 );
			add_filter( 'upgrader_pre_download', array( $this, 'verifiedNativeDownload' ), 10, 4 );
			add_filter( 'plugin_action_links_' . self::PLUGIN_FILE, array( $this, 'pluginActionLinks' ) );
		}
		if ( function_exists( 'add_action' ) ) {
			add_action( 'load-plugins.php', array( $this, 'refreshNativeUpdateNoticeAction' ), 20 );
			add_action( 'load-update-core.php', array( $this, 'refreshNativeUpdateNoticeAction' ), 20 );
			add_action( 'admin_post_wpconnector_check_updates', array( $this, 'forceNativeUpdateCheck' ) );
			add_action( 'admin_notices', array( $this, 'checkedNotice' ) );
		}
	}

	/**
	 * WordPress admin actions must return void while direct refresh callers
	 * retain the explicit boolean result of refreshNativeUpdateNotice().
	 */
	public function refreshNativeUpdateNoticeAction(): void {
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
	public function refreshNativeUpdateNotice( bool $force = false ): bool {
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
	public function pluginActionLinks( array $links ): array {
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
	public function forceNativeUpdateCheck(): void {
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
	 * Process the checked notice operation in the ConnectorUpdate adapter.
	 */
	public function checkedNotice(): void {
		if ( ! current_user_can( 'update_plugins' )
			|| ! isset( $_GET['wpconnector_update_checked'], $_GET['wpconnector_notice_nonce'] )
			|| ! is_string( $_GET['wpconnector_update_checked'] )
			|| ! is_string( $_GET['wpconnector_notice_nonce'] ) ) {
			return;
		}

		$raw_nonce = wp_unslash( $_GET['wpconnector_notice_nonce'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict string gate above; compare sanitized bytes to original and verify nonce before accepting.
		$nonce     = sanitize_text_field( $raw_nonce );
		if ( $nonce !== $raw_nonce || ! wp_verify_nonce( $nonce, 'wpconnector_checked_notice' ) ) {
			return;
		}
		$raw_notice_result = wp_unslash( $_GET['wpconnector_update_checked'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only exact raw '0' or '1' passes, after capability and nonce validation; sanitization follows.
		// Reject malformed values rather than normalizing markup into a trusted result.
		if ( ! in_array( $raw_notice_result, array( '0', '1' ), true ) ) {
			return;
		}
		$notice_result = sanitize_text_field( $raw_notice_result );
		$ok            = '1' === $notice_result;
		echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-warning' ) .
			' is-dismissible"><p>' .
			esc_html(
				$ok
				? __( 'WordPress Connector: GitHub release checked. If newer, Update Now appears in the Plugins list.', 'wordpressconnector' )
				: __( 'WordPress Connector: GitHub update check failed; verify outbound HTTPS, firewall and GitHub availability. Nothing was installed.', 'wordpressconnector' )
			) .
			'</p></div>';
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
	public function nativeUpdateOffer( $update, array $plugin_data, string $plugin_file, array $locales ) {
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

	/**
	 * Process the native offer from release operation in the ConnectorUpdate adapter.
	 *
	 * @param array  $release Input for the operation.
	 * @param string $installed Input for the operation.
	 *
	 * @return array<string, mixed>|false Canonical update offer or false.
	 */
	private function native_offer_from_release( array $release, string $installed ) {
		if ( '' === $installed || ! version_compare( $release['version'], $installed, '>' ) ) {
			return false;
		}
		return array(
			'id'           => self::UPDATE_URI,
			'slug'         => 'wordpressconnector',
			'version'      => $release['version'],
			'url'          => self::UPDATE_URI . '/releases/tag/' . $release['tag'],
			'package'      => $release['package_url'],
			'requires_php' => '7.4',
			'autoupdate'   => false,
		);
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
	public function verifiedNativeDownload( $reply, string $package, $upgrader, array $hook_extra ) {
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
	 * Process the check operation in the ConnectorUpdate adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 */
	public function check( array $payload = array(), array $context = array() ): array {
		$release = $this->release();
		$current = defined( 'WPCONNECTOR_VERSION' ) ? (string) WPCONNECTOR_VERSION : '0.0.0';
		$state   = array(
			'current_version'     => $current,
			'latest_version'      => $release['version'],
			'tag'                 => $release['tag'],
			'update_available'    => version_compare( $release['version'], $current, '>' ),
			'package_asset'       => self::PACKAGE_ASSET,
			'checksum_asset'      => self::CHECKSUM_ASSET,
			'sbom_asset'          => self::SBOM_ASSET,
			'github_asset_digest' => $release['package_digest'],
		);

		return array(
			'update'      => $state,
			'fingerprint' => Fingerprint::make( $state ),
		);
	}

	/**
	 * Process the apply operation in the ConnectorUpdate adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 * @throws \Throwable If an underlying upgrader error must be reconciled.
	 */
	public function apply( array $payload, array $context ): array {
		$this->upgrader_started = false;
		try {
			return $this->perform_verified_update( $payload, $context );
		} catch ( \Throwable $error ) {
			// A release-only failure before Core begins the upgrader is known
			// to have changed no plugin files. Return a terminal failure so
			// Runner finalizes the reserved request ID instead of stranding it.
			if ( ! empty( $context['require_owner_approval'] )
				&& empty( $context['dry_run'] ) && ! $this->upgrader_started ) {
				return array(
					'_terminal_error'            => $error->getMessage(),
					'execution_may_have_started' => false,
				);
			}
			throw $error; // Once Core starts, the outcome must be reconciled.
		}
	}

	/**
	 * Internal canonical updater used only behind the guarded entrypoint.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 * @return array Operation result.
	 * @throws \RuntimeException If any update precondition or source check fails.
	 */
	private function perform_verified_update( array $payload, array $context ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			throw new RuntimeException( 'Current user is not allowed to update plugins.' );
		}
		foreach ( array_keys( $payload ) as $key ) {
			if ( 'restore_verified' !== $key ) {
				throw new RuntimeException( 'Connector self-update accepts only the restore_verified preflight field.' );
			}
		}
		if ( isset( $payload['restore_verified'] ) && ! is_bool( $payload['restore_verified'] ) ) {
			throw new RuntimeException( 'restore_verified must be boolean.' );
		}
		// Legacy callers may still provide restore_verified, but proof of an
		// externally tested restore is no longer a prerequisite for self-updates.
		// Core temporary backups only cover installation/readback failures.
		$release = $this->release();
		$current = defined( 'WPCONNECTOR_VERSION' ) ? (string) WPCONNECTOR_VERSION : '0.0.0';
		if ( ! version_compare( $release['version'], $current, '>' ) ) {
			throw new RuntimeException( 'No newer WordPress Connector release is available.' );
		}

		$before = array(
			'plugin_file' => self::PLUGIN_FILE,
			'version'     => $current,
		);
		// Bind the preview fingerprint to both the installed version and the
		// exact canonical signed release; a changing release invalidates approval.
		$release_state       = array(
			'plugin_file'    => self::PLUGIN_FILE,
			'version'        => $current,
			'release_tag'    => $release['tag'],
			'package_digest' => $release['package_digest'],
			'source_commit'  => $release['source_commit'],
		);
		$release_fingerprint = Fingerprint::make( $release_state );
		$plan                = array(
			'from_version'                    => $current,
			'to_version'                      => $release['version'],
			'tag'                             => $release['tag'],
			'package_asset'                   => self::PACKAGE_ASSET,
			'checksum_asset'                  => self::CHECKSUM_ASSET,
			'sbom_asset'                      => self::SBOM_ASSET,
			'github_asset_digest'             => $release['package_digest'],
			'source_commit'                   => $release['source_commit'],
			'rollback_supported'              => false,
			'installation_failure_recovery'   => 'wordpress_core_temp_backup',
			'external_restore_required'       => false,
			'post_success_restore_guaranteed' => false,
		);

		if ( ! empty( $context['dry_run'] ) ) {
			if ( ! empty( $context['require_release_provenance'] ) ) {
				$preview_package = $this->download_protected_source_package( $release );
				if ( file_exists( $preview_package ) ) {
					wp_delete_file( $preview_package );
				}
			}
			return array(
				'would_update_connector' => $plan,
				// Public release metadata and installed version only; safe for
				// the MCP preview to return through its normal redaction layer.
				'release_fingerprint'    => $release_fingerprint,
				'_current_fingerprint'   => $release_fingerprint,
			);
		}
		// An OAuth release install must come from the approved source-verified preview.
		if ( ! empty( $context['require_owner_approval'] )
			&& ( empty( $context['require_release_provenance'] )
				|| ! isset( $context['expected_prewrite_fingerprint'] )
				|| ! is_string( $context['expected_prewrite_fingerprint'] )
				|| ! hash_equals( $release_fingerprint, $context['expected_prewrite_fingerprint'] ) ) ) {
			throw new RuntimeException( 'An OAuth release install requires a current protected-source preview and exact fingerprint.' );
		}
		if ( isset( $context['expected_prewrite_fingerprint'] )
			&& ( ! is_string( $context['expected_prewrite_fingerprint'] )
				|| ! hash_equals( $release_fingerprint, $context['expected_prewrite_fingerprint'] ) ) ) {
			throw new RuntimeException( 'Connector release changed after approval; preview the update again.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$package = $this->download_protected_source_package( $release );
		try {
			$actual_sha256 = substr( $release['package_digest'], 7 );

			// WordPress Core can restore installation/readback failures via its temp
			// backup. A later fatal error still has no guaranteed automatic recovery.
			$installation = $this->install_with_core_backup(
				$package,
				! empty( $context['require_owner_approval'] ) ? $release_fingerprint : null,
				! empty( $context['require_owner_approval'] ) ? ( $context['owner_approval_id'] ?? null ) : null,
				$current
			);
			wp_clean_plugins_cache( true );
			$plugins           = get_plugins();
			$installed_version = isset( $plugins[ self::PLUGIN_FILE ]['Version'] ) ? (string) $plugins[ self::PLUGIN_FILE ]['Version'] : '';
			if ( ! hash_equals( $release['version'], $installed_version ) ) {
				$this->restore_after_readback_failure( $installation, $current );
				throw new RuntimeException( 'Connector update readback failed: prior version restored; check the release package.' );
			}

			return array(
				'operation'          => 'updated',
				'before'             => $before,
				'after'              => array(
					'plugin_file' => self::PLUGIN_FILE,
					'version'     => $installed_version,
				),
				'package_sha256'     => $actual_sha256,
				'release_tag'        => $release['tag'],
				'rollback_supported' => false,
			);
		} finally {
			if ( is_string( $package ) && file_exists( $package ) ) {
				@unlink( $package );
			}
		}
	}

	/**
	 * Use WP_Upgrader's native temporary backup contract, rather than
	 * Plugin_Upgrader::install(... overwrite_package => true), which has no
	 * hook_extra.temp_backup and cannot restore after an installation failure.
	 *
	 * @param string      $package                    Validated package metadata.
	 * @param string|null $owner_approval_fingerprint Exact owner consent required by OAuth only.
	 * @param string|null $owner_approval_id          Exact approval record observed at Runner preflight.
	 * @param string|null $previous_version           Installed version to verify after a failed install.
	 *
	 * @return array{upgrader: \Plugin_Upgrader, backup: array<string,string>}
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	private function install_with_core_backup( string $package, ?string $owner_approval_fingerprint = null, ?string $owner_approval_id = null, ?string $previous_version = null ): array {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'get_filesystem_method' ) || 'direct' !== get_filesystem_method()
			|| ! is_readable( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE )
			|| ! method_exists( \Plugin_Upgrader::class, 'restore_temp_backup' ) ) {
			throw new RuntimeException( 'Connector update requires direct filesystem mode and WordPress Core temporary-backup support.' );
		}

		// A self-update runs through REST, not through the normal wp-admin update
		// screen. Record the active state before overwriting the files.
		$was_active = is_plugin_active( self::PLUGIN_FILE );
		if ( ! $was_active ) {
			throw new RuntimeException( 'Connector self-update requires an active Connector plugin.' );
		}

		// Runner already holds the mutation lock and checked idempotent replay.
		// Claim only after verifying source and filesystem/activation prerequisites.
		if ( null !== $owner_approval_fingerprint
			&& ( null === $owner_approval_id || ! ReleaseApproval::consume( $owner_approval_fingerprint, $owner_approval_id ) ) ) {
			throw new RuntimeException( 'The linked owner must approve this exact release in WordPress Plugins. Approval lasts 10 minutes and authorizes one installation attempt.' );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$backup   = array(
			'slug' => 'wordpressconnector',
			'src'  => WP_PLUGIN_DIR,
			'dir'  => 'plugins',
		);

		$upgrader->init();
		$upgrader->upgrade_strings();
		add_filter( 'upgrader_source_selection', array( $upgrader, 'check_package' ) );
		// Do not attach deactivate_plugin_before_upgrade here. WordPress Core
		// uses that hook for browser-based upgrades, but our REST self-update
		// has no browser reactivation step. Attaching it left the Connector
		// inactive after a successful file-version readback.
		add_filter( 'upgrader_pre_install', array( $upgrader, 'active_before' ), 10, 2 );
		add_filter( 'upgrader_post_install', array( $upgrader, 'active_after' ), 10, 2 );
		$result          = null;
		$execution_error = null;

		$this->verified_self_update_package_path = $package;
		try {
			// A failure from this point may leave an uncertain file state.
			$this->upgrader_started = true;
			$result                 = $upgrader->run(
				array(
					'package'                     => $package,
					'destination'                 => WP_PLUGIN_DIR,
					'clear_destination'           => true,
					'abort_if_destination_exists' => false,
					'clear_working'               => true,
					'hook_extra'                  => array(
						'plugin'      => self::PLUGIN_FILE,
						'type'        => 'plugin',
						'action'      => 'update',
						'temp_backup' => $backup,
					),
				)
			);
		} catch ( \Throwable $error ) {
			// PHP exceptions can occur after the destination was replaced.
			// Reconcile only after removing the upgrader's temporary hooks.
			$execution_error = $error;
		} finally {
			$this->verified_self_update_package_path = null;
			remove_filter( 'upgrader_source_selection', array( $upgrader, 'check_package' ) );
			// No deactivation callback was installed by this self-update.
			remove_filter( 'upgrader_pre_install', array( $upgrader, 'active_before' ), 10 );
			remove_filter( 'upgrader_post_install', array( $upgrader, 'active_after' ), 10 );
		}

		if ( null !== $execution_error || is_wp_error( $result ) || ! is_array( $result ) ) {
			// A returned WP_Error does not prove that Core restored every file.
			// Compare the old plugin version/activation and use Core's temp backup
			// when the destination has changed. Never silently retry an unknown
			// outcome or claim protection against PHP process termination.
			if ( null !== $previous_version ) {
				$this->reconcile_failed_installation( $upgrader, $backup, $previous_version, $was_active );
			}
			// Do not surface raw upgrader exceptions through a remote MCP response.
			throw new RuntimeException( 'Connector installation failed or ended with an unknown outcome; prior installation was checked but no automatic retry is allowed.' );
		}
		if ( $was_active && ! is_plugin_active( self::PLUGIN_FILE ) ) {
			// Preserve the activation state held before the guarded upgrade.
			// Never report a successful install if REST routes will disappear.
			$activation = activate_plugin( self::PLUGIN_FILE, '', false, true );
			if ( is_wp_error( $activation ) || ! is_plugin_active( self::PLUGIN_FILE ) ) {
				if ( null !== $previous_version ) {
					$this->reconcile_failed_installation( $upgrader, $backup, $previous_version, $was_active );
				}
				throw new RuntimeException( 'Connector upgrade left the plugin inactive; reactivation failed. Check the prior-version restore before retrying.' );
			}
		}
		return array(
			'upgrader' => $upgrader,
			'backup'   => $backup,
		);
	}

	/**
	 * Reconcile a recoverable synchronous failure without attempting a second
	 * installation. The original Core backup may already have been restored.
	 * Process termination and fatal PHP errors still need a tested site restore.
	 *
	 * @param \Plugin_Upgrader $upgrader        Original Core upgrader.
	 * @param array            $backup          Core temporary-backup identity.
	 * @param string           $expected_version Prior installed plugin version.
	 * @param bool             $was_active      Whether the Connector was active.
	 * @return void
	 * @throws \RuntimeException If Core cannot prove a recoverable prior version.
	 */
	private function reconcile_failed_installation( \Plugin_Upgrader $upgrader, array $backup, string $expected_version, bool $was_active ): void {
		wp_clean_plugins_cache( true );
		$plugins = get_plugins();
		$version = isset( $plugins[ self::PLUGIN_FILE ]['Version'] ) ? (string) $plugins[ self::PLUGIN_FILE ]['Version'] : '';
		if ( ! hash_equals( $expected_version, $version ) ) {
			$this->restore_after_readback_failure(
				array(
					'upgrader' => $upgrader,
					'backup'   => $backup,
				),
				$expected_version
			);
		}
		if ( $was_active && ! is_plugin_active( self::PLUGIN_FILE ) ) {
			$activation = activate_plugin( self::PLUGIN_FILE, '', false, true );
			if ( is_wp_error( $activation ) || ! is_plugin_active( self::PLUGIN_FILE ) ) {
				throw new RuntimeException( 'Connector installation failed; prior-version reactivation was not verified. Restore externally before retrying.' );
			}
		}
		// A version check does not prove every file is intact: staging must
		// independently verify source hashes and perform a full restore drill.
	}

	/**
	 * Restore after readback failure for the ConnectorUpdate adapter.
	 *
	 * @param array  $installation Input for the operation.
	 * @param string $expected_version Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function restore_after_readback_failure( array $installation, string $expected_version ): void {
		$upgrader = $installation['upgrader'];
		$backup   = $installation['backup'];
		// A failed readback must never turn into an unconditional directory
		// deletion when the temporary backup has already disappeared.
		global $wp_filesystem;
		$path = trailingslashit( WP_CONTENT_DIR ) . 'upgrade-temp-backup/plugins/wordpressconnector';
		if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->is_dir( $path ) ) {
			throw new RuntimeException( 'Connector update readback failed; no recoverable temporary backup was found. Restore externally before retrying.' );
		}
		$restored = $upgrader->restore_temp_backup( array( $backup ) );
		wp_clean_plugins_cache( true );
		$plugins = get_plugins();
		$version = isset( $plugins[ self::PLUGIN_FILE ]['Version'] ) ? (string) $plugins[ self::PLUGIN_FILE ]['Version'] : '';
		if ( true !== $restored || ! hash_equals( $expected_version, $version ) ) {
			throw new RuntimeException( 'Connector update readback failed and automatic recovery was not verified. Restore externally before retrying.' );
		}
	}

	/**
	 * Process the release operation in the ConnectorUpdate adapter.
	 *
	 * @param int $timeout Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function release( int $timeout = 10 ): array {
		$response = wp_safe_remote_get(
			self::RELEASE_API,
			array(
				'timeout'             => $timeout,
				'redirection'         => 3,
				'limit_response_size' => 262144,
				'headers'             => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Webactueel-WordPressConnector',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new RuntimeException( 'Canonical WordPress Connector release metadata is unavailable.' );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['tag_name'] ) || empty( $decoded['assets'] ) || ! is_array( $decoded['assets'] ) || ! empty( $decoded['draft'] ) || ! empty( $decoded['prerelease'] ) ) {
			throw new RuntimeException( 'Canonical release metadata has an invalid schema.' );
		}

		$tag           = (string) $decoded['tag_name'];
		$source_commit = isset( $decoded['target_commitish'] ) && is_string( $decoded['target_commitish'] )
			? strtolower( $decoded['target_commitish'] ) : '';
		if ( ! preg_match( '/^v([0-9]+\.[0-9]+\.[0-9]+)\z/', $tag, $matches ) ) {
			throw new RuntimeException( 'Canonical release tag must use vMAJOR.MINOR.PATCH.' );
		}

		$package_url     = '';
		$checksum_url    = '';
		$sbom_url        = '';
		$package_digest  = '';
		$checksum_digest = '';
		foreach ( $decoded['assets'] as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}
			$name = (string) $asset['name'];
			$url  = (string) $asset['browser_download_url'];
			if ( self::PACKAGE_ASSET === $name ) {
				$package_url    = $url;
				$package_digest = isset( $asset['digest'] ) ? strtolower( (string) $asset['digest'] ) : '';
			} elseif ( self::CHECKSUM_ASSET === $name ) {
				$checksum_url    = $url;
				$checksum_digest = isset( $asset['digest'] ) ? strtolower( (string) $asset['digest'] ) : '';
			} elseif ( self::SBOM_ASSET === $name ) {
				$sbom_url = $url;
			}
		}

		$quoted_tag = preg_quote( $tag, '#' );
		if ( ! preg_match( '#^https://github\.com/Yolol100/wordpressconnector/releases/download/' . $quoted_tag . '/wordpressconnector\.zip\z#', $package_url ) ) {
			throw new RuntimeException( 'Canonical release is missing the expected connector package asset.' );
		}
		if ( ! preg_match( '#^https://github\.com/Yolol100/wordpressconnector/releases/download/' . $quoted_tag . '/wordpressconnector\.zip\.sha256\z#', $checksum_url ) ) {
			throw new RuntimeException( 'Canonical release is missing the expected checksum asset.' );
		}
		if ( ! preg_match( '#^https://github\.com/Yolol100/wordpressconnector/releases/download/' . $quoted_tag . '/wordpressconnector\.spdx\.json\z#', $sbom_url ) ) {
			throw new RuntimeException( 'Canonical release is missing the expected SPDX SBOM asset.' );
		}
		if ( ! preg_match( '/^sha256:[a-f0-9]{64}\z/', $package_digest ) || ! preg_match( '/^sha256:[a-f0-9]{64}\z/', $checksum_digest ) ) {
			throw new RuntimeException( 'Canonical release assets are missing GitHub SHA-256 digests.' );
		}

		return array(
			'tag'             => $tag,
			'version'         => (string) $matches[1],
			'package_url'     => $package_url,
			'checksum_url'    => $checksum_url,
			'sbom_url'        => $sbom_url,
			'package_digest'  => $package_digest,
			'checksum_digest' => $checksum_digest,
			'source_commit'   => $source_commit,
		);
	}

	/**
	 * Enforce protected-main source provenance for EVERY install path, including
	 * the native Plugins screen. A matching release checksum alone is insufficient
	 * if somebody replaces the release ZIP and checksum together.
	 *
	 * @param array<string, mixed> $release Canonical GitHub release metadata.
	 * @return string Verified temporary ZIP path, owned by the caller.
	 * @throws \Throwable If download, integrity or source provenance fails.
	 */
	private function download_protected_source_package( array $release ): string {
		$package = $this->download_verified_package( $release );
		try {
			$this->verifyProtectedSource( $release, $package );
			return $package;
		} catch ( \Throwable $error ) {
			if ( file_exists( $package ) ) {
				wp_delete_file( $package );
			}
			throw $error;
		}
	}

	/**
	 * Shared verification for REST self-update and the native Plugins screen.
	 * Returns a temporary ZIP owned by the caller. Never trust a ZIP merely
	 * because it was downloaded from GitHub over HTTPS.
	 *
	 * @param array $release Verified release metadata.
	 *
	 * @throws \Throwable When the requested operation cannot be completed safely.
	 * @throws \RuntimeException When package integrity checks fail.
	 */
	private function download_verified_package( array $release ): string {
		$expected_sha256 = $this->checksum( $release['checksum_url'], $release['checksum_digest'] );
		if ( ! hash_equals( 'sha256:' . $expected_sha256, $release['package_digest'] ) ) {
			throw new RuntimeException( 'GitHub package asset digest does not match the canonical checksum asset.' );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$package = wp_tempnam( self::PACKAGE_ASSET );
		if ( ! is_string( $package ) || '' === $package ) {
			throw new RuntimeException( 'Cannot allocate a temporary Connector release ZIP.' );
		}

		try {
			// download_url() has no transfer-size limit: bound the actual streamed
			// HTTP body before hashing or inspecting the archive.
			$response = wp_safe_remote_get(
				$release['package_url'],
				array(
					'timeout'             => 30,
					'redirection'         => 5,
					'limit_response_size' => self::MAX_PACKAGE_BYTES + 1,
					'sslverify'           => true,
					'stream'              => true,
					'filename'            => $package,
					'headers'             => array( 'User-Agent' => 'Webactueel-WordPressConnector' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( 'Canonical Connector release download failed.' );
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				throw new RuntimeException( 'Canonical Connector release download returned a non-success status.' );
			}
			$size = filesize( $package );
			if ( false === $size || $size <= 0 || $size > self::MAX_PACKAGE_BYTES ) {
				throw new RuntimeException( 'Connector release package is empty or exceeds the 20 MiB limit.' );
			}
			$actual_sha256 = hash_file( 'sha256', $package );
			if ( ! is_string( $actual_sha256 ) || ! hash_equals( $expected_sha256, strtolower( $actual_sha256 ) )
				|| ! hash_equals( $release['package_digest'], 'sha256:' . strtolower( $actual_sha256 ) ) ) {
				throw new RuntimeException( 'Connector release package SHA-256 verification failed.' );
			}

			$package_info = $this->inspect_package( $package );
			if ( ! hash_equals( self::PLUGIN_FILE, $package_info['plugin_file'] )
				|| ! hash_equals( $release['version'], $package_info['version'] ) ) {
				throw new RuntimeException( 'Connector release ZIP identity or version does not match the published release.' );
			}

			return $package;
		} catch ( \Throwable $error ) {
			if ( is_string( $package ) && file_exists( $package ) ) {
				@unlink( $package );
			}
			throw $error;
		}
	}

	/**
	 * Fetch bounded JSON from only the canonical repository GitHub API.
	 * A compromised release can never supply a remote URL to this method.
	 *
	 * @param string $path API path suffix assembled from validated Git SHA.
	 * @param int    $limit Maximum JSON response bytes.
	 * @return array Decoded JSON object.
	 * @throws \RuntimeException If the bounded trusted-source request fails validation.
	 */
	private function trustedSourceJson( string $path, int $limit = 524288 ): array {
		if ( ! preg_match( '#^/(compare/[a-f0-9]{40}\.\.\.main\?per_page=1|git/commits/[a-f0-9]{40}|git/trees/[a-f0-9]{40}\?recursive=1)\z#', $path ) ) {
			throw new RuntimeException( 'Invalid protected-source request path.' );
		}
		$response = wp_safe_remote_get(
			self::SOURCE_API . $path,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => $limit + 1,
				'sslverify'           => true,
				'headers'             => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Webactueel-WordPressConnector',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new RuntimeException( 'Cannot verify the GitHub protected-main release source.' );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > $limit ) {
			throw new RuntimeException( 'Protected source response exceeded its size limit.' );
		}
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			throw new RuntimeException( 'Protected source response was not valid JSON.' );
		}
		return $json;
	}

	/**
	 * Independent release provenance: verify every decompressed plugin file
	 * against the exact Git tree of a commit already in protected main.
	 * GitHub release/checksum asset publishers cannot forge this source tree
	 * without first changing protected main; this is not a claim of locally
	 * verified Sigstore attestation.
	 *
	 * @param array  $release Canonical release metadata.
	 * @param string $package Already checksum/identity validated ZIP.
	 * @throws \RuntimeException If source commit ancestry or file integrity cannot be verified.
	 */
	private function verifyProtectedSource( array $release, string $package ): void {
		$commit = $release['source_commit'] ?? '';
		if ( ! is_string( $commit ) || ! preg_match( '/^[a-f0-9]{40}\z/', $commit ) ) {
			throw new RuntimeException( 'Release must identify the exact approved protected-main commit.' );
		}
		// The authenticated GitHub API controls the protected main ancestry;
		// the ZIP author cannot affect this proof by replacing release assets.
		// A paged comparison retains the merge-base proof; bound file patches to 8 MiB.
		$compare = $this->trustedSourceJson( '/compare/' . $commit . '...main?per_page=1', 8388608 );
		if ( ! in_array( $compare['status'] ?? '', array( 'ahead', 'identical' ), true )
			|| ( $compare['merge_base_commit']['sha'] ?? '' ) !== $commit ) {
			throw new RuntimeException( 'Release commit is not an ancestor of protected main.' );
		}
		$git_commit = $this->trustedSourceJson( '/git/commits/' . $commit, 65536 );
		$tree_sha   = $git_commit['tree']['sha'] ?? '';
		if ( ! is_string( $tree_sha ) || ! preg_match( '/^[a-f0-9]{40}\z/', $tree_sha ) ) {
			throw new RuntimeException( 'Protected release commit has no canonical Git tree.' );
		}
		$tree = $this->trustedSourceJson( '/git/trees/' . $tree_sha . '?recursive=1', 2097152 );
		if ( true === ( $tree['truncated'] ?? null ) || ! isset( $tree['tree'] ) || ! is_array( $tree['tree'] ) ) {
			throw new RuntimeException( 'Protected release source tree is incomplete.' );
		}
		$expected = array();
		$root     = 'plugin/wordpressconnector/';
		foreach ( $tree['tree'] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['path'] ) || ! is_string( $entry['path'] )
				|| 0 !== strpos( $entry['path'], $root ) ) {
				continue;
			}
			if ( 'tree' === ( $entry['type'] ?? '' ) ) {
				continue;
			}
			$relative = substr( $entry['path'], strlen( $root ) );
			$sha      = $entry['sha'] ?? '';
			if ( 'blob' !== ( $entry['type'] ?? '' ) || ! in_array( $entry['mode'] ?? '', array( '100644', '100755' ), true )
				|| ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{40}\z/', $sha )
				|| ! preg_match( '#^[A-Za-z0-9._/-]+\z#', $relative )
				|| false !== strpos( '/' . $relative . '/', '/../' )
				|| false !== strpos( '/' . $relative . '/', '/./' ) ) {
				throw new RuntimeException( 'Protected source contains an unexpected plugin file or symlink.' );
			}
			$path = 'wordpressconnector/' . $relative;
			if ( isset( $expected[ $path ] ) ) {
				throw new RuntimeException( 'Protected source has duplicate plugin entries.' );
			}
			$expected[ $path ] = $sha;
		}
		if ( count( $expected ) < 1 || count( $expected ) > self::MAX_ENTRIES
			|| ! isset( $expected[ self::PLUGIN_FILE ] ) ) {
			throw new RuntimeException( 'Protected source has no valid bounded Connector plugin tree.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $package, \ZipArchive::RDONLY ) ) {
			throw new RuntimeException( 'Cannot open the checksum-validated release ZIP for source verification.' );
		}
		try {
			if ( $zip->count() !== count( $expected ) ) {
				throw new RuntimeException( 'Release ZIP file set differs from protected main source.' );
			}
			foreach ( $expected as $name => $git_sha ) {
				$index = $zip->locateName( $name, \ZipArchive::FL_UNCHANGED );
				if ( false === $index ) {
					throw new RuntimeException( 'Release ZIP is missing a file from protected main.' );
				}
				$stat = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
				if ( ! is_array( $stat ) || (int) ( $stat['size'] ?? -1 ) < 0
					|| (int) $stat['size'] > 20971520 ) {
					throw new RuntimeException( 'Protected source verification refused an oversized ZIP entry.' );
				}
				$bytes = $zip->getFromIndex( $index, 0, \ZipArchive::FL_UNCHANGED );
				if ( ! is_string( $bytes )
					|| ! hash_equals( $git_sha, sha1( 'blob ' . strlen( $bytes ) . "\0" . $bytes ) ) ) {
					throw new RuntimeException( 'Release ZIP bytes differ from the protected main commit.' );
				}
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Process the checksum operation in the ConnectorUpdate adapter.
	 *
	 * @param string $url Input for the operation.
	 * @param string $expected_asset_digest Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function checksum( string $url, string $expected_asset_digest ): string {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 5,
				'limit_response_size' => 1024,
				'headers'             => array( 'User-Agent' => 'Webactueel-WordPressConnector' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new RuntimeException( 'Connector checksum asset could not be downloaded.' );
		}

		$raw_body            = (string) wp_remote_retrieve_body( $response );
		$actual_asset_digest = 'sha256:' . hash( 'sha256', $raw_body );
		if ( ! hash_equals( $expected_asset_digest, $actual_asset_digest ) ) {
			throw new RuntimeException( 'Connector checksum asset GitHub digest verification failed.' );
		}
		$body = trim( $raw_body );
		if ( ! preg_match( '/^([a-f0-9]{64})  wordpressconnector\.zip\z/', $body, $matches ) ) {
			throw new RuntimeException( 'Connector checksum asset has an invalid format.' );
		}
		return (string) $matches[1];
	}

	/**
	 * Inspect package for the ConnectorUpdate adapter.
	 *
	 * @param string $package Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function inspect_package( string $package ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'PHP ZipArchive is required for connector release verification.' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $package, \ZipArchive::RDONLY ) ) {
			throw new RuntimeException( 'Connector release ZIP could not be opened.' );
		}

		try {
			$entry_count = $zip->count();
			if ( $entry_count < 1 || $entry_count > self::MAX_ENTRIES ) {
				throw new RuntimeException( 'Connector release ZIP has an invalid number of entries.' );
			}

			$paths              = array();
			$uncompressed_bytes = 0;
			for ( $index = 0; $index < $entry_count; ++$index ) {
				$stat = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
				if ( ! is_array( $stat ) || ! isset( $stat['name'] ) ) {
					throw new RuntimeException( 'Connector release ZIP contains an unreadable entry.' );
				}
				$name = (string) $stat['name'];
				if ( '' === $name || strlen( $name ) > 512 || false !== strpos( $name, "\0" ) || preg_match( '/[\x01-\x1F\x7F]/', $name ) || false !== strpos( $name, '\\' ) || '/' === $name[0] || preg_match( '/^[A-Za-z]:/', $name ) ) {
					throw new RuntimeException( 'Connector release ZIP contains an unsafe entry path.' );
				}

				$normalized = '/' === substr( $name, -1 ) ? rtrim( $name, '/' ) : $name;
				if ( '' === $normalized ) {
					throw new RuntimeException( 'Connector release ZIP contains an invalid root entry.' );
				}
				$segments = explode( '/', $normalized );
				if ( 'wordpressconnector' !== $segments[0] ) {
					throw new RuntimeException( 'Connector release ZIP contains an unexpected top-level path.' );
				}
				foreach ( $segments as $segment ) {
					if ( '' === $segment || '.' === $segment || '..' === $segment ) {
						throw new RuntimeException( 'Connector release ZIP contains path traversal or an invalid segment.' );
					}
				}
				if ( isset( $paths[ $normalized ] ) ) {
					throw new RuntimeException( 'Connector release ZIP contains duplicate entry paths.' );
				}
				$paths[ $normalized ] = true;

				$entry_bytes = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
				if ( $entry_bytes < 0 ) {
					throw new RuntimeException( 'Connector release ZIP contains an invalid entry size.' );
				}
				$uncompressed_bytes += $entry_bytes;
				if ( $uncompressed_bytes > self::MAX_UNCOMPRESSED_BYTES ) {
					throw new RuntimeException( 'Connector release ZIP exceeds the uncompressed size limit.' );
				}

				$opsys      = 0;
				$attributes = 0;
				if ( $zip->getExternalAttributesIndex( $index, $opsys, $attributes ) ) {
					$mode = ( $attributes >> 16 ) & 0xF000;
					if ( 0xA000 === $mode ) {
						throw new RuntimeException( 'Connector release ZIP symlink entries are not allowed.' );
					}
				}
			}

			$main = $zip->getFromName( self::PLUGIN_FILE, 16384, \ZipArchive::FL_UNCHANGED );
			if ( ! is_string( $main ) ) {
				throw new RuntimeException( 'Connector release ZIP is missing the canonical main plugin file.' );
			}
			if ( ! preg_match( '/(?:^|\n)[ \t\/*#@]*Plugin Name:\s*WordPress Connector(?:\r?\n|$)/i', $main ) ) {
				throw new RuntimeException( 'Connector release ZIP main file has an invalid plugin identity.' );
			}
			if ( ! preg_match( '/(?:^|\n)[ \t\/*#@]*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)(?:\r?\n|$)/i', $main, $matches ) ) {
				throw new RuntimeException( 'Connector release ZIP main file has no valid semantic version.' );
			}

			return array(
				'plugin_file' => self::PLUGIN_FILE,
				'version'     => (string) $matches[1],
			);
		} finally {
			$zip->close();
		}
	}
}
