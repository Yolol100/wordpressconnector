<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

use RuntimeException;

final class Input
{
    public static function bool(array $input, string $key, bool $default = false): bool
    {
        if (! array_key_exists($key, $input)) {
            return $default;
        }
        return self::boolValue($input[$key], $key);
    }

    public static function boolValue($value, string $label): bool
    {
        if (! is_bool($value)) {
            throw new RuntimeException($label . ' must be boolean.');
        }
        return $value;
    }
}
