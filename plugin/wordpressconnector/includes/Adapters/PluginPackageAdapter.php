<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

final class PluginPackageAdapter
{
    private const MAX_PACKAGE_BYTES = 20971520;
    private const MAX_UNCOMPRESSED_BYTES = 104857600;
    private const MAX_ENTRIES = 2500;

    public function register(Registry $registry): void
    {
        $registry->register(
            'plugin.install_package',
            array($this, 'installPackage'),
            array(
                'mutation' => true,
                'privileged' => true,
                'system_update' => true,
                'description' => 'Install or overwrite a plugin from a verified ZIP uploaded to the trusted request asset root.',
            )
        );
    }

    public function installPackage(array $payload, array $context): array
    {
        $assetRoot = isset($context['asset_root']) ? (string) $context['asset_root'] : '';
        if ('' === $assetRoot) {
            throw new RuntimeException('plugin.install_package requires a trusted request asset root.');
        }

        $sourcePath = isset($payload['source_path']) ? str_replace('\\', '/', (string) $payload['source_path']) : '';
        if (! preg_match('#^plugin-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.zip\z#', $sourcePath)) {
            throw new RuntimeException('source_path must be plugin-packages/<safe-name>.zip.');
        }

        $candidate = $assetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourcePath);
        $package = Policy::assertLocalAssetPath($candidate, $assetRoot);
        if ('zip' !== strtolower((string) pathinfo($package, PATHINFO_EXTENSION))) {
            throw new RuntimeException('Plugin package must be a ZIP file.');
        }

        $maxBytes = (int) (getenv('WPCONNECTOR_MAX_PLUGIN_PACKAGE_BYTES') ?: self::MAX_PACKAGE_BYTES);
        $maxBytes = max(1048576, min(self::MAX_PACKAGE_BYTES, $maxBytes));
        $size = filesize($package);
        if (false === $size || $size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Plugin package is empty or exceeds the configured size limit.');
        }

        $expectedSha256 = isset($payload['sha256']) ? strtolower((string) $payload['sha256']) : '';
        if (! preg_match('/^[a-f0-9]{64}\z/', $expectedSha256)) {
            throw new RuntimeException('sha256 must be a 64-character lowercase hexadecimal checksum.');
        }
        $actualSha256 = hash_file('sha256', $package);
        if (! is_string($actualSha256) || ! hash_equals($expectedSha256, strtolower($actualSha256))) {
            throw new RuntimeException('Plugin package SHA-256 checksum does not match.');
        }

        $expectedPlugin = isset($payload['expected_plugin']) ? str_replace('\\', '/', (string) $payload['expected_plugin']) : '';
        if (! preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+\.php\z/', $expectedPlugin)) {
            throw new RuntimeException('expected_plugin must be an exact plugin file such as plugin-slug/plugin.php.');
        }

        $packageInfo = $this->inspectPackage($package);
        if (! hash_equals($expectedPlugin, $packageInfo['plugin_file'])) {
            throw new RuntimeException('Plugin package identity does not match expected_plugin.');
        }
        $this->assertNotSelf($expectedPlugin);

        $overwrite = Input::bool($payload, 'overwrite');
        $activate = Input::bool($payload, 'activate');
        $networkWide = Input::bool($payload, 'network_wide');
        $this->assertCapabilities($overwrite, $activate, $networkWide);

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        $installed = isset($plugins[$expectedPlugin]);

        if ($overwrite && ! $installed) {
            throw new RuntimeException('overwrite=true requires expected_plugin to already be installed.');
        }
        if (! $overwrite && $installed) {
            throw new RuntimeException('Plugin is already installed. Use overwrite=true with the same expected_plugin to replace it.');
        }

        $destinationRoot = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($expectedPlugin);
        if (! $overwrite && is_dir($destinationRoot)) {
            throw new RuntimeException('Plugin destination directory already exists. Refusing an ambiguous install.');
        }

        $before = $this->snapshot($expectedPlugin, $plugins);
        $plan = array(
            'source_path' => $sourcePath,
            'sha256' => $actualSha256,
            'bytes' => (int) $size,
            'plugin_file' => $expectedPlugin,
            'plugin_name' => $packageInfo['plugin_name'],
            'archive_entries' => $packageInfo['entries'],
            'uncompressed_bytes' => $packageInfo['uncompressed_bytes'],
            'overwrite' => $overwrite,
            'activate' => $activate,
            'network_wide' => $networkWide,
        );

        if (! empty($context['dry_run'])) {
            return array(
                'would_install_package' => $plan,
                'before' => $before,
                '_current_fingerprint' => Fingerprint::make($before),
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $result = $upgrader->install($package, array('overwrite_package' => $overwrite));
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
        if (true !== $result) {
            throw new RuntimeException('Plugin package installation failed.');
        }

        wp_clean_plugins_cache(true);
        $installedFile = $upgrader->plugin_info();
        if (! is_string($installedFile) || ! hash_equals($expectedPlugin, str_replace('\\', '/', $installedFile))) {
            throw new RuntimeException('Installed plugin identity did not match the verified package identity.');
        }

        if ($activate) {
            $needsActivation = $networkWide
                ? ! is_plugin_active_for_network($expectedPlugin)
                : ! is_plugin_active($expectedPlugin);
            if ($needsActivation) {
                $activated = activate_plugin($expectedPlugin, '', $networkWide, true);
                if (is_wp_error($activated)) {
                    throw new RuntimeException($activated->get_error_message());
                }
            }
        }

        wp_clean_plugins_cache(true);
        $afterPlugins = get_plugins();
        if (! isset($afterPlugins[$expectedPlugin])) {
            throw new RuntimeException('Plugin package readback failed: expected plugin is not installed.');
        }

        $after = $this->snapshot($expectedPlugin, $afterPlugins);
        return array(
            'operation' => $overwrite ? 'updated' : 'installed',
            'package' => $plan,
            'before' => $before,
            'after' => $after,
            'rollback_supported' => false,
        );
    }

    private function inspectPackage(string $package): array
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive is required for safe plugin package inspection.');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($package, \ZipArchive::RDONLY);
        if (true !== $opened) {
            throw new RuntimeException('Plugin ZIP could not be opened.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('Plugin ZIP has an invalid number of entries.');
            }

            $roots = array();
            $paths = array();
            $headers = array();
            $uncompressedBytes = 0;

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if (! is_array($stat) || ! isset($stat['name'])) {
                    throw new RuntimeException('Plugin ZIP contains an unreadable entry.');
                }

                $name = (string) $stat['name'];
                if ('' === $name || strlen($name) > 512 || false !== strpos($name, "\0") || preg_match('/[\x01-\x1F\x7F]/', $name) || false !== strpos($name, '\\') || '/' === $name[0] || preg_match('/^[A-Za-z]:/', $name)) {
                    throw new RuntimeException('Plugin ZIP contains an unsafe entry path.');
                }

                $directory = '/' === substr($name, -1);
                $normalized = $directory ? rtrim($name, '/') : $name;
                if ('' === $normalized) {
                    throw new RuntimeException('Plugin ZIP contains an invalid root entry.');
                }

                $segments = explode('/', $normalized);
                foreach ($segments as $segment) {
                    if ('' === $segment || '.' === $segment || '..' === $segment) {
                        throw new RuntimeException('Plugin ZIP contains path traversal or an invalid path segment.');
                    }
                }
                $roots[$segments[0]] = true;

                if (isset($paths[$normalized])) {
                    throw new RuntimeException('Plugin ZIP contains duplicate entry paths.');
                }
                $paths[$normalized] = true;

                $entryBytes = isset($stat['size']) ? (int) $stat['size'] : 0;
                if ($entryBytes < 0) {
                    throw new RuntimeException('Plugin ZIP contains an invalid entry size.');
                }
                $uncompressedBytes += $entryBytes;
                if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('Plugin ZIP exceeds the uncompressed size limit.');
                }

                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                    $mode = ($attributes >> 16) & 0xF000;
                    if (0xA000 === $mode) {
                        throw new RuntimeException('Plugin ZIP symlink entries are not allowed.');
                    }
                }

                if ($directory || 1 !== substr_count($normalized, '/') || 'php' !== strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION))) {
                    continue;
                }

                $header = $zip->getFromIndex($index, min(8192, max(1, $entryBytes)), \ZipArchive::FL_UNCHANGED);
                if (is_string($header) && preg_match('/(?:^|\n)[ \t\/*#@]*Plugin Name:\s*(.+?)(?:\r?\n|$)/i', $header, $matches)) {
                    $headers[] = array(
                        'path' => $normalized,
                        'name' => trim((string) $matches[1]),
                    );
                }
            }

            if (1 !== count($roots)) {
                throw new RuntimeException('Plugin ZIP must contain exactly one top-level plugin directory.');
            }
            if (1 !== count($headers)) {
                throw new RuntimeException('Plugin ZIP must contain exactly one detectable main plugin file directly inside that directory.');
            }

            $pluginFile = (string) $headers[0]['path'];
            if (! preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+\.php\z/', $pluginFile)) {
                throw new RuntimeException('Main plugin file must use a safe plugin directory and PHP filename.');
            }

            return array(
                'plugin_file' => $pluginFile,
                'plugin_name' => sanitize_text_field((string) $headers[0]['name']),
                'entries' => (int) $zip->numFiles,
                'uncompressed_bytes' => $uncompressedBytes,
            );
        } finally {
            $zip->close();
        }
    }

    private function assertCapabilities(bool $overwrite, bool $activate, bool $networkWide): void
    {
        if (! current_user_can('install_plugins')) {
            throw new RuntimeException('Current user is not allowed to install plugins.');
        }
        if ($overwrite && ! current_user_can('update_plugins')) {
            throw new RuntimeException('Current user is not allowed to update plugins.');
        }
        if ($activate && ! current_user_can('activate_plugins')) {
            throw new RuntimeException('Current user is not allowed to activate plugins.');
        }
        if ($networkWide) {
            if (! is_multisite()) {
                throw new RuntimeException('network_wide is only valid on multisite.');
            }
            if (! current_user_can('manage_network_plugins')) {
                throw new RuntimeException('Current user is not allowed to manage network plugins.');
            }
        }
    }

    private function assertNotSelf(string $pluginFile): void
    {
        if (defined('WPCONNECTOR_FILE') && function_exists('plugin_basename') && hash_equals(str_replace('\\', '/', plugin_basename(WPCONNECTOR_FILE)), $pluginFile)) {
            throw new RuntimeException('WordPress Connector cannot replace its own active runtime through plugin.install_package. Use the repository release/deployment path.');
        }
    }

    private function snapshot(string $pluginFile, array $plugins): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (! isset($plugins[$pluginFile])) {
            return array(
                'file' => $pluginFile,
                'installed' => false,
                'version' => null,
                'active' => false,
                'network_active' => false,
            );
        }

        return array(
            'file' => $pluginFile,
            'installed' => true,
            'name' => isset($plugins[$pluginFile]['Name']) ? (string) $plugins[$pluginFile]['Name'] : $pluginFile,
            'version' => isset($plugins[$pluginFile]['Version']) ? (string) $plugins[$pluginFile]['Version'] : '',
            'active' => is_plugin_active($pluginFile),
            'network_active' => is_multisite() ? is_plugin_active_for_network($pluginFile) : false,
        );
    }
}
