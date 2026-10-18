<?php
declare(strict_types=1);
final class ConnectorAdminStop extends RuntimeException {
    public string $html;
    public int $http;
    public function __construct($html, int $http) {
        parent::__construct('WordPress response', $http);
        $this->html = (string) $html;
        $this->http = $http;
    }
}
final class WP_Application_Passwords {
    public static array $created = array();
    public static bool $exists = false;
    public static function application_name_exists_for_user(int $userId, string $label): bool { return self::$exists; }
    public static function create_new_application_password(int $userId, array $config): array {
        self::$created[] = array('id' => $userId, 'config' => $config);
        return array('one-time-secret-ONLY', array('uuid' => 'test'));
    }
}
$GLOBALS['direct_mcp'] = array('method' => 'POST', 'user' => 77, 'admin' => true,
    'create' => true, 'nonce' => true, 'consent' => true, 'https' => true, 'allowed' => true, 'no_cache' => 0);
function current_user_can($capability, ...$args): bool {
    return 'manage_options' === $capability ? $GLOBALS['direct_mcp']['admin'] :
        ('create_app_password' === $capability && $args === array($GLOBALS['direct_mcp']['user']) && $GLOBALS['direct_mcp']['create']);
}
function check_admin_referer($action): void {
    if ('wpconnector_create_application_password' !== $action || ! $GLOBALS['direct_mcp']['nonce']) {
        throw new ConnectorAdminStop('Invalid nonce', 403);
    }
}
function get_current_user_id(): int { return $GLOBALS['direct_mcp']['user']; }
function wp_get_current_user(): object { return (object) array('ID' => $GLOBALS['direct_mcp']['user'], 'user_login' => 'safe-admin'); }
function is_ssl(): bool { return $GLOBALS['direct_mcp']['https']; }
function rest_url(string $path): string { return 'https://example.invalid/wp-json/' . $path; }
function wp_parse_url(string $url, int $component) { return parse_url($url, $component); }
function wp_is_application_passwords_available_for_user(int $userId): bool { return $GLOBALS['direct_mcp']['allowed']; }
function esc_html__($value, $domain = ''): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function admin_url(string $path): string { return 'https://example.invalid/wp-admin/' . $path; }
function nocache_headers(): void { ++$GLOBALS['direct_mcp']['no_cache']; }
function wp_die($message = '', $title = '', $args = array()) {
    throw new ConnectorAdminStop($message, (int) ($args['response'] ?? 500));
}
$source = dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/Settings.php';
if (! is_file($source)) throw new RuntimeException('Missing Settings.php fixture');
require_once $source;
$settings = new \Webactueel\WordPressConnector\Admin\Settings();
$attempt = static function () use ($settings): ConnectorAdminStop {
    $_SERVER['REQUEST_METHOD'] = $GLOBALS['direct_mcp']['method'];
    $_POST['confirm_direct_access'] = $GLOBALS['direct_mcp']['consent'] ? '1' : '0';
    try { $settings->createApplicationPassword(); }
    catch (ConnectorAdminStop $stop) { return $stop; }
    throw new RuntimeException('WordPress request escaped termination');
};
foreach (array(
    array('method', 'GET', 405), array('admin', false, 403),
    array('nonce', false, 403), array('consent', false, 400),
    array('create', false, 403), array('https', false, 403),
    array('allowed', false, 403),
) as $scenario) {
    WP_Application_Passwords::$created = array();
    $GLOBALS['direct_mcp'][$scenario[0]] = $scenario[1];
    $reply = $attempt();
    if ($reply->http !== $scenario[2] || count(WP_Application_Passwords::$created)) {
        throw new RuntimeException('Creation was not blocked: ' . $scenario[0]);
    }
    $GLOBALS['direct_mcp'][$scenario[0]] = 'method' === $scenario[0] ? 'POST' : true;
}
WP_Application_Passwords::$exists = true;
$duplicate = $attempt();
if (409 !== $duplicate->http || WP_Application_Passwords::$created) {
    throw new RuntimeException('Existing direct MCP password was duplicated.');
}
WP_Application_Passwords::$exists = false;
$success = $attempt();
if (200 !== $success->http || count(WP_Application_Passwords::$created) !== 1
    || WP_Application_Passwords::$created[0]['id'] !== 77
    || false === strpos($success->html, 'one-time-secret-ONLY')
    || false === strpos($success->html, '/webactueel-wordpress-connector/v1/mcp')
    || $GLOBALS['direct_mcp']['no_cache'] !== 1) {
    throw new RuntimeException('One-time MCP credential creation, account binding or no-cache failed.');
}
if (false !== strpos($success->html, '<script>')) {
    throw new RuntimeException('Untrusted script reached credential response.');
}
echo "Direct MCP administrator creation runtime tests OK\n";
