<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\REST {
    function unlink(string $path): bool
    {
        return empty($GLOBALS['deny_wpconn_cleanup']) ? \unlink($path) : false;
    }
    function error_log(string $message): bool
    {
        $GLOBALS['wpconn_cleanup_log'][] = $message;
        return true;
    }
}

namespace Webactueel\WordPressConnector\Runtime {
    final class Request
    {
        private string $requestId;
        private function __construct(string $requestId) { $this->requestId = $requestId; }
        public static function fromArray(array $data): self { return new self($data['id']); }
        public function id(): string { return $this->requestId; }
    }
    final class Runner
    {
        public function run(Request $request, array $context): array
        {
            return array('ok' => true, 'id' => $request->id());
        }
    }
}

namespace {
    if (! defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    $base = sys_get_temp_dir() . '/wpconnector-rest-cleanup-' . bin2hex(random_bytes(6));
    define('WPCONNECTOR_ASSET_ROOT', $base);
    function home_url(string $path = ''): string { return 'https://example.test/' . ltrim($path, '/'); }
    function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0700, true); }
    function get_current_user_id(): int { return 1; }

    class WP_Error
    {
        public string $code;
        public string $message;
        public array $data;
        public function __construct(string $code, string $message, array $data = array())
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
    }

    class WP_REST_Request
    {
        public function get_body(): string { return '{"id":"request-12345678"}'; }
        public function get_json_params(): array { return array('id' => 'request-12345678'); }
    }
    class WP_REST_Response
    {
        public array $data;
        public int $status;
        public function __construct($data, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }
    }

    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/REST/AssetStore.php';
    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/REST/Controller.php';

    $store = new \Webactueel\WordPressConnector\REST\AssetStore();
    $root = $store->rootForRequest('request-12345678', true);
    $file = $root . '/secret.txt';
    file_put_contents($file, 'private fixture');
    chmod($file, 0600);
    $controller = new \Webactueel\WordPressConnector\REST\Controller(
        new \Webactueel\WordPressConnector\Runtime\Runner(),
        $store
    );

    $GLOBALS['deny_wpconn_cleanup'] = true;
    $GLOBALS['wpconn_cleanup_log'] = array();
    $response = $controller->execute(new WP_REST_Request());
    if (! $response instanceof WP_REST_Response || 200 !== $response->status
        || true !== ($response->data['ok'] ?? false)) {
        throw new RuntimeException('Successful guarded operation lost its result on failed cleanup.');
    }
    if ($GLOBALS['wpconn_cleanup_log'] !== array('WordPress Connector could not clear private request assets.')) {
        throw new RuntimeException('Private file cleanup failure was hidden or leaked request-specific information.');
    }
    if (! is_file($file)) {
        throw new RuntimeException('Failed cleanup test unexpectedly removed the private request asset.');
    }

    $GLOBALS['deny_wpconn_cleanup'] = false;
    $store->cleanup('request-12345678');

    // A private storage misconfiguration must return a safe REST error,
    // rather than escaping as an unhandled PHP exception or exposing paths.
    define('ABSPATH', $base . '/');
    $failure = $controller->execute(new WP_REST_Request());
    if (! $failure instanceof WP_Error || 'wpconnector_asset_storage_unavailable' !== $failure->code
        || 503 !== ($failure->data['status'] ?? null)
        || 'Private request asset storage is unavailable.' !== $failure->message) {
        throw new RuntimeException('Private storage failure did not fail closed with a generic REST response.');
    }
    rmdir($base);
    echo "REST cleanup logging and private-storage error boundaries OK\n";
}
