<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

final class FilesystemPolicy
{
    private const TEXT_EXTENSIONS = array(
        'css', 'htm', 'html', 'inc', 'js', 'json', 'md', 'mjs', 'php', 'txt', 'xml', 'yaml', 'yml',
    );

    private const WRITE_PREFIXES = array(
        'wp-content/plugins/',
        'wp-content/themes/',
    );

    public static function normalize(string $path): string
    {
        $path = trim($path);
        if ('.' === $path) {
            return '.';
        }
        if ('' === $path || strlen($path) > 240 || false !== strpos($path, "\0") || '/' === $path[0] || false !== strpos($path, '\\')) {
            throw new RuntimeException('Filesystem path must be a short forward-slash relative path.');
        }
        if (preg_match('/^[A-Za-z]:\//', $path)) {
            throw new RuntimeException('Absolute filesystem paths are not allowed.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                throw new RuntimeException('Filesystem path contains an invalid segment.');
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $segment)) {
                throw new RuntimeException('Filesystem path contains control characters.');
            }
        }

        return implode('/', $segments);
    }

    public static function assertListable(string $path): string
    {
        $path = self::normalize($path);
        if ('.' !== $path) {
            self::assertNoHiddenOrSecretPath($path);
        }
        return $path;
    }

    public static function assertReadableText(string $path): string
    {
        $path = self::normalize($path);
        if ('.' === $path) {
            throw new RuntimeException('A file path is required.');
        }
        self::assertNoHiddenOrSecretPath($path);
        if (0 === strpos($path, 'wp-content/uploads/')) {
            throw new RuntimeException('Uploaded file contents must be accessed through the media workflow, not filesystem.read_text.');
        }
        self::assertTextExtension($path);
        return $path;
    }

    public static function assertWritableText(string $path): string
    {
        $path = self::assertReadableText($path);
        $allowed = false;
        foreach (self::WRITE_PREFIXES as $prefix) {
            if (0 === strpos($path, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            throw new RuntimeException('Filesystem writes are limited to existing plugin and theme text files.');
        }
        if (0 === strpos($path, 'wp-content/plugins/wordpressconnector/')) {
            throw new RuntimeException('The connector cannot rewrite its own runtime files. Deploy connector changes through GitHub/release flow.');
        }
        return $path;
    }

    public static function isWritableText(string $path): bool
    {
        try {
            self::assertWritableText($path);
            return true;
        } catch (RuntimeException $error) {
            return false;
        }
    }

    private static function assertTextExtension(string $path): void
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ('' === $extension || ! in_array($extension, self::TEXT_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported text file extension for connector filesystem access.');
        }
    }

    private static function assertNoHiddenOrSecretPath(string $path): void
    {
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (isset($segment[0]) && '.' === $segment[0]) {
                throw new RuntimeException('Hidden filesystem paths are not exposed through the connector.');
            }
        }

        $lower = strtolower($path);
        $base = strtolower((string) basename($path));
        if ('wp-config.php' === $lower || 'debug.log' === $base || 'error_log' === $base || 'php_errors.log' === $base) {
            throw new RuntimeException('Sensitive WordPress/configuration log files are not exposed through the connector.');
        }
        if (preg_match('/(^|\/)(\.env[^\/]*|auth\.json|composer-auth\.json)$/i', $path)) {
            throw new RuntimeException('Credential-bearing files are not exposed through the connector.');
        }
        if (preg_match('/\.(key|pem|p12|pfx)$/i', $path)) {
            throw new RuntimeException('Private key/certificate bundle files are not exposed through the connector.');
        }
    }
}
