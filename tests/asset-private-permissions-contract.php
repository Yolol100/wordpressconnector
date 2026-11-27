<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\REST {
    // Simulate filesystem permission failures without changing the real server.
    function chmod(string $path, int $mode): bool
    {
        $GLOBALS['wpconn_chmod_paths'][] = $path;
        if (($GLOBALS['wpconn_test_parent_alias'] ?? null) === $path) {
            return true; // Detect unsafe chmod-before-boundary without touching target.
        }
        if (($GLOBALS['wpconn_chmod_false_success'] ?? null) === $mode) {
            return true;
        }
        if (0600 === $mode && !empty($GLOBALS['wpconn_chmod_replace'])) {
            $bytes = file_get_contents($path);
            \rename($path, $path . '.original');
            file_put_contents($path, $bytes);
            return true;
        }
        if (($GLOBALS['deny_wpconn_chmod_mode'] ?? null) === $mode) {
            return false;
        }
        return \chmod($path, $mode);
    }

    // Simulate a failed directory timestamp refresh without changing the host.
    function touch(string $path): bool
    {
        $GLOBALS['wpconn_touch_paths'][] = $path;
        if (! empty($GLOBALS['deny_wpconn_touch'])) {
            return false;
        }
        return \touch($path);
    }

    // Model a cleanup permission error without weakening the real filesystem.
    function unlink(string $path): bool
    {
        if (! empty($GLOBALS['deny_wpconn_cleanup'])) {
            return false;
        }
        switch ($GLOBALS['wpconn_cleanup_mode'] ?? '') {
            case 'true_without_removal': return true;
            case 'false_after_removal': \unlink($path); return false;
            case 'throw_after_removal': \unlink($path); throw new \RuntimeException('Synthetic cleanup error.');
        }
        return \unlink($path);
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
        return 1 === preg_match('/\.json\z/', $filename)
            ? array('ext' => 'json', 'type' => 'application/json')
            : array('ext' => '', 'type' => '');
    }
    function get_allowed_mime_types(): array { return array('json' => 'application/json'); }

    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/class-policy.php';
    require_once __DIR__ . '/fixtures/direct-file-delete-fixture.php';
    $GLOBALS['wpconn_direct_file_delete'] = '\\Webactueel\\WordPressConnector\\REST\\unlink';
    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/REST/class-assetstore.php';

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

    // A cleanup return value alone must not determine whether private
    // upload rollback succeeded. Test both lying and late-failure backends.
    foreach (array('true_without_removal', 'false_after_removal', 'throw_after_removal') as $mode) {
        $GLOBALS['wpconn_cleanup_mode'] = $mode;
        $temp = tempnam(sys_get_temp_dir(), 'wpconn-cleanup');
        file_put_contents($temp, '{"fixture":true}');
        $message = '';
        try {
            $store->put($id, 'cleanup.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $temp));
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        } finally {
            unset($GLOBALS['wpconn_cleanup_mode']);
        }
        clearstatcache(true, $root . '/cleanup.json');
        $remaining = is_file($root . '/cleanup.json');
        if ($remaining) \unlink($root . '/cleanup.json');
        $expected = 'true_without_removal' === $mode
            ? 'Could not secure or remove uploaded request asset; manual recovery is required.'
            : 'Could not secure uploaded request asset.';
        if ($message !== $expected || $remaining !== ('true_without_removal' === $mode)) {
            throw new LogicException('Private upload cleanup readback failed: ' . $mode);
        }
    }

    $GLOBALS['deny_wpconn_chmod_mode'] = null;
    $temp = tempnam(sys_get_temp_dir(), 'wpconn-test');
    file_put_contents($temp, '{"ok":true}');
    $stored = $store->put($id, 'sample.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $temp));
    if (11 !== ($stored['bytes'] ?? null) || ! is_file($root . '/sample.json')
        || (fileperms($root . '/sample.json') & 0077) !== 0) {
        throw new RuntimeException('Successful HTTP upload lost secure file permissions.');
    }

    // A successful chmod return must not substitute for actual private mode.
    \chmod($base, 0755);
    $GLOBALS['wpconn_chmod_false_success'] = 0700;
    try {
        $rejected = false;
        try { $store->rootForRequest($id, true); }
        catch (RuntimeException $error) { $rejected = 'Could not secure connector asset base directory.' === $error->getMessage(); }
        if (!$rejected) throw new LogicException('False-success directory chmod was accepted.');
    } finally {
        unset($GLOBALS['wpconn_chmod_false_success']);
        \chmod($base, 0700);
    }
    // Keep the replacement even when its bytes match the originally moved upload.
    $temp = tempnam(sys_get_temp_dir(), 'wpconn-replacement');
    file_put_contents($temp, '{"replacement":true}');
    $GLOBALS['wpconn_chmod_replace'] = true;
    try {
        $rejected = false;
        try { $store->put($id,'replacement.json',array('error'=>UPLOAD_ERR_OK,'tmp_name'=>$temp)); }
        catch (RuntimeException $error) { $rejected = strpos($error->getMessage(),'manual recovery') !== false; }
        if (!$rejected || !is_file($root . '/replacement.json') || !is_file($root . '/replacement.json.original')
            || file_get_contents($root . '/replacement.json') !== '{"replacement":true}') {
            throw new LogicException('Foreign upload replacement was accepted or deleted.');
        }
    } finally {
        unset($GLOBALS['wpconn_chmod_replace']);
        foreach (array($root . '/replacement.json',$root . '/replacement.json.original') as $path) {
            if (is_file($path)) \unlink($path);
        }
    }

    // A nested upload must not be reported as successfully retained when
    // its root timestamp cannot be refreshed: cleanupExpired() uses that
    // timestamp even when a nested upload has just been created.
    $nested = $root . '/retention-test';
    if (! mkdir($nested, 0700)) {
        throw new RuntimeException('Could not set up nested retention fixture.');
    }
    \touch($root, time() - 7200);
    $GLOBALS['wpconn_touch_paths'] = array();
    $GLOBALS['deny_wpconn_touch'] = true;
    $temp = tempnam(sys_get_temp_dir(), 'wpconn-retention');
    file_put_contents($temp, '{"retained":true}');
    try {
        $store->put($id, 'retention-test/failed.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $temp));
        throw new RuntimeException('An upload with failed retention was reported successful.');
    } catch (RuntimeException $error) {
        if ('Could not refresh uploaded asset retention; the new upload was removed.' !== $error->getMessage()) {
            throw $error;
        }
    }
    if (is_file($nested . '/failed.json')
        || ! in_array($root, $GLOBALS['wpconn_touch_paths'], true)) {
        throw new RuntimeException('Failed retention left an untracked asset or skipped the expiry refresh.');
    }

    // If rollback is denied, do not claim that private bytes were removed.
    $GLOBALS['deny_wpconn_cleanup'] = true;
    $temp = tempnam(sys_get_temp_dir(), 'wpconn-retention');
    file_put_contents($temp, '{"retained":true}');
    try {
        $store->put($id, 'retention-test/manual.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $temp));
        throw new RuntimeException('Failed retention and cleanup were reported successful.');
    } catch (RuntimeException $error) {
        if ('Could not refresh uploaded asset retention or remove the new file; manual cleanup is required.' !== $error->getMessage()) {
            throw $error;
        }
    } finally {
        $GLOBALS['deny_wpconn_cleanup'] = false;
        $GLOBALS['deny_wpconn_touch'] = false;
    }
    if (! is_file($nested . '/manual.json')
        || (fileperms($nested . '/manual.json') & 0077) !== 0) {
        throw new RuntimeException('Failed private cleanup lost the retained file or its permissions.');
    }
    \unlink($nested . '/manual.json');
    \rmdir($nested);
    \touch($root);

    // file_exists() returns false for broken symlinks. Never overwrite them.
    $dangling = $root . '/dangling.json';
    $missingTarget = sys_get_temp_dir() . '/wpconn-no-such-target-' . bin2hex(random_bytes(6));
    if (! symlink($missingTarget, $dangling)) {
        throw new RuntimeException('Could not create dangling upload target test.');
    }
    $danglingTemp = tempnam(sys_get_temp_dir(), 'wpconn-dangle');
    file_put_contents($danglingTemp, '{"valid":true}');
    $danglingRejected = false;
    try {
        $store->put($id, 'dangling.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $danglingTemp));
    } catch (RuntimeException $error) {
        $danglingRejected = 'Duplicate request asset path: dangling.json' === $error->getMessage();
    }
    $originalAliasStillPresent = is_link($dangling);
    $outsideWasWritten = file_exists($missingTarget);
    if (is_file($danglingTemp)) {
        \unlink($danglingTemp);
    }
    if (is_link($dangling)) {
        \unlink($dangling);
    }
    if (! $danglingRejected || ! $originalAliasStillPresent || $outsideWasWritten) {
        throw new RuntimeException('Dangling symlink asset target was not rejected.');
    }

    // An indirect symlink entirely inside the private root is forbidden too:
    // otherwise upload paths can alias a sibling tree without detection.
    $inside = $root . '/inner-genuine';
    if (! mkdir($inside, 0700) || ! mkdir($inside . '/deep', 0700)
        || ! symlink($inside, $root . '/inner-alias')) {
        throw new RuntimeException('Could not set up indirect parent symlink fixture.');
    }
    $normalUpload = tempnam(sys_get_temp_dir(), 'wpconn-inner-normal');
    file_put_contents($normalUpload, '{"normal":true}');
    $normalStored = $store->put($id, 'inner-genuine/deep/normal.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $normalUpload));
    if (15 !== ($normalStored['bytes'] ?? null) || ! is_file($inside . '/deep/normal.json')) {
        throw new RuntimeException('Ordinary nested upload unexpectedly failed.');
    }
    $indirectUpload = tempnam(sys_get_temp_dir(), 'wpconn-inner-alias');
    file_put_contents($indirectUpload, '{"alias":true}');
    $indirectRejected = false;
    try {
        $store->put($id, 'inner-alias/deep/alias.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $indirectUpload));
    } catch (RuntimeException $error) {
        $indirectRejected = 'Symlink assets are not allowed.' === $error->getMessage();
    }
    $aliasWrote = file_exists($inside . '/deep/alias.json');
    if ($aliasWrote) \unlink($inside . '/deep/alias.json');
    if (is_file($indirectUpload)) \unlink($indirectUpload);
    \unlink($inside . '/deep/normal.json');
    \unlink($root . '/inner-alias');
    \rmdir($inside . '/deep');
    \rmdir($inside);
    if (! $indirectRejected || $aliasWrote) {
        throw new RuntimeException('Indirect symlink to a sibling asset directory was accepted.');
    }

    // The parent path must be validated before chmod, so a private-root
    // symlink cannot change the permissions of an unrelated directory.
    $outside = sys_get_temp_dir() . '/wpconn-outside-' . bin2hex(random_bytes(6));
    mkdir($outside, 0700);
    $parentAlias = $root . '/external';
    if (! symlink($outside, $parentAlias)) {
        throw new RuntimeException('Could not create isolated parent symlink.');
    }
    $outsideUpload = tempnam(sys_get_temp_dir(), 'wpconn-outside-file');
    file_put_contents($outsideUpload, '{"ok":true}');
    $GLOBALS['wpconn_test_parent_alias'] = $parentAlias;
    $GLOBALS['wpconn_chmod_paths'] = array();
    try {
        $store->put($id, 'external/sample.json', array('error' => UPLOAD_ERR_OK, 'tmp_name' => $outsideUpload));
        throw new RuntimeException('Symlinked external asset parent unexpectedly accepted.');
    } catch (RuntimeException $error) {
        if ('Symlink assets are not allowed.' !== $error->getMessage()) {
            throw $error;
        }
    }
    if (in_array($parentAlias, $GLOBALS['wpconn_chmod_paths'], true)) {
        throw new RuntimeException('External asset path was chmod-ed before boundary validation.');
    }
    $GLOBALS['wpconn_test_parent_alias'] = null;
    \unlink($outsideUpload);
    \unlink($parentAlias);
    \rmdir($outside);

    // A failed cleanup must not report success while the private upload remains.
    $GLOBALS['deny_wpconn_cleanup'] = true;
    try {
        $store->cleanup($id);
        throw new RuntimeException('Failed asset deletion silently succeeded.');
    } catch (RuntimeException $error) {
        if ('Could not remove private request asset.' !== $error->getMessage()) {
            throw $error;
        }
    }
    if (! is_file($root . '/sample.json')) {
        throw new RuntimeException('Denied cleanup unexpectedly removed the asset.');
    }
    $GLOBALS['deny_wpconn_cleanup'] = false;
    $store->cleanup($id);

    // A request-root symlink must never allow deleting another request's files.
    $foreignId = 'request-foreign12345';
    $foreignRoot = $store->rootForRequest($foreignId, true);
    $sentinel = $foreignRoot . '/keep.txt';
    file_put_contents($sentinel, 'other request');
    $aliasId = 'request-alias12345';
    $aliasRoot = $store->rootForRequest($aliasId, false);
    if (! symlink($foreignRoot, $aliasRoot)) {
        throw new RuntimeException('Test symlink could not be created.');
    }
    try {
        $store->cleanup($aliasId);
        throw new RuntimeException('Cleanup followed a foreign request symlink.');
    } catch (RuntimeException $error) {
        if ('Refusing symbolic link request asset root.' !== $error->getMessage()) {
            throw $error;
        }
    }
    if (! is_file($sentinel)) {
        throw new RuntimeException('Symlink cleanup erased foreign request data.');
    }
    try {
        $store->rootForRequest($aliasId, true);
        throw new RuntimeException('Request-root symlink accepted for new uploads.');
    } catch (RuntimeException $error) {
        if ('Refusing symbolic link request asset root.' !== $error->getMessage()) {
            throw $error;
        }
    }
    \unlink($aliasRoot);
    $store->cleanup($foreignId);

    // Expired cleanup may touch only 64-hex request directories, never
    // unrelated directories within an administrator-configured asset root.
    $unrelated = $base . '/other-app-data';
    mkdir($unrelated, 0700);
    file_put_contents($unrelated . '/keep.txt', 'not connector data');
    touch($unrelated, time() - 7200);
    $staleId = 'request-stale12345';
    $staleRoot = $store->rootForRequest($staleId, true);
    file_put_contents($staleRoot . '/old.txt', 'old');
    touch($staleRoot, time() - 7200);
    $freshRoot = $store->rootForRequest('request-fresh12345', true);
    file_put_contents($freshRoot . '/fresh.txt', 'fresh');
    $staleFile = $base . '/' . str_repeat('f', 64);
    file_put_contents($staleFile, 'not a directory');
    touch($staleFile, time() - 7200);
    $staleAlias = $base . '/' . str_repeat('e', 64);
    if (!symlink($unrelated, $staleAlias)) { throw new RuntimeException('Cannot create expiry symlink fixture.'); }
    $store->cleanupExpired(3600);
    if (!is_file($unrelated . '/keep.txt') || is_dir($staleRoot) ||
        !is_file($freshRoot . '/fresh.txt') || !is_file($staleFile) || !is_link($staleAlias)) {
        throw new RuntimeException('Expired cleanup touched fresh, unrelated or non-directory data, or kept expired connector assets.');
    }
    \unlink($staleAlias);
    \unlink($staleFile);
    $store->cleanup('request-fresh12345');
    \unlink($unrelated . '/keep.txt');
    \rmdir($unrelated);
    \rmdir($base);
    echo "Private asset permissions, symlink isolation and guarded cleanup OK\n";
}
