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

$secretKeyPattern = '/(password|passwd|secret|token|api[_-]?key|private[_-]?key|consumer[_-]?secret|client[_-]?secret|authorization|cookie|application[_-]?password|license[_-]?key)/i';

$safePublicValue = static function ($value, int $depth = 0) use (&$safePublicValue, $secretKeyPattern) {
    if ($depth > 3) {
        return '[truncated]';
    }
    if (is_string($value)) {
        return strlen($value) > 800 ? substr($value, 0, 800) . '…' : $value;
    }
    if (is_int($value) || is_float($value) || is_bool($value) || null === $value) {
        return $value;
    }
    if (! is_array($value)) {
        return null;
    }
    $out = array();
    $count = 0;
    foreach ($value as $key => $item) {
        if ($count >= 20) {
            $out['_truncated'] = true;
            break;
        }
        $name = (string) $key;
        if (preg_match($secretKeyPattern, $name)) {
            continue;
        }
        $out[$key] = $safePublicValue($item, $depth + 1);
        ++$count;
    }
    return $out;
};

$containsRequested = static function ($expected, $actual) use (&$containsRequested): bool {
    if (! is_array($expected)) {
        return $expected === $actual;
    }
    if (! is_array($actual)) {
        return false;
    }
    foreach ($expected as $key => $value) {
        if (! array_key_exists($key, $actual) || ! $containsRequested($value, $actual[$key])) {
            return false;
        }
    }
    return true;
};

$verifyLeaf = static function (string $leafAction, array $payload, array $leafResult): ?bool {
    if ('acf.update' === $leafAction) {
        $expected = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : null;
        $actual = isset($leafResult['after']) && is_array($leafResult['after']) ? $leafResult['after'] : null;
        return null !== $expected && null !== $actual ? $expected === $actual : false;
    }
    if ('post.update' !== $leafAction) {
        return null;
    }
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
};

$summarizeElementorElements = static function (array $elements, array $requestedIds = array()) use ($safePublicValue): array {
    $summaries = array();
    $allowedSettingKeys = array('title','text','button_text','__dynamic__','link','url','html','editor','description','before','after');
    $requested = array();
    foreach ($requestedIds as $elementId) {
        if (is_string($elementId) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $elementId)) {
            $requested[$elementId] = true;
        }
    }
    $walk = static function (array $nodes) use (&$walk, &$summaries, $allowedSettingKeys, $safePublicValue, $requested): void {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = isset($node['id']) ? (string) $node['id'] : '';
            if ($id !== '' && (! $requested || isset($requested[$id])) && count($summaries) < 200) {
                $entry = array('id' => $id);
                foreach (array('elType','widgetType') as $key) {
                    if (isset($node[$key]) && '' !== (string) $node[$key]) {
                        $entry[$key] = substr((string) $node[$key], 0, 100);
                    }
                }
                $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : array();
                $safeSettings = array();
                foreach ($allowedSettingKeys as $key) {
                    if (array_key_exists($key, $settings)) {
                        $safeSettings[$key] = $safePublicValue($settings[$key]);
                    }
                }
                if ($safeSettings) {
                    $entry['settings'] = $safeSettings;
                }
                $summaries[] = $entry;
            }
            if (isset($node['elements']) && is_array($node['elements']) && count($summaries) < 200) {
                $walk($node['elements']);
            }
        }
    };
    $walk($elements);
    return $summaries;
};

$summarizeAcfGroups = static function (array $groups): array {
    $safe = array();
    foreach (array_slice(array_values($groups), 0, 20) as $group) {
        if (! is_array($group)) {
            continue;
        }
        $entry = array(
            'key' => substr((string) ($group['key'] ?? ''), 0, 100),
            'title' => substr((string) ($group['title'] ?? ''), 0, 160),
            'fields' => array(),
        );
        $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : array();
        foreach (array_slice(array_values($fields), 0, 100) as $field) {
            if (! is_array($field)) {
                continue;
            }
            $entry['fields'][] = array(
                'key' => substr((string) ($field['key'] ?? ''), 0, 100),
                'name' => substr((string) ($field['name'] ?? ''), 0, 100),
                'label' => substr((string) ($field['label'] ?? ''), 0, 160),
                'type' => substr((string) ($field['type'] ?? ''), 0, 40),
                'required' => ! empty($field['required']),
            );
        }
        $safe[] = $entry;
    }
    return $safe;
};

$summarizePost = static function (array $post, bool $includeExcerpt = false) use ($safePublicValue): array {
    $safe = array(
        'id' => isset($post['id']) ? (int) $post['id'] : 0,
        'type' => substr((string) ($post['type'] ?? ''), 0, 80),
        'status' => substr((string) ($post['status'] ?? ''), 0, 40),
        'title' => substr((string) ($post['title'] ?? ''), 0, 240),
        'slug' => substr((string) ($post['slug'] ?? ''), 0, 240),
        'permalink' => substr((string) ($post['permalink'] ?? ''), 0, 500),
    );
    if ($includeExcerpt) {
        $safe['excerpt'] = $safePublicValue((string) ($post['excerpt'] ?? ''));
    }
    return $safe;
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
    $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();

    if ('post.get' === $action) {
        $post = isset($data['post']) && is_array($data['post']) ? $data['post'] : array();
        $receipt['post'] = $summarizePost($post, true);
        $postFingerprint = (string) ($data['fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $postFingerprint)) {
            $receipt['post_fingerprint'] = $postFingerprint;
        }
        $receipt['readback_verified'] = null;
    } elseif ('post.list' === $action) {
        $items = isset($data['items']) && is_array($data['items']) ? array_values($data['items']) : array();
        $receipt['items'] = array_map(static function ($post) use ($summarizePost): array {
            return $summarizePost(is_array($post) ? $post : array(), false);
        }, array_slice($items, 0, 100));
        foreach (array('page','per_page','total','pages') as $field) {
            if (isset($data[$field])) $receipt[$field] = max(0, (int) $data[$field]);
        }
        $receipt['readback_verified'] = null;
    } elseif (in_array($action, array('post.update','acf.update'), true)) {
        $receipt['readback_verified'] = $verifyLeaf($action, $payload, $data);
        if (isset($data['before']) && is_array($data['before'])) $receipt['before_fingerprint'] = $fingerprint($data['before']);
        if (isset($data['after']) && is_array($data['after'])) $receipt['after_fingerprint'] = $fingerprint($data['after']);
        if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
    } elseif ('acf.field_groups' === $action) {
        $groups = isset($data['field_groups']) && is_array($data['field_groups']) ? $data['field_groups'] : array();
        $receipt['post_id'] = isset($payload['post_id']) ? (int) $payload['post_id'] : 0;
        $receipt['field_groups'] = $summarizeAcfGroups($groups);
        $receipt['schema_fingerprint'] = $fingerprint($receipt['field_groups']);
        $receipt['readback_verified'] = null;
    } elseif ('acf.schema.ensure_text_fields' === $action) {
        $before = isset($data['before']) && is_array($data['before']) ? $data['before'] : array();
        $after = isset($data['after']) && is_array($data['after']) ? $data['after'] : array();
        $requested = isset($data['requested']) && is_array($data['requested']) ? $data['requested'] : array();
        $receipt['post_id'] = isset($data['post_id']) ? (int) $data['post_id'] : (int) ($payload['post_id'] ?? 0);
        $receipt['group_key'] = substr((string) ($data['group_key'] ?? ($payload['group_key'] ?? '')), 0, 100);
        $state = array('post_id' => $receipt['post_id'], 'group_key' => $receipt['group_key'], 'fields' => $before);
        $receipt['schema_fingerprint'] = $fingerprint($state);
        $receipt['would_create'] = array_values(array_filter(array_map('strval', isset($data['would_create']) && is_array($data['would_create']) ? $data['would_create'] : array()), static function (string $value): bool { return (bool) preg_match('/^field_[A-Za-z0-9_-]{6,80}$/D', $value); }));
        if ($dryRun) {
            $receipt['readback_verified'] = null;
        } else {
            $verified = true;
            foreach ($requested as $definition) {
                if (! is_array($definition) || empty($definition['key']) || ! isset($after[(string) $definition['key']]) || ! $containsRequested($definition, $after[(string) $definition['key']])) {
                    $verified = false;
                    break;
                }
            }
            $receipt['readback_verified'] = $verified;
            $receipt['created'] = array_values(array_filter(array_map('strval', isset($data['created']) && is_array($data['created']) ? $data['created'] : array()), static function (string $value): bool { return (bool) preg_match('/^field_[A-Za-z0-9_-]{6,80}$/D', $value); }));
            $receipt['before_fingerprint'] = $fingerprint($before);
            $receipt['after_fingerprint'] = $fingerprint($after);
            if (! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
        }
    } elseif ('elementor.inspect' === $action) {
        $document = isset($data['document']) && is_array($data['document']) ? $data['document'] : array();
        $documentFingerprint = (string) ($data['fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $documentFingerprint)) $receipt['document_fingerprint'] = $documentFingerprint;
        if (isset($document['post_id'])) $receipt['post_id'] = (int) $document['post_id'];
        foreach (array('document_type','edit_mode','template_type','elementor_version') as $field) {
            if (isset($document[$field]) && is_scalar($document[$field])) $receipt[$field] = substr((string) $document[$field], 0, 100);
        }
        $requestedIds = isset($payload['element_ids']) && is_array($payload['element_ids']) ? $payload['element_ids'] : array();
        $elements = isset($document['data']) && is_array($document['data']) ? $document['data'] : array();
        $receipt['elements'] = $summarizeElementorElements($elements, $requestedIds);
        $receipt['readback_verified'] = null;
    } elseif ('elementor.patch_element' === $action) {
        $beforeElement = isset($data['before_element']) && is_array($data['before_element']) ? $data['before_element'] : null;
        $afterElement = isset($data['after_element']) && is_array($data['after_element']) ? $data['after_element'] : null;
        $requestedSettings = isset($payload['settings']) && is_array($payload['settings']) ? $payload['settings'] : null;
        $actualSettings = is_array($afterElement) && isset($afterElement['settings']) && is_array($afterElement['settings']) ? $afterElement['settings'] : null;
        $receipt['readback_verified'] = null !== $requestedSettings && null !== $actualSettings ? $containsRequested($requestedSettings, $actualSettings) : false;
        if (isset($data['post_id'])) $receipt['post_id'] = (int) $data['post_id'];
        if (isset($data['element_id'])) $receipt['element_id'] = substr((string) $data['element_id'], 0, 64);
        if (null !== $beforeElement) $receipt['before_fingerprint'] = $fingerprint($beforeElement);
        if (null !== $afterElement) $receipt['after_fingerprint'] = $fingerprint($afterElement);
        $nextFingerprint = (string) ($data['fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $nextFingerprint)) $receipt['document_fingerprint'] = $nextFingerprint;
        if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
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
            $verified = isset($nestedEnvelope['action']) && (string) $nestedEnvelope['action'] === $nestedAction ? $verifyLeaf($nestedAction, $nestedPayload, $nestedResult) : false;
            if (true !== $verified) $allVerified = false;
            $entry = array('index' => $index, 'action' => $nestedAction, 'readback_verified' => $verified);
            if (isset($nestedResult['before']) && is_array($nestedResult['before'])) $entry['before_fingerprint'] = $fingerprint($nestedResult['before']);
            if (isset($nestedResult['after']) && is_array($nestedResult['after'])) $entry['after_fingerprint'] = $fingerprint($nestedResult['after']);
            $receiptOperations[] = $entry;
        }
        $receipt['readback_verified'] = $allVerified;
        $receipt['operations'] = $receiptOperations;
        if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
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
            // Runner intentionally removes internal _current_fingerprint before transport.
            // This public adapter has a fixed two-field state; reconstruct its digest
            // from the validated live preview, never from caller-supplied request data.
            if ($planValid
                && ($plan['package_asset'] ?? '') === 'wordpressconnector.zip'
                && ($plan['checksum_asset'] ?? '') === 'wordpressconnector.zip.sha256') {
                $receipt['before_fingerprint'] = $fingerprint(array(
                    'plugin_file' => 'wordpressconnector/wordpressconnector.php',
                    'version' => $from,
                ));
            }
            if (isset($plan['rollback_supported']) && is_bool($plan['rollback_supported'])) {
                $receipt['rollback_supported'] = $plan['rollback_supported'];
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
            if (isset($before['version']) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\z/', (string) $before['version'])) $receipt['from_version'] = (string) $before['version'];
            if ($verified) $receipt['to_version'] = $afterVersion;
            $packageSha = (string) ($data['package_sha256'] ?? '');
            if (preg_match('/^[a-f0-9]{64}\z/', $packageSha)) $receipt['package_sha256'] = $packageSha;
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
