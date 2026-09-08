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

$publicActions = array(
    'post.update',
    'acf.update',
    'connector.batch',
    'connector.rollback',
    'connector.update.check',
    'connector.update.apply',
);

$leafActions = array(
    'post.update',
    'acf.update',
);

$errors = array();
$action = isset($data['action']) ? (string) $data['action'] : '';
$payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();

if (! in_array($action, $publicActions, true)) {
    $errors[] = 'Action is not allowed in public GitHub runtime mode: ' . $action;
}

if (array_key_exists('expected_state_token', $data) && null !== $data['expected_state_token']) {
    $errors[] = 'expected_state_token must not be published in a public runtime request; use expected_fingerprint instead.';
}

$secretKeyPattern = '/(password|passwd|secret|token|api[_-]?key|private[_-]?key|consumer[_-]?secret|client[_-]?secret|authorization|cookie|application[_-]?password|license[_-]?key)/i';

$scanKeys = static function ($value, string $context = 'payload') use (&$scanKeys, &$errors, $secretKeyPattern): void {
    if (! is_array($value)) {
        return;
    }
    foreach ($value as $key => $item) {
        $name = (string) $key;
        if (preg_match($secretKeyPattern, $name)) {
            $errors[] = 'Secret-like key is forbidden in public GitHub runtime mode: ' . $context . '.' . $name;
        }
        if (is_array($item)) {
            $scanKeys($item, $context . '.' . $name);
        }
    }
};
$scanKeys($payload);

$assertPositivePostTarget = static function (array $candidate, string $context) use (&$errors): void {
    if (isset($candidate['post_id'])) {
        if ((int) $candidate['post_id'] <= 0 || (string) (int) $candidate['post_id'] !== (string) $candidate['post_id']) {
            $errors[] = $context . '.post_id must be a positive integer.';
        }
        return;
    }
    if (array_key_exists('target', $candidate)) {
        $target = $candidate['target'];
        if (! is_int($target) || $target <= 0) {
            $errors[] = $context . '.target must be a positive integer in public GitHub runtime mode.';
        }
        return;
    }
    $errors[] = $context . ' requires post_id or an integer target.';
};

$validateLeaf = static function (string $leafAction, array $leafPayload, string $context) use (&$errors, $assertPositivePostTarget): void {
    if ('post.update' === $leafAction) {
        $id = isset($leafPayload['id']) ? (int) $leafPayload['id'] : 0;
        if ($id <= 0) {
            $errors[] = $context . '.id must be a positive integer.';
        }
        if (isset($leafPayload['status']) && 'publish' !== (string) $leafPayload['status']) {
            $errors[] = $context . '.status may only be publish in public GitHub runtime mode.';
        }
        if (array_key_exists('password', $leafPayload)) {
            $errors[] = $context . '.password is forbidden in public GitHub runtime mode.';
        }
        return;
    }

    if ('acf.update' === $leafAction) {
        $assertPositivePostTarget($leafPayload, $context);
        if (empty($leafPayload['fields']) || ! is_array($leafPayload['fields'])) {
            $errors[] = $context . '.fields must be a non-empty object.';
        }
        return;
    }

    $errors[] = 'Nested action is not allowed in public GitHub runtime mode: ' . $leafAction;
};

if (in_array($action, $leafActions, true)) {
    $validateLeaf($action, $payload, 'payload');
}

if ('connector.batch' === $action) {
    $operations = isset($payload['operations']) && is_array($payload['operations']) ? $payload['operations'] : array();
    if (! $operations || count($operations) > 25) {
        $errors[] = 'connector.batch requires 1-25 public-safe operations.';
    }
    foreach ($operations as $index => $operation) {
        $context = 'payload.operations[' . $index . ']';
        if (! is_array($operation)) {
            $errors[] = $context . ' must be an object.';
            continue;
        }
        $allowedOperationKeys = array('action', 'payload', 'expected_fingerprint');
        foreach (array_keys($operation) as $key) {
            if (! in_array((string) $key, $allowedOperationKeys, true)) {
                $errors[] = $context . ' has unknown or public-forbidden key: ' . (string) $key;
            }
        }
        $nestedAction = isset($operation['action']) ? (string) $operation['action'] : '';
        if (! in_array($nestedAction, $leafActions, true)) {
            $errors[] = $context . '.action is not public-safe: ' . $nestedAction;
        }
        $nestedPayload = isset($operation['payload']) && is_array($operation['payload']) ? $operation['payload'] : array();
        $validateLeaf($nestedAction, $nestedPayload, $context . '.payload');
        if (array_key_exists('expected_fingerprint', $operation) && null !== $operation['expected_fingerprint']) {
            if (! is_string($operation['expected_fingerprint']) || ! preg_match('/^[a-f0-9]{64}$/D', $operation['expected_fingerprint'])) {
                $errors[] = $context . '.expected_fingerprint must be a SHA-256 hex string.';
            }
        }
    }
}

if ('connector.rollback' === $action) {
    $rollbackId = isset($payload['request_id']) ? (string) $payload['request_id'] : '';
    if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}$/D', $rollbackId)) {
        $errors[] = 'payload.request_id must be a valid connector request id.';
    }
}

if (in_array($action, array('connector.update.check', 'connector.update.apply'), true) && $payload !== array()) {
    $errors[] = $action . ' requires an empty payload in public GitHub runtime mode.';
}

if ('connector.update.apply' === $action && isset($data['dry_run']) && false === $data['dry_run']) {
    $fingerprint = $data['expected_fingerprint'] ?? null;
    if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
        $errors[] = 'Confirmed connector.update.apply requires expected_fingerprint from the preceding dry-run.';
    }
}

if (isset($data['dry_run']) && false === $data['dry_run'] && empty($data['confirm'])) {
    $errors[] = 'Public mutation requires confirm=true when dry_run=false.';
}

if ($errors) {
    foreach (array_values(array_unique($errors)) as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

echo 'public runtime request OK: ' . $action . PHP_EOL;
