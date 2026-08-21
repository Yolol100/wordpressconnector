<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

use RuntimeException;

final class Json
{
    public static function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Invalid JSON: ' . $error->getMessage(), 0, $error);
        }

        if (! is_array($value)) {
            throw new RuntimeException('JSON root must be an object.');
        }

        return $value;
    }

    public static function encode($value, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode($value, $flags | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Could not encode JSON: ' . $error->getMessage(), 0, $error);
        }
    }

    public static function readFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('JSON file is not readable: ' . $path);
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException('Could not read JSON file: ' . $path);
        }

        return self::decode($contents);
    }

    public static function writeFileAtomic(string $path, array $data): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            throw new RuntimeException('Could not create output directory: ' . $directory);
        }

        $temporary = tempnam($directory, '.wpconnector-');
        if (false === $temporary) {
            throw new RuntimeException('Could not create temporary output file.');
        }

        $bytes = file_put_contents($temporary, self::encode($data) . PHP_EOL, LOCK_EX);
        if (false === $bytes || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not write output file: ' . $path);
        }
    }
}
