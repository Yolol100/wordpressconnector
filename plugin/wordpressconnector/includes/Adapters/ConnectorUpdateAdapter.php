<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class ConnectorUpdateAdapter
{
    private const RELEASE_API = 'https://api.github.com/repos/Yolol100/wordpressconnector/releases/latest';
    private const PACKAGE_ASSET = 'wordpressconnector.zip';
    private const CHECKSUM_ASSET = 'wordpressconnector.zip.sha256';
    private const SBOM_ASSET = 'wordpressconnector.spdx.json';
    private const PLUGIN_FILE = 'wordpressconnector/wordpressconnector.php';
    private const UPDATE_URI = 'https://github.com/Yolol100/wordpressconnector';
    private const MAX_PACKAGE_BYTES = 20971520;
    private const MAX_UNCOMPRESSED_BYTES = 104857600;
    private const MAX_ENTRIES = 2500;
    private const NOTICE_REFRESH_TRANSIENT = 'wpconnector_github_update_notice_v1';
    // Only the already verified local ZIP for the current guarded REST self-update.
    private $verifiedSelfUpdatePackagePath = null;

    public function register(Registry $registry): void
    {
        $registry->register('connector.update.check', array($this, 'check'), array(
            'privileged' => true,
            'public_repository_safe' => true,
            'capability' => 'update_plugins',
            'description' => 'Check the canonical GitHub release for a newer verified WordPress Connector package.',
        ));
        $registry->register('connector.update.apply', array($this, 'apply'), array(
            'mutation' => true,
            'privileged' => true,
            'system_update' => true,
            'public_repository_safe' => true,
            'capability' => 'update_plugins',
            'description' => 'Update WordPress Connector from its canonical GitHub release after SHA-256 and ZIP identity verification.',
        ));
        // WordPress Core owns the dashboard upgrade; the existing adapter still
        // owns release identity, checksum and archive verification.
        if (function_exists('add_filter')) {
            add_filter('update_plugins_github.com', array($this, 'nativeUpdateOffer'), 10, 4);
            add_filter('upgrader_pre_download', array($this, 'verifiedNativeDownload'), 10, 4);
            add_filter('plugin_action_links_' . self::PLUGIN_FILE, array($this, 'pluginActionLinks'));
        }
        if (function_exists('add_action')) {
            add_action('load-plugins.php', array($this, 'refreshNativeUpdateNoticeAction'), 20);
            add_action('load-update-core.php', array($this, 'refreshNativeUpdateNoticeAction'), 20);
            add_action('admin_post_wpconnector_check_updates', array($this, 'forceNativeUpdateCheck'));
            add_action('admin_notices', array($this, 'checkedNotice'));
        }
    }

    /**
     * WordPress admin actions must return void while direct refresh callers
     * retain the explicit boolean result of refreshNativeUpdateNotice().
     */
    public function refreshNativeUpdateNoticeAction(): void
    {
        $this->refreshNativeUpdateNotice();
    }

    /**
     * WordPress Core may keep plugin update results for an hour on Plugins.
     * Refresh only this plugin's trusted GitHub release when an administrator
     * actually visits Plugins or Updates. Do not invalidate other plugins or
     * run an unauthenticated global update scan.
     */
    public function refreshNativeUpdateNotice(bool $force = false): bool
    {
        if (! current_user_can('update_plugins')) {
            return false;
        }
        if (! $force && false !== get_site_transient(self::NOTICE_REFRESH_TRANSIENT)) {
            return true;
        }

        // Throttle even failed requests; hosting firewalls and GitHub rate
        // limits must not turn every admin page view into an API retry.
        set_site_transient(self::NOTICE_REFRESH_TRANSIENT, 1, $force ? 30 : 120);
        try {
            $release = $this->release(4);
            $installed = defined('WPCONNECTOR_VERSION') ? (string) WPCONNECTOR_VERSION : '';
            if ('' === $installed) {
                return false;
            }
            $updates = get_site_transient('update_plugins');
            if (! is_object($updates)) {
                return false;
            }
            if (! isset($updates->response) || ! is_array($updates->response)) {
                $updates->response = array();
            }
            if (! isset($updates->no_update) || ! is_array($updates->no_update)) {
                $updates->no_update = array();
            }
            if (version_compare($release['version'], $installed, '>')) {
                $offer = $this->nativeOfferFromRelease($release, $installed);
                if (! is_array($offer)) {
                    return false;
                }
                $offer['new_version'] = $offer['version'];
                $offer['plugin'] = self::PLUGIN_FILE;
                $updates->response[self::PLUGIN_FILE] = (object) $offer;
                unset($updates->no_update[self::PLUGIN_FILE]);
            } elseif (isset($updates->response[self::PLUGIN_FILE])) {
                // Only discard an obsolete update which this connector owns.
                $existing = $updates->response[self::PLUGIN_FILE];
                $identity = is_object($existing) ? ($existing->id ?? null) :
                    (is_array($existing) ? ($existing['id'] ?? null) : null);
                if (self::UPDATE_URI === $identity) {
                    unset($updates->response[self::PLUGIN_FILE]);
                }
            }
            set_site_transient('update_plugins', $updates);
            return true;
        } catch (\Throwable $error) {
            // Leave the last known Core update result intact on network errors.
            // The guarded downloader will still refuse a stale/bad package.
            return false;
        }
    }

    public function pluginActionLinks(array $links): array
    {
        if (! current_user_can('update_plugins')) {
            return $links;
        }
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=wpconnector_check_updates'),
            'wpconnector_check_updates'
        );
        $links['wpconnector-refresh'] = '<a href="' . esc_url($url) . '">' .
            esc_html__('Check GitHub update now', 'wordpressconnector') . '</a>';
        return $links;
    }

    public function forceNativeUpdateCheck(): void
    {
        if (! current_user_can('update_plugins')) {
            wp_die(esc_html__('Plugin update permission is required.', 'wordpressconnector'));
        }
        check_admin_referer('wpconnector_check_updates');
        $ok = $this->refreshNativeUpdateNotice(true);
        wp_safe_redirect(add_query_arg(
            array(
                'wpconnector_update_checked' => $ok ? '1' : '0',
                'wpconnector_notice_nonce' => wp_create_nonce('wpconnector_checked_notice'),
            ),
            admin_url('plugins.php')
        ));
        exit;
    }

    public function checkedNotice(): void
    {
        if (! current_user_can('update_plugins')
            || ! isset($_GET['wpconnector_update_checked'], $_GET['wpconnector_notice_nonce'])
            || ! is_string($_GET['wpconnector_update_checked'])
            || ! is_string($_GET['wpconnector_notice_nonce'])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_GET['wpconnector_notice_nonce']));
        if (! wp_verify_nonce($nonce, 'wpconnector_checked_notice')) {
            return;
        }
        $noticeResult = sanitize_text_field(wp_unslash($_GET['wpconnector_update_checked']));
        if (! in_array($noticeResult, array('0', '1'), true)) {
            return;
        }
        $ok = '1' === $noticeResult;
        echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-warning') .
            ' is-dismissible"><p>' .
            esc_html($ok
                ? __('WordPress Connector: GitHub release checked. If newer, Update Now appears in the Plugins list.', 'wordpressconnector')
                : __('WordPress Connector: GitHub update check failed; verify outbound HTTPS, firewall and GitHub availability. Nothing was installed.', 'wordpressconnector')) .
            '</p></div>';
    }

    /**
     * Offer only this plugin's canonical release to WordPress's native updater.
     * Other plugins using GitHub's update hostname must remain untouched.
     *
     * @param array|false $update
     * @return array|false
     */
    public function nativeUpdateOffer($update, array $pluginData, string $pluginFile, array $locales)
    {
        if (self::PLUGIN_FILE !== $pluginFile || self::UPDATE_URI !== ($pluginData['UpdateURI'] ?? '')) {
            return $update;
        }

        try {
            return $this->nativeOfferFromRelease(
                $this->release(),
                isset($pluginData['Version']) ? (string) $pluginData['Version'] : ''
            );
        } catch (\Throwable $error) {
            // A missing or invalid canonical release must never create an update offer.
            return false;
        }
    }

    /** @return array<string, mixed>|false */
    private function nativeOfferFromRelease(array $release, string $installed)
    {
        if ('' === $installed || ! version_compare($release['version'], $installed, '>')) {
            return false;
        }
        return array(
            'id' => self::UPDATE_URI,
            'slug' => 'wordpressconnector',
            'version' => $release['version'],
            'url' => self::UPDATE_URI . '/releases/tag/' . $release['tag'],
            'package' => $release['package_url'],
            'requires_php' => '7.4',
            'autoupdate' => false,
        );
    }

    /**
     * WordPress checksums are not verified by Core for third-party packages.
     * Intercept only our own update and pass a verified temporary ZIP to Core.
     *
     * @param false|string|\WP_Error $reply
     * @param mixed $upgrader
     * @return false|string|\WP_Error
     */
    public function verifiedNativeDownload($reply, string $package, $upgrader, array $hookExtra)
    {
        // Core passes only "plugin" (and temp_backup) to bulk_upgrade(),
        // while a single-plugin upgrade also provides "type" and "action".
        // Accept both documented shapes, but never an explicitly conflicting
        // type or action: every connector ZIP must pass integrity verification.
        if (self::PLUGIN_FILE !== ($hookExtra['plugin'] ?? '')
            || (isset($hookExtra['type']) && 'plugin' !== $hookExtra['type'])
            || (isset($hookExtra['action']) && 'update' !== $hookExtra['action'])) {
            return $reply;
        }

        if (false !== $reply) {
            if (is_wp_error($reply)) {
                return $reply;
            }
            return new \WP_Error('wordpressconnector_update_source', 'WordPress Connector requires its verified canonical release package.');
        }

        // The guarded REST self-updater already validated this exact local ZIP.
        // Let Core use it as a local path; do not force a second remote download.
        // Every ordinary single/bulk dashboard update still verifies the release.
        if (null !== $this->verifiedSelfUpdatePackagePath
            && hash_equals($this->verifiedSelfUpdatePackagePath, $package)) {
            return false;
        }

        try {
            $release = $this->release();
            $installed = defined('WPCONNECTOR_VERSION') ? (string) WPCONNECTOR_VERSION : '';
            if ('' === $installed || ! version_compare($release['version'], $installed, '>')
                || ! hash_equals($release['package_url'], $package)) {
                throw new RuntimeException('The offered connector package is stale or not canonical.');
            }

            return $this->downloadVerifiedPackage($release);
        } catch (\Throwable $error) {
            // Core aborts before replacing any plugin file when given WP_Error.
            return new \WP_Error('wordpressconnector_update_integrity', 'WordPress Connector could not verify this update. No files were changed.');
        }
    }

    public function check(array $payload = array(), array $context = array()): array
    {
        $release = $this->release();
        $current = defined('WPCONNECTOR_VERSION') ? (string) WPCONNECTOR_VERSION : '0.0.0';
        $state = array(
            'current_version' => $current,
            'latest_version' => $release['version'],
            'tag' => $release['tag'],
            'update_available' => version_compare($release['version'], $current, '>'),
            'package_asset' => self::PACKAGE_ASSET,
            'checksum_asset' => self::CHECKSUM_ASSET,
            'sbom_asset' => self::SBOM_ASSET,
            'github_asset_digest' => $release['package_digest'],
        );

        return array(
            'update' => $state,
            'fingerprint' => Fingerprint::make($state),
        );
    }

    public function apply(array $payload, array $context): array
    {
        if (! current_user_can('update_plugins')) {
            throw new RuntimeException('Current user is not allowed to update plugins.');
        }
        foreach (array_keys($payload) as $key) {
            if ('restore_verified' !== $key) {
                throw new RuntimeException('Connector self-update accepts only the restore_verified preflight field.');
            }
        }
        if (isset($payload['restore_verified']) && ! is_bool($payload['restore_verified'])) {
            throw new RuntimeException('restore_verified must be boolean.');
        }
        // Legacy callers may still provide restore_verified, but proof of an
        // externally tested restore is no longer a prerequisite for self-updates.
        // Core temporary backups only cover installation/readback failures.
        $release = $this->release();
        $current = defined('WPCONNECTOR_VERSION') ? (string) WPCONNECTOR_VERSION : '0.0.0';
        if (! version_compare($release['version'], $current, '>')) {
            throw new RuntimeException('No newer WordPress Connector release is available.');
        }

        $before = array(
            'plugin_file' => self::PLUGIN_FILE,
            'version' => $current,
        );
        $plan = array(
            'from_version' => $current,
            'to_version' => $release['version'],
            'tag' => $release['tag'],
            'package_asset' => self::PACKAGE_ASSET,
            'checksum_asset' => self::CHECKSUM_ASSET,
            'sbom_asset' => self::SBOM_ASSET,
            'github_asset_digest' => $release['package_digest'],
            'rollback_supported' => false,
            'installation_failure_recovery' => 'wordpress_core_temp_backup',
            'external_restore_required' => false,
            'post_success_restore_guaranteed' => false,
        );

        if (! empty($context['dry_run'])) {
            return array(
                'would_update_connector' => $plan,
                '_current_fingerprint' => Fingerprint::make($before),
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $package = $this->downloadVerifiedPackage($release);
        try {
            $actualSha256 = substr($release['package_digest'], 7);

            // WordPress Core can restore installation/readback failures via its temp
            // backup. A later fatal error still has no guaranteed automatic recovery.
            $installation = $this->installWithCoreBackup($package);
            wp_clean_plugins_cache(true);
            $plugins = get_plugins();
            $installedVersion = isset($plugins[self::PLUGIN_FILE]['Version']) ? (string) $plugins[self::PLUGIN_FILE]['Version'] : '';
            if (! hash_equals($release['version'], $installedVersion)) {
                $this->restoreAfterReadbackFailure($installation, $current);
                throw new RuntimeException('Connector update readback failed: prior version restored; check the release package.');
            }

            return array(
                'operation' => 'updated',
                'before' => $before,
                'after' => array(
                    'plugin_file' => self::PLUGIN_FILE,
                    'version' => $installedVersion,
                ),
                'package_sha256' => $actualSha256,
                'release_tag' => $release['tag'],
                'rollback_supported' => false,
            );
        } finally {
            if (is_string($package) && file_exists($package)) {
                @unlink($package);
            }
        }
    }

    /**
     * Use WP_Upgrader's native temporary backup contract, rather than
     * Plugin_Upgrader::install(... overwrite_package => true), which has no
     * hook_extra.temp_backup and cannot restore after an installation failure.
     *
     * @return array{upgrader: \Plugin_Upgrader, backup: array<string,string>}
     */
    private function installWithCoreBackup(string $package): array
    {
        if (! defined('WP_PLUGIN_DIR') || ! function_exists('get_filesystem_method') || 'direct' !== get_filesystem_method()
            || ! is_readable(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE)
            || ! method_exists(\Plugin_Upgrader::class, 'restore_temp_backup')) {
            throw new RuntimeException('Connector update requires direct filesystem mode and WordPress Core temporary-backup support.');
        }

        // A self-update runs through REST, not through the normal wp-admin update
        // screen. Record the active state before overwriting the files.
        $wasActive = is_plugin_active(self::PLUGIN_FILE);
        if (! $wasActive) {
            throw new RuntimeException('Connector self-update requires an active Connector plugin.');
        }

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $backup = array('slug' => 'wordpressconnector', 'src' => WP_PLUGIN_DIR, 'dir' => 'plugins');

        $upgrader->init();
        $upgrader->upgrade_strings();
        add_filter('upgrader_source_selection', array($upgrader, 'check_package'));
        // Do not attach deactivate_plugin_before_upgrade here. WordPress Core
        // uses that hook for browser-based upgrades, but our REST self-update
        // has no browser reactivation step. Attaching it left the Connector
        // inactive after a successful file-version readback.
        add_filter('upgrader_pre_install', array($upgrader, 'active_before'), 10, 2);
        add_filter('upgrader_post_install', array($upgrader, 'active_after'), 10, 2);
        $this->verifiedSelfUpdatePackagePath = $package;
        try {
            $result = $upgrader->run(array(
                'package' => $package,
                'destination' => WP_PLUGIN_DIR,
                'clear_destination' => true,
                'abort_if_destination_exists' => false,
                'clear_working' => true,
                'hook_extra' => array(
                    'plugin' => self::PLUGIN_FILE,
                    'type' => 'plugin',
                    'action' => 'update',
                    'temp_backup' => $backup,
                ),
            ));
        } finally {
            $this->verifiedSelfUpdatePackagePath = null;
            remove_filter('upgrader_source_selection', array($upgrader, 'check_package'));
            // No deactivation callback was installed by this self-update.
            remove_filter('upgrader_pre_install', array($upgrader, 'active_before'), 10);
            remove_filter('upgrader_post_install', array($upgrader, 'active_after'), 10);
        }

        if (is_wp_error($result)) {
            throw new RuntimeException('Connector installation failed; WordPress Core temporary-backup recovery was requested.');
        }
        if (! is_array($result)) {
            throw new RuntimeException('Connector installation did not complete; verify WordPress Core backup recovery before retrying.');
        }
        if ($wasActive && ! is_plugin_active(self::PLUGIN_FILE)) {
            // Preserve the activation state held before the guarded upgrade.
            // Never report a successful install if REST routes will disappear.
            $activation = activate_plugin(self::PLUGIN_FILE, '', false, true);
            if (is_wp_error($activation) || ! is_plugin_active(self::PLUGIN_FILE)) {
                throw new RuntimeException('Connector upgrade left the plugin inactive; reactivation failed. Manual WP Admin recovery is required.');
            }
        }
        return array('upgrader' => $upgrader, 'backup' => $backup);
    }

    private function restoreAfterReadbackFailure(array $installation, string $expectedVersion): void
    {
        $upgrader = $installation['upgrader'];
        $backup = $installation['backup'];
        // A failed readback must never turn into an unconditional directory
        // deletion when the temporary backup has already disappeared.
        global $wp_filesystem;
        $path = trailingslashit(WP_CONTENT_DIR) . 'upgrade-temp-backup/plugins/wordpressconnector';
        if (! is_object($wp_filesystem) || ! $wp_filesystem->is_dir($path)) {
            throw new RuntimeException('Connector update readback failed; no recoverable temporary backup was found. Restore externally before retrying.');
        }
        $restored = $upgrader->restore_temp_backup(array($backup));
        wp_clean_plugins_cache(true);
        $plugins = get_plugins();
        $version = isset($plugins[self::PLUGIN_FILE]['Version']) ? (string) $plugins[self::PLUGIN_FILE]['Version'] : '';
        if (true !== $restored || ! hash_equals($expectedVersion, $version)) {
            throw new RuntimeException('Connector update readback failed and automatic recovery was not verified. Restore externally before retrying.');
        }
    }

    private function release(int $timeout = 10): array
    {
        $response = wp_safe_remote_get(self::RELEASE_API, array(
            'timeout' => $timeout,
            'redirection' => 3,
            'limit_response_size' => 262144,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Webactueel-WordPressConnector',
            ),
        ));
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            throw new RuntimeException('Canonical WordPress Connector release metadata is unavailable.');
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($decoded) || empty($decoded['tag_name']) || empty($decoded['assets']) || ! is_array($decoded['assets']) || ! empty($decoded['draft']) || ! empty($decoded['prerelease'])) {
            throw new RuntimeException('Canonical release metadata has an invalid schema.');
        }

        $tag = (string) $decoded['tag_name'];
        if (! preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+)\z/', $tag, $matches)) {
            throw new RuntimeException('Canonical release tag must use vMAJOR.MINOR.PATCH.');
        }

        $packageUrl = '';
        $checksumUrl = '';
        $sbomUrl = '';
        $packageDigest = '';
        $checksumDigest = '';
        foreach ($decoded['assets'] as $asset) {
            if (! is_array($asset) || empty($asset['name']) || empty($asset['browser_download_url'])) {
                continue;
            }
            $name = (string) $asset['name'];
            $url = (string) $asset['browser_download_url'];
            if (self::PACKAGE_ASSET === $name) {
                $packageUrl = $url;
                $packageDigest = isset($asset['digest']) ? strtolower((string) $asset['digest']) : '';
            } elseif (self::CHECKSUM_ASSET === $name) {
                $checksumUrl = $url;
                $checksumDigest = isset($asset['digest']) ? strtolower((string) $asset['digest']) : '';
            } elseif (self::SBOM_ASSET === $name) {
                $sbomUrl = $url;
            }
        }

        $quotedTag = preg_quote($tag, '#');
        if (! preg_match('#^https://github\.com/Yolol100/wordpressconnector/releases/download/' . $quotedTag . '/wordpressconnector\.zip\z#', $packageUrl)) {
            throw new RuntimeException('Canonical release is missing the expected connector package asset.');
        }
        if (! preg_match('#^https://github\.com/Yolol100/wordpressconnector/releases/download/' . $quotedTag . '/wordpressconnector\.zip\.sha256\z#', $checksumUrl)) {
            throw new RuntimeException('Canonical release is missing the expected checksum asset.');
        }
        if (! preg_match('#^https://github\.com/Yolol100/wordpressconnector/releases/download/' . $quotedTag . '/wordpressconnector\.spdx\.json\z#', $sbomUrl)) {
            throw new RuntimeException('Canonical release is missing the expected SPDX SBOM asset.');
        }
        if (! preg_match('/^sha256:[a-f0-9]{64}\z/', $packageDigest) || ! preg_match('/^sha256:[a-f0-9]{64}\z/', $checksumDigest)) {
            throw new RuntimeException('Canonical release assets are missing GitHub SHA-256 digests.');
        }

        return array(
            'tag' => $tag,
            'version' => (string) $matches[1],
            'package_url' => $packageUrl,
            'checksum_url' => $checksumUrl,
            'sbom_url' => $sbomUrl,
            'package_digest' => $packageDigest,
            'checksum_digest' => $checksumDigest,
        );
    }

    /**
     * Shared verification for REST self-update and the native Plugins screen.
     * Returns a temporary ZIP owned by the caller. Never trust a ZIP merely
     * because it was downloaded from GitHub over HTTPS.
     */
    private function downloadVerifiedPackage(array $release): string
    {
        $expectedSha256 = $this->checksum($release['checksum_url'], $release['checksum_digest']);
        if (! hash_equals('sha256:' . $expectedSha256, $release['package_digest'])) {
            throw new RuntimeException('GitHub package asset digest does not match the canonical checksum asset.');
        }

        if (! function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $package = download_url($release['package_url'], 30);
        if (is_wp_error($package)) {
            throw new RuntimeException($package->get_error_message());
        }

        try {
            $size = filesize($package);
            if (false === $size || $size <= 0 || $size > self::MAX_PACKAGE_BYTES) {
                throw new RuntimeException('Connector release package is empty or exceeds the 20 MiB limit.');
            }
            $actualSha256 = hash_file('sha256', $package);
            if (! is_string($actualSha256) || ! hash_equals($expectedSha256, strtolower($actualSha256))
                || ! hash_equals($release['package_digest'], 'sha256:' . strtolower($actualSha256))) {
                throw new RuntimeException('Connector release package SHA-256 verification failed.');
            }

            $packageInfo = $this->inspectPackage($package);
            if (! hash_equals(self::PLUGIN_FILE, $packageInfo['plugin_file'])
                || ! hash_equals($release['version'], $packageInfo['version'])) {
                throw new RuntimeException('Connector release ZIP identity or version does not match the published release.');
            }

            return $package;
        } catch (\Throwable $error) {
            if (is_string($package) && file_exists($package)) {
                @unlink($package);
            }
            throw $error;
        }
    }

    private function checksum(string $url, string $expectedAssetDigest): string
    {
        $response = wp_safe_remote_get($url, array(
            'timeout' => 10,
            'redirection' => 5,
            'limit_response_size' => 1024,
            'headers' => array('User-Agent' => 'Webactueel-WordPressConnector'),
        ));
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            throw new RuntimeException('Connector checksum asset could not be downloaded.');
        }

        $rawBody = (string) wp_remote_retrieve_body($response);
        $actualAssetDigest = 'sha256:' . hash('sha256', $rawBody);
        if (! hash_equals($expectedAssetDigest, $actualAssetDigest)) {
            throw new RuntimeException('Connector checksum asset GitHub digest verification failed.');
        }
        $body = trim($rawBody);
        if (! preg_match('/^([a-f0-9]{64})  wordpressconnector\.zip\z/', $body, $matches)) {
            throw new RuntimeException('Connector checksum asset has an invalid format.');
        }
        return (string) $matches[1];
    }

    private function inspectPackage(string $package): array
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive is required for connector release verification.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($package, \ZipArchive::RDONLY)) {
            throw new RuntimeException('Connector release ZIP could not be opened.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('Connector release ZIP has an invalid number of entries.');
            }

            $paths = array();
            $uncompressedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if (! is_array($stat) || ! isset($stat['name'])) {
                    throw new RuntimeException('Connector release ZIP contains an unreadable entry.');
                }
                $name = (string) $stat['name'];
                if ('' === $name || strlen($name) > 512 || false !== strpos($name, "\0") || preg_match('/[\x01-\x1F\x7F]/', $name) || false !== strpos($name, '\\') || '/' === $name[0] || preg_match('/^[A-Za-z]:/', $name)) {
                    throw new RuntimeException('Connector release ZIP contains an unsafe entry path.');
                }

                $normalized = '/' === substr($name, -1) ? rtrim($name, '/') : $name;
                if ('' === $normalized) {
                    throw new RuntimeException('Connector release ZIP contains an invalid root entry.');
                }
                $segments = explode('/', $normalized);
                if ('wordpressconnector' !== $segments[0]) {
                    throw new RuntimeException('Connector release ZIP contains an unexpected top-level path.');
                }
                foreach ($segments as $segment) {
                    if ('' === $segment || '.' === $segment || '..' === $segment) {
                        throw new RuntimeException('Connector release ZIP contains path traversal or an invalid segment.');
                    }
                }
                if (isset($paths[$normalized])) {
                    throw new RuntimeException('Connector release ZIP contains duplicate entry paths.');
                }
                $paths[$normalized] = true;

                $entryBytes = isset($stat['size']) ? (int) $stat['size'] : 0;
                if ($entryBytes < 0) {
                    throw new RuntimeException('Connector release ZIP contains an invalid entry size.');
                }
                $uncompressedBytes += $entryBytes;
                if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('Connector release ZIP exceeds the uncompressed size limit.');
                }

                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                    $mode = ($attributes >> 16) & 0xF000;
                    if (0xA000 === $mode) {
                        throw new RuntimeException('Connector release ZIP symlink entries are not allowed.');
                    }
                }
            }

            $main = $zip->getFromName(self::PLUGIN_FILE, 16384, \ZipArchive::FL_UNCHANGED);
            if (! is_string($main)) {
                throw new RuntimeException('Connector release ZIP is missing the canonical main plugin file.');
            }
            if (! preg_match('/(?:^|\n)[ \t\/*#@]*Plugin Name:\s*WordPress Connector(?:\r?\n|$)/i', $main)) {
                throw new RuntimeException('Connector release ZIP main file has an invalid plugin identity.');
            }
            if (! preg_match('/(?:^|\n)[ \t\/*#@]*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)(?:\r?\n|$)/i', $main, $matches)) {
                throw new RuntimeException('Connector release ZIP main file has no valid semantic version.');
            }

            return array(
                'plugin_file' => self::PLUGIN_FILE,
                'version' => (string) $matches[1],
            );
        } finally {
            $zip->close();
        }
    }
}
