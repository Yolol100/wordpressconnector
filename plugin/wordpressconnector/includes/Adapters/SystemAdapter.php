<?php
/**
 * Registers and handles System connector actions.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

require_once __DIR__ . '/trait-systemadapteractions.php';

/**
 * Registers and handles System connector actions.
 */
final class SystemAdapter {
	use SystemAdapterActions {
		plugin_directory_search as pluginDirectorySearch;
		user_list as userList;
		user_get as userGet;
		user_create as userCreate;
		user_update as userUpdate;
		user_force_password_reset as userForcePasswordReset;
		user_delete as userDelete;
		role_list as roleList;
		role_create as roleCreate;
		role_update as roleUpdate;
		role_delete as roleDelete;
		plugin_list as pluginList;
		plugin_activate as pluginActivate;
		plugin_deactivate as pluginDeactivate;
		plugin_install as pluginInstall;
		plugin_update as pluginUpdate;
		plugin_delete as pluginDelete;
		theme_list as themeList;
		theme_switch as themeSwitch;
		theme_install as themeInstall;
		theme_update as themeUpdate;
		theme_delete as themeDelete;
		core_check_updates as coreCheckUpdates;
		core_update as coreUpdate;
		cron_list as cronList;
		cron_delete as cronDelete;
		cron_schedule as cronSchedule;
		rewrite_flush as rewriteFlush;
		cache_flush as cacheFlush;
		site_list as siteList;
		site_create as siteCreate;
		site_update as siteUpdate;
		site_delete as siteDelete;
	}

	/**
	 * Register connector actions and their handlers.
	 *
	 * @param Registry $registry Action registry to extend.
	 */
	public function register( Registry $registry ): void {
		$privileged    = array( 'privileged' => true );
		$sensitive     = array(
			'privileged' => true,
			'sensitive'  => true,
		);
		$system_update = array(
			'mutation'      => true,
			'privileged'    => true,
			'system_update' => true,
		);

		$registry->register( 'system.doctor', array( $this, 'doctor' ), array( 'description' => 'Run connector/runtime preflight diagnostics.' ) );

		$registry->register(
			'user.list',
			array( $this, 'userList' ),
			$sensitive + array(
				'capability'  => 'list_users',
				'description' => 'List WordPress users.',
			)
		);
		$registry->register(
			'user.get',
			array( $this, 'userGet' ),
			$sensitive + array(
				'capability'  => 'list_users',
				'description' => 'Read a WordPress user.',
			)
		);
		$registry->register(
			'user.create',
			array( $this, 'userCreate' ),
			$sensitive + array(
				'mutation'    => true,
				'capability'  => 'create_users',
				'description' => 'Create a WordPress user with a server-generated password.',
			)
		);
		$registry->register(
			'user.update',
			array( $this, 'userUpdate' ),
			$sensitive + array(
				'mutation'    => true,
				'capability'  => 'edit_users',
				'description' => 'Update WordPress user profile fields and roles.',
			)
		);
		$registry->register(
			'user.force_password_reset',
			array( $this, 'userForcePasswordReset' ),
			$sensitive + array(
				'mutation'    => true,
				'capability'  => 'edit_users',
				'description' => 'Immediately rotate a WordPress user password, revoke active sessions and send a dedicated security reset link without exposing secret material. Non-rollbackable.',
			)
		);
		$registry->register(
			'user.delete',
			array( $this, 'userDelete' ),
			$sensitive + array(
				'mutation'    => true,
				'capability'  => 'delete_users',
				'description' => 'Delete a WordPress user.',
			)
		);

		$registry->register(
			'role.list',
			array( $this, 'roleList' ),
			$privileged + array(
				'capability'  => 'promote_users',
				'description' => 'List roles and capabilities.',
			)
		);
		$registry->register(
			'role.create',
			array( $this, 'roleCreate' ),
			$privileged + array(
				'mutation'    => true,
				'capability'  => 'promote_users',
				'description' => 'Create a WordPress role.',
			)
		);
		$registry->register(
			'role.update',
			array( $this, 'roleUpdate' ),
			$privileged + array(
				'mutation'    => true,
				'capability'  => 'promote_users',
				'description' => 'Add or remove capabilities from a role.',
			)
		);
		$registry->register(
			'role.delete',
			array( $this, 'roleDelete' ),
			$privileged + array(
				'mutation'    => true,
				'capability'  => 'promote_users',
				'description' => 'Delete a WordPress role.',
			)
		);

		$registry->register(
			'plugin.list',
			array( $this, 'pluginList' ),
			$privileged + array(
				'capability'  => 'activate_plugins',
				'description' => 'List installed plugins with activation, available update state and permitted actions.',
			)
		);
		$registry->register(
			'plugin.directory.search',
			array( $this, 'pluginDirectorySearch' ),
			$privileged + array(
				'capability'  => 'install_plugins',
				'description' => 'Search the official WordPress.org plugin directory for bounded install candidates.',
			)
		);
		$registry->register(
			'plugin.activate',
			array( $this, 'pluginActivate' ),
			$privileged + array(
				'mutation'    => true,
				'capability'  => 'activate_plugins',
				'description' => 'Activate an installed plugin.',
			)
		);
		$registry->register(
			'plugin.deactivate',
			array( $this, 'pluginDeactivate' ),
			$privileged + array(
				'mutation'    => true,
				'capability'  => 'activate_plugins',
				'description' => 'Deactivate an installed plugin.',
			)
		);
		$registry->register(
			'plugin.install',
			array( $this, 'pluginInstall' ),
			$system_update + array(
				'capability'  => 'install_plugins',
				'description' => 'Install a plugin from WordPress.org by slug.',
			)
		);
		$registry->register(
			'plugin.update',
			array( $this, 'pluginUpdate' ),
			$system_update + array(
				'capability'  => 'update_plugins',
				'description' => 'Update one installed plugin.',
			)
		);
		$registry->register(
			'plugin.delete',
			array( $this, 'pluginDelete' ),
			$system_update + array(
				'capability'  => 'delete_plugins',
				'description' => 'Delete an inactive installed plugin.',
			)
		);

		$registry->register(
			'theme.list',
			array( $this, 'themeList' ),
			$privileged + array(
				'capability'  => 'switch_themes',
				'description' => 'List installed themes.',
			)
		);
		$registry->register(
			'theme.switch',
			array( $this, 'themeSwitch' ),
			$privileged + array(
				'mutation'    => true,
				'capability'  => 'switch_themes',
				'description' => 'Switch the active theme.',
			)
		);
		$registry->register(
			'theme.install',
			array( $this, 'themeInstall' ),
			$system_update + array(
				'capability'  => 'install_themes',
				'description' => 'Install a theme from WordPress.org by slug.',
			)
		);
		$registry->register(
			'theme.update',
			array( $this, 'themeUpdate' ),
			$system_update + array(
				'capability'  => 'update_themes',
				'description' => 'Update one installed theme.',
			)
		);
		$registry->register(
			'theme.delete',
			array( $this, 'themeDelete' ),
			$system_update + array(
				'capability'  => 'delete_themes',
				'description' => 'Delete an inactive theme.',
			)
		);

		$registry->register(
			'core.check_updates',
			array( $this, 'coreCheckUpdates' ),
			$privileged + array(
				'capability'  => 'update_core',
				'description' => 'Check WordPress core updates.',
			)
		);
		$registry->register(
			'core.update',
			array( $this, 'coreUpdate' ),
			$system_update + array(
				'capability'  => 'update_core',
				'description' => 'Update WordPress core. Non-rollbackable.',
			)
		);

		$registry->register(
			'cron.list',
			array( $this, 'cronList' ),
			$privileged + array(
				'capability'  => 'manage_options',
				'description' => 'List scheduled cron events.',
			)
		);
		$registry->register(
			'cron.run',
			array( $this, 'cronRun' ),
			$privileged + array(
				'mutation'    => true,
				'capability'  => 'manage_options',
				'description' => 'Run one existing scheduled cron event immediately.',
			)
		);
		$registry->register(
			'cron.delete',
			array( $this, 'cronDelete' ),
			$privileged + array(
				'mutation'    => true,
				'capability'  => 'manage_options',
				'description' => 'Delete scheduled events for one hook.',
			)
		);
		$registry->register(
			'cron.schedule',
			array( $this, 'cronSchedule' ),
			$privileged + array(
				'mutation'    => true,
				'capability'  => 'manage_options',
				'description' => 'Reschedule an existing cron hook and argument set.',
			)
		);

		$registry->register(
			'rewrite.flush',
			array( $this, 'rewriteFlush' ),
			$privileged + array(
				'mutation'    => true,
				'description' => 'Flush rewrite rules.',
			)
		);
		$registry->register(
			'cache.flush',
			array( $this, 'cacheFlush' ),
			$privileged + array(
				'mutation'    => true,
				'description' => 'Flush WordPress object cache.',
			)
		);

		$registry->register(
			'multisite.site.list',
			array( $this, 'siteList' ),
			$sensitive + array(
				'capability'  => 'manage_sites',
				'description' => 'List multisite network sites.',
			)
		);
		$registry->register(
			'multisite.site.create',
			array( $this, 'siteCreate' ),
			$sensitive + array(
				'mutation'    => true,
				'capability'  => 'create_sites',
				'description' => 'Create a multisite site.',
			)
		);
		$registry->register(
			'multisite.site.update',
			array( $this, 'siteUpdate' ),
			$sensitive + array(
				'mutation'    => true,
				'capability'  => 'manage_sites',
				'description' => 'Update multisite site fields.',
			)
		);
		$registry->register(
			'multisite.site.delete',
			array( $this, 'siteDelete' ),
			$sensitive + array(
				'mutation'      => true,
				'system_update' => true,
				'capability'    => 'delete_sites',
				'description'   => 'Delete a multisite site.',
			)
		);
	}

	/**
	 * Inspect system readiness and diagnostic state.
	 *
	 * @return array Result of this operation.
	 */
	public function doctor(): array {
		global $wpdb, $wp_version;
		$file_modifications_allowed = function_exists( 'wp_is_file_mod_allowed' )
			? (bool) wp_is_file_mod_allowed( 'plugin_update' )
			: ( ! defined( 'DISALLOW_FILE_MODS' ) || ! DISALLOW_FILE_MODS );
		$checks                     = array(
			'wordpress_bootstrap'         => defined( 'ABSPATH' ),
			'database'                    => $wpdb instanceof \wpdb && (bool) $wpdb->ready,
			'wp_cli'                      => defined( 'WP_CLI' ) && WP_CLI,
			'uploads_writable'            => wp_is_writable( wp_get_upload_dir()['basedir'] ),
			'plugin_dir_writable'         => wp_is_writable( WP_PLUGIN_DIR ),
			'wordpress_version'           => (string) $wp_version,
			'php_version'                 => PHP_VERSION,
			'woocommerce'                 => class_exists( 'WooCommerce' ),
			'elementor'                   => class_exists( 'Elementor\\Plugin' ),
			'acf'                         => function_exists( 'get_fields' ),
			'multisite'                   => is_multisite(),
			'file_modifications_allowed'  => $file_modifications_allowed,
			'file_editor_disabled'        => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'file_modifications_disabled' => ! $file_modifications_allowed,
			'plugin_update_permission'    => current_user_can( 'update_plugins' ),
			'plugin_install_permission'   => current_user_can( 'install_plugins' ),
		);
		$checks['ok']               = $checks['wordpress_bootstrap'] && $checks['database'];
		return $checks;
	}

	/**
	 * Read the requested expected update version.
	 *
	 * @param array $payload Request parameters.
	 * @param array $allowed_fields Allowed request fields.
	 *
	 * @return string|null Result of this operation.
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	private function expected_update_version( array $payload, array $allowed_fields ): ?string {
		if ( array_diff( array_keys( $payload ), $allowed_fields ) ) {
			throw new RuntimeException( 'Update request contains unsupported fields.' );
		}
		if ( ! array_key_exists( 'expected_target_version', $payload ) ) {
			return null;
		}
		$version = $payload['expected_target_version'];
		if ( ! is_string( $version ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\\z/', $version ) ) {
			throw new RuntimeException( 'expected_target_version must be an exact, bounded version string.' );
		}
		return $version;
	}

	/**
	 * Read the provider's available update version.
	 *
	 * @param string $transient_name WordPress update transient name.
	 * @param string $identity Target plugin or theme identity.
	 *
	 * @return string|null Result of this operation.
	 */
	private function available_update_version( string $transient_name, string $identity ): ?string {
		$transient = get_site_transient( $transient_name );
		$offers    = is_object( $transient ) ? ( $transient->response ?? null ) : null;
		if ( ! is_array( $offers ) || ! isset( $offers[ $identity ] ) ) {
			return null;
		}
		$offer   = $offers[ $identity ];
		$version = is_array( $offer ) ? ( $offer['new_version'] ?? $offer['version'] ?? null )
			: ( is_object( $offer ) ? ( $offer->new_version ?? $offer->version ?? null ) : null );
		return is_string( $version ) && '' !== $version ? $version : null;
	}

	/**
	 * Handle the cron run connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function cronRun( array $payload, array $context ): array {
		$hook = isset( $payload['hook'] ) ? sanitize_key( (string) $payload['hook'] ) : '';
		$args = isset( $payload['args'] ) && is_array( $payload['args'] ) ? $payload['args'] : array();
		if ( '' === $hook ) {
			throw new RuntimeException( 'hook is required.' );
		}
		$event = wp_get_scheduled_event( $hook, $args );
		if ( ! $event ) {
			throw new RuntimeException( 'cron.run only accepts an existing scheduled hook with the exact argument set.' );
		}
		$state = array(
			'hook'      => $hook,
			'timestamp' => (int) $event->timestamp,
			'schedule'  => $event->schedule,
			'args'      => $event->args,
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_run'            => $state,
				'_current_fingerprint' => Fingerprint::make( $state ),
			);
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Only the exact existing scheduled WP-Cron hook is allowed after authorization.
		do_action_ref_array( $hook, $event->args );
		return array(
			'ran'                => $state,
			'rollback_supported' => false,
		);
	}

	/**
	 * Reject ambiguous multisite IDs before reading or mutating a site.
	 *
	 * @param array $payload Requested site identifier.
	 *
	 * @return int Exact positive site ID.
	 *
	 * @throws \RuntimeException If the site ID is malformed.
	 */
	private function require_site_id( array $payload ): int {
		try {
			return Input::positive_id( $payload['id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'Site not found.' );
		}
	}

	/**
	 * Handle internal user logic.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return \WP_User Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	private function user( array $payload ): \WP_User {
		try {
			$id = Input::positive_id( $payload['id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'User not found.' );
		}
		$user = get_user_by( 'id', $id );
		if ( ! $user instanceof \WP_User ) {
			throw new RuntimeException( 'User not found.' );
		}
		return $user;
	}

	/**
	 * Handle internal user snapshot logic.
	 *
	 * @param \WP_User $user Value used by this operation.
	 *
	 * @return array Result of this operation.
	 */
	private function user_snapshot( \WP_User $user ): array {
		return array(
			'id'           => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'email'        => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
			'description'  => (string) $user->description,
			'url'          => (string) $user->user_url,
			'roles'        => array_values( $user->roles ),
			'capabilities' => $user->allcaps,
		);
	}

	/**
	 * Handle internal apply user fields logic.
	 *
	 * @param int   $id Value used by this operation.
	 * @param array $payload Request parameters.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	private function apply_user_fields( int $id, array $payload ): void {
		$fields = array( 'ID' => $id );
		$map    = array(
			'email'        => 'user_email',
			'display_name' => 'display_name',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'description'  => 'description',
			'url'          => 'user_url',
		);
		foreach ( $map as $source => $target ) {
			if ( array_key_exists( $source, $payload ) ) {
				$fields[ $target ] = (string) $payload[ $source ];
			}
		}
		if ( count( $fields ) > 1 ) {
			$result = wp_update_user( $fields );
			if ( is_wp_error( $result ) || $id !== $result ) {
				throw new RuntimeException( 'WordPress user profile update failed.' );
			}
		}
	}

	/**
	 * A plugin can filter plugins_api()/themes_api() responses. Never install an
	 * untrusted package URL merely because that API returned it. Custom ZIPs
	 * use plugin.install_package with independent SHA-256 and archive checks.
	 *
	 * @param string $url URL to inspect.
	 * @param string $slug Validated slug value.
	 * @param string $kind Validated kind value.
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	private function official_directory_package_url( string $url, string $slug, string $kind ): string {
		if ( strlen( $url ) > 320 || ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
			throw new RuntimeException( 'Invalid official directory package reference.' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' ||
			( $parts['host'] ?? '' ) !== 'downloads.wordpress.org' ||
			isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) ||
			isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && (int) 443 !== $parts['port'] ) ) {
			throw new RuntimeException( 'Directory package URL is not a canonical HTTPS WordPress.org download.' );
		}
		$pattern = '#\A/' . $kind . '/' . preg_quote( $slug, '#' ) .
			'(?:\.[A-Za-z0-9][A-Za-z0-9._+-]{0,95})?\.zip\z#D';
		if ( ! preg_match( $pattern, (string) ( $parts['path'] ?? '' ) ) ) {
			throw new RuntimeException( 'Directory package URL does not match the requested slug and archive type.' );
		}
		return $url;
	}

	/**
	 * Handle internal plugin file logic.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return string Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	private function plugin_file( array $payload ): string {
		$file = isset( $payload['file'] ) ? str_replace( '\\', '/', (string) $payload['file'] ) : '';
		if ( '' === $file || false !== strpos( $file, '..' ) || 0 === strpos( $file, '/' ) ) {
			throw new RuntimeException( 'A safe relative plugin file is required.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! isset( get_plugins()[ $file ] ) ) {
			throw new RuntimeException( 'Plugin file not found.' );
		}
		return $file;
	}

	/**
	 * Handle internal plugin version logic.
	 *
	 * @param string $file Value used by this operation.
	 *
	 * @return string Result of this operation.
	 */
	private function plugin_version( string $file ): string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		return isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '';
	}

	/**
	 * Handle internal site snapshot logic.
	 *
	 * @param \WP_Site $site Value used by this operation.
	 *
	 * @return array Result of this operation.
	 */
	private function site_snapshot( \WP_Site $site ): array {
		return array(
			'id'           => (int) $site->blog_id,
			'network_id'   => (int) $site->site_id,
			'domain'       => (string) $site->domain,
			'path'         => (string) $site->path,
			'registered'   => (string) $site->registered,
			'last_updated' => (string) $site->last_updated,
			'public'       => (int) $site->public,
			'archived'     => (int) $site->archived,
			'mature'       => (int) $site->mature,
			'spam'         => (int) $site->spam,
			'deleted'      => (int) $site->deleted,
		);
	}
}
