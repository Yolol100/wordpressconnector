<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/validate-request.php <request.json>\n");
    exit(2);
}

$path = $argv[1];
if (! is_file($path) || ! is_readable($path)) {
    fwrite(STDERR, "Request is not readable: {$path}\n");
    exit(2);
}

try {
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    fwrite(STDERR, 'Invalid JSON: ' . $error->getMessage() . "\n");
    exit(1);
}

$errors = array();
if (! is_array($data)) $errors[] = 'Root must be an object.';
$id = is_array($data) && isset($data['request_id']) ? (string) $data['request_id'] : '';
$action = is_array($data) && isset($data['action']) ? (string) $data['action'] : '';
if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}$/', $id)) $errors[] = 'Invalid request_id.';
if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $action)) $errors[] = 'Invalid action.';
if (! is_array($data['payload'] ?? null)) $errors[] = 'payload must be an object.';
if (isset($data['dry_run']) && ! is_bool($data['dry_run'])) $errors[] = 'dry_run must be boolean.';
if (isset($data['confirm']) && ! is_bool($data['confirm'])) $errors[] = 'confirm must be boolean.';
if (isset($data['expected_fingerprint']) && null !== $data['expected_fingerprint'] && ! preg_match('/^[a-f0-9]{64}$/', (string) $data['expected_fingerprint'])) $errors[] = 'Invalid expected_fingerprint.';
$allowed = array('version', 'request_id', 'action', 'dry_run', 'confirm', 'expected_fingerprint', 'payload');
foreach (array_keys(is_array($data) ? $data : array()) as $key) if (! in_array($key, $allowed, true)) $errors[] = 'Unknown top-level key: ' . $key;
if (isset($data['version']) && 1 !== (int) $data['version']) $errors[] = 'Unsupported version.';

if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, $error . "\n");
    exit(1);
}

echo $id . ' ' . $action . PHP_EOL;
