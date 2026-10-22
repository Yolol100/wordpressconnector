<?php

declare(strict_types=1);

// Controlled WordPress user model: verify preview parity and reversible roles.
class WP_User
{
    public int $ID = 27;
    public string $user_login = 'managed-user';
    public string $user_email = 'managed@example.test';
    public string $display_name = 'Managed';
    public string $first_name = '';
    public string $last_name = '';
    public string $description = '';
    public string $user_url = '';
    public array $roles = array('subscriber', 'editor');
    public array $allcaps = array('read' => true);
    public function set_role(string $role): void { $this->roles = '' === $role ? array() : array($role); }
    public function add_role(string $role): void { if (! in_array($role, $this->roles, true)) $this->roles[] = $role; }
    public function remove_role(string $role): void { $this->roles = array_values(array_diff($this->roles, array($role))); }
}
$GLOBALS['fixture_wp_user'] = new WP_User();
$GLOBALS['fixture_can_promote'] = true;
function get_user_by(string $field, int $id)
{
    return 'id' === $field && 27 === $id ? $GLOBALS['fixture_wp_user'] : false;
}
function current_user_can(string $cap, ...$args): bool
{
    if (in_array($cap, array('edit_user', 'promote_user'), true) && ($args[0] ?? null) !== 27) {
        throw new RuntimeException('Wrong user ID in permission lookup.');
    }
    return ! in_array($cap, array('promote_users', 'promote_user'), true) || $GLOBALS['fixture_can_promote'];
}
function get_editable_roles(): array { return array_fill_keys(array('subscriber', 'editor', 'author'), array()); }
function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/', '', $value)); }
function is_wp_error($value): bool { return false; }
function wp_update_user(array $input)
{
    $user = get_user_by('id', (int) $input['ID']);
    foreach (array('user_email', 'display_name', 'first_name', 'last_name', 'description', 'user_url') as $key) {
        if (array_key_exists($key, $input)) $user->{$key} = (string) $input[$key];
    }
    return (int) $user->ID;
}

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes';
require_once $root . '/Support/Json.php';
require_once $root . '/Support/Fingerprint.php';
require_once $root . '/Support/Input.php';
require_once $root . '/Adapters/SystemAdapter.php';
$adapter = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();
$assert = static function ($got, $expected, string $label): void {
    if ($got !== $expected) {
        throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($got));
    }
};

$preview = $adapter->userUpdate(array('id' => 27, 'remove_roles' => array('editor')), array('dry_run' => true));
$assert($preview['after']['roles'], array('subscriber'), 'Removal preview');
$assert($GLOBALS['fixture_wp_user']->roles, array('subscriber', 'editor'), 'Dry-run must not write');
$preview = $adapter->userUpdate(array('id' => 27, 'role' => 'author', 'add_roles' => array('editor')), array('dry_run' => true));
$assert($preview['after']['roles'], array('author', 'editor'), 'Reset-and-add preview');
$preview = $adapter->userUpdate(array('id' => 27, 'add_roles' => array('editor', 'author'), 'remove_roles' => array('subscriber')), array('dry_run' => true));
$assert($preview['after']['roles'], array('editor', 'author'), 'Dedup-and-remove preview');

$modified = $adapter->userUpdate(array('id' => 27, 'remove_roles' => array('editor')), array('dry_run' => false));
$assert($modified['after']['roles'], array('subscriber'), 'Write readback');
$restore = $modified['_rollback'];
$assert($restore['action'], 'user.update', 'Rollback action');
$assert($restore['payload']['role'] ?? null, 'subscriber', 'Rollback first role');
$assert($restore['payload']['add_roles'] ?? null, array('editor'), 'Rollback other roles');
$adapter->userUpdate($restore['payload'], array('dry_run' => false));
$assert($GLOBALS['fixture_wp_user']->roles, array('subscriber', 'editor'), 'Rollback restores all roles');

$GLOBALS['fixture_can_promote'] = false;
$profile = $adapter->userUpdate(array('id' => 27, 'display_name' => 'New Name'), array('dry_run' => false));
$assert($GLOBALS['fixture_wp_user']->display_name, 'New Name', 'Profile write');
foreach (array('role', 'add_roles', 'remove_roles') as $key) {
    if (array_key_exists($key, $profile['_rollback']['payload'])) throw new RuntimeException('Profile-only rollback must not touch roles: ' . $key);
}
$adapter->userUpdate($profile['_rollback']['payload'], array('dry_run' => false));
$assert($GLOBALS['fixture_wp_user']->display_name, 'Managed', 'Profile-only rollback');
$GLOBALS['fixture_can_promote'] = true;

$GLOBALS['fixture_wp_user']->roles = array();
$modified = $adapter->userUpdate(array('id' => 27, 'add_roles' => array('editor')), array('dry_run' => false));
$assert($modified['after']['roles'], array('editor'), 'No-role user write');
$restore = $modified['_rollback']['payload'];
$assert($restore['remove_roles'] ?? null, array('editor'), 'Rollback removes assigned role');
if (isset($restore['role'])) throw new RuntimeException('Rollback invented an unassigned default role.');
$adapter->userUpdate($restore, array('dry_run' => false));
$assert($GLOBALS['fixture_wp_user']->roles, array(), 'No-role user rollback');

echo "Role previews, exact role rollback, profile-only rollback and no-role accounts OK\n";
