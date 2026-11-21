<?php
/**
 * WordPress-native admin UI is isolated to one privileged settings screen.
 * Existing security decisions remain server-rendered and nonce protected.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('WPCONNECTOR_FILE')) {
    define('WPCONNECTOR_FILE', dirname(__DIR__) . '/plugin/wordpressconnector/wordpressconnector.php');
}
if (!defined('WPCONNECTOR_VERSION')) {
    define('WPCONNECTOR_VERSION', '1.20.13-test');
}
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/Settings.php';

$GLOBALS['admin_ui_fixture'] = array('allow' => false, 'styles' => array(), 'scripts' => array(), 'data' => array());
function current_user_can(string $cap): bool {
    return $GLOBALS['admin_ui_fixture']['allow'] && 'manage_options' === $cap;
}
function plugins_url(string $path, string $file = ''): string {
    return 'https://fixture.invalid/wp-content/plugins/wordpressconnector/' . $path;
}
function wp_enqueue_style(string $name, string $url, array $deps, $version): void {
    $GLOBALS['admin_ui_fixture']['styles'][] = array($name, $url, $deps, $version);
}
function wp_enqueue_script(string $name, string $url, array $deps, $version, bool $footer): void {
    $GLOBALS['admin_ui_fixture']['scripts'][] = array($name, $url, $deps, $version, $footer);
}
function wp_localize_script(string $name, string $global, array $data): void {
    $GLOBALS['admin_ui_fixture']['data'][] = array($name, $global, $data);
}
function __($text, $domain = ''): string { return (string)$text; }

$check = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};
$page = new \Webactueel\WordPressConnector\Admin\Settings();
$page->enqueue_admin_interface('settings_page_wordpressconnector');
$check(!$GLOBALS['admin_ui_fixture']['scripts'], 'Unprivileged users do not load React UI.');
$GLOBALS['admin_ui_fixture']['allow'] = true;
$page->enqueue_admin_interface('settings_page_other');
$check(!$GLOBALS['admin_ui_fixture']['scripts'], 'React UI must not be enqueued on unrelated admin screens.');
$page->enqueue_admin_interface('settings_page_wordpressconnector');

$styles = $GLOBALS['admin_ui_fixture']['styles'];
$scripts = $GLOBALS['admin_ui_fixture']['scripts'];
$data = $GLOBALS['admin_ui_fixture']['data'];
$check(count($styles) === 1 && count($scripts) === 1 && count($data) === 1, 'Exactly one scoped style, script, and config.');
$check($styles[0][0] === 'wpconnector-admin-ui' && $styles[0][2] === array('wp-components'), 'WordPress component CSS dependency.');
$check($scripts[0][0] === 'wpconnector-admin-ui' && $scripts[0][2] === array('wp-element','wp-components'), 'WordPress React dependencies; no duplicate React runtime.');
$check($scripts[0][4] === true && $data[0][1] === 'WPConnectorAdminUI', 'Foot-loaded script and safe config.');
$tabs = array_column($data[0][2]['tabs'], 'name');
$check($tabs === array('overview','chatgpt','github','advanced'), 'Simple four-tab information architecture.');

$settings = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/Settings.php');
$js = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/assets/admin-ui.js');
$css = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/assets/admin-ui.css');
foreach ($tabs as $tab) {
    $check(strpos($settings, 'id="wpconnector-' . $tab . '"') !== false, 'Missing progressive-enhancement panel ' . $tab);
    $check(strpos($settings, 'wpconnector-heading-' . $tab) !== false, 'Accessible heading for panel ' . $tab);
}
foreach (array(
    'wp_nonce_field( \'wpconnector_site_pairing\' )',
    'wp_nonce_field( \'wpconnector_native_oauth_mode\' )',
    'wp_nonce_field( \'wpconnector_create_application_password\' )',
    'name="confirm_direct_access"',
    'name="native_oauth_decision"',
    'name="pairing_decision"',
    'wpconnector-test-mcp',
    'wpconnector-copy-mcp'
) as $needle) {
    $check(strpos($settings, $needle) !== false, 'Security or test form removed: ' . $needle);
}
$check(strpos($settings, 'wpconnector-tab-fallback') !== false, 'Functional HTML fallback if React fails.');
$check(strpos($settings, '<details class="wpconnector-disclosure">') !== false, 'Advanced technical controls stay collapsible.');
foreach (array('role: \'tablist\'', "'aria-controls'", 'ArrowRight', 'ArrowLeft', 'Home', 'End', 'hashchange', 'wp.element') as $needle) {
    $check(strpos($js, $needle) !== false, 'WordPress React tab accessibility contract: ' . $needle);
}
$check(strpos($js, 'Authorization') === false && strpos($js, 'localStorage') === false && strpos($js, 'wp.apiFetch') === false, 'Admin nav never handles credentials or mutations.');
$check(strpos($css, '.wpconnector-panel[hidden]') !== false && strpos($css, '.wpconnector-admin ') !== false, 'Scoped styles and hidden panel enforcement.');
echo "WordPress-native admin UI contract PASS: scoped React, safe forms, accessible tabs and no secrets.\n";
