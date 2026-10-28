<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\REST {
    function chmod(string $path, int $mode): bool
    {
        $GLOBALS['wpconn_base_chmod_paths'][] = $path;
        return true; // Never change the unrelated target's mode during this test.
    }
}

namespace {
    if (! defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    $outside = sys_get_temp_dir() . '/wpconn-unrelated-' . bin2hex(random_bytes(6));
    $alias = sys_get_temp_dir() . '/wpconn-root-alias-' . bin2hex(random_bytes(6));
    if (! mkdir($outside, 0755) || ! symlink($outside, $alias)) {
        throw new RuntimeException('Could not set up isolated asset-root symlink test.');
    }
    define('WPCONNECTOR_ASSET_ROOT', $alias);
    function home_url(string $path = ''): string { return 'https://example.test/' . ltrim($path, '/'); }
    function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0700, true); }

    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/REST/AssetStore.php';
    $store = new \Webactueel\WordPressConnector\REST\AssetStore();
    $GLOBALS['wpconn_base_chmod_paths'] = array();
    $deniedCreate = false;
    try {
        $store->rootForRequest('request-12345678', true);
    } catch (RuntimeException $error) {
        $deniedCreate = 'Refusing symbolic link connector asset base directory.' === $error->getMessage();
    }
    $deniedCleanup = false;
    try {
        $store->cleanupExpired(3600);
    } catch (RuntimeException $error) {
        $deniedCleanup = 'Refusing symbolic link connector asset base directory.' === $error->getMessage();
    }
    $requestRoot = $outside . '/' . hash('sha256', home_url('/') . '|request-12345678');
    $createdOutside = is_dir($requestRoot);
    $touchedAlias = in_array($alias, $GLOBALS['wpconn_base_chmod_paths'], true);
    if ($createdOutside) {
        rmdir($requestRoot); // Cleanup test fixture, even after an intentionally failed baseline.
    }
    unlink($alias);
    rmdir($outside);

    if (! $deniedCreate || ! $deniedCleanup || $touchedAlias || $createdOutside) {
        throw new RuntimeException('A symlinked asset base escaped private-storage isolation.');
    }
    echo "Private asset root rejects symlink aliases without changing outside directory\n";
}
