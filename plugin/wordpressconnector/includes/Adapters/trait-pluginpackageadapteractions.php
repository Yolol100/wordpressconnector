<?php
/**
 * Canonical public method implementations for PluginPackageAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait PluginPackageAdapterActions {
	/**
	 * A theme ZIP uses the same private request asset store and exact SHA-256
	 * binding as plugin packages. Public requests cannot invoke this action.
	 *
	 * @param Registry $registry Action registry to register handlers with.
	 */
	public function register_theme_package( Registry $registry ): void {
		$registry->register(
			'theme.install_package',
			array( $this, 'installThemePackage' ),
			array(
				'mutation'      => true,
				'privileged'    => true,
				'system_update' => true,
				'capability'    => 'install_themes',
				'description'   => 'Install or replace an inactive theme from a verified, private request ZIP.',
			)
		);
	}

	/**
	 * Process the install theme package operation in the PluginPackage adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 * @throws \Throwable If the operation cannot be completed safely.
	 */
	public function install_theme_package( array $payload, array $context ): array {
		if ( array_diff( array_keys( $payload ), array( 'source_path', 'sha256', 'expected_stylesheet', 'overwrite' ) ) ) {
			throw new RuntimeException( 'Theme package request contains unsupported fields.' );
		}
		$source   = isset( $payload['source_path'] ) && is_string( $payload['source_path'] ) ? $payload['source_path'] : '';
		$expected = isset( $payload['expected_stylesheet'] ) && is_string( $payload['expected_stylesheet'] ) ? $payload['expected_stylesheet'] : '';
		if ( ! preg_match( '#^theme-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.zip\z#', $source )
			|| ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,79}\z/', $expected )
			|| ! array_key_exists( 'overwrite', $payload ) || ! is_bool( $payload['overwrite'] ) ) {
			throw new RuntimeException( 'Theme package requires safe ZIP, expected_stylesheet and explicit overwrite boolean.' );
		}
		$overwrite = $payload['overwrite'];
		if ( ! current_user_can( 'install_themes' ) || ( $overwrite && ! current_user_can( 'update_themes' ) ) ) {
			throw new RuntimeException( 'Current user lacks theme install or replacement capabilities.' );
		}
		$root = isset( $context['asset_root'] ) ? (string) $context['asset_root'] : '';
		if ( '' === $root ) {
			throw new RuntimeException( 'Theme ZIP requires a trusted, private request asset root.' );
		}
		$package = Policy::assertLocalAssetPath( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $source ), $root );
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content_root = realpath( WP_CONTENT_DIR );
			if ( false !== $content_root && 0 === strpos( $package, rtrim( $content_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR ) ) {
				throw new RuntimeException( 'Theme package must not be inside WordPress upgrade scratch storage.' );
			}
		}
		$size = filesize( $package );
		if ( false === $size || $size < 1 || $size > self::MAX_PACKAGE_BYTES ) {
			throw new RuntimeException( 'Theme package exceeds the 20 MiB size limit.' );
		}
		$sha256 = isset( $payload['sha256'] ) && is_string( $payload['sha256'] ) ? $payload['sha256'] : '';
		if ( ! preg_match( '/^[a-f0-9]{64}\z/', $sha256 )
			|| ! hash_equals( $sha256, (string) hash_file( 'sha256', $package ) ) ) {
			throw new RuntimeException( 'Theme package SHA-256 does not match.' );
		}
		$info = $this->inspect_theme_package( $package, $expected );
		if ( get_stylesheet() === $expected || get_template() === $expected ) {
			throw new RuntimeException( 'Refusing to replace or install over the active theme or its parent.' );
		}
		$current   = wp_get_theme( $expected );
		$installed = $current->exists();
		if ( $installed !== $overwrite ) {
			throw new RuntimeException( $installed ? 'Theme exists; overwrite must be explicitly true.' : 'Theme is not installed; overwrite must be false.' );
		}
		if ( $installed ) {
			$theme_root = realpath( get_theme_root( $expected ) );
			$canonical  = realpath( get_theme_root() );
			if ( false === $theme_root || false === $canonical || ! hash_equals( $canonical, $theme_root ) ) {
				throw new RuntimeException( 'Custom theme roots cannot be overwritten via the connector.' );
			}
			foreach ( wp_get_themes() as $name => $theme ) {
				if ( $name !== $expected && (string) $theme->get( 'Template' ) === $expected ) {
					throw new RuntimeException( 'Refusing to overwrite a parent theme used by an installed child.' );
				}
			}
		}
		$before = array(
			'stylesheet' => $expected,
			'installed'  => $installed,
			'version'    => $installed ? (string) $current->get( 'Version' ) : null,
		);
		$plan   = array(
			'stylesheet'         => $expected,
			'source_path'        => $source,
			'sha256'             => $sha256,
			'bytes'              => (int) $size,
			'overwrite'          => $overwrite,
			'archive_entries'    => $info['entries'],
			'uncompressed_bytes' => $info['uncompressed_bytes'],
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_install_theme_package' => $plan,
				'before'                      => $before,
				'_current_fingerprint'        => Fingerprint::make( $before ),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'theme_install' ) ) {
			throw new RuntimeException( 'WordPress file modification policy forbids theme installation.' );
		}
		if ( $overwrite && ! Policy::flag( 'WPCONNECTOR_ALLOW_THEME_PACKAGE_REPLACE' ) ) {
			throw new RuntimeException( 'Theme ZIP replacement requires explicit server-side authorization and staging restore proof.' );
		}
		$style_path        = trailingslashit( get_theme_root( $expected ) ) . $expected . DIRECTORY_SEPARATOR . 'style.css';
		$before_style_hash = $overwrite ? hash_file( 'sha256', $style_path ) : '';
		if ( $overwrite && ( ! is_string( $before_style_hash ) || ! preg_match( '/^[a-f0-9]{64}\z/', $before_style_hash ) ) ) {
			throw new RuntimeException( 'Could not verify original theme files before replacement.' );
		}
		$upgrader         = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
		$backup           = array(
			'slug' => $expected,
			'src'  => get_theme_root(),
			'dir'  => 'themes',
		);
		$restore_pending  = false;
		$shutdown_restore = static function () use ( $upgrader, $backup, &$restore_pending ): void {
			if ( $restore_pending ) {
				$upgrader->restore_temp_backup( array( $backup ) );
			}
		};
		if ( $overwrite ) {
			global $wp_filesystem;
			if ( true !== WP_Filesystem() || ! is_object( $wp_filesystem )
				|| ! $wp_filesystem->wp_content_dir() ) {
				throw new RuntimeException( 'WordPress filesystem is unavailable; cannot create theme replacement backup.' );
			}
			$existing_backup = trailingslashit( $wp_filesystem->wp_content_dir() ) .
				'upgrade-temp-backup/themes/' . $expected;
			if ( $wp_filesystem->exists( $existing_backup ) ) {
				throw new RuntimeException( 'An unresolved backup of this theme exists; refusing to overwrite it.' );
			}
			// Register recovery before moving anything. If the PHP request dies
			// during WordPress installation, shutdown can still restore the
			// original inactive theme from Core's temp backup.
			$restore_pending = true;
			add_action( 'shutdown', $shutdown_restore, 10, 0 );
			$saved = $upgrader->move_to_temp_backup_dir( $backup );
			if ( true !== $saved ) {
				$restore_pending = false;
				remove_action( 'shutdown', $shutdown_restore, 10 );
				throw new RuntimeException( 'The original theme could not be backed up safely.' );
			}
		}

		try {
			// The original inactive theme was moved to Core's protected
			// backup directory, so installation must never clear a live path.
			$result = $upgrader->install( $package, array( 'overwrite_package' => false ) );
			if ( is_wp_error( $result ) || true !== $result ) {
				throw new RuntimeException( 'Theme ZIP installation failed; check WordPress upgrader and filesystem permissions.' );
			}
			wp_clean_themes_cache();
			$after_theme = wp_get_theme( $expected );
			if ( ! $after_theme->exists() || $after_theme->errors() ) {
				throw new RuntimeException( 'Theme installation readback failed.' );
			}
			$after = array(
				'stylesheet' => $expected,
				'version'    => (string) $after_theme->get( 'Version' ),
			);
		} catch ( \Throwable $error ) {
			if ( $overwrite ) {
				$restored        = $upgrader->restore_temp_backup( array( $backup ) );
				$restore_pending = false;
				remove_action( 'shutdown', $shutdown_restore, 10 );
				wp_clean_themes_cache();
				$restored_style_hash = is_file( $style_path ) ? hash_file( 'sha256', $style_path ) : false;
				if ( true !== $restored || ! wp_get_theme( $expected )->exists()
					|| ! is_string( $restored_style_hash ) || ! hash_equals( $before_style_hash, $restored_style_hash ) ) {
					throw new RuntimeException( 'Theme replacement failed and automatic recovery could not be verified. Manual recovery is required.' );
				}
				throw new RuntimeException( 'Theme replacement failed; original theme restored. Check the WordPress upgrader and filesystem permissions.' );
			}
			throw $error;
		}
		$restore_pending                   = false;
		$temporary_backup_cleanup_verified = null;
		if ( $overwrite ) {
			remove_action( 'shutdown', $shutdown_restore, 10 );
			$cleaned                           = $upgrader->delete_temp_backup( array( $backup ) );
			$temporary_backup_cleanup_verified = true === $cleaned;
		}
		return array(
			'operation'                         => $overwrite ? 'updated' : 'installed',
			'package'                           => $plan,
			'before'                            => $before,
			'after'                             => $after,
			'on_failure_restore_supported'      => $overwrite,
			'temporary_backup_cleanup_verified' => $temporary_backup_cleanup_verified,
			'manual_cleanup_required'           => $overwrite && ! $temporary_backup_cleanup_verified,
			// WordPress temp backups may be cleaned up automatically; do not
			// advertise a durable user-triggered rollback after success.
			'rollback_supported'                => false,
			'readback_verified'                 => true,
		);
	}

	/**
	 * Process the install package operation in the PluginPackage adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function install_package( array $payload, array $context ): array {
		$asset_root = isset( $context['asset_root'] ) ? (string) $context['asset_root'] : '';
		if ( '' === $asset_root ) {
			throw new RuntimeException( 'plugin.install_package requires a trusted request asset root.' );
		}

		$source_path = isset( $payload['source_path'] ) ? str_replace( '\\', '/', (string) $payload['source_path'] ) : '';
		if ( ! preg_match( '#^plugin-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.zip\z#', $source_path ) ) {
			throw new RuntimeException( 'source_path must be plugin-packages/<safe-name>.zip.' );
		}

		$candidate = $asset_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $source_path );
		$package   = Policy::assertLocalAssetPath( $candidate, $asset_root );
		// Core's WP_Upgrader::unpack_package() clears wp-content/upgrade
		// before extraction. A ZIP stored there would be deleted mid-install.
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content_dir = realpath( WP_CONTENT_DIR );
			if ( false !== $content_dir && 0 === strpos(
				$package,
				rtrim( $content_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR
			) ) {
				throw new RuntimeException( 'Plugin ZIP is in WordPress upgrade scratch storage, which Core clears during installation; upload it to the trusted asset store outside wp-content/upgrade.' );
			}
		}
		if ( 'zip' !== strtolower( (string) pathinfo( $package, PATHINFO_EXTENSION ) ) ) {
			throw new RuntimeException( 'Plugin package must be a ZIP file.' );
		}

		$configured_limit = getenv( 'WPCONNECTOR_MAX_PLUGIN_PACKAGE_BYTES' );
		$max_bytes        = (int) ( $configured_limit ? $configured_limit : self::MAX_PACKAGE_BYTES );
		$max_bytes        = max( 1048576, min( self::MAX_PACKAGE_BYTES, $max_bytes ) );
		$size             = filesize( $package );
		if ( false === $size || $size <= 0 || $size > $max_bytes ) {
			throw new RuntimeException( 'Plugin package is empty or exceeds the configured size limit.' );
		}

		$expected_sha256 = isset( $payload['sha256'] ) ? strtolower( (string) $payload['sha256'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}\z/', $expected_sha256 ) ) {
			throw new RuntimeException( 'sha256 must be a 64-character lowercase hexadecimal checksum.' );
		}
		$actual_sha256 = hash_file( 'sha256', $package );
		if ( ! is_string( $actual_sha256 ) || ! hash_equals( $expected_sha256, strtolower( $actual_sha256 ) ) ) {
			throw new RuntimeException( 'Plugin package SHA-256 checksum does not match.' );
		}

		$expected_plugin = isset( $payload['expected_plugin'] ) ? str_replace( '\\', '/', (string) $payload['expected_plugin'] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+\.php\z/', $expected_plugin ) ) {
			throw new RuntimeException( 'expected_plugin must be an exact plugin file such as plugin-slug/plugin.php.' );
		}

		$package_info = $this->inspect_package( $package );
		if ( ! hash_equals( $expected_plugin, $package_info['plugin_file'] ) ) {
			throw new RuntimeException( 'Plugin package identity does not match expected_plugin.' );
		}
		$this->assert_not_self( $expected_plugin );

		$overwrite    = Input::bool( $payload, 'overwrite' );
		$activate     = Input::bool( $payload, 'activate' );
		$network_wide = Input::bool( $payload, 'network_wide' );
		$this->assert_capabilities( $overwrite, $activate, $network_wide );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins   = get_plugins();
		$installed = isset( $plugins[ $expected_plugin ] );

		if ( $overwrite && ! $installed ) {
			throw new RuntimeException( 'overwrite=true requires expected_plugin to already be installed.' );
		}
		if ( ! $overwrite && $installed ) {
			throw new RuntimeException( 'Plugin is already installed. Use overwrite=true with the same expected_plugin to replace it.' );
		}

		$destination_root = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $expected_plugin );
		if ( ! $overwrite && is_dir( $destination_root ) ) {
			throw new RuntimeException( 'Plugin destination directory already exists. Refusing an ambiguous install.' );
		}

		$before = $this->snapshot( $expected_plugin, $plugins );
		$plan   = array(
			'source_path'        => $source_path,
			'sha256'             => $actual_sha256,
			'bytes'              => (int) $size,
			'plugin_file'        => $expected_plugin,
			'plugin_name'        => $package_info['plugin_name'],
			'archive_entries'    => $package_info['entries'],
			'uncompressed_bytes' => $package_info['uncompressed_bytes'],
			'overwrite'          => $overwrite,
			'activate'           => $activate,
			'network_wide'       => $network_wide,
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_install_package' => $plan,
				'before'                => $before,
				'_current_fingerprint'  => Fingerprint::make( $before ),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $package, array( 'overwrite_package' => $overwrite ) );
		if ( is_wp_error( $result ) || true !== $result ) {
			throw new RuntimeException( 'Plugin package installation failed; verify filesystem, target directory and host restrictions.' );
		}

		wp_clean_plugins_cache( true );
		$installed_file = $upgrader->plugin_info();
		if ( ! is_string( $installed_file ) || ! hash_equals( $expected_plugin, str_replace( '\\', '/', $installed_file ) ) ) {
			throw new RuntimeException( 'Installed plugin identity did not match the verified package identity.' );
		}

		if ( $activate ) {
			$needs_activation = $network_wide
				? ! is_plugin_active_for_network( $expected_plugin )
				: ! is_plugin_active( $expected_plugin );
			if ( $needs_activation ) {
				// This is a user-requested fresh activation, not a silent Core upgrade.
				// Third-party install hooks must run exactly as in wp-admin.
				$activated = activate_plugin( $expected_plugin, '', $network_wide, false );
				if ( is_wp_error( $activated ) ) {
					throw new RuntimeException( 'Plugin was installed but activation failed; check plugin requirements or security restrictions.' );
				}
			}
		}

		wp_clean_plugins_cache( true );
		$after_plugins = get_plugins();
		if ( ! isset( $after_plugins[ $expected_plugin ] ) ) {
			throw new RuntimeException( 'Plugin package readback failed: expected plugin is not installed.' );
		}

		$after = $this->snapshot( $expected_plugin, $after_plugins );
		if ( $activate && ! ( $network_wide ? $after['network_active'] : $after['active'] ) ) {
			throw new RuntimeException( 'Plugin was installed but activation did not persist; investigate the activation hook and server restrictions.' );
		}
		return array(
			'operation'          => $overwrite ? 'updated' : 'installed',
			'package'            => $plan,
			'before'             => $before,
			'after'              => $after,
			'rollback_supported' => false,
		);
	}
}
