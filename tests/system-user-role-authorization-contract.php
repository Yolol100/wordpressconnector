<?php

declare(strict_types=1);

// Exercise the actual adapter against minimal WordPress permission doubles.
class WP_User
{
    public int $ID = 27;
    public string $user_login = 'managed-user';
    public string $user_email = 'managed@example.test';
    public string $display_name = 'Managed User';
    public string $first_name = '';
    public string $last_name = '';
    public string $description = '';
    public string $user_url = '';
    public array $roles = array('subscriber');
    public array $allcaps = array('read' => true);
}

$GLOBALS['wpconn_auth_caps'] = array(
    'edit_user' => false,
    'delete_user' => false,
    'promote_users' => true,
    'promote_user' => false,
    'manage_options' => false,
);
$GLOBALS['wpconn_multisite'] = false;
$GLOBALS['wpconn_super_admin'] = false;
$GLOBALS['wpconn_editable_roles'] = array('subscriber' => array(), 'editor' => array());

function get_user_by(string $field, int $id)
{
    return 'id' === $field && 27 === $id ? new WP_User() : false;
}
function current_user_can(string $cap, ...$args): bool
{
    if (in_array($cap, array('edit_user', 'delete_user', 'promote_user'), true) && ($args[0] ?? null) !== 27) {
        throw new RuntimeException('Object permission check did not receive the actual user ID.');
    }
    return ! empty($GLOBALS['wpconn_auth_caps'][$cap]);
}
function get_editable_roles(): array { return $GLOBALS['wpconn_editable_roles']; }
function is_multisite(): bool { return $GLOBALS['wpconn_multisite']; }
function is_super_admin(): bool { return $GLOBALS['wpconn_super_admin']; }
function get_option(string $name) { return 'default_role' === $name ? 'subscriber' : null; }
function sanitize_user(string $value, bool $strict = false): string { return $value; }
function sanitize_email(string $value): string { return $value; }
function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/', '', $value)); }
function get_role(string $name)
{
    return 'editor' === $name ? (object) array('capabilities' => array('edit_posts' => true)) : false;
}

$base = dirname(__DIR__) . '/plugin/wordpressconnector/includes';
require_once $base . '/Support/Json.php';
require_once $base . '/Support/Fingerprint.php';
require_once $base . '/Support/Input.php';
require_once $base . '/Adapters/SystemAdapter.php';

$adapter = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();
$deny = static function (callable $action, string $message): void {
    try {
        $action();
    } catch (RuntimeException $error) {
        if ($error->getMessage() === $message) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Forbidden account or role change was accepted: ' . $message);
};

$deny(static function () use ($adapter): void {
    $adapter->userUpdate(array('id' => 27, 'display_name' => 'Unauthorized'), array('dry_run' => true));
}, 'Current user is not allowed to edit this account.');
$deny(static function () use ($adapter): void {
    $adapter->userDelete(array('id' => 27), array('dry_run' => true));
}, 'Current user is not allowed to delete this account.');

$GLOBALS['wpconn_auth_caps']['edit_user'] = true;
$deny(static function () use ($adapter): void {
    $adapter->userUpdate(array('id' => 27, 'role' => 'editor'), array('dry_run' => true));
}, "Current user is not allowed to change this account's roles.");
$GLOBALS['wpconn_auth_caps']['promote_user'] = true;
$deny(static function () use ($adapter): void {
    $adapter->userUpdate(array('id' => 27, 'role' => 'administrator'), array('dry_run' => true));
}, 'User role is not assignable by the current user.');
$deny(static function () use ($adapter): void {
    $adapter->userUpdate(array('id' => 27, 'add_roles' => array('administrator')), array('dry_run' => true));
}, 'User role is not assignable by the current user.');
$deny(static function () use ($adapter): void {
    $adapter->userCreate(array('login' => 'candidate', 'email' => 'candidate@example.test', 'role' => 'administrator'), array('dry_run' => true));
}, 'User role is not assignable by the current user.');

$allowed = $adapter->userUpdate(array('id' => 27, 'role' => 'editor'), array('dry_run' => true));
if (($allowed['after']['roles'] ?? array()) !== array('editor') || empty($allowed['_current_fingerprint'])) {
    throw new RuntimeException('Allowed user role update lost the dry-run contract.');
}
$created = $adapter->userCreate(array('login' => 'candidate', 'email' => 'candidate@example.test', 'role' => 'editor'), array('dry_run' => true));
if (($created['would_create']['role'] ?? null) !== 'editor') {
    throw new RuntimeException('Allowed user creation lost the role assignment contract.');
}
$GLOBALS['wpconn_auth_caps']['delete_user'] = true;
if (empty($adapter->userDelete(array('id' => 27), array('dry_run' => true))['_current_fingerprint'])) {
    throw new RuntimeException('Allowed user deletion lost the dry-run contract.');
}

$deny(static function () use ($adapter): void {
    $adapter->roleCreate(array('role' => 'reviewer', 'name' => 'Reviewer'), array('dry_run' => true));
}, 'Current user is not allowed to manage role capabilities.');
$deny(static function () use ($adapter): void {
    $adapter->roleUpdate(array('role' => 'editor', 'add' => array('manage_options' => true)), array('dry_run' => true));
}, 'Current user is not allowed to manage role capabilities.');
$deny(static function () use ($adapter): void {
    $adapter->roleDelete(array('role' => 'editor'), array('dry_run' => true));
}, 'Current user is not allowed to manage role capabilities.');

$GLOBALS['wpconn_auth_caps']['manage_options'] = true;
if (empty($adapter->roleCreate(array('role' => 'reviewer', 'name' => 'Reviewer'), array('dry_run' => true))['_current_fingerprint'])) {
    throw new RuntimeException('Full site administrator lost the role management dry-run.');
}
$GLOBALS['wpconn_multisite'] = true;
$deny(static function () use ($adapter): void {
    $adapter->roleCreate(array('role' => 'reviewer', 'name' => 'Reviewer'), array('dry_run' => true));
}, 'Current user is not allowed to manage role capabilities.');
$GLOBALS['wpconn_super_admin'] = true;
if (empty($adapter->roleCreate(array('role' => 'reviewer', 'name' => 'Reviewer'), array('dry_run' => true))['_current_fingerprint'])) {
    throw new RuntimeException('Network super administrator lost the role management dry-run.');
}

echo "User object rights, assignable roles and role manager authorization OK\\n";
