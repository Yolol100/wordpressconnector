<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once $root . '/plugin/wordpressconnector/includes/Support/Input.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once $root . '/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php';

use Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Input;

$expectFailure = static function (callable $callback, string $label): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        return;
    }
    fwrite(STDERR, "Expected security-boundary failure: {$label}.\n");
    exit(1);
};

$registry = new Registry();
$registry->register('test.secure', static fn(): array => array('ok' => true), array(
    'privileged' => true,
    'public_repository_safe' => true,
    'capability' => 'update_plugins',
));
$descriptor = $registry->descriptor('test.secure');
if (true !== $descriptor['public_repository_safe'] || 'update_plugins' !== $descriptor['capability']) {
    fwrite(STDERR, "Registry discarded security metadata.\n");
    exit(1);
}
$catalog = $registry->catalog();
if (true !== $catalog['test.secure']['public_repository_safe'] || 'update_plugins' !== $catalog['test.secure']['capability']) {
    fwrite(STDERR, "Registry catalog discarded security metadata.\n");
    exit(1);
}
$expectFailure(static function (): void {
    $r = new Registry();
    $r->register('test.badcap', static fn(): array => array(), array('capability' => 'Bad Capability'));
}, 'invalid action capability');
$expectFailure(static fn() => Input::boolValue('false', 'payload.flag'), 'string boolean');
$expectFailure(static fn() => Input::boolValue(1, 'payload.flag'), 'integer boolean');
if (true !== Input::boolValue(true, 'payload.flag') || false !== Input::boolValue(false, 'payload.flag')) {
    fwrite(STDERR, "Strict boolean helper changed valid booleans.\n");
    exit(1);
}

$updates = new Registry();
(new ConnectorUpdateAdapter())->register($updates);
foreach (array('connector.update.check', 'connector.update.apply') as $action) {
    if (true !== $updates->descriptor($action)['public_repository_safe']) {
        fwrite(STDERR, "Canonical public self-update action lost its public_repository_safe marker: {$action}.\n");
        exit(1);
    }
}

$system = (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/SystemAdapter.php');
$core = (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/CoreAdapter.php');
$filesystem = (string) file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/FilesystemAdapter.php');

foreach (array(
    "'capability' => 'create_users'",
    "'capability' => 'edit_users'",
    "'capability' => 'delete_users'",
    "'capability' => 'promote_users'",
    "'capability' => 'activate_plugins'",
    "'capability' => 'install_plugins'",
    "'capability' => 'update_plugins'",
    "'capability' => 'delete_plugins'",
    "'capability' => 'switch_themes'",
    "'capability' => 'install_themes'",
    "'capability' => 'update_themes'",
    "'capability' => 'delete_themes'",
    "'capability' => 'update_core'",
    "'capability' => 'manage_sites'",
    "'capability' => 'create_sites'",
    "'capability' => 'delete_sites'",
    'wp_get_scheduled_event($hook, $args)',
    'cron.run only accepts an existing scheduled hook with the exact argument set.',
    'cron.schedule only reschedules an existing hook with the exact argument set.',
    "Input::boolValue(\$grant, 'payload.capabilities.' . \$cap)",
) as $needle) {
    if (strpos($system, $needle) === false) {
        fwrite(STDERR, "Missing SystemAdapter boundary: {$needle}\n");
        exit(1);
    }
}
if (substr_count($system, 'wp_get_scheduled_event($hook, $args)') < 2) {
    fwrite(STDERR, "Cron run and schedule must both bind to an existing exact event.\n");
    exit(1);
}
if (substr_count($core, 'Policy::assertMetaKeyAllowed($key);') !== 3) {
    fwrite(STDERR, "All three generic meta actions must reject protected/internal metadata.\n");
    exit(1);
}
if (substr_count($core, 'Policy::assertOptionKeyAllowed($name);') !== 6) {
    fwrite(STDERR, "Site and network generic option actions must reject system-owned options.\n");
    exit(1);
}
foreach (array("'edit_plugins'", "'edit_themes'", 'Current user lacks the required filesystem capability') as $needle) {
    if (strpos($filesystem, $needle) === false) {
        fwrite(STDERR, "Missing filesystem capability boundary: {$needle}\n");
        exit(1);
    }
}

echo "security boundary contract OK\n";
