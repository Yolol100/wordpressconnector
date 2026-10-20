<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters {
    // Intercept the real adapter's filesystem calls to enforce its read limit.
    function file_get_contents(...$args) {
        if (5 !== count($args) || 131073 !== (int) $args[4]) {
            throw new \RuntimeException('Unbounded filesystem read reached real I/O.');
        }
        ++$GLOBALS['bounded_reads'];
        return \file_get_contents(...$args);
    }
    function filesize($path) {
        return ! empty($GLOBALS['simulate_stale_size']) ? 1 : \filesize($path);
    }
}

namespace {
    $root = dirname(__DIR__);
    $temp = sys_get_temp_dir() . '/wpconnector-io-' . bin2hex(random_bytes(6));
    $directory = $temp . '/wp-content/plugins/qa-fixture';
    if (! mkdir($directory, 0700, true)) {
        throw new \RuntimeException('Cannot create disposable WordPress fixture.');
    }
    define('ABSPATH', $temp . '/');
    $target = $directory . '/fixture.txt';
    $relative = 'wp-content/plugins/qa-fixture/fixture.txt';
    $GLOBALS['bounded_reads'] = 0;
    $GLOBALS['simulate_stale_size'] = false;

    function current_user_can(string $capability): bool {
        return 'edit_plugins' === $capability;
    }

    require_once $root . '/plugin/wordpressconnector/includes/Security/FilesystemPolicy.php';
    require_once $root . '/plugin/wordpressconnector/includes/Security/Policy.php';
    require_once $root . '/plugin/wordpressconnector/includes/Support/Json.php';
    require_once $root . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
    require_once $root . '/plugin/wordpressconnector/includes/Adapters/FilesystemAdapter.php';

    $adapter = new \Webactueel\WordPressConnector\Adapters\FilesystemAdapter();
    $deny = static function (callable $call, string $errorFragment): void {
        try {
            $call();
        } catch (\RuntimeException $e) {
            if (false !== strpos($e->getMessage(), $errorFragment)) return;
            throw new \RuntimeException('Wrong refusal: ' . $e->getMessage(), 0, $e);
        }
        throw new \RuntimeException('Oversized file was accepted.');
    };

    try {
        file_put_contents($target, str_repeat('a', 131072));
        $read = $adapter->readText(array('path' => $relative));
        if (131072 !== strlen($read['content']) || 131072 !== $read['file']['bytes']) {
            throw new \RuntimeException('Valid max-size read failed.');
        }
        $preview = $adapter->writeText(array('path' => $relative, 'content' => 'replacement'), array('dry_run' => true));
        if (131072 !== $preview['before']['bytes'] || 11 !== $preview['after']['bytes']) {
            throw new \RuntimeException('Valid write dry-run failed.');
        }
        $deletion = $adapter->deleteText(array('path' => $relative), array('dry_run' => true));
        if (131072 !== $deletion['before']['bytes'] || ! file_exists($target)) {
            throw new \RuntimeException('Valid delete dry-run failed.');
        }

        file_put_contents($target, str_repeat('b', 131073));
        clearstatcache(true, $target);
        $GLOBALS['simulate_stale_size'] = true;
        $deny(static function () use ($adapter, $relative): void {
            $adapter->readText(array('path' => $relative));
        }, 'read limit');
        $deny(static function () use ($adapter, $relative): void {
            $adapter->writeText(array('path' => $relative, 'content' => 'replacement'), array('dry_run' => true));
        }, 'write limit');
        $deny(static function () use ($adapter, $relative): void {
            $adapter->deleteText(array('path' => $relative), array('dry_run' => true));
        }, 'safe text limit');
        if ($GLOBALS['bounded_reads'] < 6 || 131073 !== \filesize($target)) {
            throw new \RuntimeException('Bounded read coverage or no-write proof failed.');
        }
        echo "Filesystem bounded IO and stale-filesize rejection OK\n";
    } finally {
        @unlink($target);
        @rmdir($directory);
        @rmdir(dirname($directory));
        @rmdir(dirname(dirname($directory)));
        @rmdir($temp);
    }
}
