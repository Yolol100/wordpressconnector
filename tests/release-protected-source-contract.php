<?php
/**
 * Ensure an OAuth Connector release is byte-identical to a commit reachable
 * from protected main, independently of replaceable release ZIP/checksum.
 */
declare(strict_types=1);

const RELEASE_TEST_COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const RELEASE_TEST_TREE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$GLOBALS['source_case'] = array(
    'mode' => 'valid',
    'calls' => array(),
    'trusted_body' => "<?php\n/**\n * Plugin Name: WordPress Connector\n * Version: 1.20.12\n */\n",
    'package_fixture' => null,
    'temporary_downloads' => array(),
    'deleted_downloads' => array(),
);

function is_wp_error($value): bool { return false; }
function wp_tempnam($name) {
    $path = tempnam(sys_get_temp_dir(), 'source-download-');
    $GLOBALS['source_case']['temporary_downloads'][] = $path;
    return $path;
}
function wp_delete_file($path) {
    $GLOBALS['source_case']['deleted_downloads'][] = $path;
    // Model Core's wp_delete_file filter redirect with a disposable sentinel.
    $path = $GLOBALS['source_case']['delete_redirect'] ?? $path;
    return !file_exists($path) || unlink($path);
}
function wp_remote_retrieve_response_code(array $response): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body(array $response): string { return (string) $response['body']; }
function wp_safe_remote_get(string $url, array $opts): array {
    $downloadRoot = 'https://github.com/Yolol100/wordpressconnector/releases/download/v1.20.12/';
    if ($url === $downloadRoot . 'wordpressconnector.zip.sha256') {
        $sha = hash_file('sha256', $GLOBALS['source_case']['package_fixture']);
        return array('response' => array('code' => 200), 'body' => $sha . "  wordpressconnector.zip\n");
    }
    if ($url === $downloadRoot . 'wordpressconnector.zip') {
        if (($opts['stream'] ?? false) !== true || ($opts['sslverify'] ?? false) !== true
            || ! is_string($opts['filename'] ?? null) || ($opts['limit_response_size'] ?? 0) !== 20971521) {
            throw new RuntimeException('Native package download did not enforce bounded TLS streaming.');
        }
        if (! copy($GLOBALS['source_case']['package_fixture'], $opts['filename'])) {
            throw new RuntimeException('Unable to stream synthetic canonical package.');
        }
        return array('response' => array('code' => 200), 'body' => '');
    }
    if ($opts['redirection'] !== 0 || $opts['sslverify'] !== true
        || ($opts['limit_response_size'] ?? 0) < 1 || ($opts['limit_response_size'] ?? 0) > 8388609) {
        throw new RuntimeException('Untrusted source HTTP settings.');
    }
    $base = 'https://api.github.com/repos/Yolol100/wordpressconnector';
    if (strpos($url, $base . '/') !== 0) {
        throw new RuntimeException('Attempted to contact a noncanonical repository API.');
    }
    if (strpos($url, '/compare/') !== false && substr($url, -11) !== '?per_page=1') {
        throw new RuntimeException('GitHub compare request must limit the commit listing.');
    }
    $GLOBALS['source_case']['calls'][] = $url;
    $mode = $GLOBALS['source_case']['mode'];
    if (strpos($url, '/compare/') !== false) {
        $payload = array(
            'status' => $mode === 'not_ancestor' ? 'diverged' : 'ahead',
            'merge_base_commit' => array(
                'sha' => $mode === 'bad_merge_base' ? str_repeat('c', 40) : RELEASE_TEST_COMMIT,
            ),
        );
        if ($mode === 'large_compare') $payload['files'] = str_repeat('x', 700000);
        if ($mode === 'oversized_compare') $payload['files'] = str_repeat('x', 8500000);
    } elseif (strpos($url, '/git/commits/') !== false) {
        $payload = array('tree' => array('sha' => RELEASE_TEST_TREE));
    } elseif (strpos($url, '/git/trees/') !== false) {
        $trusted = $GLOBALS['source_case']['trusted_body'];
        $blob = sha1('blob ' . strlen($trusted) . "\0" . $trusted);
        $payload = array(
            'truncated' => $mode === 'truncated',
            'tree' => array(array(
                'path' => 'plugin/wordpressconnector/wordpressconnector.php',
                'mode' => $mode === 'symlink' ? '120000' : '100644',
                'type' => 'blob',
                'sha' => $blob,
            )),
        );
    } else {
        throw new RuntimeException('Unexpected source API path.');
    }
    if ($mode === 'network_down') {
        return array('response' => array('code' => 503), 'body' => 'temporary outage');
    }
    // Match WordPress HTTP API limit_response_size truncation behavior.
    $body = json_encode($payload);
    if (strlen($body) > $opts['limit_response_size']) {
        $body = substr($body, 0, $opts['limit_response_size']);
    }
    return array('response' => array('code' => 200), 'body' => $body);
}

require_once __DIR__ . '/fixtures/direct-file-delete-fixture.php';
$GLOBALS['wpconn_direct_file_delete'] = static function ($path): bool {
    $GLOBALS['source_case']['deleted_downloads'][] = $path;
    return !file_exists($path) || unlink($path);
};
require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/class-connectorupdateadapter.php';

use Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter;

$zipPath = tempnam(sys_get_temp_dir(), 'connector-source-');
if (! is_string($zipPath)) { throw new RuntimeException('Unable to create an isolated ZIP.'); }
register_shutdown_function(static function() use ($zipPath): void { @unlink($zipPath); });
$adapter = new ConnectorUpdateAdapter();
$verify = new ReflectionMethod(ConnectorUpdateAdapter::class, 'verify_protected_source');
$verify->setAccessible(true);
$release = array('source_commit' => RELEASE_TEST_COMMIT);
$makeZip = static function(array $files) use ($zipPath): void {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not open test ZIP.');
    }
    foreach ($files as $path => $body) {
        $zip->addFromString($path, $body);
    }
    if (!$zip->close()) { throw new RuntimeException('Could not close test ZIP.'); }
};
$pass = static function() use ($adapter, $verify, $release, $zipPath): void {
    $verify->invoke($adapter, $release, $zipPath);
};
$deny = static function(string $mode, string $reason) use ($pass): void {
    $GLOBALS['source_case']['mode'] = $mode;
    $denied = false;
    try { $pass(); } catch (RuntimeException $error) { $denied = true; }
    if (!$denied) { throw new RuntimeException('Did not fail closed: ' . $reason); }
};
$makeZip(array('wordpressconnector/wordpressconnector.php' => $GLOBALS['source_case']['trusted_body']));
$pass();
if (count($GLOBALS['source_case']['calls']) !== 3) {
    throw new RuntimeException('A legitimate protected-source proof must use compare, Git commit and Git tree.');
}
$GLOBALS['source_case']['mode'] = 'large_compare';
$pass(); // A legitimate response above the old 512 KiB cap must still pass.
$GLOBALS['source_case']['mode'] = 'oversized_compare';
$oversizedDenied = false;
try { $pass(); } catch (RuntimeException $error) { $oversizedDenied = true; }
if (! $oversizedDenied) { throw new RuntimeException('Oversized GitHub API response must fail closed.'); }
$GLOBALS['source_case']['mode'] = 'valid';
foreach (array(
    'not_ancestor' => 'release commit not on main',
    'bad_merge_base' => 'release base mismatch',
    'truncated' => 'incomplete trusted source',
    'symlink' => 'untrusted symlink source',
    'network_down' => 'API outage',
) as $mode => $reason) {
    $deny($mode, $reason);
}
$GLOBALS['source_case']['mode'] = 'valid';
$makeZip(array('wordpressconnector/wordpressconnector.php' => '<?php echo "malicious release asset";'));
$deny('valid', 'attacker replaced ZIP and matching checksum without altering protected main');
$makeZip(array(
    'wordpressconnector/wordpressconnector.php' => $GLOBALS['source_case']['trusted_body'],
    'wordpressconnector/extra.php' => "<?php echo 'unexpected';",
));
$deny('valid', 'unexpected extra PHP file');
$makeZip(array('wordpressconnector/wordpressconnector.php' => $GLOBALS['source_case']['trusted_body']));
$invalidSourceRejected = false;
try {
    $verify->invoke($adapter, array('source_commit' => 'main'), $zipPath);
} catch (RuntimeException $error) {
    $invalidSourceRejected = true;
}
if (!$invalidSourceRejected) { throw new RuntimeException('Must refuse an unpinned release commit.'); }
// The native Plugins update must not accept a ZIP whose owner-published
// SHA-256/checksum asset agrees with the ZIP but whose bytes disagree with main.
$nativeCallback = new ReflectionMethod(ConnectorUpdateAdapter::class, 'verifiedNativeDownload');
$nativeSourceLines = file($nativeCallback->getFileName());
if (!is_array($nativeSourceLines) || !$nativeCallback->isPublic()
    || false === strpos(implode('', array_slice($nativeSourceLines,
        $nativeCallback->getStartLine() - 1, $nativeCallback->getEndLine() - $nativeCallback->getStartLine() + 1)),
        'download_protected_source_package( $release )')) {
    throw new RuntimeException('Native Update Now does not use the protected-source verifier.');
}
$protectedDownload = new ReflectionMethod(ConnectorUpdateAdapter::class, 'download_protected_source_package');
$protectedDownload->setAccessible(true);
$makeRelease = static function() use ($zipPath): array {
    $checksum = hash_file('sha256', $zipPath) . "  wordpressconnector.zip\n";
    $prefix = 'https://github.com/Yolol100/wordpressconnector/releases/download/v1.20.12/';
    return array(
        'source_commit' => RELEASE_TEST_COMMIT,
        'version' => '1.20.12',
        'package_url' => $prefix . 'wordpressconnector.zip',
        'checksum_url' => $prefix . 'wordpressconnector.zip.sha256',
        'package_digest' => 'sha256:' . hash_file('sha256', $zipPath),
        'checksum_digest' => 'sha256:' . hash('sha256', $checksum),
    );
};
$GLOBALS['source_case']['package_fixture'] = $zipPath;
$makeZip(array('wordpressconnector/wordpressconnector.php' => $GLOBALS['source_case']['trusted_body']));
$validPackage = $protectedDownload->invoke($adapter, $makeRelease());
if (!is_file($validPackage) || hash_file('sha256', $validPackage) !== hash_file('sha256', $zipPath)) {
    throw new RuntimeException('Protected-source native package should be usable for Core.');
}
wp_delete_file($validPackage);
$malicious = str_replace('*/', " * Added unreviewed release bytes\n */", $GLOBALS['source_case']['trusted_body']);
$makeZip(array('wordpressconnector/wordpressconnector.php' => $malicious));
$deniedTamper = false;
$cleanupsBefore = count($GLOBALS['source_case']['deleted_downloads']);
try {
    $protectedDownload->invoke($adapter, $makeRelease());
} catch (RuntimeException $error) {
    $deniedTamper = str_contains($error->getMessage(), 'protected main') ||
        str_contains($error->getMessage(), 'protected main commit');
}
if (!$deniedTamper || count($GLOBALS['source_case']['deleted_downloads']) !== $cleanupsBefore + 1
    || is_file(end($GLOBALS['source_case']['deleted_downloads']))) {
    throw new RuntimeException('Checksum-matching malicious ZIP bypassed native protected-source verification or leaked a temporary file.');
}
// A protected temporary package is owned by the updater, not by a
// general-purpose media deletion filter. It must never delete a different path.
$sentinel = tempnam(sys_get_temp_dir(), 'protected-delete-sentinel-');
file_put_contents($sentinel, 'unrelated fixture');
$GLOBALS['source_case']['delete_redirect'] = $sentinel;
$rejected = false;
try {
    $protectedDownload->invoke($adapter, $makeRelease());
} catch (RuntimeException $error) {
    $rejected = true;
} finally {
    unset($GLOBALS['source_case']['delete_redirect']);
}
$download = end($GLOBALS['source_case']['temporary_downloads']);
$sentinelIntact = is_file($sentinel) && file_get_contents($sentinel) === 'unrelated fixture';
$downloadGone = !file_exists($download) && !is_link($download);
if (is_file($sentinel)) unlink($sentinel);
if (is_file($download)) unlink($download);
if (!$rejected || !$sentinelIntact || !$downloadGone) {
    throw new LogicException('Protected package cleanup allowed a filtered deletion target.');
}

echo "protected main origin, exact PHP bytes, native source verification and ZIP tamper cases denied\n";
