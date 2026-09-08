<?php

declare(strict_types=1);

$catalogPath = dirname(__DIR__) . '/docs/ACTION-CATALOG.md';
$catalog = file_get_contents($catalogPath);
if ($catalog === false) {
    fwrite(STDERR, "Unable to read action catalog.\n");
    exit(1);
}

$expected = array(
    'cache.flush' => 'mutation, privileged',
    'core.check_updates' => 'privileged',
    'core.update' => 'mutation, privileged, system_update',
    'cron.delete' => 'mutation, privileged',
    'cron.list' => 'privileged',
    'cron.run' => 'mutation, privileged',
    'cron.schedule' => 'mutation, privileged',
    'custom_css.inspect' => 'privileged',
    'custom_css.update' => 'mutation, privileged',
    'elementor.inventory' => 'read-only',
    'multisite.site.create' => 'mutation, privileged, sensitive',
    'multisite.site.delete' => 'mutation, privileged, sensitive, system_update',
    'multisite.site.list' => 'privileged, sensitive',
    'multisite.site.update' => 'mutation, privileged, sensitive',
    'plugin.activate' => 'mutation, privileged',
    'plugin.deactivate' => 'mutation, privileged',
    'plugin.delete' => 'mutation, privileged, system_update',
    'plugin.install' => 'mutation, privileged, system_update',
    'plugin.list' => 'privileged',
    'plugin.update' => 'mutation, privileged, system_update',
    'rewrite.flush' => 'mutation, privileged',
    'role.create' => 'mutation, privileged',
    'role.delete' => 'mutation, privileged',
    'role.list' => 'privileged',
    'role.update' => 'mutation, privileged',
    'theme.delete' => 'mutation, privileged, system_update',
    'theme.install' => 'mutation, privileged, system_update',
    'theme.list' => 'privileged',
    'theme.switch' => 'mutation, privileged',
    'theme.update' => 'mutation, privileged, system_update',
    'user.create' => 'mutation, privileged, sensitive',
    'user.delete' => 'mutation, privileged, sensitive',
    'user.get' => 'privileged, sensitive',
    'user.list' => 'privileged, sensitive',
    'user.update' => 'mutation, privileged, sensitive',
);

$rows = array();
foreach (preg_split('/\R/', $catalog) as $line) {
    if (preg_match('/^\| `([^`]+)` \| ([^|]+) \|/', $line, $matches) === 1) {
        $rows[$matches[1]] = trim($matches[2]);
    }
}

foreach ($expected as $action => $security) {
    if (! isset($rows[$action])) {
        fwrite(STDERR, "Missing action catalog row: {$action}\n");
        exit(1);
    }
    if ($rows[$action] !== $security) {
        fwrite(STDERR, "Security catalog mismatch for {$action}: expected '{$security}', got '{$rows[$action]}'.\n");
        exit(1);
    }
}

$source = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/SystemAdapter.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read SystemAdapter.php.\n");
    exit(1);
}

$registrationLines = array();
foreach (preg_split('/\R/', $source) as $line) {
    if (preg_match("/register\\('([^']+)'/", $line, $matches) === 1) {
        $registrationLines[$matches[1]] = $line;
    }
}

foreach (array('plugin.install', 'plugin.update', 'plugin.delete', 'theme.install', 'theme.update', 'theme.delete', 'core.update') as $action) {
    if (! isset($registrationLines[$action]) || strpos($registrationLines[$action], '$systemUpdate') === false) {
        fwrite(STDERR, "System-update registration contract missing for {$action}.\n");
        exit(1);
    }
}

foreach (array('user.list', 'user.get', 'multisite.site.list') as $action) {
    if (! isset($registrationLines[$action]) || strpos($registrationLines[$action], '$sensitive') === false) {
        fwrite(STDERR, "Sensitive registration contract missing for {$action}.\n");
        exit(1);
    }
}

echo "action catalog contract OK\n";
