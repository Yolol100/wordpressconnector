<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support;

final class Fingerprint
{
    public static function make($value): string
    {
        return hash('sha256', Json::encode(self::normalize($value), false));
    }

    private static function normalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
