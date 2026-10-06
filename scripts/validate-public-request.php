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

$action = (string) ($data['action'] ?? '');
$publicActions = array(
    'post.list',
    'post.get',
    'post.update',
    'acf.update',
    'acf.portfolio_stats_update',
    'portfolio.case_text_update',
    'acf.field_groups',
    'acf.schema.ensure_text_fields',
    'elementor.inspect',
    'elementor.patch_element',
    'connector.batch',
    'connector.rollback',
    'connector.update.check',
    'connector.update.apply',
    'code_snippets.patch',
    'maintenance.cache_capabilities',
    'maintenance.cache_flush',
    'custom_css.inspect',
    'custom_css.patch',
    'plugin.install_package',
);
if (! in_array($action, $publicActions, true)) {
    fwrite(STDERR, 'Action is not allowed in public GitHub runtime mode: ' . $action . "\n");
    exit(1);
}

if ('connector.batch' === $action) {
    $operations = isset($data['payload']['operations']) && is_array($data['payload']['operations']) ? $data['payload']['operations'] : array();
    foreach ($operations as $index => $operation) {
        $nestedAction = is_array($operation) && isset($operation['action']) ? (string) $operation['action'] : '';
        if (! in_array($nestedAction, array('post.update', 'acf.update'), true)) {
            fwrite(STDERR, 'Public connector.batch operation is not allowed at index ' . $index . ': ' . $nestedAction . "\n");
            exit(1);
        }
    }
}



if ('plugin.install_package' === $action) {
    $errors = array();
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    $allowedKeys = array('source_path','sha256','expected_plugin','overwrite','activate','network_wide');

    foreach (array_keys($payload) as $key) {
        if (! in_array((string) $key, $allowedKeys, true)) {
            $errors[] = 'Plugin package install payload has unsupported key: ' . (string) $key;
        }
    }

    $sourcePath = isset($payload['source_path']) && is_string($payload['source_path']) ? $payload['source_path'] : '';
    if (! preg_match('#^plugin-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\\.zip$#D', $sourcePath)) {
        $errors[] = 'Plugin package install requires source_path plugin-packages/<safe-name>.zip.';
    }

    $sha256 = isset($payload['sha256']) && is_string($payload['sha256']) ? $payload['sha256'] : '';
    if (! preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
        $errors[] = 'Plugin package install requires an exact lowercase SHA-256 checksum.';
    }

    $expectedPlugin = isset($payload['expected_plugin']) && is_string($payload['expected_plugin']) ? $payload['expected_plugin'] : '';
    if (! preg_match('/^[A-Za-z0-9._-]+\\/[A-Za-z0-9._-]+\\.php$/D', $expectedPlugin)) {
        $errors[] = 'Plugin package install requires an exact expected_plugin file.';
    }

    if (! array_key_exists('overwrite', $payload) || ! is_bool($payload['overwrite'])) {
        $errors[] = 'Public plugin package install requires an explicit boolean overwrite value.';
    } elseif (true === $payload['overwrite']
        && ! hash_equals('webactueel-mailbox-bridge/webactueel-mailbox-bridge.php', $expectedPlugin)) {
        $errors[] = 'Public plugin package overwrite is restricted to the Webactueel Mailbox Bridge.';
    }
    if (! array_key_exists('network_wide', $payload) || false !== $payload['network_wide']) {
        $errors[] = 'Public plugin package install requires network_wide=false.';
    }
    if (! array_key_exists('activate', $payload) || ! is_bool($payload['activate'])) {
        $errors[] = 'Public plugin package install requires an explicit boolean activate value.';
    }

    if (array_key_exists('expected_state_token', $data) && null !== $data['expected_state_token']) {
        $errors[] = 'expected_state_token must not be published in a public runtime request; use expected_fingerprint instead.';
    }

    $dryRun = ! array_key_exists('dry_run', $data) || true === $data['dry_run'];
    if ($dryRun) {
        if (! empty($data['confirm'])) {
            $errors[] = 'Public plugin package dry-run requires confirm=false.';
        }
        if (array_key_exists('expected_fingerprint', $data) && null !== $data['expected_fingerprint']) {
            $errors[] = 'Public plugin package dry-run must not include expected_fingerprint.';
        }
    } else {
        if (empty($data['confirm'])) {
            $errors[] = 'Confirmed public plugin package install requires confirm=true.';
        }
        $fingerprint = $data['expected_fingerprint'] ?? null;
        if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            $errors[] = 'Confirmed public plugin package install requires expected_fingerprint from the preceding dry-run.';
        }
    }

    if ($errors) {
        foreach (array_values(array_unique($errors)) as $error) {
            fwrite(STDERR, $error . "\n");
        }
        exit(1);
    }

    echo 'public runtime request OK: plugin.install_package' . PHP_EOL;
    return;
}

if ('custom_css.inspect' === $action || 'custom_css.patch' === $action) {
    $errors = array();
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    $allowedKeys = 'custom_css.inspect' === $action
        ? array('stylesheet')
        : array('stylesheet','patch_id','operation','css');

    foreach (array_keys($payload) as $key) {
        if (! in_array((string) $key, $allowedKeys, true)) {
            $errors[] = 'Public Additional CSS payload has unsupported key: ' . (string) $key;
        }
    }

    if (isset($payload['stylesheet']) && (! is_string($payload['stylesheet']) || ! preg_match('/^[A-Za-z0-9._-]{1,191}$/D', $payload['stylesheet']))) {
        $errors[] = 'Public Additional CSS stylesheet is invalid.';
    }

    if ('custom_css.patch' === $action) {
        $patchId = isset($payload['patch_id']) && is_string($payload['patch_id']) ? $payload['patch_id'] : '';
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/D', $patchId)) {
            $errors[] = 'Public custom_css.patch requires a safe 3-64 character patch_id.';
        }
        $operation = isset($payload['operation']) && is_string($payload['operation']) ? strtolower($payload['operation']) : '';
        if (! in_array($operation, array('upsert','remove'), true)) {
            $errors[] = 'Public custom_css.patch operation must be upsert or remove.';
        } elseif ('remove' === $operation) {
            if (array_key_exists('css', $payload)) {
                $errors[] = 'Public custom_css.patch remove must not include css.';
            }
        } else {
            $css = isset($payload['css']) && is_string($payload['css']) ? $payload['css'] : '';
            if ('' === trim($css) || strlen($css) > 65536) {
                $errors[] = 'Public custom_css.patch css must be non-empty and at most 64 KiB.';
            }
            if (preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $css)) {
                $errors[] = 'Public custom_css.patch css contains unsupported control characters.';
            }
            if (false !== stripos($css, '</style') || false !== stripos($css, 'wpconnector:')) {
                $errors[] = 'Public custom_css.patch css contains a forbidden style boundary or connector marker.';
            }
        }
    }

    if (array_key_exists('expected_state_token', $data) && null !== $data['expected_state_token']) {
        $errors[] = 'expected_state_token must not be published in a public runtime request; use expected_fingerprint instead.';
    }

    $dryRun = ! array_key_exists('dry_run', $data) || true === $data['dry_run'];
    if ('custom_css.inspect' === $action) {
        if (! $dryRun) {
            $errors[] = 'custom_css.inspect must use dry_run=true in public GitHub runtime mode.';
        }
    } elseif (! $dryRun) {
        if (empty($data['confirm'])) {
            $errors[] = 'Public mutation requires confirm=true when dry_run=false.';
        }
        $fingerprint = $data['expected_fingerprint'] ?? null;
        if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            $errors[] = 'Confirmed custom_css.patch requires expected_fingerprint from the preceding dry-run.';
        }
    }

    if ($errors) {
        foreach (array_values(array_unique($errors)) as $error) {
            fwrite(STDERR, $error . "\n");
        }
        exit(1);
    }

    echo 'public runtime request OK: ' . $action . PHP_EOL;
    return;
}

if ('maintenance.cache_capabilities' === $action) {
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    if ($payload) {
        fwrite(STDERR, "maintenance.cache_capabilities requires an empty payload.\n");
        exit(1);
    }
    if (array_key_exists('expected_state_token', $data) && null !== $data['expected_state_token']) {
        fwrite(STDERR, "expected_state_token must not be published in a public runtime request.\n");
        exit(1);
    }
    echo 'public runtime request OK: maintenance.cache_capabilities' . PHP_EOL;
    return;
}

if ('maintenance.cache_flush' === $action) {
    $errors = array();
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();

    foreach (array_keys($payload) as $key) {
        if ('layer' !== (string) $key) {
            $errors[] = 'Cache flush payload has unsupported key: ' . (string) $key;
        }
    }

    $layer = isset($payload['layer']) && is_string($payload['layer']) ? $payload['layer'] : '';
    if (! in_array($layer, array('elementor','wp_rocket','asset_cleanup'), true)) {
        $errors[] = 'Cache flush layer must be elementor, wp_rocket or asset_cleanup.';
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
            $errors[] = 'Confirmed maintenance.cache_flush requires expected_fingerprint from the preceding dry-run.';
        }
    }

    if ($errors) {
        foreach (array_values(array_unique($errors)) as $error) {
            fwrite(STDERR, $error . "\n");
        }
        exit(1);
    }

    echo 'public runtime request OK: maintenance.cache_flush' . PHP_EOL;
    return;
}

if ('code_snippets.patch' === $action) {
    $errors = array();
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();

    foreach (array_keys($payload) as $key) {
        if (! in_array((string) $key, array('snippet_id','match_code_contains','replacements'), true)) {
            $errors[] = 'Code Snippets patch payload has unsupported key: ' . (string) $key;
        }
    }

    $snippetId = isset($payload['snippet_id']) ? $payload['snippet_id'] : null;
    $marker = isset($payload['match_code_contains']) ? $payload['match_code_contains'] : null;
    $hasId = is_int($snippetId) && $snippetId > 0;
    $hasMarker = is_string($marker) && '' !== $marker;

    if ($hasId === $hasMarker) {
        $errors[] = 'Code Snippets patch requires exactly one of snippet_id or match_code_contains.';
    }
    if ($hasMarker && (strlen($marker) > 240 || preg_match('/[\\x00-\\x1F\\x7F]/', $marker))) {
        $errors[] = 'Code Snippets patch marker must be printable and at most 240 bytes.';
    }

    $replacements = isset($payload['replacements']) && is_array($payload['replacements'])
        ? array_values($payload['replacements'])
        : array();
    if (! $replacements || count($replacements) > 4) {
        $errors[] = 'Code Snippets patch requires 1-4 replacements.';
    }

    foreach ($replacements as $index => $replacement) {
        if (! is_array($replacement)) {
            $errors[] = 'Code Snippets replacement ' . $index . ' must be an object.';
            continue;
        }
        foreach (array_keys($replacement) as $key) {
            if (! in_array((string) $key, array('find','replace'), true)) {
                $errors[] = 'Code Snippets replacement contains unsupported key: ' . (string) $key;
            }
        }
        $find = isset($replacement['find']) && is_string($replacement['find']) ? $replacement['find'] : '';
        $replace = isset($replacement['replace']) && is_string($replacement['replace']) ? $replacement['replace'] : '';
        if ('' === $find || strlen($find) > 131072 || strlen($replace) > 131072) {
            $errors[] = 'Code Snippets replacement exceeds the bounded size limit.';
        }
        if (false !== strpos($find, "\0") || false !== strpos($replace, "\0")) {
            $errors[] = 'Code Snippets replacement may not contain NUL bytes.';
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
            $errors[] = 'Confirmed code_snippets.patch requires expected_fingerprint from the preceding dry-run.';
        }
    }

    if ($errors) {
        foreach (array_values(array_unique($errors)) as $error) {
            fwrite(STDERR, $error . "\n");
        }
        exit(1);
    }

    echo 'public runtime request OK: code_snippets.patch' . PHP_EOL;
    return;
}

if ('portfolio.case_text_update' === $action) {
    $errors = array();
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    foreach (array_keys($payload) as $key) {
        if (! in_array((string) $key, array('post_id','content','description_1','description_2'), true)) {
            $errors[] = 'Portfolio case text payload has unsupported key: ' . (string) $key;
        }
    }
    if (! isset($payload['post_id']) || ! is_int($payload['post_id']) || $payload['post_id'] <= 0) {
        $errors[] = 'Portfolio case text payload.post_id must be a positive integer.';
    }
    $hasValue = false;
    foreach (array('content','description_1','description_2') as $key) {
        if (! array_key_exists($key, $payload)) continue;
        $hasValue = true;
        $value = $payload[$key];
        $limit = 'content' === $key ? 12000 : 5000;
        if (! is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $errors[] = 'Portfolio case text value is invalid: ' . $key;
        }
        if ('content' !== $key && is_string($value) && (false !== strpos($value, '<') || false !== strpos($value, '>'))) {
            $errors[] = 'Portfolio case descriptions must not contain HTML.';
        }
    }
    if (! $hasValue) $errors[] = 'Portfolio case text requires at least one text value.';
    if (array_key_exists('expected_state_token', $data) && null !== $data['expected_state_token']) {
        $errors[] = 'expected_state_token must not be published in a public runtime request; use expected_fingerprint instead.';
    }
    $dryRun = ! array_key_exists('dry_run', $data) || true === $data['dry_run'];
    if (! $dryRun) {
        if (empty($data['confirm'])) $errors[] = 'Public mutation requires confirm=true when dry_run=false.';
        $fingerprint = $data['expected_fingerprint'] ?? null;
        if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            $errors[] = 'Confirmed portfolio.case_text_update requires expected_fingerprint from the preceding dry-run.';
        }
    }
    if ($errors) {
        foreach (array_values(array_unique($errors)) as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: portfolio.case_text_update' . PHP_EOL;
    return;
}

if ('acf.portfolio_stats_update' !== $action) {
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
