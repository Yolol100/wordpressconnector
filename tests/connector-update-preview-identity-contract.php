<?php
/**
 * Verify canonical self-update previews are bound to the exact GitHub
 * release SHA, not merely the installed version. Offline synthetic API.
 */
declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support {
    final class Fingerprint {
        public static function make(array $data): string {
            return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES));
        }
    }
}

namespace {
    define('WPCONNECTOR_VERSION', '1.20.11');
    $GLOBALS['release_digest'] = str_repeat('a', 64);
    $GLOBALS['release_tag'] = 'v1.20.12';
    $GLOBALS['http_calls'] = 0;

    function current_user_can(string $capability): bool {
        return $capability === 'update_plugins';
    }
    function is_wp_error($response): bool { return false; }
    function wp_remote_retrieve_response_code(array $response): int {
        return $response['response']['code'];
    }
    function wp_remote_retrieve_body(array $response): string {
        return $response['body'];
    }
    function wp_safe_remote_get(string $url, array $args): array {
        if ($url !== 'https://api.github.com/repos/Yolol100/wordpressconnector/releases/latest') {
            throw new \RuntimeException('Unexpected external source.');
        }
        ++$GLOBALS['http_calls'];
        $tag = $GLOBALS['release_tag'];
        $assets = array();
        foreach (array('wordpressconnector.zip', 'wordpressconnector.zip.sha256', 'wordpressconnector.spdx.json') as $name) {
            $assets[] = array(
                'name' => $name,
                'browser_download_url' => 'https://github.com/Yolol100/wordpressconnector/releases/download/' . $tag . '/' . $name,
                'digest' => 'sha256:' . ($name === 'wordpressconnector.zip' ? $GLOBALS['release_digest'] : str_repeat('b', 64)),
            );
        }
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array('tag_name' => $tag, 'draft' => false, 'prerelease' => false, 'assets' => $assets)),
        );
    }

    require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/class-connectorupdateadapter.php';
    $adapter = new \Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter();

    $first = $adapter->apply(array(), array('dry_run' => true));
    $fingerprint = $first['_current_fingerprint'] ?? '';
    if (($first['release_fingerprint'] ?? null) !== $fingerprint) {
        throw new \RuntimeException('Preview must disclose the same canonical fingerprint consumed by the guarded update.');
    }
    if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)
        || ($first['would_update_connector']['github_asset_digest'] ?? '') !== 'sha256:' . str_repeat('a', 64)) {
        throw new \RuntimeException('Self-update preview is missing the verified release digest identity.');
    }
    $again = $adapter->apply(array(), array('dry_run' => true));
    if ($again['_current_fingerprint'] !== $fingerprint) {
        throw new \RuntimeException('The same installed version and release must produce the same fingerprint.');
    }
    $GLOBALS['release_digest'] = str_repeat('c', 64);
    $changed = $adapter->apply(array(), array('dry_run' => true));
    if ($changed['_current_fingerprint'] === $fingerprint) {
        throw new \RuntimeException('A changed release asset digest must invalidate prior owner approval.');
    }
    $GLOBALS['release_digest'] = str_repeat('a', 64);
    $GLOBALS['release_tag'] = 'v1.20.13';
    $newTag = $adapter->apply(array(), array('dry_run' => true));
    if ($newTag['_current_fingerprint'] === $fingerprint) {
        throw new \RuntimeException('A changed GitHub release tag must invalidate prior owner approval.');
    }
    $GLOBALS['release_tag'] = 'v1.20.12';
    $GLOBALS['release_digest'] = str_repeat('c', 64);
    $failedClosed = false;
    try {
        $adapter->apply(array(), array(
            'dry_run' => false,
            'expected_prewrite_fingerprint' => $fingerprint,
        ));
    } catch (\RuntimeException $error) {
        $failedClosed = strpos($error->getMessage(), 'changed after approval') !== false;
    }
    if (! $failedClosed) {
        throw new \RuntimeException('A changed release was not rejected before any WordPress file operation.');
    }
    if ($GLOBALS['http_calls'] !== 5) {
        throw new \RuntimeException('Unexpected synthetic GitHub API request count.');
    }
    echo "signed release digest, tag and TOCTOU approval guards OK\n";
}
