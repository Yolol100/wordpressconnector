<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime {
    final class Registry
    {
        public $actions = array();
        public function register(string $name, $handler, array $options = array()): void
        {
            $this->actions[$name] = $options;
        }
    }
}

namespace {
    $GLOBALS['native_hooks'] = array();
    $GLOBALS['test_release_version'] = '0.0.0';
    $GLOBALS['test_release_status'] = 200;
    $GLOBALS['release_calls'] = 0;
    $GLOBALS['download_calls'] = 0;
    $GLOBALS['notice_actions'] = array();
    $GLOBALS['wp_site_transients'] = array();
    $GLOBALS['test_can_update_plugins'] = true;

    class WP_Error
    {
        private $message;
        public function __construct(string $code = 'failed', string $message = '') { $this->message = $message; }
        public function get_error_message(): string { return $this->message; }
    }
    function add_filter(string $name, $callback, int $priority = 10, int $args = 1): void
    {
        $GLOBALS['native_hooks'][$name] = array('callback' => $callback, 'args' => $args);
    }
    function add_action(string $name, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['notice_actions'][$name] = array('callback' => $callback, 'priority' => $priority);
    }
    function get_site_transient($name) { return $GLOBALS['wp_site_transients'][$name] ?? false; }
    function set_site_transient($name, $value, $expiration = 0): bool {
        $GLOBALS['wp_site_transients'][$name] = $value;
        return true;
    }
    function current_user_can($capability): bool { return $GLOBALS['test_can_update_plugins']; }
    function admin_url($path): string { return 'https://example.com/wp-admin/' . $path; }
    function wp_nonce_url($url, $action): string { return $url . '&_wpnonce=fixture'; }
    function esc_url($url): string { return $url; }
    function esc_html__($text, $domain): string { return $text; }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function wp_safe_remote_get(string $url, array $options = array())
    {
        ++$GLOBALS['release_calls'];
        if (false !== strpos($url, '/releases/latest')) {
            $tag = 'v' . $GLOBALS['test_release_version'];
            $prefix = 'https://github.com/Yolol100/wordpressconnector/releases/download/' . $tag . '/';
            $checksum = str_repeat('a', 64) . "  wordpressconnector.zip\n";
            return array('code' => $GLOBALS['test_release_status'], 'body' => json_encode(array(
                'tag_name' => $tag, 'draft' => false, 'prerelease' => false,
                'assets' => array(
                    array('name' => 'wordpressconnector.zip', 'browser_download_url' => $prefix . 'wordpressconnector.zip', 'digest' => 'sha256:' . str_repeat('a', 64)),
                    array('name' => 'wordpressconnector.zip.sha256', 'browser_download_url' => $prefix . 'wordpressconnector.zip.sha256', 'digest' => 'sha256:' . hash('sha256', $checksum)),
                    array('name' => 'wordpressconnector.spdx.json', 'browser_download_url' => $prefix . 'wordpressconnector.spdx.json'),
                ),
            )));
        }
        if (false !== strpos($url, '.sha256')) {
            return array('code' => 200, 'body' => str_repeat('a', 64) . "  wordpressconnector.zip\n");
        }
        return new WP_Error('unexpected_url', 'Unexpected request.');
    }
    function wp_remote_retrieve_response_code(array $response): int { return (int) $response['code']; }
    function wp_remote_retrieve_body(array $response): string { return (string) $response['body']; }
    function download_url(string $url, int $timeout = 30)
    {
        ++$GLOBALS['download_calls'];
        return new WP_Error('test_no_download', 'Fake transport refuses downloads.');
    }

    $root = dirname(__DIR__);
    $bootstrap = (string) file_get_contents($root . '/plugin/wordpressconnector/wordpressconnector.php');
    if (false === strpos($bootstrap, 'Update URI: https://github.com/Yolol100/wordpressconnector')
        || ! preg_match('/Version:\s*(\d+\.\d+\.\d+)/', $bootstrap, $version)) {
        throw new \RuntimeException('Native Update URI or plugin version missing.');
    }
    define('WPCONNECTOR_VERSION', $version[1]);
    $newVersionParts = explode('.', $version[1]);
    $newVersionParts[2] = (string) ((int) $newVersionParts[2] + 1);
    $newerVersion = implode('.', $newVersionParts);
    $GLOBALS['test_release_version'] = $newerVersion;
    require_once $root . '/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php';
    $adapter = new \Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter();
    $registry = new \Webactueel\WordPressConnector\Runtime\Registry();
    $adapter->register($registry);

    foreach (array('connector.update.check', 'connector.update.apply') as $action) {
        if (! isset($registry->actions[$action])) throw new \RuntimeException('Existing action missing: ' . $action);
    }
    foreach (array('update_plugins_github.com', 'upgrader_pre_download') as $name) {
        if (! isset($GLOBALS['native_hooks'][$name]) || 4 !== $GLOBALS['native_hooks'][$name]['args']) {
            throw new \RuntimeException('Native update hook missing: ' . $name);
        }
    }

    $uri = 'https://github.com/Yolol100/wordpressconnector';
    $plugin = 'wordpressconnector/wordpressconnector.php';
    $meta = array('UpdateURI' => $uri, 'Version' => $version[1]);
    $update = $adapter->nativeUpdateOffer(false, $meta, $plugin, array('nl_NL'));
    if (! is_array($update) || $update['version'] !== $newerVersion || $update['slug'] !== 'wordpressconnector'
        || ! isset($update['package']) || false === strpos($update['package'], '/v' . $newerVersion . '/wordpressconnector.zip')
        || $update['autoupdate'] !== false) {
        throw new \RuntimeException('Canonical native update was not offered.');
    }
    $calls = $GLOBALS['release_calls'];
    if (false !== $adapter->nativeUpdateOffer(false, $meta, 'some-other-plugin/main.php', array())
        || false !== $adapter->nativeUpdateOffer(false, array('UpdateURI' => 'https://other.example/plugin', 'Version' => '1.0'), $plugin, array())
        || $calls !== $GLOBALS['release_calls']) {
        throw new \RuntimeException('Native updater affected an unrelated plugin.');
    }
    if (false !== $adapter->nativeUpdateOffer(false, array('UpdateURI' => $uri, 'Version' => $newerVersion), $plugin, array())) {
        throw new \RuntimeException('Native updater offered equal version.');
    }
    $GLOBALS['test_release_version'] = '1.18.9';
    if (false !== $adapter->nativeUpdateOffer(false, $meta, $plugin, array())) {
        throw new \RuntimeException('Native updater offered a downgrade.');
    }
    $GLOBALS['test_release_status'] = 503;
    if (false !== $adapter->nativeUpdateOffer(false, $meta, $plugin, array())) {
        throw new \RuntimeException('Failed release source did not fail closed.');
    }
    $GLOBALS['test_release_version'] = $newerVersion;
    $GLOBALS['test_release_status'] = 200;

    $hook = array('plugin' => $plugin, 'type' => 'plugin', 'action' => 'update');
    if (false !== $adapter->verifiedNativeDownload(false, $update['package'], null, array('plugin' => 'other/file.php'))) {
        throw new \RuntimeException('Native downloader intercepted another plugin.');
    }
    if (! is_wp_error($adapter->verifiedNativeDownload('/tmp/foreign.zip', $update['package'], null, $hook))) {
        throw new \RuntimeException('Native updater trusted another pre-download handler.');
    }
    if (! is_wp_error($adapter->verifiedNativeDownload(false, 'https://evil.example/package.zip', null, $hook))
        || 0 !== $GLOBALS['download_calls']) {
        throw new \RuntimeException('Native updater allowed an untrusted ZIP URI.');
    }
    if (! is_wp_error($adapter->verifiedNativeDownload(false, $update['package'], null, $hook))
        || 1 !== $GLOBALS['download_calls']) {
        throw new \RuntimeException('Verified-download failure did not stop the update.');
    }

    // WordPress Plugin_Upgrader::bulk_upgrade() omits type/action in hook_extra.
    // The connector still MUST verify its own ZIP before the bulk installer runs.
    $bulkHook = array('plugin' => $plugin, 'temp_backup' => array('slug' => 'wordpressconnector'));
    if (! is_wp_error($adapter->verifiedNativeDownload(false, 'https://evil.example/package.zip', null, $bulkHook))
        || 1 !== $GLOBALS['download_calls']) {
        throw new \RuntimeException('Bulk update skipped the connector package authenticity check.');
    }
    if (! is_wp_error($adapter->verifiedNativeDownload(false, $update['package'], null, $bulkHook))
        || 2 !== $GLOBALS['download_calls']) {
        throw new \RuntimeException('Bulk updater bypassed the verified ZIP download or failed open.');
    }
    foreach (array(array('type' => 'theme'), array('action' => 'install')) as $conflict) {
        if (false !== $adapter->verifiedNativeDownload(false, $update['package'], null, array_merge($bulkHook, $conflict))
            || 2 !== $GLOBALS['download_calls']) {
            throw new \RuntimeException('Conflicting update target incorrectly accepted.');
        }
    }

    // A new GitHub release must appear in the native Plugins update transient
    // even when WordPress Core's hourly update check has not run.
    $GLOBALS['wp_site_transients']['update_plugins'] = (object) array(
        'last_checked' => time(),
        'response' => array('other/keep.php' => (object) array('id' => 'https://other.example')),
        'no_update' => array('other/nochange.php' => (object) array('id' => 'other')),
        'checked' => array($plugin => $version[1]),
    );
    $priorCalls = $GLOBALS['release_calls'];
    if (! $adapter->refreshNativeUpdateNotice()) {
        throw new \RuntimeException('Targeted update notice refresh did not complete.');
    }
    $notice = $GLOBALS['wp_site_transients']['update_plugins'];
    $new = $notice->response[$plugin] ?? null;
    if (! is_object($new) || $new->new_version !== $newerVersion ||
        $new->plugin !== $plugin || $new->id !== $uri ||
        ! isset($notice->response['other/keep.php']) ||
        ! isset($notice->no_update['other/nochange.php']) ||
        $notice->last_checked !== $GLOBALS['wp_site_transients']['update_plugins']->last_checked) {
        throw new \RuntimeException('Native update notice missing or another plugin was changed.');
    }
    $callsAfter = $GLOBALS['release_calls'];
    $adapter->refreshNativeUpdateNotice();
    if ($GLOBALS['release_calls'] !== $callsAfter || $callsAfter !== $priorCalls + 1) {
        throw new \RuntimeException('Bounded update notice polling made repeated GitHub API calls.');
    }
    $list = $adapter->pluginActionLinks(array('deactivate' => 'keep'));
    if (!isset($list['deactivate'], $list['wpconnector-refresh']) ||
        strpos($list['wpconnector-refresh'], 'wpconnector_check_updates') === false) {
        throw new \RuntimeException('Manual GitHub release refresh missing from Plugins row.');
    }
    $GLOBALS['test_can_update_plugins'] = false;
    if (false !== $adapter->refreshNativeUpdateNotice(true) ||
        count($adapter->pluginActionLinks(array())) !== 0) {
        throw new \RuntimeException('Unprivileged visitor can trigger an update check.');
    }
    $GLOBALS['test_can_update_plugins'] = true;
    // An equal/older GitHub release clears only a previous connector offer.
    $GLOBALS['test_release_version'] = $version[1];
    if (! $adapter->refreshNativeUpdateNotice(true) ||
        isset($GLOBALS['wp_site_transients']['update_plugins']->response[$plugin]) ||
        ! isset($GLOBALS['wp_site_transients']['update_plugins']->response['other/keep.php'])) {
        throw new \RuntimeException('Installed version did not clear its own stale native update.');
    }
    // Failed release API must not overwrite unrelated or existing update data.
    $GLOBALS['test_release_status'] = 503;
    $beforeFailure = serialize($GLOBALS['wp_site_transients']['update_plugins']);
    if (false !== $adapter->refreshNativeUpdateNotice(true) ||
        serialize($GLOBALS['wp_site_transients']['update_plugins']) !== $beforeFailure) {
        throw new \RuntimeException('GitHub outage changed the native update notices.');
    }
    foreach (array('load-plugins.php', 'load-update-core.php', 'admin_post_wpconnector_check_updates') as $hook) {
        if (!isset($GLOBALS['notice_actions'][$hook])) {
            throw new \RuntimeException('Native Plugins update refresh hook missing: ' . $hook);
        }
    }

    // WordPress actions consume no return value; the direct refresh API still
    // returns its boolean outcome for the admin handler and contract callers.
    foreach (array('load-plugins.php', 'load-update-core.php') as $hook) {
        $callback = $GLOBALS['notice_actions'][$hook]['callback'];
        if (! is_callable($callback) || null !== call_user_func($callback)) {
            throw new \RuntimeException('Registered WordPress update action must return void: ' . $hook);
        }
        $GLOBALS['test_can_update_plugins'] = false;
        $priorRequests = $GLOBALS['release_calls'];
        if (null !== call_user_func($callback) || $priorRequests !== $GLOBALS['release_calls']) {
            throw new \RuntimeException('WordPress update action must reject an unprivileged caller.');
        }
        $GLOBALS['test_can_update_plugins'] = true;
    }

    echo "native plugin update offers, single and bulk verified download gates OK\n";
}
