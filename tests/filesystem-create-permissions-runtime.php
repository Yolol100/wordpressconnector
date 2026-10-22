<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters {
    // Fail real filesystem operations at the operating-system boundary.
    function chmod($path, $mode): bool {
        ++$GLOBALS['wpconn_create_chmod_calls'];
        if (! empty($GLOBALS['wpconn_create_chmod_denied'])) {
            return false;
        }
        return \chmod($path, $mode);
    }
    function fclose($handle): bool {
        $closed = \fclose($handle);
        if (! empty($GLOBALS['wpconn_create_close_denied'])) {
            return false;
        }
        return $closed;
    }
    function file_get_contents($path, ...$args) {
        if (! empty($GLOBALS['wpconn_create_readback_corrupt'])) {
            return 'corrupted fixture bytes';
        }
        return \file_get_contents($path, ...$args);
    }
    function unlink($path): bool {
        if (! empty($GLOBALS['wpconn_create_cleanup_denied'])) {
            return false;
        }
        return \unlink($path);
    }
}

namespace {
    $base = dirname(__DIR__);
    $root = sys_get_temp_dir() . '/wpconnector-create-mode-' . bin2hex(random_bytes(6));
    $dir = $root . '/wp-content/plugins/fixture';
    $admin = $root . '/wp-admin/includes';
    if (! mkdir($dir, 0700, true) || ! mkdir($admin, 0700, true)) {
        throw new RuntimeException('Unable to prepare disposable filesystem fixture.');
    }
    file_put_contents($admin . '/file.php', "<?php\n");
    define('ABSPATH', $root . '/');

    class WP_Filesystem_Base {}
    $GLOBALS['wp_filesystem'] = new WP_Filesystem_Base();
    function current_user_can(string $capability): bool { return 'edit_plugins' === $capability; }
    function wp_is_file_mod_allowed(string $context): bool { return true; }
    function get_filesystem_method($args = array(), $context = ''): string { return 'direct'; }
    function WP_Filesystem($credentials = false, $context = ''): bool { return true; }

    require_once $base . '/plugin/wordpressconnector/includes/Security/Policy.php';
    require_once $base . '/plugin/wordpressconnector/includes/Security/FilesystemPolicy.php';
    require_once $base . '/plugin/wordpressconnector/includes/Support/Json.php';
    require_once $base . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
    require_once $base . '/plugin/wordpressconnector/includes/Adapters/FilesystemAdapter.php';

    $subject = new \Webactueel\WordPressConnector\Adapters\FilesystemAdapter();
    $relative = 'wp-content/plugins/fixture/new.txt';
    $absolute = $root . '/' . $relative;
    $content = 'Synthetic public text fixture.';
    $GLOBALS['wpconn_create_chmod_calls'] = 0;
    $GLOBALS['wpconn_create_chmod_denied'] = false;
    $GLOBALS['wpconn_create_close_denied'] = false;
    $GLOBALS['wpconn_create_cleanup_denied'] = false;
    $GLOBALS['wpconn_create_readback_corrupt'] = false;

    try {
        // An OS permission failure must not silently report a successful write.
        foreach (array('chmod', 'close') as $fault) {
            $GLOBALS['wpconn_create_chmod_denied'] = 'chmod' === $fault;
            $GLOBALS['wpconn_create_close_denied'] = 'close' === $fault;
            $rejected = false;
            try {
                $subject->createText(array('path' => $relative, 'content' => $content), array('dry_run' => false));
            } catch (RuntimeException $error) {
                $rejected = false !== strpos($error->getMessage(), 'write or permissions failed');
                if (! $rejected) {
                    throw new RuntimeException('Wrong error after ' . $fault . ' failure: ' . $error->getMessage(), 0, $error);
                }
            }
            if (! $rejected || file_exists($absolute)) {
                throw new RuntimeException('File creation reported success or left an unsafe file after ' . $fault . ' failure.');
            }
            $GLOBALS['wpconn_create_chmod_denied'] = false;
            $GLOBALS['wpconn_create_close_denied'] = false;
        }

        // When the OS also denies deletion, report manual recovery and never
        // claim the private filesystem target has been rolled back.
        $GLOBALS['wpconn_create_chmod_denied'] = true;
        $GLOBALS['wpconn_create_cleanup_denied'] = true;
        $manual = false;
        try {
            $subject->createText(array('path' => $relative, 'content' => $content), array('dry_run' => false));
        } catch (RuntimeException $error) {
            $manual = false !== strpos($error->getMessage(), 'manual recovery required');
            if (! $manual) {
                throw new RuntimeException('Cleanup failure must be explicit: ' . $error->getMessage(), 0, $error);
            }
        }
        if (! $manual || ! is_file($absolute)) {
            throw new RuntimeException('Failed cleanup was not surfaced as an incomplete rollback.');
        }
        $GLOBALS['wpconn_create_chmod_denied'] = false;
        $GLOBALS['wpconn_create_cleanup_denied'] = false;
        if (! \unlink($absolute)) {
            throw new RuntimeException('Could not remove disposable fixture after controlled cleanup failure.');
        }

        // Failed post-write readback needs verified removal, including when
        // the host disallows rollback cleanup.
        foreach (array(false, true) as $cleanupDenied) {
            $GLOBALS['wpconn_create_readback_corrupt'] = true;
            $GLOBALS['wpconn_create_cleanup_denied'] = $cleanupDenied;
            $readbackRejected = false;
            try {
                $subject->createText(array('path' => $relative, 'content' => $content), array('dry_run' => false));
            } catch (RuntimeException $error) {
                $expected = $cleanupDenied ? 'manual recovery required' : 'readback failed';
                $readbackRejected = false !== strpos($error->getMessage(), $expected);
                if (! $readbackRejected) {
                    throw new RuntimeException('Readback/cleanup failure was misleading: ' . $error->getMessage(), 0, $error);
                }
            }
            if (! $readbackRejected || is_file($absolute) !== $cleanupDenied) {
                throw new RuntimeException('Readback failure left incorrect cleanup state.');
            }
            $GLOBALS['wpconn_create_readback_corrupt'] = false;
            $GLOBALS['wpconn_create_cleanup_denied'] = false;
            if ($cleanupDenied && ! \unlink($absolute)) {
                throw new RuntimeException('Failed to remove disposable unreadable fixture.');
            }
        }

        $created = $subject->createText(array('path' => $relative, 'content' => $content), array('dry_run' => false));
        if (! is_file($absolute)
            || file_get_contents($absolute) !== $content
            || ($created['after']['sha256'] ?? '') !== hash('sha256', $content)
            || $GLOBALS['wpconn_create_chmod_calls'] < 3) {
            throw new RuntimeException('Successful exclusive text creation failed after negative tests.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && (fileperms($absolute) & 0777) !== 0644) {
            throw new RuntimeException('New text file has unexpected POSIX permissions.');
        }
        echo "Filesystem create fail-closed permission/close/cleanup and readback OK\n";
    } finally {
        $GLOBALS['wpconn_create_chmod_denied'] = false;
        $GLOBALS['wpconn_create_close_denied'] = false;
        $GLOBALS['wpconn_create_cleanup_denied'] = false;
        $GLOBALS['wpconn_create_readback_corrupt'] = false;
        if (is_file($absolute)) { \unlink($absolute); }
        \unlink($admin . '/file.php');
        \rmdir($admin);
        \rmdir(dirname($admin));
        \rmdir($dir);
        \rmdir(dirname($dir));
        \rmdir(dirname(dirname($dir)));
        \rmdir($root);
    }
}
