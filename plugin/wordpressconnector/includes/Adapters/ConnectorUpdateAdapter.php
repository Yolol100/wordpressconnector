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
    private const PLUGIN_FILE = 'wordpressconnector/wordpressconnector.php';
    private const MAX_PACKAGE_BYTES = 20971520;
    private const MAX_UNCOMPRESSED_BYTES = 104857600;
    private const MAX_ENTRIES = 2500;

    public function register(Registry $registry): void
    {
        $registry->register('connector.update.check', array($this, 'check'), array(
            'privileged' => true,
            'public_repository_safe' => true,
            'description' => 'Check the canonical GitHub release for a newer verified WordPress Connector package.',
        ));
        $registry->register('connector.update.apply', array($this, 'apply'), array(
            'mutation' => true,
            'privileged' => true,
            'system_update' => true,
            'public_repository_safe' => true,
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
            'rollback_supported' => false,
        );

        if (! empty($context['dry_run'])) {
            return array(
                'would_update_connector' => $plan,
                '_current_fingerprint' => Fingerprint::make($before),
            );
        }

        $expectedSha256 = $this->checksum($release['checksum_url']);
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
            if (! is_string($actualSha256) || ! hash_equals($expectedSha256, strtolower($actualSha256))) {
                throw new RuntimeException('Connector release package SHA-256 verification failed.');
            }

            $packageInfo = $this->inspectPackage($package);
            if (! hash_equals(self::PLUGIN_FILE, $packageInfo['plugin_file'])) {
                throw new RuntimeException('Connector release package identity is invalid.');
            }
            if (! hash_equals($release['version'], $packageInfo['version'])) {
                throw new RuntimeException('Connector release package version does not match the release tag.');
            }

            $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
            $result = $upgrader->install($package, array('overwrite_package' => true));
            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }
            if (true !== $result) {
                throw new RuntimeException('Connector release installation failed.');
            }

            wp_clean_plugins_cache(true);
            $plugins = get_plugins();
            if (! isset($plugins[self::PLUGIN_FILE])) {
                throw new RuntimeException('Connector update readback failed: plugin is missing after installation.');
            }
            $installedVersion = isset($plugins[self::PLUGIN_FILE]['Version']) ? (string) $plugins[self::PLUGIN_FILE]['Version'] : '';
            if (! hash_equals($release['version'], $installedVersion)) {
                throw new RuntimeException('Connector update readback failed: installed version does not match release version.');
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
        if (! is_array($decoded) || empty($decoded['tag_name']) || empty($decoded['assets']) || ! is_array($decoded['assets'])) {
            throw new RuntimeException('Canonical release metadata has an invalid schema.');
        }

        $tag = (string) $decoded['tag_name'];
        if (! preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+)\z/', $tag, $matches)) {
            throw new RuntimeException('Canonical release tag must use vMAJOR.MINOR.PATCH.');
        }

        $packageUrl = '';
        $checksumUrl = '';
        foreach ($decoded['assets'] as $asset) {
            if (! is_array($asset) || empty($asset['name']) || empty($asset['browser_download_url'])) {
                continue;
            }
            $name = (string) $asset['name'];
            $url = (string) $asset['browser_download_url'];
            if (self::PACKAGE_ASSET === $name) {
                $packageUrl = $url;
            } elseif (self::CHECKSUM_ASSET === $name) {
                $checksumUrl = $url;
            }
        }

        $quotedTag = preg_quote($tag, '#');
        if (! preg_match('#^https://github\.com/Yolol100/wordpressconnector/releases/download/' . $quotedTag . '/wordpressconnector\.zip\z#', $packageUrl)) {
            throw new RuntimeException('Canonical release is missing the expected connector package asset.');
        }
        if (! preg_match('#^https://github\.com/Yolol100/wordpressconnector/releases/download/' . $quotedTag . '/wordpressconnector\.zip\.sha256\z#', $checksumUrl)) {
            throw new RuntimeException('Canonical release is missing the expected checksum asset.');
        }

        return array(
            'tag' => $tag,
            'version' => (string) $matches[1],
            'package_url' => $packageUrl,
            'checksum_url' => $checksumUrl,
        );
    }

    private function checksum(string $url): string
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

        $body = trim((string) wp_remote_retrieve_body($response));
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
