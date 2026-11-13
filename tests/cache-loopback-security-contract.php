<?php

declare(strict_types=1);

namespace WpAssetCleanUp\OptimiseAssets {
    final class OptimizeCommon
    {
        public static function clearCache(): void
        {
            ++$GLOBALS['wpc_loopback_flush_count'];
            $GLOBALS['wpc_loopback_transients'][WPACU_PLUGIN_ID . '_last_clear_cache'] = time();
            \do_action('wpacu_clear_cache_after');
        }
    }
}

namespace {
    use Webactueel\WordPressConnector\Adapters\CacheMaintenanceAdapter;

    define('WPACU_PLUGIN_ID', 'wpacu_test_id');

    final class LoopbackTestResponse extends \RuntimeException
    {
        public $ok;
        public $status;
        public $payload;

        public function __construct(bool $ok, int $status, array $payload)
        {
            parent::__construct('Captured WordPress JSON response.');
            $this->ok = $ok;
            $this->status = $status;
            $this->payload = $payload;
        }
    }

    $GLOBALS['wpc_loopback_transients'] = array();
    $GLOBALS['wpc_loopback_hooks'] = array();
    $GLOBALS['wpc_loopback_flush_count'] = 0;

    function get_transient(string $name)
    {
        return $GLOBALS['wpc_loopback_transients'][$name] ?? false;
    }

    function delete_transient(string $name): bool
    {
        unset($GLOBALS['wpc_loopback_transients'][$name]);
        return true;
    }

    function add_action(string $name, callable $callback, int $priority = 10, int $accepted = 1): void
    {
        $GLOBALS['wpc_loopback_hooks'][$name][] = $callback;
    }

    function remove_action(string $name, callable $callback, int $priority = 10): void
    {
        $GLOBALS['wpc_loopback_hooks'][$name] = array_values(array_filter(
            $GLOBALS['wpc_loopback_hooks'][$name] ?? array(),
            static function ($registered) use ($callback): bool {
                return $registered !== $callback;
            }
        ));
    }

    function do_action(string $name): void
    {
        foreach ($GLOBALS['wpc_loopback_hooks'][$name] ?? array() as $callback) {
            $callback();
        }
    }

    function nocache_headers(): void
    {
    }

    function wp_send_json_error(array $payload, int $status = 200): void
    {
        throw new LoopbackTestResponse(false, $status, $payload);
    }

    function wp_send_json_success(array $payload, int $status = 200): void
    {
        throw new LoopbackTestResponse(true, $status, $payload);
    }

    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/CacheMaintenanceAdapter.php';

    $adapter = new CacheMaintenanceAdapter();
    $token = str_repeat('ab12', 16);
    $key = substr(hash('sha256', $token), 0, 32);
    $transient = 'wpconnector_ac_flush_' . $key;
    $digest = hash('sha256', $token);

    $request = static function (array $headers) use ($adapter): LoopbackTestResponse {
        $_SERVER = $headers;
        try {
            $adapter->handleAssetCleanupLoopback();
        } catch (LoopbackTestResponse $response) {
            return $response;
        }
        throw new \RuntimeException('The cache loopback did not return a JSON response.');
    };
    $validHeaders = array(
        'REQUEST_METHOD' => 'POST',
        'HTTP_X_WPCONNECTOR_CACHE_KEY' => strtoupper($key),
        'HTTP_X_WPCONNECTOR_CACHE_TOKEN' => strtoupper($token),
    );
    $reject = static function (array $headers, int $status) use ($request): void {
        $response = $request($headers);
        if ($response->ok !== false || $response->status !== $status) {
            throw new \RuntimeException('Invalid or unauthorized cache loopback was accepted.');
        }
    };

    $GLOBALS['wpc_loopback_transients'][$transient] = $digest;
    $reject(array_merge($validHeaders, array('REQUEST_METHOD' => 'GET')), 405);
    $reject(array_merge($validHeaders, array('REQUEST_METHOD' => array('POST'))), 405);
    $reject(array_merge($validHeaders, array('HTTP_X_WPCONNECTOR_CACHE_KEY' => array($key))), 403);
    $reject(array_merge($validHeaders, array('HTTP_X_WPCONNECTOR_CACHE_TOKEN' => array($token))), 403);
    $reject(array_merge($validHeaders, array('HTTP_X_WPCONNECTOR_CACHE_TOKEN' => '\\' . $token)), 403);
    $reject(array_merge($validHeaders, array('HTTP_X_WPCONNECTOR_CACHE_KEY' => $key . "\n")), 403);
    if (($GLOBALS['wpc_loopback_transients'][$transient] ?? null) !== $digest || $GLOBALS['wpc_loopback_flush_count'] !== 0) {
        throw new \RuntimeException('Invalid request consumed the loopback authorization or flushed cache.');
    }

    $GLOBALS['wpc_loopback_transients'][$transient] = str_repeat('f', 64);
    $reject($validHeaders, 403);
    if (isset($GLOBALS['wpc_loopback_transients'][$transient])) {
        throw new \RuntimeException('A one-time cache token was not consumed after a failed authorization.');
    }

    $GLOBALS['wpc_loopback_transients'][$transient] = $digest;
    $response = $request($validHeaders);
    if (
        $response->ok !== true || $response->status !== 200
        || ($response->payload['verified'] ?? null) !== true
        || $GLOBALS['wpc_loopback_flush_count'] !== 1
        || isset($GLOBALS['wpc_loopback_transients'][$transient])
    ) {
        throw new \RuntimeException('Valid one-time loopback failed to verify provider completion.');
    }

    $reject($validHeaders, 403);
    if ($GLOBALS['wpc_loopback_flush_count'] !== 1) {
        throw new \RuntimeException('Replay unexpectedly flushed cache twice.');
    }

    echo "one-time Asset CleanUp loopback authorization OK\n";
}
