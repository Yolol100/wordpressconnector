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
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ('' === $tmpName || ! is_uploaded_file($tmpName)) {
            throw new RuntimeException('Asset upload is not a valid HTTP upload.');
        }
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Asset exceeds the per-file size limit.');
        }

        $fileType = wp_check_filetype(basename($relativePath), get_allowed_mime_types());
        if (empty($fileType['ext']) || empty($fileType['type'])) {
            throw new RuntimeException('Asset extension is not an allowed WordPress media type.');
        }

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

    private function baseRoot(bool $create): string
    {
        $uploads = wp_upload_dir();
        if (! empty($uploads['error']) || empty($uploads['basedir'])) {
            throw new RuntimeException('WordPress uploads directory is unavailable.');
        }

        $base = rtrim((string) $uploads['basedir'], '/\\') . DIRECTORY_SEPARATOR . 'wpconnector-inbox';
        if ($create && ! is_dir($base) && ! wp_mkdir_p($base)) {
            throw new RuntimeException('Could not create connector asset base directory.');
        }
        return $base;
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
        $path = str_replace('\\', '/', trim($path));
        if ('' === $path || strlen($path) > 240 || '/' === $path[0]) {
            throw new RuntimeException('asset_path must be a short relative path.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                throw new RuntimeException('asset_path contains an invalid path segment.');
            }
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $segment)) {
                throw new RuntimeException('asset_path contains unsupported characters.');
            }
        }

        return implode('/', $segments);
    }

    private function assertRequestId(string $requestId): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}$/', $requestId)) {
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
