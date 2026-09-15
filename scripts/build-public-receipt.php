<?php

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/build-public-receipt.php <request.json> <result.json> <receipt.json>\n");
    exit(2);
}

$requestPath = $argv[1];
$resultPath = $argv[2];
$receiptPath = $argv[3];

try {
    $request = json_decode((string) file_get_contents($requestPath), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    fwrite(STDERR, 'Invalid request JSON while building public receipt: ' . $error->getMessage() . "\n");
    exit(1);
}

if (! is_array($request) || 'acf.portfolio_stats_update' !== (string) ($request['action'] ?? '')) {
    require __DIR__ . '/build-public-receipt-legacy.php';
    return;
}

try {
    $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    fwrite(STDERR, 'Invalid result JSON while building portfolio stats receipt: ' . $error->getMessage() . "\n");
    exit(1);
}

if (! is_array($result)) {
    fwrite(STDERR, "Result must be a JSON object.\n");
    exit(1);
}

$requestId = (string) ($request['request_id'] ?? '');
$action = (string) ($request['action'] ?? '');
if ($requestId === '' || $requestId !== (string) ($result['request_id'] ?? '') || $action !== (string) ($result['action'] ?? '')) {
    fwrite(STDERR, "Request/result identity mismatch.\n");
    exit(1);
}

$normalize = static function ($value) use (&$normalize) {
    if (! is_array($value)) {
        return $value;
    }
    if ($value && array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value);
    }
    foreach ($value as $key => $item) {
        $value[$key] = $normalize($item);
    }
    return $value;
};

$fingerprint = static function ($value) use ($normalize): string {
    $json = json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return hash('sha256', $json);
};

$errorCode = static function (string $message): string {
    $value = strtolower($message);
    if (false !== strpos($value, 'stale target')) return 'stale_target';
    if (false !== strpos($value, 'not found')) return 'not_found';
    if (false !== strpos($value, 'disabled') || false !== strpos($value, 'requires') || false !== strpos($value, 'permission')) return 'permission_gate';
    if (false !== strpos($value, 'another connector mutation')) return 'mutation_lock';
    if (false !== strpos($value, 'invalid') || false !== strpos($value, 'required')) return 'invalid_request';
    if (false !== strpos($value, 'transport')) return 'transport_error';
    return 'connector_error';
};

$ok = ! empty($result['ok']);
$dryRun = ! empty($result['dry_run']);
$receipt = array(
    'version' => 1,
    'ok' => $ok,
    'request_id' => $requestId,
    'action' => $action,
    'dry_run' => $dryRun,
    'completed_at_gmt' => (string) ($result['completed_at_gmt'] ?? gmdate('c')),
    'public_repository_mode' => true,
    'full_result_persisted' => false,
);

$resultDirectory = dirname($resultPath);
if ('results' === basename($resultDirectory)) {
    $healthPath = dirname($resultDirectory) . '/health.json';
    if (is_file($healthPath) && is_readable($healthPath)) {
        try {
            $health = json_decode((string) file_get_contents($healthPath), true, 512, JSON_THROW_ON_ERROR);
            $connectorVersion = is_array($health) ? (string) ($health['version'] ?? '') : '';
            if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\z/', $connectorVersion)) {
                $receipt['connector_version'] = $connectorVersion;
            }
        } catch (JsonException $error) {
            // Supplemental metadata only.
        }
    }
}

if (! $ok) {
    $receipt['error_code'] = $errorCode((string) ($result['error'] ?? 'connector error'));
} else {
    $payload = isset($request['payload']) && is_array($request['payload']) ? $request['payload'] : array();
    $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
    $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();
    $before = isset($data['before']) && is_array($data['before']) ? $data['before'] : null;
    $after = isset($data['after']) && is_array($data['after']) ? $data['after'] : null;

    $receipt['post_id'] = isset($data['post_id']) ? (int) $data['post_id'] : (int) ($payload['post_id'] ?? 0);
    $receipt['readback_verified'] = $dryRun ? null : (null !== $after && $fields === $after);
    if (null !== $before) {
        $receipt['before_fingerprint'] = $fingerprint($before);
    }
    if (null !== $after) {
        $receipt['after_fingerprint'] = $fingerprint($after);
    }
    if (! $dryRun && ! empty($data['rollback_request_id'])) {
        $receipt['rollback_available'] = true;
    }
}

$encoded = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (false === file_put_contents($receiptPath, $encoded . PHP_EOL)) {
    fwrite(STDERR, "Could not write public receipt.\n");
    exit(1);
}
