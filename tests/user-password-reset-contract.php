<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/SystemAdapter.php';
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "Unable to read SystemAdapter.php.\n");
    exit(1);
}

$start = strpos($source, 'public function userForcePasswordReset');
$end = strpos($source, 'public function userDelete', $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "Emergency password reset action is missing.\n");
    exit(1);
}
$method = substr($source, $start, $end - $start);

$required = array(
    "register('user.force_password_reset'",
    "'sensitive' => true",
    "'capability' => 'edit_users'",
    "current_user_can('edit_user'",
    'wp_generate_password(',
    'wp_set_password(',
    'WP_Session_Tokens::get_instance(',
    '->destroy_all()',
    'get_password_reset_key(',
    'network_site_url(',
    'wp_mail(',
    "'rollback_supported' => false",
);

foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing emergency password reset contract marker: {$needle}\n");
        exit(1);
    }
}

if (strpos($method, 'retrieve_password(') !== false) {
    fwrite(STDERR, "Emergency reset must not use the normal retrieve_password() email flow after rotating the password.\n");
    exit(1);
}

foreach (array("'password' =>", "'reset_key' =>", "'key' =>") as $forbidden) {
    if (strpos($method, $forbidden) !== false) {
        fwrite(STDERR, "Emergency reset response must not expose password/reset-key material: {$forbidden}\n");
        exit(1);
    }
}

echo "user password reset contract OK\n";
