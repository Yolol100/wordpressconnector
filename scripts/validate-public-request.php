<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/validate-public-request.php <request.json>\n");
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

if (! is_array($data)) {
    fwrite(STDERR, "Request root must be an object.\n");
    exit(1);
}

if ('acf.portfolio_stats_update' !== (string) ($data['action'] ?? '')) {
    require __DIR__ . '/validate-public-request-legacy.php';
    return;
}

$errors = array();
$payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
$allowedPayloadKeys = array('post_id', 'fields');
foreach (array_keys($payload) as $key) {
    if (! in_array((string) $key, $allowedPayloadKeys, true)) {
        $errors[] = 'Portfolio stats payload has unsupported key: ' . (string) $key;
    }
}

if (! isset($payload['post_id']) || ! is_int($payload['post_id']) || $payload['post_id'] <= 0) {
    $errors[] = 'Portfolio stats payload.post_id must be a positive integer.';
}

$fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
if (! $fields || count($fields) > 8 || array_keys($fields) === range(0, count($fields) - 1)) {
    $errors[] = 'Portfolio stats payload.fields must be a 1-8 field object.';
}

$allowedFieldKeys = array(
    'field_portfolio_stat_1_value',
    'field_portfolio_stat_1_label',
    'field_portfolio_stat_2_value',
    'field_portfolio_stat_2_label',
    'field_portfolio_stat_3_value',
    'field_portfolio_stat_3_label',
    'field_portfolio_stat_4_value',
    'field_portfolio_stat_4_label',
);
foreach ($fields as $fieldKey => $value) {
    $fieldKey = (string) $fieldKey;
    if (! in_array($fieldKey, $allowedFieldKeys, true)) {
        $errors[] = 'Portfolio stats field is outside the fixed public allowlist: ' . $fieldKey;
    }
    if (! is_string($value) || strlen($value) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        $errors[] = 'Portfolio stats values must be text strings up to 1000 bytes without control characters.';
    }
}

if (array_key_exists('expected_state_token', $data) && null !== $data['expected_state_token']) {
    $errors[] = 'expected_state_token must not be published in a public runtime request; use expected_fingerprint instead.';
}

$dryRun = ! array_key_exists('dry_run', $data) || true === $data['dry_run'];
if (! $dryRun) {
    if (empty($data['confirm'])) {
        $errors[] = 'Public mutation requires confirm=true when dry_run=false.';
    }
    $fingerprint = $data['expected_fingerprint'] ?? null;
    if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
        $errors[] = 'Confirmed acf.portfolio_stats_update requires expected_fingerprint from the preceding dry-run.';
    }
}

if ($errors) {
    foreach (array_values(array_unique($errors)) as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

echo 'public runtime request OK: acf.portfolio_stats_update' . PHP_EOL;
