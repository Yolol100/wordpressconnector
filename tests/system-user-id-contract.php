<?php
/**
 * Exact user target IDs and non-lossy reassignment regression contract.
 */
declare(strict_types=1);

class WP_User {
    public $ID;
    public $user_login = 'target';
    public $user_email = 'before@example.test';
    public $display_name = 'Before';
    public $first_name = 'First';
    public $last_name = 'Last';
    public $description = '';
    public $user_url = '';
    public $roles = array('editor');
    public $allcaps = array('read' => true);

    public function __construct(int $id) { $this->ID = $id; }
    public function set_role(string $role): void { $this->roles = array($role); }
    public function add_role(string $role): void {
        if (! in_array($role, $this->roles, true)) { $this->roles[] = $role; }
    }
    public function remove_role(string $role): void {
        $this->roles = array_values(array_diff($this->roles, array($role)));
    }
}
$GLOBALS['user_fixture'] = new WP_User(99);
$GLOBALS['user_lookups'] = 0;
$GLOBALS['user_updates'] = 0;
$GLOBALS['user_deletes'] = array();
$GLOBALS['user_resets'] = 0;

function get_user_by($field, $id) {
    ++$GLOBALS['user_lookups'];
    if ('id' !== $field || ! is_int($id)) {
        throw new RuntimeException('User lookup received a coerced ID.');
    }
    return 99 === $id ? $GLOBALS['user_fixture'] : false;
}
function current_user_can($capability, $id = null): bool { return true; }
function sanitize_key($value): string { return strtolower((string) $value); }
function wp_update_user($fields) {
    ++$GLOBALS['user_updates'];
    if (99 !== ($fields['ID'] ?? null)) {
        throw new RuntimeException('Wrong user update target.');
    }
    if (array_key_exists('user_provider_result',$GLOBALS)) { return $GLOBALS['user_provider_result']; }
    $map = array(
        'user_email' => 'user_email',
        'display_name' => 'display_name',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'description' => 'description',
        'user_url' => 'user_url',
    );
    foreach ($map as $field => $property) {
        if (array_key_exists($field, $fields)) {
            $GLOBALS['user_fixture']->$property = $fields[$field];
        }
    }
    return 99;
}
class WP_Error { public function get_error_message(): string { return 'private provider diagnostic'; } }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_delete_user($id, $reassign = null): bool {
    if (99 !== $id || (null !== $reassign && ! is_int($reassign))) {
        throw new RuntimeException('Wrong user deletion or reassignment target.');
    }
    $GLOBALS['user_deletes'][] = array($id, $reassign);
    return true;
}
function wp_set_password($password, $id): void { ++$GLOBALS['user_resets']; }

$root = dirname(__DIR__);
foreach (array('Support/Json.php', 'Support/Fingerprint.php',
    'Support/Input.php', 'Adapters/SystemAdapter.php') as $file) {
    require_once $root . '/plugin/wordpressconnector/includes/' . $file;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();

$invalid = array(true, false, 99.5, -99, 0, '0', '',
    '99junk', '9.9e1', ' 99', "99\n",
    '999999999999999999999999999999', array(99), null);
foreach ($invalid as $id) {
    foreach (array('userGet', 'userUpdate', 'userDelete', 'userForcePasswordReset') as $method) {
        foreach (array(true, false) as $dryRun) {
            $lookups = $GLOBALS['user_lookups'];
            $updates = $GLOBALS['user_updates'];
            $deletes = count($GLOBALS['user_deletes']);
            $resets = $GLOBALS['user_resets'];
            try {
                if ('userGet' === $method) {
                    $adapter->userGet(array('id' => $id));
                } else {
                    $adapter->$method(array('id' => $id), array('dry_run' => $dryRun));
                }
                throw new RuntimeException('Malformed user ID accepted.');
            } catch (RuntimeException $error) {
                if ('User not found.' !== $error->getMessage()) {
                    throw $error;
                }
            }
            if ($lookups !== $GLOBALS['user_lookups']
                || $updates !== $GLOBALS['user_updates']
                || $deletes !== count($GLOBALS['user_deletes'])
                || $resets !== $GLOBALS['user_resets']) {
                throw new RuntimeException('Malformed user ID reached a WordPress API.');
            }
        }
    }
}
foreach (array(99, '99', '00099') as $id) {
    $read = $adapter->userGet(array('id' => $id));
    if (($read['user']['id'] ?? null) !== 99
        || ($read['user']['email'] ?? null) !== 'before@example.test'
        || ! preg_match('/\A[a-f0-9]{64}\z/', $read['fingerprint'] ?? '')) {
        throw new RuntimeException('Valid user read contract changed.');
    }
    $preview = $adapter->userForcePasswordReset(
        array('id' => $id), array('dry_run' => true)
    );
    if (($preview['user_id'] ?? null) !== 99
        || ($preview['rollback_supported'] ?? null) !== false
        || $GLOBALS['user_resets'] !== 0) {
        throw new RuntimeException('Password reset dry-run contract changed.');
    }
}
$preview = $adapter->userUpdate(
    array('id' => '00099', 'email' => 'after@example.test'),
    array('dry_run' => true)
);
if (($preview['before']['email'] ?? null) !== 'before@example.test'
    || ($preview['after']['email'] ?? null) !== 'after@example.test'
    || $GLOBALS['user_updates'] !== 0) {
    throw new RuntimeException('User update dry-run changed.');
}
$updated = $adapter->userUpdate(
    array('id' => '00099', 'email' => 'after@example.test'),
    array('dry_run' => false)
);
if (($updated['after']['email'] ?? null) !== 'after@example.test'
    || ($updated['_rollback']['action'] ?? null) !== 'user.update'
    || ($updated['_rollback']['payload']['email'] ?? null) !== 'before@example.test'
    || $GLOBALS['user_updates'] !== 1) {
    throw new RuntimeException('User update or rollback metadata changed.');
}
$rollback = $adapter->userUpdate($updated['_rollback']['payload'], array('dry_run' => false));
if (($rollback['after']['email'] ?? null) !== 'before@example.test'
    || $GLOBALS['user_updates'] !== 2) {
    throw new RuntimeException('User profile rollback did not restore the prior value.');
}
// Reassignment must reject malformed IDs before reaching wp_delete_user.
$badReassign = array(true, false, 77.5, -77, '-77', '',
    '77junk', '7.7e1', ' 77', "77\n",
    '999999999999999999999999999999', array(77));
foreach ($badReassign as $id) {
    foreach (array(true, false) as $dryRun) {
        $deletes = count($GLOBALS['user_deletes']);
        try {
            $adapter->userDelete(
                array('id' => 99, 'reassign' => $id), array('dry_run' => $dryRun)
            );
            throw new RuntimeException('Malformed reassign ID accepted.');
        } catch (RuntimeException $error) {
            if ('reassign must be a non-negative user ID.' !== $error->getMessage()) {
                throw $error;
            }
        }
        if ($deletes !== count($GLOBALS['user_deletes'])) {
            throw new RuntimeException('Malformed reassignment reached user deletion.');
        }
    }
}
foreach (array(77, '77', '00077', 0, '0', '0000') as $id) {
    $expected = in_array($id, array(0, '0', '0000'), true) ? 0 : 77;
    $preview = $adapter->userDelete(
        array('id' => '00099', 'reassign' => $id), array('dry_run' => true)
    );
    if (($preview['before']['id'] ?? null) !== 99
        || ($preview['reassign'] ?? null) !== $expected) {
        throw new RuntimeException('Valid reassignment preview changed.');
    }
}
foreach (array(array(), array('reassign' => null)) as $optional) {
    $preview = $adapter->userDelete(
        array_merge(array('id' => 99), $optional), array('dry_run' => true)
    );
    if (! array_key_exists('reassign', $preview) || null !== $preview['reassign']) {
        throw new RuntimeException('Optional reassignment default changed.');
    }
}
// The actual delete path uses a disposable WordPress include shim and a stub.
// No real WordPress user, email or site is accessed.
$temp = sys_get_temp_dir() . '/wpconn-user-ids-' . getmypid() . '-' . bin2hex(random_bytes(4));
$stubDir = $temp . '/wp-admin/includes';
if (! mkdir($stubDir, 0700, true)
    || false === file_put_contents($stubDir . '/user.php', "<?php\n")) {
    throw new RuntimeException('Could not create isolated user deletion test shim.');
}
define('ABSPATH', $temp . '/');
try {
    $deleted = $adapter->userDelete(
        array('id' => '00099', 'reassign' => '00077'), array('dry_run' => false)
    );
    if (($deleted['deleted'] ?? null) !== 99
        || ($deleted['rollback_supported'] ?? null) !== false
        || end($GLOBALS['user_deletes']) !== array(99, 77)) {
        throw new RuntimeException('Valid user delete or reassignment contract changed.');
    }
    $deletedDefault = $adapter->userDelete(
        array('id' => 99), array('dry_run' => false)
    );
    if (($deletedDefault['deleted'] ?? null) !== 99
        || end($GLOBALS['user_deletes']) !== array(99, null)) {
        throw new RuntimeException('Legacy null reassignment behavior changed.');
    }
} finally {
    unlink($stubDir . '/user.php');
    rmdir($stubDir);
    rmdir($temp . '/wp-admin');
    rmdir($temp);
}
foreach (array(new WP_Error(), false, 0, 100, '99') as $failure) {
    $GLOBALS['user_provider_result'] = $failure;
    $before = clone $GLOBALS['user_fixture'];
    try {
        $adapter->userUpdate(array('id'=>99,'email'=>'unsafe@example.test','role'=>'administrator'),array('dry_run'=>false));
        throw new RuntimeException('Rejected user provider result accepted.');
    } catch (RuntimeException $error) {
        if ('WordPress user profile update failed.' !== $error->getMessage()) throw $error;
    }
    if ($GLOBALS['user_fixture']->user_email !== $before->user_email || $GLOBALS['user_fixture']->roles !== $before->roles) {
        throw new RuntimeException('Rejected provider result changed user or roles.');
    }
}
unset($GLOBALS['user_provider_result']);
echo "System user exact ID, reassignment, rollback and dry-run contract OK\n";
