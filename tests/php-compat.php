<?php

declare(strict_types=1);

// Test-only compatibility for PHP versions that predate str_contains().
// Product source must not depend on this shim.
if (! function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}
