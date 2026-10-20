<?php

declare(strict_types=1);

// Only run in a disposable WordPress/MySQL test instance as --user=admin.
if (! defined('ABSPATH') || ! class_exists('Webactueel\WordPressConnector\Adapters\SystemAdapter')) {
    throw new RuntimeException('Connector must be active on a real WordPress instance.');
}
if (! current_user_can('manage_options') || ! current_user_can('create_users')) {
    throw new RuntimeException('Test administrator is not authenticated.');
}

$userName = 'wpconn_qa_' . strtolower(wp_generate_password(10, false, false));
$userId = wp_create_user($userName, wp_generate_password(32, true, true), $userName . '@example.test');
if (is_wp_error($userId)) throw new RuntimeException('Could not create disposable QA account.');
$roleName = 'wpconn_qa_' . strtolower(wp_generate_password(10, false, false));
$createdRole = false;
try {
    $user = get_user_by('id', (int) $userId);
    $user->set_role('subscriber');
    $user->add_role('editor');
    $adapter = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();
    $preview = $adapter->userUpdate(array('id'=>(int)$userId,'remove_roles'=>array('editor')),array('dry_run'=>true));
    if (($preview['after']['roles'] ?? null) !== array('subscriber')) throw new RuntimeException('Real WordPress role preview was incorrect.');
    $before = get_user_by('id',(int)$userId)->roles;
    if ($before !== array('subscriber','editor')) throw new RuntimeException('Dry-run changed real WordPress roles.');
    $changed = $adapter->userUpdate(array('id'=>(int)$userId,'remove_roles'=>array('editor')),array('dry_run'=>false));
    if (($changed['after']['roles'] ?? null) !== array('subscriber')) throw new RuntimeException('Real WordPress write/readback mismatch.');
    $restore = $changed['_rollback'] ?? null;
    if (! is_array($restore) || ($restore['action']??null) !== 'user.update') throw new RuntimeException('Rollback receipt missing.');
    $adapter->userUpdate($restore['payload'], array('dry_run'=>false));
    if (get_user_by('id',(int)$userId)->roles !== array('subscriber','editor')) throw new RuntimeException('Real WordPress rollback lost a role.');

    if (! add_role($roleName, 'Connector QA Role', array('read'=>true))) throw new RuntimeException('Could not create disposable QA role.');
    $createdRole = true;
    $rejected = false;
    try {
        $adapter->roleUpdate(array('role'=>$roleName,'add'=>array('edit_posts'=>true,'manage_options'=>'invalid_bool')),array('dry_run'=>false));
    } catch (RuntimeException $expected) {
        $rejected = true;
    }
    if (! $rejected || get_role($roleName)->capabilities !== array('read'=>true)) {
        throw new RuntimeException('Invalid role grant caused partial database writes.');
    }
    $adapter->roleUpdate(array('role'=>$roleName,'add'=>array('edit_posts'=>true)),array('dry_run'=>false));
    if ((get_role($roleName)->capabilities['edit_posts'] ?? null) !== true) throw new RuntimeException('Valid role grant failed.');
    WP_CLI::success('Real WordPress user role preview/rollback and role-grant validation passed.');
} finally {
    if ($createdRole) remove_role($roleName);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    if (get_user_by('id', (int)$userId)) wp_delete_user((int)$userId);
}
