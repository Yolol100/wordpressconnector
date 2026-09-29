<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class CacheMaintenanceAdapter
{
    private const LAYERS = array('elementor', 'wp_rocket', 'asset_cleanup');

    public function register(Registry $registry): void
    {
        $registry->register('maintenance.cache_capabilities', array($this, 'capabilities'), array(
            'privileged' => true,
            'public_repository_safe' => true,
            'capability' => 'manage_options',
            'description' => 'Inspect availability and versions for the bounded Elementor, WP Rocket and Asset CleanUp cache maintenance layers.',
        ));

        $registry->register('maintenance.cache_flush', array($this, 'flush'), array(
            'mutation' => true,
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Flush one bounded cache layer: Elementor files/data, WP Rocket full-domain page cache, or Asset CleanUp CSS/JS cache metadata.',
        ));
    }

    public function capabilities(array $payload = array(), array $context = array()): array
    {
        $layers = array();
        foreach (self::LAYERS as $layer) {
            $layers[$layer] = $this->layerState($layer);
        }

        return array(
            'layers' => $layers,
            'fingerprint' => Fingerprint::make($layers),
        );
    }

    public function flush(array $payload, array $context): array
    {
        $layer = isset($payload['layer']) ? sanitize_key((string) $payload['layer']) : '';
        if (! in_array($layer, self::LAYERS, true)) {
            throw new RuntimeException('maintenance.cache_flush requires layer: elementor, wp_rocket or asset_cleanup.');
        }

        $before = $this->layerState($layer);
        if (empty($before['available'])) {
            throw new RuntimeException('Requested cache layer is not available: ' . $layer . '.');
        }

        $result = array(
            'layer' => $layer,
            'provider' => $before['provider'],
            'version' => $before['version'],
            'available' => true,
            'rollback_supported' => false,
            'rebuild_mode' => $this->rebuildMode($layer),
            '_current_fingerprint' => Fingerprint::make($before),
        );

        if ('asset_cleanup' === $layer) {
            $result['json_cache_files_before'] = $this->countAssetCleanupJsonFiles();
        }

        if (! empty($context['dry_run'])) {
            $result['would_flush'] = true;
            $result['readback_verified'] = null;
            return $result;
        }

        if ('elementor' === $layer) {
            $result['readback_verified'] = $this->flushElementor();
        } elseif ('wp_rocket' === $layer) {
            $result['readback_verified'] = $this->flushWpRocket();
        } else {
            $result['readback_verified'] = $this->flushAssetCleanup();
            $result['json_cache_files_after'] = $this->countAssetCleanupJsonFiles();
        }

        if (true !== $result['readback_verified']) {
            throw new RuntimeException('Cache flush verification failed for layer: ' . $layer . '.');
        }

        return $result;
    }

    private function layerState(string $layer): array
    {
        if ('elementor' === $layer) {
            $plugin = class_exists('Elementor\\Plugin') ? \Elementor\Plugin::$instance : null;
            $filesManager = is_object($plugin) && isset($plugin->files_manager) ? $plugin->files_manager : null;
            return array(
                'provider' => 'Elementor',
                'available' => is_object($filesManager) && method_exists($filesManager, 'clear_cache'),
                'version' => defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : '',
            );
        }

        if ('wp_rocket' === $layer) {
            return array(
                'provider' => 'WP Rocket',
                'available' => function_exists('rocket_clean_domain'),
                'version' => defined('WP_ROCKET_VERSION') ? (string) WP_ROCKET_VERSION : '',
            );
        }

        $class = $this->ensureAssetCleanupClass();
        return array(
            'provider' => 'Asset CleanUp',
            'available' => '' !== $class && (
                is_callable(array($class, 'clearCache'))
                || is_callable(array($class, 'clearAllCache'))
            ),
            'version' => defined('WPACU_PLUGIN_VERSION') ? (string) WPACU_PLUGIN_VERSION : '',
        );
    }

    private function flushElementor(): bool
    {
        $plugin = class_exists('Elementor\\Plugin') ? \Elementor\Plugin::$instance : null;
        $filesManager = is_object($plugin) && isset($plugin->files_manager) ? $plugin->files_manager : null;
        if (! is_object($filesManager) || ! method_exists($filesManager, 'clear_cache')) {
            throw new RuntimeException('Elementor cache API is unavailable.');
        }

        $verified = false;
        $callback = static function () use (&$verified): void {
            $verified = true;
        };

        add_action('elementor/core/files/clear_cache', $callback, PHP_INT_MAX, 0);
        try {
            $filesManager->clear_cache();
        } finally {
            remove_action('elementor/core/files/clear_cache', $callback, PHP_INT_MAX);
        }

        return $verified;
    }

    private function flushWpRocket(): bool
    {
        if (! function_exists('rocket_clean_domain')) {
            throw new RuntimeException('WP Rocket cache API is unavailable.');
        }

        $verified = false;
        $callback = static function () use (&$verified): void {
            $verified = true;
        };

        add_action('rocket_after_clean_domain', $callback, PHP_INT_MAX, 0);
        try {
            $result = rocket_clean_domain();
        } finally {
            remove_action('rocket_after_clean_domain', $callback, PHP_INT_MAX);
        }

        if (true === $result || $verified) {
            return true;
        }

        if (! function_exists('rocket_clean_cache_dir')) {
            return false;
        }

        $fallbackVerified = false;
        $fallbackCallback = static function () use (&$fallbackVerified): void {
            $fallbackVerified = true;
        };

        add_action('after_rocket_clean_cache_dir', $fallbackCallback, PHP_INT_MAX, 0);
        try {
            rocket_clean_cache_dir();
        } finally {
            remove_action('after_rocket_clean_cache_dir', $fallbackCallback, PHP_INT_MAX);
        }

        return $fallbackVerified;
    }

    private function flushAssetCleanup(): bool
    {
        $class = $this->ensureAssetCleanupClass();
        $method = '';

        if ('' !== $class && is_callable(array($class, 'clearCache'))) {
            $method = 'clearCache';
        } elseif ('' !== $class && is_callable(array($class, 'clearAllCache'))) {
            $method = 'clearAllCache';
        }

        if ('' === $method) {
            throw new RuntimeException('Asset CleanUp cache API is unavailable.');
        }

        if (! defined('WPACU_PLUGIN_ID')) {
            throw new RuntimeException('Asset CleanUp cache verification marker is unavailable.');
        }

        $transient = (string) WPACU_PLUGIN_ID . '_last_clear_cache';
        $before = get_transient($transient);
        $startedAt = time();
        $verifiedByHook = false;
        $callback = static function () use (&$verifiedByHook): void {
            $verifiedByHook = true;
        };

        add_action('wpacu_clear_cache_after', $callback, PHP_INT_MAX, 0);
        try {
            call_user_func(array($class, $method), false);
        } finally {
            remove_action('wpacu_clear_cache_after', $callback, PHP_INT_MAX);
        }

        $after = get_transient($transient);
        $beforeValue = is_numeric($before) ? (int) $before : 0;
        $afterValue = is_numeric($after) ? (int) $after : 0;

        return $verifiedByHook
            && $afterValue >= ($startedAt - 1)
            && $afterValue >= $beforeValue;
    }

    private function ensureAssetCleanupClass(): string
    {
        $class = 'WpAssetCleanUp\\OptimiseAssets\\OptimizeCommon';
        if (class_exists($class)) {
            return $class;
        }

        $classesPath = defined('WPACU_PLUGIN_CLASSES_PATH')
            ? (string) WPACU_PLUGIN_CLASSES_PATH
            : (defined('WPACU_PLUGIN_DIR')
                ? rtrim((string) WPACU_PLUGIN_DIR, '/\\') . DIRECTORY_SEPARATOR . 'classes'
                : '');

        $base = '' !== $classesPath ? realpath($classesPath) : false;
        if (false === $base || ! is_dir($base)) {
            return '';
        }

        static $autoloadRegistered = false;

        if (! $autoloadRegistered) {
            $prefix = 'WpAssetCleanUp\\';

            spl_autoload_register(
                static function (string $className) use ($base, $prefix): void {
                    if (0 !== strncmp($className, $prefix, strlen($prefix))) {
                        return;
                    }

                    $relative = substr($className, strlen($prefix));
                    if (false === $relative || '' === $relative) {
                        return;
                    }

                    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
                    $candidate = $base . DIRECTORY_SEPARATOR . $relative;
                    $real = realpath($candidate);

                    if (
                        false === $real
                        || 0 !== strpos($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
                        || ! is_file($real)
                    ) {
                        return;
                    }

                    include_once $real;
                }
            );

            $autoloadRegistered = true;
        }

        return class_exists($class) ? $class : '';
    }

    private function countAssetCleanupJsonFiles(): int
    {
        $directory = defined('WP_CONTENT_DIR')
            ? rtrim((string) WP_CONTENT_DIR, '/\\') . '/cache/asset-cleanup'
            : '';

        if ('' === $directory || ! is_dir($directory)) {
            return 0;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );
        } catch (\UnexpectedValueException $error) {
            return 0;
        }

        $count = 0;
        foreach ($iterator as $item) {
            if ($item->isFile() && 'json' === strtolower((string) $item->getExtension())) {
                ++$count;
                if ($count >= 10000) {
                    break;
                }
            }
        }

        return $count;
    }

    private function rebuildMode(string $layer): string
    {
        if ('elementor' === $layer) {
            return 'regenerate_on_next_page_request';
        }
        if ('wp_rocket' === $layer) {
            return 'wp_rocket_preload_if_enabled';
        }
        return 'regenerate_optimized_assets_on_next_eligible_request';
    }
}
