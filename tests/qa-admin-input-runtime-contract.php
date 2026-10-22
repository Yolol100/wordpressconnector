<?php

declare(strict_types=1);

// Execute actual production handlers with only the necessary WordPress API doubles.
set_error_handler(static function (int $severity, string $message): void {
    throw new ErrorException($message, 0, $severity);
});

final class WpcQaExit extends Exception
{
    public $status;
    public function __construct(int $status)
    {
        parent::__construct('WordPress response intercepted');
        $this->status = $status;
    }
}

$GLOBALS['wpc_qa_admin'] = true;
function current_user_can($capability, ...$args): bool { return $GLOBALS['wpc_qa_admin']; }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function __(string $text, string $domain = ''): string { return $text; }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function absint($value): int { return abs((int) $value); }
function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
function wp_die($message = '', $title = '', $args = array()): void { throw new WpcQaExit((int) ($args['response'] ?? 500)); }
function check_admin_referer($action): int { return 1; }
function wp_send_json_error(array $data = array(), int $status = 200): void { throw new WpcQaExit($status); }
function get_transient($key) { return false; }
function delete_transient($key): void {}

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
require_once $root . 'Adapters/ConnectorUpdateAdapter.php';
require_once $root . 'Adapters/CacheMaintenanceAdapter.php';
require_once $root . 'Admin/Settings.php';
require_once $root . 'Admin/ElementorJsonExport.php';

$updater = new \Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter();
$notice = static function ($value, bool $canUpdate) use ($updater): string {
    $_GET = array('wpconnector_update_checked' => $value);
    $GLOBALS['wpc_qa_admin'] = $canUpdate;
    ob_start();
    try {
        $updater->checkedNotice();
    } finally {
        $output = ob_get_clean();
    }
    return $output;
};
if ('' !== $notice(array('1'), true) || '' !== $notice('invalid', true)
    || '' !== $notice('1', false)
    || false === strpos($notice('1', true), 'notice-success')
    || false === strpos($notice('0', true), 'notice-warning')) {
    throw new RuntimeException('Update notice input/role boundary regression.');
}

$cache = new \Webactueel\WordPressConnector\Adapters\CacheMaintenanceAdapter();
$cacheDeny = static function ($method, $key, $token, int $expected) use ($cache): void {
    $_SERVER = array('REQUEST_METHOD' => $method, 'HTTP_X_WPCONNECTOR_CACHE_KEY' => $key, 'HTTP_X_WPCONNECTOR_CACHE_TOKEN' => $token);
    try {
        $cache->handleAssetCleanupLoopback();
    } catch (WpcQaExit $response) {
        if ($response->status !== $expected) {
            throw new RuntimeException('Unexpected cache authorization response.');
        }
        return;
    }
    throw new RuntimeException('Invalid cache request was not rejected.');
};
$cacheDeny(array('POST'), '', '', 405);
$cacheDeny('GET', '', '', 405);
$cacheDeny('POST', array('A'), str_repeat('A', 64), 403);
$cacheDeny('POST', str_repeat('A', 32), array('B'), 403);
$cacheDeny('POST', str_repeat('A', 32), str_repeat('B', 64), 403);

$settings = new \Webactueel\WordPressConnector\Admin\Settings();
$credentialDeny = static function ($method, $confirm, bool $isAdmin, int $expected) use ($settings): void {
    $_SERVER = array('REQUEST_METHOD' => $method);
    $_POST = array('confirm_direct_access' => $confirm);
    $GLOBALS['wpc_qa_admin'] = $isAdmin;
    try {
        $settings->createApplicationPassword();
    } catch (WpcQaExit $response) {
        if ($response->status !== $expected) {
            throw new RuntimeException('Unexpected credential authorization response.');
        }
        return;
    }
    throw new RuntimeException('Invalid credential request was not rejected.');
};
$credentialDeny(array('POST'), '1', true, 405);
$credentialDeny('POST', '1', false, 403);
$credentialDeny('POST', array('1'), true, 400);

$export = new \Webactueel\WordPressConnector\Admin\ElementorJsonExport();
foreach (array(array('post_id' => array('7')), array('post_id' => array('7'), 'include_site_parts' => array('1'))) as $query) {
    $_GET = $query;
    try {
        $export->download();
    } catch (WpcQaExit $response) {
        if (400 !== $response->status) {
            throw new RuntimeException('Malformed Elementor export input did not fail closed.');
        }
        continue;
    }
    throw new RuntimeException('Malformed Elementor export request was not denied.');
}

restore_error_handler();
echo "WP admin notice, cache loopback and credential input/role boundaries OK\n";
