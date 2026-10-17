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

    if ('plugin.audit.inspect' === $action) {
        $expectedKeys = array('installed_count', 'active_count', 'inactive_count',
            'unmodeled_count', 'provider_checks_count', 'must_use_count',
            'dropin_count', 'plugins',
            'image_lazyload_overlap', 'checkout_qa', 'smtp_delivery_qa',
            'translation_browser_qa', 'private_settings_exported');
        if (array_diff(array_keys($data), $expectedKeys)
            || ! isset($data['plugins']) || ! is_array($data['plugins'])
            || count($data['plugins']) > 150
            || ($data['private_settings_exported'] ?? null) !== false) {
            throw new RuntimeException('Public plugin audit result structure is not safe.');
        }
        $knownIds = array(
            'acf_yoast_analysis','acf_page_text_manager','acf','aios',
            'woocommerce','mollie','ewww_image_optimizer','aioseo_broken_link_checker',
            'all_in_one_wp_migration','asset_cleanup','auto_image_attributes',
            'broken_link_checker','code_snippets','content_sync_manager',
            'classic_editor','duplicate_page','elementor','elementor_pro',
            'gtranslate','hostinger_ai','hostinger_easy_onboarding','hostinger_reach',
            'hostinger_tools','imagify','joinchat','litespeed_cache','loco_translate',
            'redirection','under_construction','really_simple_security','site_kit',
            'wordfence','wordpress_connector','wp_file_manager','wp_mail_smtp',
            'wp_rocket','yoast','yoast_premium',
        );
        $checkNames = array(
            'guest_checkout','stock_management','cart_page','checkout_page',
            'english_source','nl_en_languages','organization_name',
            'organization_description','organization_logo','button_tip_saved',
            'message_saved','whatsapp_number','image_lazyload','remove_unused_css','missing_dimensions',
            'settings_record','effective_settings','delivery','provider_probe',
        );
        $checkValues = array('enabled','disabled','unknown','matches','mismatch',
            'present','missing','unverified','error');
        $coverageValues = array('registered','partial','not_checked',
            'inventory_only','restricted','unmodeled');
        $publicPlugins = array();
        $activeCount = 0;
        $unknownCount = 0;
        $checkedCount = 0;
        foreach ($data['plugins'] as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), array('id','active','coverage','checks'))
                || ! is_string($entry['id'] ?? null)
                || ! is_bool($entry['active'] ?? null)
                || ! in_array($entry['coverage'] ?? null, $coverageValues, true)
                || ! is_array($entry['checks'] ?? null)
                || count($entry['checks']) > count($checkNames)) {
                throw new RuntimeException('Unsafe public plugin inventory item.');
            }
            $id = $entry['id'];
            $unknown = preg_match('/^unmodeled_[1-9][0-9]*\\z/', $id) === 1;
            if (! $unknown && ! in_array($id, $knownIds, true)) {
                throw new RuntimeException('Unrecognized plugin audit identifier.');
            }
            $checks = array();
            foreach ($entry['checks'] as $check => $value) {
                if (! in_array($check, $checkNames, true) || ! in_array($value, $checkValues, true)) {
                    throw new RuntimeException('Unexpected public plugin audit check or value.');
                }
                $checks[$check] = $value;
            }
            $publicPlugins[] = array(
                'id' => $id,
                'active' => $entry['active'],
                'coverage' => $entry['coverage'],
                'checks' => $checks,
            );
            $activeCount += $entry['active'] ? 1 : 0;
            $unknownCount += $unknown ? 1 : 0;
            $checkedCount += $checks ? 1 : 0;
        }
        $installed = count($publicPlugins);
        foreach (array(
            'installed_count' => $installed,
            'active_count' => $activeCount,
            'inactive_count' => $installed - $activeCount,
            'unmodeled_count' => $unknownCount,
            'provider_checks_count' => $checkedCount,
        ) as $name => $expected) {
            if (! isset($data[$name]) || ! is_int($data[$name]) || $data[$name] !== $expected) {
                throw new RuntimeException('Public plugin audit totals do not reconcile.');
            }
            $receipt[$name] = $expected;
        }
        $overlap = $data['image_lazyload_overlap'] ?? null;
        if (! in_array($overlap, array('unverified','possible_overlap','no_overlap_observed'), true)) {
            throw new RuntimeException('Unknown image optimization overlap status.');
        }
        foreach (array('checkout_qa','smtp_delivery_qa','translation_browser_qa') as $qa) {
            if (($data[$qa] ?? null) !== 'not_run') {
                throw new RuntimeException('Public audit cannot claim browser, email or checkout acceptance.');
            }
        }
        foreach (array('must_use_count', 'dropin_count') as $name) {
            $value = $data[$name] ?? null;
            if (null !== $value && (! is_int($value) || $value < 0 || $value > 150)) {
                throw new RuntimeException('Invalid public WordPress special-plugin count.');
            }
            $receipt[$name] = $value;
        }
        $receipt['plugins'] = $publicPlugins;
        $receipt['image_lazyload_overlap'] = $overlap;
        $receipt['checkout_qa'] = 'not_run';
        $receipt['smtp_delivery_qa'] = 'not_run';
        $receipt['translation_browser_qa'] = 'not_run';
        $receipt['private_settings_exported'] = false;
        $receipt['readback_verified'] = null;
    } elseif ('plugin.install_package' === $action) {
        $expectedPlugin = isset($payload['expected_plugin']) && is_string($payload['expected_plugin']) ? $payload['expected_plugin'] : '';
        $expectedSha = isset($payload['sha256']) && is_string($payload['sha256']) ? strtolower($payload['sha256']) : '';
        if (preg_match('/^[A-Za-z0-9._-]+\\/[A-Za-z0-9._-]+\\.php$/D', $expectedPlugin)) {
            $receipt['plugin_file'] = $expectedPlugin;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha)) {
            $receipt['package_sha256'] = $expectedSha;
        }
        $receipt['activate_requested'] = true === ($payload['activate'] ?? null);
        $expectedOverwrite = true === ($payload['overwrite'] ?? null);
        $receipt['overwrite'] = $expectedOverwrite;
        $receipt['network_wide'] = false;
        $receipt['rollback_supported'] = false;

        $before = isset($data['before']) && is_array($data['before']) ? $data['before'] : null;
        if (null !== $before) {
            $receipt['before_fingerprint'] = $fingerprint($before);
            $receipt['already_installed'] = ! empty($before['installed']);
        }

        if ($dryRun) {
            $plan = isset($data['would_install_package']) && is_array($data['would_install_package']) ? $data['would_install_package'] : array();
            $planSha = isset($plan['sha256']) ? strtolower((string) $plan['sha256']) : '';
            $planFile = isset($plan['plugin_file']) ? (string) $plan['plugin_file'] : '';
            $receipt['package_verified'] = $expectedSha !== ''
                && $expectedPlugin !== ''
                && hash_equals($expectedSha, $planSha)
                && hash_equals($expectedPlugin, $planFile)
                && $expectedOverwrite === ($plan['overwrite'] ?? null)
                && false === ($plan['network_wide'] ?? null);
            if (isset($plan['bytes'])) {
                $receipt['package_bytes'] = max(0, (int) $plan['bytes']);
            }
            if (isset($plan['archive_entries'])) {
                $receipt['archive_entries'] = max(0, (int) $plan['archive_entries']);
            }
            $receipt['readback_verified'] = null;
        } else {
            $after = isset($data['after']) && is_array($data['after']) ? $data['after'] : null;
            if (null !== $after) {
                $receipt['after_fingerprint'] = $fingerprint($after);
            }
            $package = isset($data['package']) && is_array($data['package']) ? $data['package'] : array();
            $actualSha = isset($package['sha256']) ? strtolower((string) $package['sha256']) : '';
            $actualFile = isset($after['file']) ? (string) $after['file'] : '';
            $activationOk = true === ($payload['activate'] ?? null)
                ? ! empty($after['active'])
                : empty($after['active']);
            $receipt['operation'] = isset($data['operation']) && in_array((string) $data['operation'], array('installed','updated'), true)
                ? (string) $data['operation']
                : '';
            $receipt['readback_verified'] = null !== $after
                && ! empty($after['installed'])
                && $expectedSha !== ''
                && hash_equals($expectedSha, $actualSha)
                && $expectedPlugin !== ''
                && hash_equals($expectedPlugin, $actualFile)
                && $activationOk;
        }
    } elseif ('portfolio.case_text_update' === $action) {
        $before = isset($data['before']) && is_array($data['before']) ? $data['before'] : null;
        $after = isset($data['after']) && is_array($data['after']) ? $data['after'] : null;
        $expected = array();
        foreach (array('content','description_1','description_2') as $key) {
            if (array_key_exists($key, $payload)) $expected[$key] = $payload[$key];
        }
        $receipt['post_id'] = isset($data['post_id']) ? (int) $data['post_id'] : (int) ($payload['post_id'] ?? 0);
        $receipt['readback_verified'] = $dryRun ? null : (null !== $after && $expected === $after);
        if (null !== $before) $receipt['before_fingerprint'] = $fingerprint($before);
        if (null !== $after) $receipt['after_fingerprint'] = $fingerprint($after);
        if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
    } elseif ('acf.portfolio_stats_update' === $action) {
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        $before = isset($data['before']) && is_array($data['before']) ? $data['before'] : null;
        $after = isset($data['after']) && is_array($data['after']) ? $data['after'] : null;
        $receipt['post_id'] = isset($data['post_id']) ? (int) $data['post_id'] : (int) ($payload['post_id'] ?? 0);
        $receipt['readback_verified'] = $dryRun ? null : (null !== $after && $fields === $after);
        if (null !== $before) $receipt['before_fingerprint'] = $fingerprint($before);
        if (null !== $after) $receipt['after_fingerprint'] = $fingerprint($after);
        if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
    } elseif ('maintenance.cache_capabilities' === $action) {
        $layers = isset($data['layers']) && is_array($data['layers']) ? $data['layers'] : array();
        $safeLayers = array();
        foreach (array('elementor','wp_rocket','asset_cleanup') as $layer) {
            $state = isset($layers[$layer]) && is_array($layers[$layer]) ? $layers[$layer] : array();
            $safeLayers[$layer] = array(
                'provider' => substr((string) ($state['provider'] ?? ''), 0, 80),
                'available' => ! empty($state['available']),
                'version' => substr((string) ($state['version'] ?? ''), 0, 40),
            );
        }
        $receipt['layers'] = $safeLayers;
        $fp = (string) ($data['fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}\\z/', $fp)) $receipt['layers_fingerprint'] = $fp;
        $receipt['readback_verified'] = null;
    } elseif ('maintenance.cache_flush' === $action) {
        $layer = (string) ($data['layer'] ?? ($payload['layer'] ?? ''));
        if (in_array($layer, array('elementor','wp_rocket','asset_cleanup'), true)) {
            $receipt['layer'] = $layer;
        }
        $receipt['provider'] = substr((string) ($data['provider'] ?? ''), 0, 80);
        $receipt['provider_version'] = substr((string) ($data['version'] ?? ''), 0, 40);
        $receipt['rollback_supported'] = false;
        // A provider completion signal does not prove that all CSS URLs have
        // returned HTTP 200. Keep that distinction visible to API clients.
        $receipt['verification_scope'] = 'provider_completion_only';
        $receipt['regenerate_requested'] = 'elementor' === $layer && true === ($data['regenerate_requested'] ?? false);
        $receipt['regenerate_invoked'] = ! $dryRun && 'elementor' === $layer && true === ($data['regenerate_invoked'] ?? false);
        $receipt['regeneration_verified'] = $dryRun ? null : ('elementor' === $layer && true === ($data['regeneration_verified'] ?? false));
        if (isset($data['rebuild_mode']) && is_scalar($data['rebuild_mode'])) {
            $receipt['rebuild_mode'] = substr((string) $data['rebuild_mode'], 0, 100);
        }
        if (isset($data['json_cache_files_before'])) $receipt['json_cache_files_before'] = max(0, (int) $data['json_cache_files_before']);
        if (isset($data['json_cache_files_after'])) $receipt['json_cache_files_after'] = max(0, (int) $data['json_cache_files_after']);
        $receipt['readback_verified'] = $dryRun ? null : (true === ($data['readback_verified'] ?? null));
        if ($dryRun) {
            $state = array(
                'provider' => (string) ($data['provider'] ?? ''),
                'available' => ! empty($data['available']),
                'version' => (string) ($data['version'] ?? ''),
            );
            $receipt['before_fingerprint'] = $fingerprint($state);
            $receipt['would_flush'] = ! empty($data['would_flush']);
        }
    } elseif ('code_snippets.patch' === $action) {
        $snippet = isset($data['snippet']) && is_array($data['snippet']) ? $data['snippet'] : array();
        $receipt['snippet_id'] = isset($snippet['id']) ? max(0, (int) $snippet['id']) : 0;
        foreach (array('scope','type') as $field) {
            if (isset($snippet[$field]) && is_scalar($snippet[$field])) {
                $receipt[$field] = substr((string) $snippet[$field], 0, 80);
            }
        }
        if (isset($snippet['priority'])) $receipt['priority'] = (int) $snippet['priority'];
        if (isset($snippet['active'])) $receipt['active'] = (bool) $snippet['active'];
        if (isset($data['replacement_count'])) $receipt['replacement_count'] = max(0, min(4, (int) $data['replacement_count']));
        foreach (array('before_code_fingerprint','after_code_fingerprint','before_fingerprint','after_fingerprint') as $field) {
            $value = isset($data[$field]) ? (string) $data[$field] : '';
            if (preg_match('/^[a-f0-9]{64}\\z/', $value)) {
                $receipt[$field] = $value;
            }
        }
        $receipt['readback_verified'] = $dryRun ? null : (true === ($data['readback_verified'] ?? null));
        if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
    } elseif ('custom_css.inspect' === $action) {
        $snapshot = isset($data['custom_css']) && is_array($data['custom_css']) ? $data['custom_css'] : array();
        $css = isset($snapshot['css']) && is_string($snapshot['css']) ? $snapshot['css'] : '';
        $receipt['stylesheet'] = substr((string) ($snapshot['stylesheet'] ?? ($payload['stylesheet'] ?? '')), 0, 191);
        $receipt['css_bytes'] = strlen($css);
        $receipt['css_sha256'] = hash('sha256', $css);
        $stateFingerprint = (string) ($data['fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}\\z/', $stateFingerprint)) $receipt['custom_css_fingerprint'] = $stateFingerprint;
        $receipt['readback_verified'] = null;
    } elseif ('custom_css.patch' === $action) {
        $receipt['stylesheet'] = substr((string) (($data['after']['stylesheet'] ?? null) ?: ($data['before']['stylesheet'] ?? null) ?: ($payload['stylesheet'] ?? '')), 0, 191);
        $receipt['patch_id'] = substr((string) ($data['patch_id'] ?? ($payload['patch_id'] ?? '')), 0, 64);
        $receipt['operation'] = in_array((string) ($data['operation'] ?? ($payload['operation'] ?? '')), array('upsert','remove'), true)
            ? (string) ($data['operation'] ?? ($payload['operation'] ?? ''))
            : '';
        $receipt['changed'] = ! empty($data['changed']);
        foreach (array('before_css_bytes','after_css_bytes') as $field) {
            if (isset($data[$field])) $receipt[$field] = max(0, (int) $data[$field]);
        }
        foreach (array('before_css_sha256','after_css_sha256') as $field) {
            $value = isset($data[$field]) ? (string) $data[$field] : '';
            if (preg_match('/^[a-f0-9]{64}\\z/', $value)) $receipt[$field] = $value;
        }
        $receipt['readback_verified'] = $dryRun ? null : (true === ($data['readback_verified'] ?? null));
        if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
    } elseif ('connector.read_batch' === $action) {
        // Public batch transport already permits only known non-sensitive read
        // actions. Mirror the existing stand-alone public receipt projections,
        // never the raw nested result (which can contain WordPress post content).
        $requested = isset($payload['operations']) && is_array($payload['operations']) ? array_values($payload['operations']) : array();
        $returned = isset($data['operations']) && is_array($data['operations']) ? array_values($data['operations']) : array();
        if (! $requested || count($requested) > 25 || count($requested) !== count($returned)) {
            throw new RuntimeException('Public read batch result count mismatch.');
        }
        $safeActions = array(
            'post.list', 'post.get', 'acf.field_groups', 'elementor.inspect',
            'connector.update.check', 'maintenance.cache_capabilities', 'custom_css.inspect',
        );
        $summaries = array();
        foreach ($requested as $index => $requestOperation) {
            $name = is_array($requestOperation) ? (string) ($requestOperation['action'] ?? '') : '';
            $operation = isset($returned[$index]) && is_array($returned[$index]) ? $returned[$index] : array();
            if (! in_array($name, $safeActions, true) || ($operation['action'] ?? null) !== $name
                || ! isset($operation['result']) || ! is_array($operation['result'])) {
                throw new RuntimeException('Public read batch result action mismatch.');
            }
            $leaf = $operation['result'];
            $summary = array('index' => $index, 'action' => $name);
            if ('post.get' === $name) {
                $post = isset($leaf['post']) && is_array($leaf['post']) ? $leaf['post'] : array();
                $summary['post'] = $summarizePost($post, true);
                $postFingerprint = (string) ($leaf['fingerprint'] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/D', $postFingerprint)) {
                    $summary['post_fingerprint'] = $postFingerprint;
                }
            } elseif ('post.list' === $name) {
                $items = isset($leaf['items']) && is_array($leaf['items']) ? array_values($leaf['items']) : array();
                $summary['items'] = array_map(static function ($post) use ($summarizePost): array {
                    return $summarizePost(is_array($post) ? $post : array(), false);
                }, array_slice($items, 0, 25));
                $summary['items_truncated'] = count($items) > 25;
                foreach (array('page', 'per_page', 'total', 'pages') as $field) {
                    if (isset($leaf[$field])) $summary[$field] = max(0, (int) $leaf[$field]);
                }
            } else {
                // Other safe read types return only a digest until a dedicated
                // bounded public projection is available. Avoid raw settings,
                // Elementor markup, ACF values, cache metadata, or CSS exports.
                $summary['result_fingerprint'] = $fingerprint($leaf);
            }
            $summaries[] = $summary;
        }
        $receipt['operations'] = $summaries;
        $receipt['readback_verified'] = null;
    } elseif (in_array($action, array(
        'gtranslate.settings.inspect','gtranslate.settings.update',
        'yoast.site_representation.inspect','yoast.site_representation.update',
    ), true)) {
        $isGTranslate = 0 === strpos($action, 'gtranslate.');
        $projectFields = static function ($value) use ($isGTranslate): array {
            if (! is_array($value)) return array();
            $out = array();
            $keys = $isGTranslate
                ? array('default_language','incl_langs','fincl_langs','detect_browser_language')
                : array('company_or_person','company_name','org_description');
            foreach ($keys as $key) {
                if (! array_key_exists($key, $value)) continue;
                $item = $value[$key];
                if (is_string($item) && strlen($item) <= 500
                    && ! preg_match('/[\x00-\x1F\x7F<>]/', $item)) {
                    $out[$key] = $item;
                } elseif ($isGTranslate && $key === 'detect_browser_language' && ($item === 0 || $item === 1)) {
                    $out[$key] = $item;
                } elseif ($isGTranslate && in_array($key, array('incl_langs','fincl_langs'), true)
                    && is_array($item) && $item === array('nl','en')) {
                    $out[$key] = $item;
                }
            }
            return $out;
        };
        $isInspect = false !== strpos($action, '.inspect');
        $after = $projectFields($isInspect ? ($data['fields'] ?? array()) : ($data['after'] ?? array()));
        $receipt['fields'] = $after;
        if ($isGTranslate && isset($data['version']) && is_scalar($data['version'])) {
            $receipt['provider_version'] = substr((string) $data['version'], 0, 30);
        }
        if ($isInspect) {
            $fp = isset($data['fingerprint']) ? (string) $data['fingerprint'] : '';
            if (preg_match('/^[a-f0-9]{64}$/D', $fp)) $receipt['settings_fingerprint'] = $fp;
            $receipt['readback_verified'] = null;
        } else {
            $before = isset($data['before']) && is_array($data['before']) ? $data['before'] : array();
            $beforeFp = $isGTranslate ? (string) ($data['before_fingerprint'] ?? '') : $fingerprint($before);
            if (preg_match('/^[a-f0-9]{64}$/D', $beforeFp)) $receipt['before_fingerprint'] = $beforeFp;
            if (isset($data['after']) && is_array($data['after'])) $receipt['after_fingerprint'] = $fingerprint($data['after']);
            $expected = $projectFields($payload['fields'] ?? array());
            $receipt['readback_verified'] = $dryRun ? null
                : ($expected && count($expected) === count($payload['fields'] ?? array())
                    && $containsRequested($expected, $after));
            if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
        }
    } elseif ('site.identity.inspect' === $action || 'site.identity.update' === $action) {
        $keys = array('blogname','blogdescription','timezone_string','WPLANG');
        $readFields = static function ($value) use ($keys): array {
            $fields = array();
            if (! is_array($value)) return $fields;
            foreach ($keys as $key) {
                if (array_key_exists($key, $value) && is_string($value[$key])
                    && strlen($value[$key]) <= 240 && ! preg_match('/[\x00-\x1F\x7F<>]/', $value[$key])) {
                    $fields[$key] = $value[$key];
                }
            }
            return $fields;
        };
        if ('site.identity.inspect' === $action) {
            $receipt['fields'] = $readFields($data['fields'] ?? array());
            $fp = isset($data['fingerprint']) ? (string) $data['fingerprint'] : '';
            if (preg_match('/^[a-f0-9]{64}$/D', $fp)) $receipt['settings_fingerprint'] = $fp;
            $receipt['readback_verified'] = null;
        } else {
            $before = $readFields($data['before'] ?? array());
            $after = $readFields($data['after'] ?? array());
            $expected = $readFields($payload['fields'] ?? array());
            $receipt['fields'] = $after;
            if ($before) $receipt['before_fingerprint'] = $fingerprint($before);
            if ($after) $receipt['after_fingerprint'] = $fingerprint($after);
            $receipt['readback_verified'] = $dryRun ? null
                : ($expected && count($expected) === count($payload['fields'] ?? array())
                    && $containsRequested($expected, $after)
                    && true === ($data['readback_verified'] ?? null));
            if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
        }
    } elseif ('post.literal_replace' === $action) {
        $receipt['post_id'] = isset($data['post_id']) ? (int) $data['post_id'] : (int) ($payload['id'] ?? 0);
        $receipt['match_count'] = isset($data['match_count']) ? (int) $data['match_count'] : 0;
        foreach (array('before_fingerprint','before_content_sha256','after_content_sha256') as $field) {
            $value = isset($data[$field]) ? (string) $data[$field] : '';
            if (preg_match('/^[a-f0-9]{64}$/D', $value)) $receipt[$field] = $value;
        }
        $receipt['readback_verified'] = $dryRun ? null
            : (! empty($data['readback_verified'])
                && $receipt['match_count'] === (int) ($payload['expected_matches'] ?? 0)
                && isset($receipt['before_content_sha256'], $receipt['after_content_sha256']));
        if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
    } elseif ('post.get' === $action) {
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
    } elseif ('yoast.snippet.inspect' === $action) {
        $receipt['post_id'] = (int) ($data['post_id'] ?? 0);
        $fields = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : array();
        $receipt['fields'] = array_intersect_key($fields, array_flip(array('title','description')));
        $fp = (string) ($data['fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}\z/', $fp)) $receipt['before_fingerprint'] = $fp;
        $receipt['readback_verified'] = null;
    } elseif ('yoast.snippet.update' === $action) {
        $receipt['post_id'] = (int) ($data['post_id'] ?? ($payload['id'] ?? 0));
        $requested = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        $before = isset($data['before']) && is_array($data['before']) ? $data['before'] : array();
        $after = isset($data['after']) && is_array($data['after']) ? $data['after'] : array();
        $receipt['updated_keys'] = array_values(array_intersect(array_keys($requested), array('title','description')));
        if ($before) $receipt['before_fingerprint'] = $fingerprint($before);
        if ($after) $receipt['after_fingerprint'] = $fingerprint($after);
        $receipt['readback_verified'] = $dryRun ? null : (
            isset($after['post_id'], $after['fields']) &&
            (int) $after['post_id'] === (int) ($payload['id'] ?? 0) &&
            $containsRequested($requested, $after['fields'])
        );
        if (! $dryRun && ! empty($data['rollback_request_id'])) $receipt['rollback_available'] = true;
    } elseif ('yoast.templates.inspect' === $action) {
        $fields = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : array();
        $receipt['taxonomy_titles'] = array_intersect_key($fields, array_flip(array('category_title','product_category_title')));
        $receipt['product_templates'] = array_intersect_key($fields, array_flip(array('product_title','product_description')));
        $fp = (string) ($data['fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}\\z/', $fp)) $receipt['before_fingerprint'] = $fp;
        $receipt['readback_verified'] = null;
    } elseif ('yoast.templates.update' === $action) {
        $before = isset($data['before']) && is_array($data['before']) ? $data['before'] : array();
        $after = isset($data['after']) && is_array($data['after']) ? $data['after'] : array();
        $requested = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        $receipt['updated_keys'] = array_values(array_intersect(array_keys($requested), array('category_title','product_category_title','product_title','product_description')));
        $receipt['before_fingerprint'] = $fingerprint($before);
        $receipt['after_fingerprint'] = $fingerprint($after);
        $receipt['readback_verified'] = $dryRun ? null : $containsRequested($requested, $after);
    } elseif ('elementor.header_links.inspect' === $action) {
        $receipt['post_id'] = (int) ($data['post_id'] ?? 0);
        $receipt['eligible'] = true === ($data['eligible'] ?? false);
        $reason = (string) ($data['reason_code'] ?? '');
        $receipt['reason_code'] = in_array($reason, array('ready','edit_post_denied','widget_or_source_mismatch','not_header_template','not_published_elementor_library'), true) ? $reason : 'unknown';
        $checks = isset($data['checks']) && is_array($data['checks']) ? $data['checks'] : array();
        $receipt['checks'] = array_values(array_map(static function (array $check): array {
            return array(
                'element_id' => substr((string) ($check['element_id'] ?? ''),0,64),
                'is_icon_list' => true === ($check['is_icon_list'] ?? false),
                'exact_url_matches' => min(2,max(0,(int) ($check['exact_url_matches'] ?? 0))),
            );
        }, array_slice(array_filter($checks,'is_array'),0,4)));
        $fp = (string) ($data['document_fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}\\z/', $fp)) $receipt['document_fingerprint'] = $fp;
        $receipt['readback_verified'] = null;
    } elseif ('elementor.header_links.replace' === $action) {
        $receipt['post_id'] = (int) ($data['post_id'] ?? 0);
        $changes = isset($data['changes']) && is_array($data['changes']) ? $data['changes'] : array();
        $receipt['element_ids'] = array_values(array_filter(array_map(static function ($change): string {
            return is_array($change) ? substr((string) ($change['element_id'] ?? ''), 0, 64) : '';
        }, array_slice($changes, 0, 4)), static function (string $id): bool {
            return (bool) preg_match('/^[a-zA-Z0-9_-]{5,64}\\z/', $id);
        }));
        $beforeFp = (string) ($data['before_fingerprint'] ?? '');
        $afterFp = (string) ($data['after_fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}\\z/', $beforeFp)) $receipt['before_fingerprint'] = $beforeFp;
        if (preg_match('/^[a-f0-9]{64}\\z/', $afterFp)) $receipt['after_fingerprint'] = $afterFp;
        $receipt['readback_verified'] = $dryRun ? null : (true === ($data['readback_verified'] ?? false));
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
