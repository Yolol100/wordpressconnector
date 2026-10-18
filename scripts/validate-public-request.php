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
    'post.literal_replace',
    'plugin.audit.inspect',
    'site.identity.inspect',
    'site.identity.update',
    'gtranslate.settings.inspect',
    'gtranslate.settings.update',
    'yoast.site_representation.inspect',
    'yoast.site_representation.update',
    'yoast.snippet.inspect',
    'yoast.snippet.update',
    'yoast.templates.inspect',
    'yoast.templates.update',
    'acf.update',
    'acf.portfolio_stats_update',
    'portfolio.case_text_update',
    'acf.field_groups',
    'acf.schema.ensure_text_fields',
    'elementor.inspect',
    'elementor.patch_element',
    'elementor.header_links.replace',
    'elementor.header_links.inspect',
    'connector.batch',
    'connector.read_batch',
    'connector.rollback',
    'connector.update.check',
    'connector.update.apply',
    'code_snippets.patch',
    'maintenance.cache_capabilities',
    'maintenance.cache_flush',
    'custom_css.inspect',
    'custom_css.patch',
    'plugin.install_package',
    'theme.install_package',
);
if (! in_array($action, $publicActions, true)) {
    fwrite(STDERR, 'Action is not allowed in public GitHub runtime mode: ' . $action . "\n");
    exit(1);
}

// Explicit public snippet exception: only existing published page/post/product
// SEO title and meta-description overrides. No robots/canonical/focus keyword.
if (in_array($action, array('yoast.snippet.inspect', 'yoast.snippet.update'), true)) {
    $errors = array();
    $read = 'yoast.snippet.inspect' === $action;
    $root = array('version','site_url','request_id','action','payload','dry_run','confirm');
    if (! $read) $root[] = 'expected_fingerprint';
    if (array_diff(array_keys($data), $root) || array_key_exists('expected_state_token', $data)) {
        $errors[] = 'Snippet request has forbidden root keys.';
    }
    $payload = $data['payload'] ?? null;
    if (! is_array($payload) || ! is_int($payload['id'] ?? null) || $payload['id'] < 1) {
        $errors[] = 'Snippet request requires a positive integer id.';
    }
    if ($read) {
        if (! is_array($payload) || array_keys($payload) !== array('id') ||
            ($data['dry_run'] ?? null) !== true || ($data['confirm'] ?? null) !== false) {
            $errors[] = 'Snippet inspection is an id-only read request.';
        }
    } else {
        if (! is_array($payload) || array_diff(array_keys($payload), array('id','fields'))) {
            $errors[] = 'Snippet update accepts only id and fields.';
        }
        $fields = is_array($payload) ? ($payload['fields'] ?? null) : null;
        if (! is_array($fields) || ! $fields || count($fields) > 2 ||
            array_keys($fields) === range(0, count($fields) - 1)) {
            $errors[] = 'Snippet update requires 1-2 named fields.';
        } else {
            foreach ($fields as $key => $value) {
                $max = 'title' === $key ? 190 : 320;
                if (! in_array($key, array('title','description'), true) ||
                    ! is_string($value) || strlen($value) < 10 || strlen($value) > $max ||
                    trim($value) !== $value || strpos($value, '%%') !== false ||
                    preg_match('/[\x00-\x1f\x7f<>]/', $value)) {
                    $errors[] = 'Snippet update field is not allowed or plain text is invalid.';
                }
            }
        }
        if (($data['dry_run'] ?? null) === true) {
            if (($data['confirm'] ?? null) !== false || array_key_exists('expected_fingerprint', $data)) {
                $errors[] = 'Snippet dry-run must not confirm or supply a fingerprint.';
            }
        } elseif (($data['dry_run'] ?? null) === false) {
            if (($data['confirm'] ?? null) !== true ||
                ! is_string($data['expected_fingerprint'] ?? null) ||
                ! preg_match('/^[a-f0-9]{64}\z/', $data['expected_fingerprint'])) {
                $errors[] = 'Confirmed snippet edit requires the prior dry-run fingerprint.';
            }
        } else {
            $errors[] = 'Snippet requests require explicit dry_run.';
        }
    }
    if ($errors) {
        foreach (array_unique($errors) as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: ' . $action . PHP_EOL;
    return;
}

// The PUBLIC GitHub transport may patch only four non-sensitive SEO templates.
// Never accept arbitrary options, private Yoast configuration or per-post SEO.
if (in_array($action, array('yoast.templates.inspect', 'yoast.templates.update'), true)) {
    $errors = array();
    $read = 'yoast.templates.inspect' === $action;
    $allowedRoot = array('version','site_url','request_id','action','payload','dry_run','confirm');
    if (! $read) $allowedRoot[] = 'expected_fingerprint';
    if (array_diff(array_keys($data), $allowedRoot)) $errors[] = 'Public Yoast template request has forbidden root keys.';
    $payload = $data['payload'] ?? null;
    if (! is_array($payload)) $errors[] = 'Public Yoast template payload must be an object.';
    if ($read) {
        if ($payload !== array() || ($data['dry_run'] ?? null) !== true || ($data['confirm'] ?? null) !== false) {
            $errors[] = 'Yoast template inspection must be an empty dry-run.';
        }
    } else {
        if (! is_array($payload) || array_diff(array_keys($payload), array('fields'))) {
            $errors[] = 'Public Yoast template request accepts only fields.';
        }
        $fields = is_array($payload) ? ($payload['fields'] ?? null) : null;
        if (! is_array($fields) || ! $fields || count($fields) > 4) {
            $errors[] = 'Public Yoast template update requires one to four fields.';
        } else {
            foreach ($fields as $key => $value) {
                $allowed = array('category_title','product_category_title','product_title','product_description');
                $token = in_array($key, array('category_title','product_category_title'), true)
                    ? '%%term_title%%' : ($key === 'product_title' ? '%%title%%' : '%%excerpt%%');
                if (! in_array((string) $key, $allowed, true)
                    || ! is_string($value) || strlen($value) < 10 || strlen($value) > 150
                    || false === strpos($value, $token)
                    || preg_match('/[\x00-\x1f\x7f<>]/', $value)) {
                    $errors[] = 'Public Yoast template field or content variable is not allowed.';
                }
            }
        }
        if (($data['dry_run'] ?? null) === true) {
            if (($data['confirm'] ?? null) !== false || array_key_exists('expected_fingerprint', $data)) {
                $errors[] = 'Public Yoast template dry-run forbids confirmation and fingerprint.';
            }
        } elseif (($data['dry_run'] ?? null) === false) {
            if (($data['confirm'] ?? null) !== true || ! is_string($data['expected_fingerprint'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}\z/', $data['expected_fingerprint'])) {
                $errors[] = 'Public Yoast template write requires confirmed dry-run fingerprint.';
            }
        } else {
            $errors[] = 'Public Yoast template request requires explicit dry_run.';
        }
    }
    if ($errors) {
        foreach (array_unique($errors) as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: ' . $action . PHP_EOL;
    return;
}

// Narrow read-only header eligibility check. Never export the template JSON.
if ('elementor.header_links.inspect' === $action) {
    $errors = array();
    if (array_diff(array_keys($data), array('version','site_url','request_id','action','payload','dry_run','confirm'))
        || ($data['dry_run'] ?? null) !== true || ($data['confirm'] ?? null) !== false) {
        $errors[] = 'Header diagnostics are restricted to read-only dry-runs.';
    }
    $payload = $data['payload'] ?? null;
    if (! is_array($payload) || array_diff(array_keys($payload), array('post_id','checks'))
        || ! is_int($payload['post_id'] ?? null) || $payload['post_id'] < 1) {
        $errors[] = 'Header diagnostic payload requires only positive post_id and checks.';
    }
    $checks = is_array($payload) ? ($payload['checks'] ?? null) : null;
    $siteHost = strtolower((string) parse_url((string) ($data['site_url'] ?? ''), PHP_URL_HOST));
    if (! is_array($checks) || ! $checks || count($checks) > 4) {
        $errors[] = 'Header diagnostics require 1-4 checks.';
    } else {
        $ids = array();
        foreach ($checks as $check) {
            if (! is_array($check) || array_diff(array_keys($check), array('element_id','from'))) {
                $errors[] = 'Header diagnostic has unsupported keys.';
                continue;
            }
            $id = $check['element_id'] ?? null;
            $url = $check['from'] ?? null;
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{5,64}\\z/', $id)
                || isset($ids[$id]) || ! is_string($url) || strlen($url) > 500
                || ! filter_var($url, FILTER_VALIDATE_URL)
                || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $siteHost) {
                $errors[] = 'Header diagnostic widget or URL is invalid.';
            } else $ids[$id] = true;
        }
    }
    if ($errors) {
        foreach (array_unique($errors) as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: elementor.header_links.inspect' . PHP_EOL;
    return;
}

// Controlled edits to a published Elementor header: exact HTTPS link replacements
// only, never arbitrary template contents or bulk settings.
if ('elementor.header_links.replace' === $action) {
    $errors = array();
    $allowedRoot = array('version','site_url','request_id','action','payload','dry_run','confirm','expected_fingerprint');
    if (array_diff(array_keys($data), $allowedRoot) || array_key_exists('expected_state_token', $data)) {
        $errors[] = 'Public Elementor header request has forbidden root fields.';
    }
    $payload = $data['payload'] ?? null;
    if (! is_array($payload) || array_diff(array_keys($payload), array('post_id','changes'))) {
        $errors[] = 'Header repair accepts only post_id and changes.';
    }
    if (! is_array($payload) || ! is_int($payload['post_id'] ?? null) || $payload['post_id'] < 1) {
        $errors[] = 'Header repair requires a positive post_id.';
    }
    $changes = is_array($payload) ? ($payload['changes'] ?? null) : null;
    if (! is_array($changes) || ! $changes || count($changes) > 4) {
        $errors[] = 'Header repair requires 1-4 exact link replacements.';
    } else {
        $siteHost = strtolower((string) parse_url((string) ($data['site_url'] ?? ''), PHP_URL_HOST));
        $seen = array();
        foreach ($changes as $change) {
            if (! is_array($change) || array_diff(array_keys($change), array('element_id','from','to'))) {
                $errors[] = 'Header repair change has forbidden keys.';
                continue;
            }
            $elementId = $change['element_id'] ?? null;
            if (! is_string($elementId) || ! preg_match('/^[a-zA-Z0-9_-]{5,64}\\z/', $elementId)
                || isset($seen[$elementId])) {
                $errors[] = 'Invalid or duplicate header widget ID.';
            } else $seen[$elementId] = true;
            foreach (array('from','to') as $field) {
                $url = $change[$field] ?? null;
                if (! is_string($url) || strlen($url) > 500 || ! filter_var($url, FILTER_VALIDATE_URL)
                    || parse_url($url, PHP_URL_SCHEME) !== 'https'
                    || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $siteHost
                    || ($field === 'to' && (null !== parse_url($url, PHP_URL_QUERY) || null !== parse_url($url, PHP_URL_FRAGMENT)))) {
                    $errors[] = 'Header URL must be same-site HTTPS, with no destination query or fragment.';
                }
            }
            if (($change['from'] ?? null) === ($change['to'] ?? null)) $errors[] = 'Header link must change to a different URL.';
        }
    }
    if (($data['dry_run'] ?? null) === true) {
        if (($data['confirm'] ?? null) !== false || array_key_exists('expected_fingerprint', $data)) {
            $errors[] = 'Header dry-run cannot carry confirmation fingerprint.';
        }
    } elseif (($data['dry_run'] ?? null) === false) {
        if (($data['confirm'] ?? null) !== true || ! is_string($data['expected_fingerprint'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}\\z/', $data['expected_fingerprint'])) {
            $errors[] = 'Header write requires confirmation and fresh dry-run fingerprint.';
        }
    } else $errors[] = 'Header repair requires explicit dry_run.';
    if ($errors) {
        foreach (array_unique($errors) as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: elementor.header_links.replace' . PHP_EOL;
    return;
}

// The full private integration catalog is intentionally NOT available in a
// public repository. This read-only action publishes only enumerated flags.
if ('plugin.audit.inspect' === $action) {
    $errors = array();
    $allowedRoot = array('version', 'site_url', 'request_id', 'action', 'payload', 'dry_run', 'confirm');
    if (array_diff(array_keys($data), $allowedRoot)) {
        $errors[] = 'Public plugin audit forbids extra request fields or state tokens.';
    }
    if (($data['version'] ?? null) !== 1
        || ! isset($data['site_url']) || ! is_string($data['site_url'])
        || ! filter_var($data['site_url'], FILTER_VALIDATE_URL)
        || 'https' !== strtolower((string) parse_url($data['site_url'], PHP_URL_SCHEME))) {
        $errors[] = 'Public plugin audit requires the versioned HTTPS site target.';
    }
    if (! isset($data['request_id']) || ! is_string($data['request_id'])
        || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{7,79}\\z/', $data['request_id'])) {
        $errors[] = 'Public plugin audit requires a bounded request ID.';
    }
    if (! array_key_exists('payload', $data) || ! is_array($data['payload']) || $data['payload']
        || ($data['dry_run'] ?? null) !== true || ($data['confirm'] ?? null) !== false) {
        $errors[] = 'Public plugin audit must have an empty payload, dry_run=true and confirm=false.';
    }
    if ($errors) {
        foreach ($errors as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: plugin.audit.inspect' . PHP_EOL;
    return;
}

if (in_array($action, array(
    'gtranslate.settings.inspect','gtranslate.settings.update',
    'yoast.site_representation.inspect','yoast.site_representation.update',
), true)) {
    $errors = array();
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    $isRead = false !== strpos($action, '.inspect');
    if (isset($data['expected_state_token'])) {
        $errors[] = 'Public provider configuration must never transport a state token.';
    }
    if ($isRead) {
        if ($payload || ($data['dry_run'] ?? null) !== true || ! empty($data['confirm'])
            || isset($data['expected_fingerprint'])) {
            $errors[] = 'Public provider settings inspection must be read-only and empty.';
        }
    } else {
        $isGTranslate = 'gtranslate.settings.update' === $action;
        $payloadAllowed = $isGTranslate ? array('fields','expected_before_fingerprint') : array('fields');
        if (array_diff(array_keys($payload), $payloadAllowed)) {
            $errors[] = 'Provider settings payload includes a forbidden field.';
        }
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (! $fields || count($fields) > ($isGTranslate ? 4 : 2)
            || array_keys($fields) === range(0, count($fields)-1)) {
            $errors[] = 'Provider settings require bounded named fields.';
        }
        foreach ($fields as $key => $value) {
            if ($isGTranslate) {
                if ($key === 'default_language') {
                    if (! in_array($value, array('nl','en'), true)) $errors[] = 'GTranslate source must be Dutch or English.';
                } elseif ($key === 'incl_langs' || $key === 'fincl_langs') {
                    if (! is_array($value) || $value !== array('nl','en')) $errors[] = 'GTranslate languages must be exactly nl/en.';
                } elseif ($key === 'detect_browser_language') {
                    if ($value !== 0 && $value !== 1) $errors[] = 'GTranslate detection must be 0 or 1.';
                } else {
                    $errors[] = 'Unexpected GTranslate provider field.';
                }
            } elseif ($key !== 'company_name' && $key !== 'org_description') {
                $errors[] = 'Unexpected Yoast organization field.';
            } elseif (! is_string($value) || trim($value) === '' || strlen($value) > 300
                || preg_match('/[\x00-\x1F\x7F<>]/', $value)) {
                $errors[] = 'Yoast organization text must be nonempty plain text.';
            }
        }
        $dryRun = $data['dry_run'] ?? null;
        if ($dryRun === true) {
            if (! empty($data['confirm']) || isset($data['expected_fingerprint'])
                || isset($payload['expected_before_fingerprint'])) {
                $errors[] = 'Provider settings dry-run must not carry confirmation fingerprints.';
            }
        } elseif ($dryRun === false) {
            $fingerprint = $data['expected_fingerprint'] ?? null;
            if (($data['confirm'] ?? null) !== true || ! is_string($fingerprint)
                || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
                $errors[] = 'Provider settings write requires confirmed dry-run fingerprint.';
            }
            if ($isGTranslate) {
                $payloadFingerprint = $payload['expected_before_fingerprint'] ?? null;
                if (! is_string($payloadFingerprint) || $payloadFingerprint !== $fingerprint) {
                    $errors[] = 'GTranslate provider and request fingerprints must match.';
                }
            }
        } else {
            $errors[] = 'Provider settings require explicit dry_run.';
        }
    }
    if ($errors) {
        foreach ($errors as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: ' . $action . PHP_EOL;
    return;
}

if ('site.identity.inspect' === $action || 'site.identity.update' === $action) {
    $errors = array();
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    if (isset($data['expected_state_token'])) {
        $errors[] = 'Public site identity cannot include site state tokens.';
    }
    if ('site.identity.inspect' === $action) {
        if ($payload || ($data['dry_run'] ?? null) !== true || ! empty($data['confirm'])
            || isset($data['expected_fingerprint'])) {
            $errors[] = 'Public site identity inspection is read-only with an empty payload.';
        }
    } else {
        if (array_diff(array_keys($payload), array('fields'))) {
            $errors[] = 'Public site identity accepts only fields.';
        }
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        $allowed = array('blogname','blogdescription','timezone_string','WPLANG');
        if (! $fields || count($fields) > 4 || array_keys($fields) === range(0, count($fields)-1)) {
            $errors[] = 'Site identity must contain 1–4 selected fields.';
        }
        foreach ($fields as $key => $value) {
            if (! in_array((string) $key, $allowed, true) || ! is_string($value)) {
                $errors[] = 'Unsupported public site identity field or type.';
                continue;
            }
            if ($key === 'blogname' || $key === 'blogdescription') {
                $max = 'blogname' === $key ? 100 : 240;
                if (trim($value) === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F<>]/', $value)) {
                    $errors[] = 'Public site identity must be nonempty plain text.';
                }
            } elseif ($key === 'timezone_string' && ! in_array($value, timezone_identifiers_list(), true)) {
                $errors[] = 'Public site identity timezone must be IANA.';
            } elseif ($key === 'WPLANG' && ! in_array($value, array('en_GB','en_US','nl_NL'), true)) {
                $errors[] = 'Public site identity locale is outside the allowlist.';
            }
        }
        if (($data['dry_run'] ?? null) === true) {
            if (! empty($data['confirm']) || isset($data['expected_fingerprint'])) {
                $errors[] = 'Site identity dry-run cannot be confirmed.';
            }
        } elseif (($data['dry_run'] ?? null) === false) {
            if (($data['confirm'] ?? null) !== true
                || ! isset($data['expected_fingerprint'])
                || ! is_string($data['expected_fingerprint'])
                || ! preg_match('/^[a-f0-9]{64}$/D', $data['expected_fingerprint'])) {
                $errors[] = 'Site identity write requires confirmed dry-run fingerprint.';
            }
        } else {
            $errors[] = 'Site identity request must specify dry_run.';
        }
    }
    if ($errors) {
        foreach ($errors as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: ' . $action . PHP_EOL;
    return;
}

if ('post.literal_replace' === $action) {
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    $errors = array();
    if (array_diff(array_keys($payload), array('id','find','replace','expected_matches'))) {
        $errors[] = 'Literal replacement accepts only id, find, replace and expected_matches.';
    }
    if (! isset($payload['id']) || ! is_int($payload['id']) || $payload['id'] <= 0) {
        $errors[] = 'Literal replacement requires an existing positive page ID.';
    }
    $find = isset($payload['find']) && is_string($payload['find']) ? $payload['find'] : '';
    $replace = isset($payload['replace']) && is_string($payload['replace']) ? $payload['replace'] : '';
    if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9 &.,_-]{2,79}$/D', $find)
        || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 &.,_-]{2,79}$/D', $replace)
        || $find === $replace) {
        $errors[] = 'Literal replacement requires two distinct plain-text phrases.';
    }
    $expected = $payload['expected_matches'] ?? null;
    if (null !== $expected && (! is_int($expected) || $expected < 1 || $expected > 25)) {
        $errors[] = 'Literal replacement expected_matches must be 1-25.';
    }
    if (isset($data['expected_state_token'])) {
        $errors[] = 'Public requests cannot use site state tokens.';
    }
    $dryRun = true === ($data['dry_run'] ?? true);
    if ($dryRun) {
        if (! empty($data['confirm']) || array_key_exists('expected_fingerprint', $data)) {
            $errors[] = 'Literal replacement dry-run must not confirm or supply a fingerprint.';
        }
    } else {
        if (true !== ($data['confirm'] ?? null) || null === $expected) {
            $errors[] = 'Literal replacement requires confirmation and exact expected_matches.';
        }
        $fp = $data['expected_fingerprint'] ?? null;
        if (! is_string($fp) || ! preg_match('/^[a-f0-9]{64}$/D', $fp)) {
            $errors[] = 'Literal replacement requires expected_fingerprint from the preceding dry-run.';
        }
    }
    if ($errors) {
        foreach ($errors as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: post.literal_replace' . PHP_EOL;
    return;
}

if ('connector.read_batch' === $action) {
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    $operations = isset($payload['operations']) && is_array($payload['operations']) ? $payload['operations'] : array();
    $safeReads = array('post.list', 'post.get', 'acf.field_groups', 'elementor.inspect', 'connector.update.check', 'maintenance.cache_capabilities', 'custom_css.inspect');
    if (array_keys($payload) !== array('operations') || ! $operations || count($operations) > 25) {
        fwrite(STDERR, "Public read batch requires 1-25 operations.\n");
        exit(1);
    }
    foreach ($operations as $index => $operation) {
        $leaf = is_array($operation) && isset($operation['action']) && is_string($operation['action']) ? $operation['action'] : '';
        if (! in_array($leaf, $safeReads, true) || ! isset($operation['payload']) || ! is_array($operation['payload'])
            || array_diff(array_keys($operation), array('action', 'payload'))) {
            fwrite(STDERR, 'Unsafe public read batch operation at index ' . $index . ".\n");
            exit(1);
        }
    }
}

if ('connector.update.apply' === $action) {
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    // Preserve the optional legacy boolean for existing callers without
    // requiring a restore attestation. Runner still enforces confirmation,
    // permissions, and the dry-run fingerprint for live operations.
    if (array_diff(array_keys($payload), array('restore_verified'))
        || (isset($payload['restore_verified']) && ! is_bool($payload['restore_verified']))) {
        fwrite(STDERR, "Connector self-update accepts only the optional boolean restore_verified compatibility field.\n");
        exit(1);
    }
}

if ('connector.batch' === $action) {
    $operations = isset($data['payload']['operations']) && is_array($data['payload']['operations']) ? $data['payload']['operations'] : array();
    $confirmedBatch = isset($data['dry_run']) && false === $data['dry_run'];
    foreach ($operations as $index => $operation) {
        $nestedAction = is_array($operation) && isset($operation['action']) ? (string) $operation['action'] : '';
        if (! in_array($nestedAction, array('post.update', 'acf.update'), true)) {
            fwrite(STDERR, 'Public connector.batch operation is not allowed at index ' . $index . ': ' . $nestedAction . "\n");
            exit(1);
        }
        $fingerprint = is_array($operation) ? ($operation['expected_fingerprint'] ?? null) : null;
        if ($confirmedBatch && null === $fingerprint) {
            fwrite(STDERR, 'Confirmed public connector.batch requires expected_fingerprint at index ' . $index . ".\n");
            exit(1);
        }
        if (null !== $fingerprint && (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}\\z/', $fingerprint))) {
            fwrite(STDERR, 'Invalid public batch expected_fingerprint at index ' . $index . ".\n");
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

if ('theme.install_package' === $action) {
    $payload = $data['payload'] ?? null;
    $errors = array();
    if (! is_array($payload) || array_diff(array_keys($payload), array('source_path','sha256','expected_stylesheet','overwrite'))
        || count($payload) !== 4
        || ! is_string($payload['source_path'] ?? null)
        || ! preg_match('#^theme-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.zip$#D', $payload['source_path'])
        || ! is_string($payload['sha256'] ?? null)
        || ! preg_match('/^[a-f0-9]{64}$/D', $payload['sha256'])
        || ! is_string($payload['expected_stylesheet'] ?? null)
        || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/D', $payload['expected_stylesheet'])
        || ($payload['overwrite'] ?? null) !== false) {
        $errors[] = 'Public theme ZIP installs allow only a new theme with verified identity, checksum and overwrite=false.';
    }
    if (array_key_exists('expected_state_token', $data) && null !== $data['expected_state_token']) {
        $errors[] = 'Public theme requests must not contain private state tokens.';
    }
    if (($data['dry_run'] ?? null) === true) {
        if (($data['confirm'] ?? null) !== false || array_key_exists('expected_fingerprint', $data)) {
            $errors[] = 'Public theme dry-run cannot be confirmed or include a fingerprint.';
        }
    } elseif (($data['dry_run'] ?? null) === false) {
        $fingerprint = $data['expected_fingerprint'] ?? null;
        if (($data['confirm'] ?? null) !== true || ! is_string($fingerprint)
            || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            $errors[] = 'Public theme install requires confirmation and preceding dry-run fingerprint.';
        }
    } else {
        $errors[] = 'Public theme install requires explicit dry_run flag.';
    }
    if ($errors) {
        foreach (array_unique($errors) as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo 'public runtime request OK: theme.install_package' . PHP_EOL;
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
        if (! in_array((string) $key, array('layer', 'regenerate'), true)) {
            $errors[] = 'Cache flush payload has unsupported key: ' . (string) $key;
        }
    }

    $layer = isset($payload['layer']) && is_string($payload['layer']) ? $payload['layer'] : '';
    if (! in_array($layer, array('elementor','wp_rocket','asset_cleanup'), true)) {
        $errors[] = 'Cache flush layer must be elementor, wp_rocket or asset_cleanup.';
    }
    if (array_key_exists('regenerate', $payload)) {
        if (! is_bool($payload['regenerate']) || ('elementor' !== $layer && true === $payload['regenerate'])) {
            $errors[] = 'Cache regeneration must be boolean and is supported only for Elementor.';
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
