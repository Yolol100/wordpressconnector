<?php

declare(strict_types=1);
namespace Webactueel\WordPressConnector\Support;
final class Fingerprint
{
    public static function make($value): string { return hash('sha256', Json::encode(self::normalize($value), false)); }
    public static function siteToken($value): string { return self::siteTokenFromFingerprint(self::make($value)); }
    public static function siteTokenFromFingerprint(string $fingerprint): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) { $fingerprint = self::make($fingerprint); }
        if (function_exists('wp_salt')) { $secret = (string) wp_salt('auth'); }
        elseif (defined('AUTH_SALT')) { $secret = (string) AUTH_SALT; }
        else { $secret = 'wordpressconnector-test-state-token'; }
        return hash_hmac('sha256', $fingerprint, $secret);
    }
    private static function normalize($value)
    {
        if (! is_array($value)) { return $value; }
        if (array_keys($value) !== range(0, count($value) - 1)) { ksort($value); }
        foreach ($value as $key => $item) { $value[$key] = self::normalize($item); }
        return $value;
    }
}
