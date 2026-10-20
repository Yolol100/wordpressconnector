<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\SitePairing;

/**
 * Read-only administrative setup evidence.
 *
 * Detection is NOT OAuth authorization, a backup, or restoration proof.
 * Nothing in this adapter creates credentials, contacts hosts or writes state.
 */
final class ConnectionReadinessAdapter {

	private const BACKUP_PROVIDERS = array(
		'updraftplus/updraftplus.php'                     => 'UpdraftPlus',
		'wpvivid-backuprestore/wpvivid-backuprestore.php' => 'WPvivid',
		'wpvivid-backup-pro/wpvivid-backup-pro.php'       => 'WPvivid Pro',
		'duplicator/duplicator.php'                       => 'Duplicator',
		'backwpup/backwpup.php'                           => 'BackWPup',
		'all-in-one-wp-migration/all-in-one-wp-migration.php' => 'All-in-One WP Migration',
	);

	/** Direct admin links only; not provider APIs or proof of recovery. */
	private const BACKUP_ADMIN_PAGES = array(
		'WPvivid'                => 'admin.php?page=WPvivid',
		'WPvivid Pro'            => 'admin.php?page=wpvivid-dashboard',
		'All-in-One WP Migration' => 'admin.php?page=ai1wm_backups',
	);

	public function register( Registry $registry ): void {
		$registry->register(
			'connector.setup.inspect',
			array( $this, 'inspect' ),
			array(
				'privileged'  => true,
				'capability'  => 'manage_options',
				'description' => 'Read private setup readiness evidence for ChatGPT OAuth and full-site backup/recovery; detection does not prove authorization or restore.',
			)
		);
	}

	public function inspect( array $payload = array(), array $context = array() ): array {
		if ( array() !== $payload ) {
			throw new RuntimeException( 'Connection readiness does not accept parameters.' );
		}

		$endpoint  = rest_url( 'webactueel-wordpress-connector/v1/mcp' );
		$https     = is_ssl() && is_string( $endpoint )
			&& 'https' === strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) );
		$paired    = SitePairing::pairedAdministratorId() > 0;
		$activePlugins = $this->activePluginPaths();
		$providers = $this->activeBackupProviders( $activePlugins );
		$provider = 1 === count( $providers ) ? $providers[0] : null;
		$nativeMcpAdapter = in_array('mcp-adapter/mcp-adapter.php', $activePlugins, true)
			&& class_exists('WP\\MCP\\Core\\McpAdapter');
		$multisite = function_exists( 'is_multisite' ) && is_multisite();
		$blogId = $multisite && function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null;
		$blogId = is_int( $blogId ) && $blogId > 0 ? $blogId : null;

		return array(
			'read_only'                 => true,
			'wordpress'                 => array(
				'https_ready'                    => $https,
				'github_pairing_recorded'        => $paired,
				'github_oidc_execution_verified' => false,
			),
			'chatgpt'                   => array(
				'mcp_endpoint_https'           => $https,
				'oauth_21_ready'               => false,
				'oauth_authorization_verified' => false,
				'native_mcp_adapter_detected' => $nativeMcpAdapter,
				'native_mcp_server_candidate' => $nativeMcpAdapter && $https ? rest_url('mcp/mcp-adapter-default-server') : null,
				'native_mcp_server_verified' => false,
				'state'                        => 'requires_trusted_oauth_provider_and_explicit_account_consent',
			),
			'recovery'                  => array(
				'active_backup_plugins_detected' => $providers,
				'backup_provider_detected'         => $provider,
				'backup_provider_admin_path'       => null === $provider ? null : ( self::BACKUP_ADMIN_PAGES[ $provider ] ?? null ),
				'backup_provider_choice_required' => count( $providers ) > 1,
				'backup_automation_verified'       => false,
				'database_backup_verified'       => false,
				'site_files_backup_verified'     => false,
				'offsite_backup_verified'        => false,
				'staging_restore_verified'       => false,
				'state'                          => 'independent_host_backup_and_staging_restore_required',
			),
			'site_management' => array(
				'wordpress_multisite_detected'   => $multisite,
				'current_blog_id'               => $blogId,
				'mainwp_dashboard_detected'      => in_array( 'mainwp/mainwp.php', $activePlugins, true ),
				'mainwp_child_detected'          => in_array( 'mainwp-child/mainwp-child.php', $activePlugins, true ),
				'remote_sites_authorized'       => false,
				'mainwp_connection_verified'    => false,
				'private_site_registry_ready'   => false,
			),
			'high_risk_changes_cleared' => false,
		);
	}

	private function activeBackupProviders( array $active ): array {
		$detected = array();
		foreach ( self::BACKUP_PROVIDERS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$detected[ $name ] = true;
			}
		}
		// WPvivid Pro extends the same family: do not treat Free + Pro as
		// two independent backup providers or silently choose a competing one.
		if ( isset( $detected['WPvivid Pro'] ) ) {
			unset( $detected['WPvivid'] );
		}
		// Detection proves neither a complete backup nor a successful restore.
		return array_keys( $detected );
	}

	private function activePluginPaths(): array {
		$active = function_exists( 'get_option' )
			? get_option( 'active_plugins', array() ) : array();
		$active = is_array( $active ) ? $active : array();

		// Multisite network-wide activation uses plugin paths as option keys.
		$network = function_exists( 'get_site_option' )
			? get_site_option( 'active_sitewide_plugins', array() ) : array();
		if ( is_array( $network ) ) {
			$active = array_merge( $active, array_keys( $network ) );
		}

		// WordPress options can contain invalid values after a broken migration;
		// do not let array_unique() cast arrays/objects and raise warnings.
		return array_values( array_filter( $active, 'is_string' ) );
	}
}
