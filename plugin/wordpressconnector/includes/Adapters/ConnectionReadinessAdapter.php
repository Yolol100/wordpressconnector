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
final class ConnectionReadinessAdapter
{
    private const BACKUP_PROVIDERS = array(
        'updraftplus/updraftplus.php' => 'UpdraftPlus',
        'wpvivid-backuprestore/wpvivid-backuprestore.php' => 'WPvivid',
        'duplicator/duplicator.php' => 'Duplicator',
        'backwpup/backwpup.php' => 'BackWPup',
    );

    public function register(Registry $registry): void
    {
        $registry->register('connector.setup.inspect', array($this, 'inspect'), array(
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Read private setup readiness evidence for ChatGPT OAuth and full-site backup/recovery; detection does not prove authorization or restore.',
        ));
    }

    public function inspect(array $payload = array(), array $context = array()): array
    {
        if (array() !== $payload) {
            throw new RuntimeException('Connection readiness does not accept parameters.');
        }

        $endpoint = rest_url('webactueel-wordpress-connector/v1/mcp');
        $https = is_ssl() && is_string($endpoint)
            && 'https' === strtolower((string) wp_parse_url($endpoint, PHP_URL_SCHEME));
        $paired = SitePairing::pairedAdministratorId() > 0;
        $providers = $this->activeBackupProviders();

        return array(
            'read_only' => true,
            'wordpress' => array(
                'https_ready' => $https,
                'github_pairing_recorded' => $paired,
                'github_oidc_execution_verified' => false,
            ),
            'chatgpt' => array(
                'mcp_endpoint_https' => $https,
                'oauth_21_ready' => false,
                'oauth_authorization_verified' => false,
                'state' => 'requires_trusted_oauth_provider_and_explicit_account_consent',
            ),
            'recovery' => array(
                'active_backup_plugins_detected' => $providers,
                'database_backup_verified' => false,
                'site_files_backup_verified' => false,
                'offsite_backup_verified' => false,
                'staging_restore_verified' => false,
                'state' => 'independent_host_backup_and_staging_restore_required',
            ),
            'high_risk_changes_cleared' => false,
        );
    }

    private function activeBackupProviders(): array
    {
        $active = function_exists('get_option')
            ? get_option('active_plugins', array()) : array();
        $active = is_array($active) ? $active : array();

        // Multisite network-wide activation uses plugin paths as option keys.
        $network = function_exists('get_site_option')
            ? get_site_option('active_sitewide_plugins', array()) : array();
        if (is_array($network)) {
            $active = array_merge($active, array_keys($network));
        }

        $detected = array();
        foreach (self::BACKUP_PROVIDERS as $file => $name) {
            if (in_array($file, $active, true)) {
                $detected[] = $name;
            }
        }
        // Neither an active plugin nor Core's upgrade-temp-backup is proof
        // of successful files+DB backup, an offsite copy, or staging restore.
        return $detected;
    }
}
