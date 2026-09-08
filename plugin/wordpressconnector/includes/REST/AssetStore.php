<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\REST;

use RuntimeException;

final class AssetStore
{
    private const MAX_FILES = 10;
    private const MAX_TOTAL_BYTES = 26214400;
    private const MAX_FILE_BYTES = 20971520;

    public function put(string $requestId, string $relativePath, array $file): array
    {
        $this->assertRequestId($requestId);
        $relativePath = $this->normalizeRelativePath($relativePath);

        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if (UPLOAD_ERR_OK !== $error) {
            throw new RuntimeException('Asset upload failed with PHP upload error ' . $error . '.');
        }

        $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ('' === $tmpName || ! is_uploaded_file($tmpName)) {
            throw new RuntimeException('Asset upload is not a valid HTTP upload.');
        }
        $actualSize = filesize($tmpName);
        if (false === $actualSize || $actualSize <= 0 || $actualSize > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Asset exceeds the per-file size limit.');
        }
        $size = (int) $actualSize;

        $fileType = $this->allowedFileType($relativePath);

        $root = $this->rootForRequest($requestId, true);
        $usage = $this->usage($root);
        if ($usage['files'] >= self::MAX_FILES || ($usage['bytes'] + $size) > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('Request asset limit exceeded (max 10 files / 25 MiB total).');
        }

        $destination = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $parent = dirname($destination);
        if (! is_dir($parent) && ! wp_mkdir_p($parent)) {
            throw new RuntimeException('Could not create request asset directory.');
        }
        @chmod($parent, 0700);

        $rootReal = realpath($root);
        $parentReal = realpath($parent);
        if (false === $rootReal || false === $parentReal) {
            throw new RuntimeException('Could not resolve request asset directory.');
        }
        $rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR);
        if ($parentReal !== $rootReal && 0 !== strpos($parentReal, $rootReal . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Asset path escapes the request asset root.');
        }

        if (file_exists($destination)) {
            throw new RuntimeException('Duplicate request asset path: ' . $relativePath);
        }
        if (! move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Could not store uploaded request asset.');
        }
        @chmod($destination, 0600);
        @touch($root);

        return array(
            'request_id' => $requestId,
            'asset_path' => $relativePath,
            'bytes' => $size,
            'mime_type' => (string) $fileType['type'],
        );
    }

    public function rootForRequest(string $requestId, bool $create = false): string
    {
        $this->assertRequestId($requestId);
        $base = $this->baseRoot($create);
        $directory = $base . DIRECTORY_SEPARATOR . hash('sha256', home_url('/') . '|' . $requestId);

        if ($create && ! is_dir($directory) && ! wp_mkdir_p($directory)) {
            throw new RuntimeException('Could not create request asset root.');
        }
        if ($create) {
            @chmod($directory, 0700);
        }

        return $directory;
    }

    public function cleanup(string $requestId): void
    {
        $directory = $this->rootForRequest($requestId, false);
        if (is_dir($directory)) {
            $this->removeTree($directory);
        }
    }

    public function cleanupExpired(int $olderThanSeconds = DAY_IN_SECONDS): void
    {
        $base = $this->baseRoot(false);
        if (! is_dir($base)) {
            return;
        }

        $cutoff = time() - max(3600, $olderThanSeconds);
        foreach (scandir($base) ?: array() as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $base . DIRECTORY_SEPARATOR . $item;
            $modified = @filemtime($path);
            if (is_dir($path) && ! is_link($path) && false !== $modified && $modified < $cutoff) {
                $this->removeTree($path);
            }
        }
    }

    private function allowedFileType(string $relativePath): array
    {
        $basename = basename($relativePath);
        $fileType = wp_check_filetype($basename, get_allowed_mime_types());
        if (! empty($fileType['ext']) && ! empty($fileType['type'])) {
            return $fileType;
        }

        if (preg_match('#^plugin-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.zip\z#', $relativePath)) {
            $zipType = wp_check_filetype($basename, array('zip' => 'application/zip'));
            if (! empty($zipType['ext']) && ! empty($zipType['type'])) {
                return $zipType;
            }
        }

        throw new RuntimeException('Asset extension is not an allowed WordPress media type or connector plugin package.');
    }

    private function baseRoot(bool $create): string
    {
        $configured = defined('WPCONNECTOR_ASSET_ROOT') ? trim((string) constant('WPCONNECTOR_ASSET_ROOT')) : '';
        if ('' !== $configured) {
            if (! $this->isAbsolutePath($configured)) {
                throw new RuntimeException('WPCONNECTOR_ASSET_ROOT must be an absolute private filesystem path.');
            }
            $base = rtrim($configured, '/\\');
        } else {
            $temp = rtrim((string) sys_get_temp_dir(), '/\\');
            if ('' === $temp || ! $this->isAbsolutePath($temp)) {
                throw new RuntimeException('A private system temporary directory is unavailable.');
            }
            $siteNamespace = substr(hash('sha256', home_url('/')), 0, 20);
            $base = $temp . DIRECTORY_SEPARATOR . 'wpconnector-inbox-' . $siteNamespace;
        }

        $this->assertOutsideWebRoot($base);

        if ($create && ! is_dir($base) && ! wp_mkdir_p($base)) {
            throw new RuntimeException('Could not create connector asset base directory.');
        }
        if ($create) {
            @chmod($base, 0700);
            $resolved = realpath($base);
            if (false === $resolved) {
                throw new RuntimeException('Could not resolve connector asset base directory.');
            }
            $this->assertOutsideWebRoot($resolved);
        }
        return $base;
    }

    private function assertOutsideWebRoot(string $path): void
    {
        $webRoot = defined('ABSPATH') ? realpath(ABSPATH) : false;
        if (false === $webRoot) {
            return;
        }

        $webRoot = rtrim($webRoot, DIRECTORY_SEPARATOR);
        $resolved = realpath($path);
        if (false !== $resolved) {
            $candidate = rtrim($resolved, DIRECTORY_SEPARATOR);
        } else {
            $parent = realpath(dirname($path));
            if (false === $parent) {
                throw new RuntimeException('Connector asset root parent cannot be resolved.');
            }
            $candidate = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path);
        }

        if ($candidate === $webRoot || 0 === strpos($candidate, $webRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Connector request assets must be stored outside the public WordPress root.');
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return '/' === substr($path, 0, 1) || 1 === preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private function usage(string $root): array
    {
        $files = 0;
        $bytes = 0;
        if (! is_dir($root)) {
            return array('files' => 0, 'bytes' => 0);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && ! $entry->isLink()) {
                ++$files;
                $bytes += (int) $entry->getSize();
            }
        }
        return array('files' => $files, 'bytes' => $bytes);
    }

    private function normalizeRelativePath(string $path): string
    {
        if ('' === $path || strlen($path) > 240 || preg_match('/[\x00-\x20\x7F]/', $path) || '/' === $path[0] || false !== strpos($path, '\\')) {
            throw new RuntimeException('asset_path must be a short relative path without whitespace or control characters.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                throw new RuntimeException('asset_path contains an invalid path segment.');
            }
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/', $segment)) {
                throw new RuntimeException('asset_path contains unsupported characters.');
            }
        }

        return implode('/', $segments);
    }

    private function assertRequestId(string $requestId): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $requestId)) {
            throw new RuntimeException('request_id must be 8-100 safe characters.');
        }
    }

    private function removeTree(string $directory): void
    {
        $base = realpath($this->baseRoot(false));
        $real = realpath($directory);
        if (false === $base || false === $real) {
            return;
        }

        $base = rtrim($base, DIRECTORY_SEPARATOR);
        if ($real === $base || 0 !== strpos($real, $base . DIRECTORY_SEPARATOR) || is_link($real)) {
            throw new RuntimeException('Refusing unsafe connector asset cleanup path.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                @unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($real);
    }
}
