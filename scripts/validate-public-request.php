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
    'acf.field_groups',
    'acf.schema.ensure_text_fields',
    'elementor.inspect',
    'elementor.patch_element',
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

$assertPositiveId = static function (array $candidate, string $context) use (&$errors): void {
    if (! isset($candidate['id']) || ! is_int($candidate['id']) || $candidate['id'] <= 0) {
        $errors[] = $context . '.id must be a positive integer.';
    }
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

if ('acf.field_groups' === $action) {
    foreach (array_keys($payload) as $key) {
        if ('post_id' !== (string) $key) {
            $errors[] = 'payload has unknown or public-forbidden ACF field-group key: ' . (string) $key;
        }
    }
    if (! isset($payload['post_id']) || ! is_int($payload['post_id']) || $payload['post_id'] <= 0) {
        $errors[] = 'payload.post_id must be a positive integer for public ACF field-group discovery.';
    }
    if (isset($data['dry_run']) && false === $data['dry_run']) {
        $errors[] = 'acf.field_groups must use dry_run=true in public GitHub runtime mode.';
    }
}

if ('acf.schema.ensure_text_fields' === $action) {
    $allowedKeys = array('post_id', 'group_key', 'fields');
    foreach (array_keys($payload) as $key) {
        if (! in_array((string) $key, $allowedKeys, true)) {
            $errors[] = 'payload has unknown or public-forbidden ACF schema key: ' . (string) $key;
        }
    }
    if (! isset($payload['post_id']) || ! is_int($payload['post_id']) || $payload['post_id'] <= 0) {
        $errors[] = 'payload.post_id must be a positive integer for ACF schema ensure.';
    }
    $groupKey = isset($payload['group_key']) ? (string) $payload['group_key'] : '';
    if (! preg_match('/^group_[A-Za-z0-9_-]{6,80}$/D', $groupKey)) {
        $errors[] = 'payload.group_key must be a valid ACF field-group key.';
    }
    $fields = isset($payload['fields']) && is_array($payload['fields']) ? array_values($payload['fields']) : array();
    if (! $fields || count($fields) > 12) {
        $errors[] = 'payload.fields must contain 1-12 ACF text field definitions.';
    }
    $seenKeys = array();
    $seenNames = array();
    foreach ($fields as $index => $field) {
        $context = 'payload.fields[' . $index . ']';
        if (! is_array($field)) {
            $errors[] = $context . ' must be an object.';
            continue;
        }
        foreach (array_keys($field) as $key) {
            if (! in_array((string) $key, array('key', 'name', 'label'), true)) {
                $errors[] = $context . ' has unknown ACF text field property: ' . (string) $key;
            }
        }
        $fieldKey = isset($field['key']) ? (string) $field['key'] : '';
        $name = isset($field['name']) ? (string) $field['name'] : '';
        $label = isset($field['label']) ? trim((string) $field['label']) : '';
        if (! preg_match('/^field_[A-Za-z0-9_-]{6,80}$/D', $fieldKey)) {
            $errors[] = $context . '.key is invalid.';
        }
        if (! preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $name)) {
            $errors[] = $context . '.name is invalid.';
        }
        if ('' === $label || strlen($label) > 80 || preg_match('/[\x00-\x1F\x7F]/', $label)) {
            $errors[] = $context . '.label is invalid.';
        }
        if (preg_match($secretKeyPattern, $name) || preg_match($secretKeyPattern, $label)) {
            $errors[] = $context . ' uses a secret-like ACF field name or label.';
        }
        if (isset($seenKeys[$fieldKey]) || isset($seenNames[$name])) {
            $errors[] = $context . ' duplicates another ACF field key or name.';
        }
        $seenKeys[$fieldKey] = true;
        $seenNames[$name] = true;
    }
    if (isset($data['dry_run']) && false === $data['dry_run']) {
        $fingerprint = $data['expected_fingerprint'] ?? null;
        if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            $errors[] = 'Confirmed acf.schema.ensure_text_fields requires expected_fingerprint from the preceding dry-run.';
        }
    }
}

if ('elementor.inspect' === $action) {
    $allowedKeys = array('id', 'element_ids');
    foreach (array_keys($payload) as $key) {
        if (! in_array((string) $key, $allowedKeys, true)) {
            $errors[] = 'payload has unknown or public-forbidden Elementor inspect key: ' . (string) $key;
        }
    }
    $assertPositiveId($payload, 'payload');
    if (isset($data['dry_run']) && false === $data['dry_run']) {
        $errors[] = 'elementor.inspect must use dry_run=true in public GitHub runtime mode.';
    }
    if (array_key_exists('element_ids', $payload)) {
        if (! is_array($payload['element_ids']) || count($payload['element_ids']) > 50) {
            $errors[] = 'payload.element_ids must be an array of at most 50 Elementor element ids.';
        } else {
            foreach ($payload['element_ids'] as $elementId) {
                if (! is_string($elementId) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $elementId)) {
                    $errors[] = 'payload.element_ids contains an invalid Elementor element id.';
                    break;
                }
            }
        }
    }
}

if ('elementor.patch_element' === $action) {
    $allowedKeys = array('id', 'element_id', 'settings');
    foreach (array_keys($payload) as $key) {
        if (! in_array((string) $key, $allowedKeys, true)) {
            $errors[] = 'payload has unknown or public-forbidden Elementor patch key: ' . (string) $key;
        }
    }
    $assertPositiveId($payload, 'payload');
    $elementId = isset($payload['element_id']) ? (string) $payload['element_id'] : '';
    if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $elementId)) {
        $errors[] = 'payload.element_id must be a valid Elementor element id.';
    }
    if (empty($payload['settings']) || ! is_array($payload['settings'])) {
        $errors[] = 'payload.settings must be a non-empty object.';
    } else {
        $keys = array_keys($payload['settings']);
        if ($keys === range(0, count($keys) - 1)) {
            $errors[] = 'payload.settings must be an object, not a list.';
        }
    }
    if (isset($data['dry_run']) && false === $data['dry_run']) {
        $fingerprint = $data['expected_fingerprint'] ?? null;
        if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            $errors[] = 'Confirmed elementor.patch_element requires expected_fingerprint from the preceding dry-run or inspect.';
        }
    }
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
