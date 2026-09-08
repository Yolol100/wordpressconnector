<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$GLOBALS['wpconnector_test_caps'] = array();
function current_user_can($capability): bool {
    return in_array((string) $capability, $GLOBALS['wpconnector_test_caps'], true);
}
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';

use Webactueel\WordPressConnector\Security\Policy;

$expectFailure = static function (callable $callback, string $label): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        return;
    }
    fwrite(STDERR, "Expected policy failure: {$label}.\n");
    exit(1);
};

$descriptor = array('mutation' => false, 'privileged' => false, 'sensitive' => false, 'system_update' => false);
Policy::assertActionAllowed($descriptor, true, false);
$expectFailure(static fn() => Policy::assertKeyAllowed('api_key'), 'secret-like key');
$expectFailure(static fn() => Policy::assertMetaKeyAllowed('_edit_lock'), 'protected metadata');
$expectFailure(static fn() => Policy::assertOptionKeyAllowed('active_plugins'), 'system-owned option');
Policy::assertMetaKeyAllowed('public_project_note');
Policy::assertOptionKeyAllowed('blogdescription');

putenv('WPCONNECTOR_PUBLIC_REPOSITORY=1');
putenv('WPCONNECTOR_ALLOW_PRIVILEGED=1');
putenv('WPCONNECTOR_ALLOW_SYSTEM_UPDATES=1');
putenv('WPCONNECTOR_ALLOW_WRITES=1');
$GLOBALS['wpconnector_test_caps'] = array('update_plugins');

Policy::assertActionAllowed(array(
    'mutation' => true,
    'privileged' => true,
    'system_update' => true,
    'public_repository_safe' => true,
    'capability' => 'update_plugins',
), false, true);

$expectFailure(static fn() => Policy::assertActionAllowed(array('privileged' => true), true, false), 'unmarked public privileged action');
$expectFailure(static fn() => Policy::assertActionAllowed(array('privileged' => true, 'sensitive' => true, 'public_repository_safe' => true), true, false), 'public sensitive action');
$expectFailure(static fn() => Policy::assertActionAllowed(array('privileged' => true, 'public_repository_safe' => true, 'capability' => 'install_plugins'), true, false), 'missing function-level capability');

foreach (array('WPCONNECTOR_PUBLIC_REPOSITORY', 'WPCONNECTOR_ALLOW_PRIVILEGED', 'WPCONNECTOR_ALLOW_SYSTEM_UPDATES', 'WPCONNECTOR_ALLOW_WRITES') as $name) {
    putenv($name);
}
$GLOBALS['wpconnector_test_caps'] = array();

echo "policy contract OK\n";
