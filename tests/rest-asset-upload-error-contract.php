<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\REST {
    function is_uploaded_file(string $path): bool { return is_file($path); }
}

namespace Webactueel\WordPressConnector\Runtime {
    final class Runner {}
}

namespace {
    if (! defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    $base = sys_get_temp_dir() . '/wpconn-private-' . bin2hex(random_bytes(6));
    define('WPCONNECTOR_ASSET_ROOT', $base);
    function home_url(string $path = ''): string { return 'https://example.test/' . ltrim($path, '/'); }
    function wp_mkdir_p(string $path): bool
    {
        if (! empty($GLOBALS['wpconn_storage_broken'])) {
            throw new RuntimeException('Storage location is unavailable at ' . $path);
        }
        return is_dir($path) || mkdir($path, 0700, true);
    }
    function wp_check_filetype(string $filename, array $allow): array
    {
        return 'sample.json' === $filename
            ? array('ext' => 'json', 'type' => 'application/json')
            : array('ext' => '', 'type' => '');
    }
    function get_allowed_mime_types(): array { return array('json' => 'application/json'); }

    class WP_REST_Request
    {
        private $filename;
        private $temp;
        public function __construct(string $filename, string $temp)
        {
            $this->filename = $filename;
            $this->temp = $temp;
        }
        public function get_file_params(): array
        {
            return array('file' => array('error' => UPLOAD_ERR_OK, 'tmp_name' => $this->temp));
        }
        public function get_param(string $key)
        {
            return 'request_id' === $key ? 'request-12345678' : $this->filename;
        }
    }
    class WP_REST_Response
    {
        public array $data;
        public int $status;
        public function __construct($data, int $status = 200) { $this->data=$data; $this->status=$status; }
    }
    class WP_Error
    {
        public string $code;
        public string $message;
        public array $data;
        public function __construct(string $code, string $message, array $data = array())
        {
            $this->code=$code; $this->message=$message; $this->data=$data;
        }
    }

    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/REST/AssetStore.php';
    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/REST/Controller.php';
    $controller = new \Webactueel\WordPressConnector\REST\Controller(
        new \Webactueel\WordPressConnector\Runtime\Runner(),
        new \Webactueel\WordPressConnector\REST\AssetStore()
    );
    $temp = tempnam(sys_get_temp_dir(), 'wpconn-upload');
    file_put_contents($temp, '{"ok":true}');

    // Malformed client input retains a clear, stable 400 response.
    $invalid = $controller->uploadAsset(new WP_REST_Request('sample.exe', $temp));
    if (! $invalid instanceof WP_Error || 400 !== ($invalid->data['status'] ?? null)
        || 'wpconnector_asset_rejected' !== $invalid->code
        || 'Asset extension is not an allowed WordPress media type or connector plugin/theme package.' !== $invalid->message) {
        throw new RuntimeException('Invalid upload validation semantics changed unexpectedly.');
    }

    // A hosting/storage exception may contain full server paths and must never appear in REST JSON.
    $GLOBALS['wpconn_storage_broken'] = true;
    $fault = $controller->uploadAsset(new WP_REST_Request('sample.json', $temp));
    $GLOBALS['wpconn_storage_broken'] = false;
    unlink($temp);
    if (! $fault instanceof WP_Error || 503 !== ($fault->data['status'] ?? null)
        || 'wpconnector_asset_storage_unavailable' !== $fault->code
        || 'Private request asset storage is unavailable.' !== $fault->message
        || false !== strpos($fault->message, $base)) {
        throw new RuntimeException('REST upload leaked private storage details or returned the wrong HTTP status.');
    }
    echo "REST upload rejects invalid input and hides private storage paths\n";
}
