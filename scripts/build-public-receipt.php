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
    $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    fwrite(STDERR, 'Invalid JSON while building public receipt: ' . $error->getMessage() . "\n");
    exit(1);
}

if (! is_array($request) || ! is_array($result)) {
    fwrite(STDERR, "Request and result must be JSON objects.\n");
    exit(1);
}

$requestId = (string) ($request['request_id'] ?? '');
$action = (string) ($request['action'] ?? '');
if ($requestId === '' || $requestId !== (string) ($result['request_id'] ?? '') || $action === '' || $action !== (string) ($result['action'] ?? '')) {
    fwrite(STDERR, "Request/result identity mismatch.\n");
    exit(1);
}

$normalize = static function ($value) use (&$normalize) {
    if (! is_array($value)) {
        return $value;
    }
    if (array_keys($value) !== range(0, count($value) - 1)) {
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

$verifyLeaf = static function (string $leafAction, array $payload, array $leafResult): ?bool {
    if ('acf.update' === $leafAction) {
        $expected = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : null;
        $actual = isset($leafResult['after']) && is_array($leafResult['after']) ? $leafResult['after'] : null;
        return null !== $expected && null !== $actual ? $expected === $actual : false;
    }

    if ('post.update' === $leafAction) {
        $after = isset($leafResult['after']) && is_array($leafResult['after']) ? $leafResult['after'] : null;
        if (null === $after) {
            return false;
        }
        $keys = array('title','content','excerpt','status','slug','type','author','parent','menu_order','comment_status','ping_status');
        $integerKeys = array('author','parent','menu_order');
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            if (! array_key_exists($key, $after)) {
                return false;
            }
            $expected = in_array($key, $integerKeys, true) ? (int) $payload[$key] : (string) $payload[$key];
            $actual = in_array($key, $integerKeys, true) ? (int) $after[$key] : (string) $after[$key];
            if ($expected !== $actual) {
                return false;
            }
        }
        return true;
    }

    return null;
};

$errorCode = static function (string $message): string {
    $value = strtolower($message);
    if (str_contains($value, 'stale target')) return 'stale_target';
    if (str_contains($value, 'not found')) return 'not_found';
    if (str_contains($value, 'disabled') || str_contains($value, 'requires') || str_contains($value, 'permission')) return 'permission_gate';
    if (str_contains($value, 'another connector mutation')) return 'mutation_lock';
    if (str_contains($value, 'invalid') || str_contains($value, 'required')) return 'invalid_request';
    if (str_contains($value, 'transport')) return 'transport_error';
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
            // Health metadata is supplemental. Never fail or expose raw health data here.
        }
    }
}

if (! $ok) {
    $receipt['error_code'] = $errorCode((string) ($result['error'] ?? 'connector error'));
} else {
    $payload = isset($request['payload']) && is_array($request['payload']) ? $request['payload'] : array();
    $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();

    if (in_array($action, array('post.update', 'acf.update'), true)) {
        $receipt['readback_verified'] = $verifyLeaf($action, $payload, $data);
        if (isset($data['before']) && is_array($data['before'])) {
            $receipt['before_fingerprint'] = $fingerprint($data['before']);
        }
        if (isset($data['after']) && is_array($data['after'])) {
            $receipt['after_fingerprint'] = $fingerprint($data['after']);
        }
        if (! $dryRun && ! empty($data['rollback_request_id'])) {
            $receipt['rollback_available'] = true;
        }
    } elseif ('connector.batch' === $action) {
        $operations = isset($payload['operations']) && is_array($payload['operations']) ? array_values($payload['operations']) : array();
        $resultOperations = isset($data['operations']) && is_array($data['operations']) ? array_values($data['operations']) : array();
        $receiptOperations = array();
        $allVerified = count($operations) === count($resultOperations) && count($operations) > 0;
        foreach ($operations as $index => $operation) {
            $nestedAction = is_array($operation) ? (string) ($operation['action'] ?? '') : '';
            $nestedPayload = is_array($operation) && isset($operation['payload']) && is_array($operation['payload']) ? $operation['payload'] : array();
            $nestedEnvelope = isset($resultOperations[$index]) && is_array($resultOperations[$index]) ? $resultOperations[$index] : array();
            $nestedResult = isset($nestedEnvelope['result']) && is_array($nestedEnvelope['result']) ? $nestedEnvelope['result'] : array();
            $verified = isset($nestedEnvelope['action']) && (string) $nestedEnvelope['action'] === $nestedAction
                ? $verifyLeaf($nestedAction, $nestedPayload, $nestedResult)
                : false;
            if (true !== $verified) {
                $allVerified = false;
            }
            $entry = array('index' => $index, 'action' => $nestedAction, 'readback_verified' => $verified);
            if (isset($nestedResult['before']) && is_array($nestedResult['before'])) {
                $entry['before_fingerprint'] = $fingerprint($nestedResult['before']);
            }
            if (isset($nestedResult['after']) && is_array($nestedResult['after'])) {
                $entry['after_fingerprint'] = $fingerprint($nestedResult['after']);
            }
            $receiptOperations[] = $entry;
        }
        $receipt['readback_verified'] = $allVerified;
        $receipt['operations'] = $receiptOperations;
        if (! $dryRun && ! empty($data['rollback_request_id'])) {
            $receipt['rollback_available'] = true;
        }
    } elseif ('connector.rollback' === $action) {
        $receipt['readback_verified'] = null;
        $receipt['rollback_executed'] = true;
    } elseif ('connector.update.check' === $action) {
        $update = isset($data['update']) && is_array($data['update']) ? $data['update'] : array();
        $current = (string) ($update['current_version'] ?? '');
        $latest = (string) ($update['latest_version'] ?? '');
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\z/', $current) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\z/', $latest)) {
            $receipt['current_version'] = $current;
            $receipt['latest_version'] = $latest;
            $receipt['update_available'] = ! empty($update['update_available']);
        }
        $receipt['readback_verified'] = null;
    } elseif ('connector.update.apply' === $action) {
        if ($dryRun) {
            $plan = isset($data['would_update_connector']) && is_array($data['would_update_connector']) ? $data['would_update_connector'] : array();
            $from = (string) ($plan['from_version'] ?? '');
            $to = (string) ($plan['to_version'] ?? '');
            $tag = (string) ($plan['tag'] ?? '');
            $planValid = preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\z/', $from)
                && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\z/', $to)
                && $tag === 'v' . $to
                && version_compare($to, $from, '>');
            $receipt['update_plan_verified'] = (bool) $planValid;
            if ($planValid) {
                $receipt['from_version'] = $from;
                $receipt['to_version'] = $to;
            }
            $currentFingerprint = (string) ($data['_current_fingerprint'] ?? '');
            if (preg_match('/^[a-f0-9]{64}\z/', $currentFingerprint)) {
                $receipt['before_fingerprint'] = $currentFingerprint;
            }
            $receipt['readback_verified'] = null;
        } else {
            $before = isset($data['before']) && is_array($data['before']) ? $data['before'] : array();
            $after = isset($data['after']) && is_array($data['after']) ? $data['after'] : array();
            $releaseTag = (string) ($data['release_tag'] ?? '');
            $afterVersion = (string) ($after['version'] ?? '');
            $verified = (string) ($after['plugin_file'] ?? '') === 'wordpressconnector/wordpressconnector.php'
                && preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+)\z/', $releaseTag, $matches)
                && $afterVersion === (string) ($matches[1] ?? '');
            $receipt['readback_verified'] = (bool) $verified;
            if (isset($before['version']) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\z/', (string) $before['version'])) {
                $receipt['from_version'] = (string) $before['version'];
            }
            if ($verified) {
                $receipt['to_version'] = $afterVersion;
            }
            $packageSha = (string) ($data['package_sha256'] ?? '');
            if (preg_match('/^[a-f0-9]{64}\z/', $packageSha)) {
                $receipt['package_sha256'] = $packageSha;
            }
        }
    }
}

$directory = dirname($receiptPath);
if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Could not create receipt directory.\n");
    exit(1);
}

file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);

echo 'public receipt built for ' . $requestId . PHP_EOL;
