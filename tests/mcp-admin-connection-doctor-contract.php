<?php

declare(strict_types=1);

namespace {
    if (! defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');
    if (! defined('WPCONNECTOR_FILE')) define('WPCONNECTOR_FILE', dirname(__DIR__) . '/plugin/wordpressconnector/wordpressconnector.php');
    if (! defined('WPCONNECTOR_VERSION')) define('WPCONNECTOR_VERSION', '1.19.19-test');

    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/Settings.php';

    $GLOBALS['doctor_fixture'] = array('allow' => false, 'scripts' => array(), 'config' => array());
    function current_user_can(string $cap): bool { return $GLOBALS['doctor_fixture']['allow'] && 'manage_options' === $cap; }
    function plugins_url(string $path, string $file = ''): string { return 'https://wp.example.test/' . $path; }
    function wp_enqueue_script(string $handle, string $src, array $deps, $version, bool $footer): void {
        $GLOBALS['doctor_fixture']['scripts'][] = array($handle, $src, $deps, $version, $footer);
    }
    function wp_localize_script(string $handle, string $name, array $data): void {
        $GLOBALS['doctor_fixture']['config'][] = array($handle, $name, $data);
    }
    function rest_url(string $path): string { return 'https://wp.example.test/wp-json/' . $path; }
    function wp_create_nonce(string $action): string { return 'fixture-nonce-' . $action; }
    function __($value, $domain = ''): string { return (string) $value; }

    $settings = new \Webactueel\WordPressConnector\Admin\Settings();
    $assert = static function (bool $ok, string $error): void {
        if (! $ok) throw new \RuntimeException($error);
    };

    $settings->enqueueConnectionDoctor('settings_page_wordpressconnector');
    $assert(count($GLOBALS['doctor_fixture']['scripts']) === 0,
        'MCP browser test must never load for non-admin users.');
    $GLOBALS['doctor_fixture']['allow'] = true;
    $settings->enqueueConnectionDoctor('settings_page_unrelated');
    $assert(count($GLOBALS['doctor_fixture']['scripts']) === 0,
        'MCP browser test must not appear on other wp-admin pages.');
    $settings->enqueueConnectionDoctor('settings_page_wordpressconnector');

    $scripts = $GLOBALS['doctor_fixture']['scripts'];
    $config = $GLOBALS['doctor_fixture']['config'];
    $assert(count($scripts) === 1 && count($config) === 1
        && $scripts[0][0] === 'wpconnector-connection-doctor'
        && strpos($scripts[0][1], 'assets/connection-doctor.js') !== false
        && $scripts[0][3] === WPCONNECTOR_VERSION
        && $config[0][0] === 'wpconnector-connection-doctor'
        && $config[0][1] === 'WPConnectorDoctor'
        && $config[0][2]['nonce'] === 'fixture-nonce-wp_rest',
        'Admin-only JS enqueue, version and WordPress REST nonce must be correct.');

    $ui = (string) file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/Settings.php');
    foreach (array('wpconnector-copy-mcp', 'wpconnector-test-mcp', 'wpconnector-mcp-address',
        'aria-live="polite"', 'does NOT connect ChatGPT', 'renderConnectionRecoveryGuide') as $needle) {
        $assert(strpos($ui, $needle) !== false, 'Admin UI missing connection control or evidence limit: ' . $needle);
    }
    $js = (string) file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/assets/connection-doctor.js');
    foreach (array("'server/discover'", "'X-WP-Nonce'", "'MCP-Protocol-Version'", "'Mcp-Method'",
        "credentials: 'same-origin'", "cache: 'no-store'", 'response.ok',
        'testButton.disabled = true', 'testButton.disabled = false', 'textContent') as $needle) {
        $assert(strpos($js, $needle) !== false, 'MCP doctor misses secure browser transport: ' . $needle);
    }
    foreach (array('Authorization: Bearer', 'wp_remote_post(', 'application_password', 'admin-post.php',
        'wordpress_connector_execute', 'tools/call', 'localStorage', 'sessionStorage') as $forbidden) {
        $assert(strpos($js, $forbidden) === false,
            'MCP doctor must not request writes, credentials or persistent browser tokens: ' . $forbidden);
    }
    echo "MCP admin connection doctor: admin-only, nonce-authenticated discovery contract OK\n";
}
