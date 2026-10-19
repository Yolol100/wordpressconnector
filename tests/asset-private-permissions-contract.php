<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\REST {
    // Simulate filesystem permission failures without changing the real server.
    function chmod(string $path, int $mode): bool
    {
        if (($GLOBALS['deny_wpconn_chmod_mode'] ?? null) === $mode) {
            return false;
        }
        return \chmod($path, $mode);
    }

    // Isolated HTTP-upload test doubles, never part of the plugin ZIP.
    function is_uploaded_file(string $path): bool
    {
        return is_file($path);
    }

    function move_uploaded_file(string $source, string $destination): bool
    {
        return \rename($source, $destination);
    }
}

namespace {
    $base = sys_get_temp_dir() . '/wpconnector-permission-contract-' . bin2hex(random_bytes(6));
    define('WPCONNECTOR_ASSET_ROOT', $base);
    function home_url(string $path = ''): string { return 'https://example.test/' . ltrim($path, '/'); }
    function wp_mkdir_p(string $dir): bool { return is_dir($dir) || mkdir($dir, 0700, true); }
    function wp_check_filetype(string $filename, array $allowed): array
    {
        return 'sample.json' === $filename
            ? array('ext' => 'json', 'type' => 'application/json')
            : array('ext' => '', 'type' => '');
    }
    function get_allowed_mime_types(): array { return array('json' => 'application/json'); }

    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/REST/AssetStore.php';

    $store = new \Webactueel\WordPressConnector\REST\AssetStore();
    $id = 'request-12345678';
    $GLOBALS['deny_wpconn_chmod_mode'] = 0700;
    try {
        $store->rootForRequest($id, true);
        throw new RuntimeException('Insecure directory silently accepted.');
    } catch (RuntimeException $error) {
        if ('Could not secure connector asset base directory.' !== $error->getMessage()) {
            throw $error;
        }
    }

    $GLOBALS['deny_wpconn_chmod_mode'] = null;
    $root = $store->rootForRequest($id, true);
    if (! is_dir($root) || (fileperms($root) & 0077) !== 0
        || (fileperms($base) & 0077) !== 0) {
        throw new RuntimeException('Expected private 0700 asset directories.');
    }

    $temp = tempnam(sys_get_temp_dir(), 'wpconn-test');
    file_put_contents($temp, '{"ok":true}');
    $GLOBALS['deny_wpconn_chmod_mode'] = 0600;
    try {
        $store->put($id, 'sample.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $temp));
        throw new RuntimeException('Insecure uploaded file silently accepted.');
    } catch (RuntimeException $error) {
        if ('Could not secure uploaded request asset.' !== $error->getMessage()) {
            throw $error;
        }
    }
    if (is_file($root . '/sample.json')) {
        throw new RuntimeException('Rejected uploaded file was not removed.');
    }

    $GLOBALS['deny_wpconn_chmod_mode'] = null;
    $temp = tempnam(sys_get_temp_dir(), 'wpconn-test');
    file_put_contents($temp, '{"ok":true}');
    $stored = $store->put($id, 'sample.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $temp));
    if (11 !== ($stored['bytes'] ?? null) || ! is_file($root . '/sample.json')
        || (fileperms($root . '/sample.json') & 0077) !== 0) {
        throw new RuntimeException('Successful HTTP upload lost secure file permissions.');
    }

    $store->cleanup($id);
    rmdir($base);
    echo "Private asset permissions, denied uploads and cleanup OK\n";
}
