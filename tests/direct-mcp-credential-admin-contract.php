<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/Settings.php');
foreach (array(
    'admin_post_wpconnector_create_application_password',
    'check_admin_referer(\'wpconnector_create_application_password\')',
    'get_current_user_id()',
    'wp_get_current_user()',
    'current_user_can(\'manage_options\')',
    'current_user_can(\'create_app_password\', $userId)',
    'wp_is_application_passwords_available_for_user($userId)',
    'application_name_exists_for_user($userId, self::DIRECT_MCP_PASSWORD_LABEL)',
    'create_new_application_password(',
    'nocache_headers();',
    'autocomplete="off"',
    'admin_url(\'profile.php\')',
    'Never put this password in a GitHub branch',
) as $needle) {
    if (false === strpos($source, $needle)) {
        throw new RuntimeException('Missing direct MCP credential security contract: ' . $needle);
    }
}
foreach (array(
    'get_users(',
    'get_user_by(\'id\', 1)',
    'update_option(\'wpconnector_application_password',
    'set_transient(\'wpconnector_application_password',
    'wp_remote_post(',
    'wp_remote_get(',
) as $forbidden) {
    if (false !== strpos($source, $forbidden)) {
        throw new RuntimeException('Unsafe direct MCP credential transport or account selection: ' . $forbidden);
    }
}
echo "Direct MCP credential admin contract OK\n";
