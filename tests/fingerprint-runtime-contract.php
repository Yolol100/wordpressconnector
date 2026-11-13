<?php

declare(strict_types=1);

use Webactueel\WordPressConnector\Support\Fingerprint;

define('AUTH_SALT', 'standalone-fingerprint-contract-salt');
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';

$assertSame = static function ($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
};

$ordered = array(
    'a' => array('first' => 1, 'second' => 2),
    'z' => array('left', 'right'),
);
$reordered = array(
    'z' => array('left', 'right'),
    'a' => array('second' => 2, 'first' => 1),
);
$canonical = '{"a":{"first":1,"second":2},"z":["left","right"]}';
$expected = hash('sha256', $canonical);
$assertSame($expected, Fingerprint::make($ordered), 'Canonical fingerprint hash changed.');
$assertSame($expected, Fingerprint::make($reordered), 'Associative key ordering changed.');
if (Fingerprint::make(array('left', 'right')) === Fingerprint::make(array('right', 'left'))) {
    throw new RuntimeException('Indexed list order must remain significant.');
}

$token = hash_hmac('sha256', $expected, AUTH_SALT);
$assertSame($token, Fingerprint::siteTokenFromFingerprint($expected), 'Valid fingerprint HMAC changed.');
$assertSame($token, Fingerprint::siteToken($ordered), 'Site token no longer uses canonical fingerprint.');

$invalid = strtoupper($expected);
$assertSame(
    hash_hmac('sha256', Fingerprint::make($invalid), AUTH_SALT),
    Fingerprint::siteTokenFromFingerprint($invalid),
    'Invalid fingerprint must be canonicalized before HMAC.'
);

echo "fingerprint canonicalization and site-token contract OK\n";
