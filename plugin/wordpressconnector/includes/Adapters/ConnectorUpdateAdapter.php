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
    private const MAX_PACKAGE_BYTES = 20971520;
    private const MAX_UNCOMPRESSED_BYTES = 104857600;
    private const MAX_ENTRIES = 2500;

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
            'post_success_restore_guaranteed' => false,
        );

        if (! empty($context['dry_run'])) {
            return array(
                'would_update_connector' => $plan,
                '_current_fingerprint' => Fingerprint::make($before),
            );
        }

        $expectedSha256 = $this->checksum($release['checksum_url'], $release['checksum_digest']);
        if (! hash_equals('sha256:' . $expectedSha256, $release['package_digest'])) {
            throw new RuntimeException('GitHub package asset digest does not match the canonical checksum asset.');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

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
            if (! is_string($actualSha256) || ! hash_equals($expectedSha256, strtolower($actualSha256)) || ! hash_equals($release['package_digest'], 'sha256:' . strtolower($actualSha256))) {
                throw new RuntimeException('Connector release package SHA-256 verification failed.');
            }

            $packageInfo = $this->inspectPackage($package);
            if (! hash_equals(self::PLUGIN_FILE, $packageInfo['plugin_file'])) {
                throw new RuntimeException('Connector release package identity is invalid.');
            }
            if (! hash_equals($release['version'], $packageInfo['version'])) {
                throw new RuntimeException('Connector release package version does not match the release tag.');
            }

            // WordPress Core's temp-backup installer preserves the previous plugin on
            // installation failure. Self-updates still require a tested external restore
            // before production use because fatal errors can surface on the next request.
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

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $backup = array('slug' => 'wordpressconnector', 'src' => WP_PLUGIN_DIR, 'dir' => 'plugins');

        $upgrader->init();
        $upgrader->upgrade_strings();
        add_filter('upgrader_source_selection', array($upgrader, 'check_package'));
        add_filter('upgrader_pre_install', array($upgrader, 'deactivate_plugin_before_upgrade'), 10, 2);
        add_filter('upgrader_pre_install', array($upgrader, 'active_before'), 10, 2);
        add_filter('upgrader_post_install', array($upgrader, 'active_after'), 10, 2);
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
            remove_filter('upgrader_source_selection', array($upgrader, 'check_package'));
            remove_filter('upgrader_pre_install', array($upgrader, 'deactivate_plugin_before_upgrade'), 10);
            remove_filter('upgrader_pre_install', array($upgrader, 'active_before'), 10);
            remove_filter('upgrader_post_install', array($upgrader, 'active_after'), 10);
        }

        if (is_wp_error($result)) {
            throw new RuntimeException('Connector installation failed; WordPress Core temporary-backup recovery was requested.');
        }
        if (! is_array($result)) {
            throw new RuntimeException('Connector installation did not complete; verify WordPress Core backup recovery before retrying.');
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

    private function release(): array
    {
        $response = wp_safe_remote_get(self::RELEASE_API, array(
            'timeout' => 10,
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
