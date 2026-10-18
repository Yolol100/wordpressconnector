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
                'capability' => 'install_plugins',
                'description' => 'Install or overwrite a plugin from a verified ZIP uploaded to the trusted request asset root.',
            )
        );
        $this->registerThemePackage($registry);
    }

    /**
     * A theme ZIP uses the same private request asset store and exact SHA-256
     * binding as plugin packages. Public requests cannot invoke this action.
     */
    public function registerThemePackage(Registry $registry): void
    {
        $registry->register(
            'theme.install_package',
            array($this, 'installThemePackage'),
            array(
                'mutation' => true,
                'privileged' => true,
                'system_update' => true,
                'capability' => 'install_themes',
                'description' => 'Install or replace an inactive theme from a verified, private request ZIP.',
            )
        );
    }

    public function installThemePackage(array $payload, array $context): array
    {
        if (array_diff(array_keys($payload), array('source_path', 'sha256', 'expected_stylesheet', 'overwrite'))) {
            throw new RuntimeException('Theme package request contains unsupported fields.');
        }
        $source = isset($payload['source_path']) && is_string($payload['source_path']) ? $payload['source_path'] : '';
        $expected = isset($payload['expected_stylesheet']) && is_string($payload['expected_stylesheet']) ? $payload['expected_stylesheet'] : '';
        if (! preg_match('#^theme-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.zip\z#', $source)
            || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,79}\z/', $expected)
            || ! array_key_exists('overwrite', $payload) || ! is_bool($payload['overwrite'])) {
            throw new RuntimeException('Theme package requires safe ZIP, expected_stylesheet and explicit overwrite boolean.');
        }
        $overwrite = $payload['overwrite'];
        if (! current_user_can('install_themes') || ($overwrite && ! current_user_can('update_themes'))) {
            throw new RuntimeException('Current user lacks theme install or replacement capabilities.');
        }
        $root = isset($context['asset_root']) ? (string) $context['asset_root'] : '';
        if ('' === $root) {
            throw new RuntimeException('Theme ZIP requires a trusted, private request asset root.');
        }
        $package = Policy::assertLocalAssetPath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source), $root);
        if (defined('WP_CONTENT_DIR')) {
            $contentRoot = realpath(WP_CONTENT_DIR);
            if (false !== $contentRoot && 0 === strpos($package, rtrim($contentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Theme package must not be inside WordPress upgrade scratch storage.');
            }
        }
        $size = filesize($package);
        if (false === $size || $size < 1 || $size > self::MAX_PACKAGE_BYTES) {
            throw new RuntimeException('Theme package exceeds the 20 MiB size limit.');
        }
        $sha256 = isset($payload['sha256']) && is_string($payload['sha256']) ? $payload['sha256'] : '';
        if (! preg_match('/^[a-f0-9]{64}\z/', $sha256)
            || ! hash_equals($sha256, (string) hash_file('sha256', $package))) {
            throw new RuntimeException('Theme package SHA-256 does not match.');
        }
        $info = $this->inspectThemePackage($package, $expected);
        if ($expected === get_stylesheet() || $expected === get_template()) {
            throw new RuntimeException('Refusing to replace or install over the active theme or its parent.');
        }
        $current = wp_get_theme($expected);
        $installed = $current->exists();
        if ($installed !== $overwrite) {
            throw new RuntimeException($installed ? 'Theme exists; overwrite must be explicitly true.' : 'Theme is not installed; overwrite must be false.');
        }
        if ($installed) {
            $themeRoot = realpath(get_theme_root($expected));
            $canonical = realpath(get_theme_root());
            if (false === $themeRoot || false === $canonical || ! hash_equals($canonical, $themeRoot)) {
                throw new RuntimeException('Custom theme roots cannot be overwritten via the connector.');
            }
            foreach (wp_get_themes() as $name => $theme) {
                if ($name !== $expected && (string) $theme->get('Template') === $expected) {
                    throw new RuntimeException('Refusing to overwrite a parent theme used by an installed child.');
                }
            }
        }
        $before = array('stylesheet' => $expected, 'installed' => $installed, 'version' => $installed ? (string) $current->get('Version') : null);
        $plan = array('stylesheet' => $expected, 'source_path' => $source,
            'sha256' => $sha256, 'bytes' => (int) $size, 'overwrite' => $overwrite,
            'archive_entries' => $info['entries'], 'uncompressed_bytes' => $info['uncompressed_bytes']);
        if (! empty($context['dry_run'])) {
            return array('would_install_theme_package' => $plan, 'before' => $before,
                '_current_fingerprint' => Fingerprint::make($before));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if (function_exists('wp_is_file_mod_allowed') && ! wp_is_file_mod_allowed('theme_install')) {
            throw new RuntimeException('WordPress file modification policy forbids theme installation.');
        }
        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $backup = array('slug' => $expected, 'src' => get_theme_root(), 'dir' => 'themes');
        $restorePending = false;
        $shutdownRestore = static function () use ($upgrader, $backup, &$restorePending): void {
            if ($restorePending) {
                $upgrader->restore_temp_backup(array($backup));
            }
        };
        if ($overwrite) {
            global $wp_filesystem;
            if (true !== WP_Filesystem() || ! is_object($wp_filesystem)
                || ! $wp_filesystem->wp_content_dir()) {
                throw new RuntimeException('WordPress filesystem is unavailable; cannot create theme replacement backup.');
            }
            $existingBackup = trailingslashit($wp_filesystem->wp_content_dir()) .
                'upgrade-temp-backup/themes/' . $expected;
            if ($wp_filesystem->exists($existingBackup)) {
                throw new RuntimeException('An unresolved backup of this theme exists; refusing to overwrite it.');
            }
            // Register recovery before moving anything. If the PHP request dies
            // during WordPress installation, shutdown can still restore the
            // original inactive theme from Core's temp backup.
            $restorePending = true;
            add_action('shutdown', $shutdownRestore, 10, 0);
            $saved = $upgrader->move_to_temp_backup_dir($backup);
            if (true !== $saved) {
                $restorePending = false;
                remove_action('shutdown', $shutdownRestore, 10);
                throw new RuntimeException(is_wp_error($saved) ? $saved->get_error_message()
                    : 'The original theme could not be backed up safely.');
            }
        }

        try {
            // The original inactive theme was moved to Core's protected
            // backup directory, so installation must never clear a live path.
            $result = $upgrader->install($package, array('overwrite_package' => false));
            if (is_wp_error($result) || true !== $result) {
                throw new RuntimeException(is_wp_error($result) ? $result->get_error_message()
                    : 'Theme ZIP installation failed; check WordPress upgrader and filesystem permissions.');
            }
            wp_clean_themes_cache();
            $afterTheme = wp_get_theme($expected);
            if (! $afterTheme->exists()) {
                throw new RuntimeException('Theme installation readback failed.');
            }
            $after = array('stylesheet' => $expected,
                'version' => (string) $afterTheme->get('Version'));
        } catch (\Throwable $error) {
            if ($overwrite) {
                $restored = $upgrader->restore_temp_backup(array($backup));
                $restorePending = false;
                remove_action('shutdown', $shutdownRestore, 10);
                wp_clean_themes_cache();
                if (true !== $restored || ! wp_get_theme($expected)->exists()) {
                    throw new RuntimeException('Theme replacement failed and automatic recovery could not be verified. Manual recovery is required.');
                }
                throw new RuntimeException('Theme replacement failed; original theme restored: ' . $error->getMessage());
            }
            throw $error;
        }
        $restorePending = false;
        if ($overwrite) {
            remove_action('shutdown', $shutdownRestore, 10);
        }
        return array('operation' => $overwrite ? 'updated' : 'installed',
            'package' => $plan, 'before' => $before, 'after' => $after,
            'on_failure_restore_supported' => $overwrite,
            // WordPress temp backups may be cleaned up automatically; do not
            // advertise a durable user-triggered rollback after success.
            'rollback_supported' => false, 'readback_verified' => true);
    }

    private function inspectThemePackage(string $package, string $expected): array
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive is required for theme package inspection.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($package, \ZipArchive::RDONLY)) {
            throw new RuntimeException('Invalid theme ZIP archive.');
        }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('Theme ZIP has an invalid number of files.');
            }
            $seen = array();
            $total = 0;
            $style = '';
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
                if (! is_array($stat) || ! isset($stat['name'])) {
                    throw new RuntimeException('Unreadable theme ZIP member.');
                }
                $path = (string) $stat['name'];
                if (strlen($path) > 512 || false !== strpos($path, "\0")
                    || preg_match('/[\x01-\x1F\x7F]/', $path)
                    || false !== strpos($path, '\\') || '/' === substr($path, 0, 1)
                    || preg_match('/^[A-Za-z]:/', $path)) {
                    throw new RuntimeException('Unsafe theme ZIP path.');
                }
                $normal = rtrim($path, '/');
                $segments = explode('/', $normal);
                if (count($segments) < 1 || $segments[0] !== $expected || isset($seen[$normal])) {
                    throw new RuntimeException('Theme ZIP folder or duplicate path mismatch.');
                }
                foreach ($segments as $segment) {
                    if ($segment === '' || $segment === '.' || $segment === '..') {
                        throw new RuntimeException('Theme ZIP contains traversal or invalid path.');
                    }
                }
                $seen[$normal] = true;
                $bytes = isset($stat['size']) ? (int) $stat['size'] : -1;
                if ($bytes < 0) throw new RuntimeException('Invalid theme ZIP entry size.');
                $total += $bytes;
                if ($total > self::MAX_UNCOMPRESSED_BYTES) throw new RuntimeException('Theme ZIP exceeds extracted size budget.');
                $opsys = 0; $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && 0xA000 === (($attributes >> 16) & 0xF000)) {
                    throw new RuntimeException('Theme ZIP must not contain symbolic links.');
                }
                if ($normal === $expected . '/style.css' && '/' !== substr($path, -1)) {
                    $style = (string) $zip->getFromIndex($i, min(8192, $bytes), \ZipArchive::FL_UNCHANGED);
                }
            }
            if ('' === $style || ! preg_match('/(?:^|\n)[ \t\/\*#@]*Theme Name:\s*\S/i', $style)) {
                throw new RuntimeException('Theme ZIP lacks a valid root style.css theme header.');
            }
            return array('entries' => $zip->numFiles, 'uncompressed_bytes' => $total);
        } finally {
            $zip->close();
        }
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
        // Core's WP_Upgrader::unpack_package() clears wp-content/upgrade
        // before extraction. A ZIP stored there would be deleted mid-install.
        if (defined('WP_CONTENT_DIR')) {
            $contentDir = realpath(WP_CONTENT_DIR);
            if (false !== $contentDir && 0 === strpos($package,
                rtrim($contentDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Plugin ZIP is in WordPress upgrade scratch storage, which Core clears during installation; upload it to the trusted asset store outside wp-content/upgrade.');
            }
        }
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
            $errors = isset($upgrader->skin) && method_exists($upgrader->skin, 'get_errors')
                ? $upgrader->skin->get_errors() : null;
            $details = is_wp_error($errors) ? (array) $errors->get_error_messages() : array();
            $message = $details ? substr(sanitize_text_field(implode('; ', $details)), 0, 280)
                : 'WordPress Core upgrader returned an unsuccessful result; verify filesystem, target directory and host restrictions.';
            throw new RuntimeException('Plugin package installation failed: ' . $message);
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
                // This is a user-requested fresh activation, not a silent Core upgrade.
                // Third-party install hooks must run exactly as in wp-admin.
                $activated = activate_plugin($expectedPlugin, '', $networkWide, false);
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
        if ($activate && ! ($networkWide ? $after['network_active'] : $after['active'])) {
            throw new RuntimeException('Plugin was installed but activation did not persist; investigate the activation hook and server restrictions.');
        }
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
