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
    'connector.rest_catalog' => 'privileged',
    'connector.setup.inspect' => 'privileged',
    'core.check_updates' => 'privileged',
    'core.update' => 'mutation, privileged, system_update',
    'cron.delete' => 'mutation, privileged',
    'cron.list' => 'privileged',
    'cron.run' => 'mutation, privileged',
    'cron.schedule' => 'mutation, privileged',
    'custom_css.inspect' => 'privileged',
    'custom_css.update' => 'mutation, privileged',
    'custom_css.patch' => 'mutation, privileged',
    'maintenance.cache_capabilities' => 'privileged',
    'maintenance.cache_flush' => 'mutation, privileged',
    'code_snippets.patch' => 'mutation, privileged',
    'code_snippets.catalog_only.inspect' => 'privileged',
    'code_snippets.catalog_only.ensure' => 'mutation, privileged',
    'code_snippets.catalog_only.undo' => 'mutation, privileged',
    'code_snippets.restore_code' => 'mutation, privileged',
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
    'site_editor.template.list' => 'privileged',
    'site_editor.template.inspect' => 'privileged',
    'site_editor.template.update' => 'mutation, privileged',
    'site_editor.template.restore' => 'mutation, privileged',
    'site_editor.global_styles.inspect' => 'privileged',
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
    'user.force_password_reset' => 'mutation, privileged, sensitive',
    'woocommerce.order.get' => 'privileged, sensitive',
    'woocommerce.order.list' => 'privileged, sensitive',
    'woocommerce.customer.get' => 'privileged, sensitive',
    'woocommerce.shipping_zone.get' => 'privileged',
    'woocommerce.shipping_zone.list' => 'privileged',
    'woocommerce.tax_class.list' => 'privileged',
    'woocommerce.tax_rate.list' => 'privileged',
    'acf.portfolio_stats_update' => 'mutation, privileged',
    'acf.schema.ensure_text_fields' => 'mutation, privileged',
    'acf.schema.remove_text_fields' => 'mutation, privileged',
    'acf.schema.ensure_fields' => 'mutation, privileged',
    'acf.schema.remove_fields' => 'mutation, privileged',
    'acf.schema.create_field_group' => 'mutation, privileged',
    'acf.schema.delete_field_group' => 'mutation, privileged',
    'portfolio.case_text_update' => 'mutation, privileged',
    'wordpress.ability.execute' => 'mutation, privileged',
    'wordpress.ability.execute_approved' => 'mutation, privileged, sensitive',
    'wordpress.ability.read' => 'privileged, sensitive',
);

$rows = array();
foreach (preg_split('/\R/', $catalog) as $line) {
    if (preg_match('/^\| `([^`]+)` \| ([^|]+) \|/', $line, $matches) === 1) {
        $rows[$matches[1]] = trim($matches[2]);
    }
}

// Every first-party action registration must have exactly one catalog row.
// Scan only the canonical adapters and Runner, not WordPress extension hooks
// or test fixtures, so source and documentation cannot silently drift.
$registrationPaths = glob(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/*.php');
if (! is_array($registrationPaths) || ! $registrationPaths) {
    fwrite(STDERR, "Unable to locate Connector adapters.\n");
    exit(1);
}
$registrationPaths[] = dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Runner.php';
$registered = array();
foreach ($registrationPaths as $registrationPath) {
    $registrationSource = file_get_contents($registrationPath);
    if ($registrationSource === false) {
        fwrite(STDERR, "Unable to read action registration source.\n");
        exit(1);
    }
    if (preg_match_all("/->register\\(\\s*'([^']+)'/s", $registrationSource, $matches) === false) {
        fwrite(STDERR, "Unable to parse action registration source.\n");
        exit(1);
    }
    foreach ($matches[1] as $name) {
        if (isset($registered[$name])) {
            fwrite(STDERR, "Duplicate first-party action registration: {$name}\n");
            exit(1);
        }
        $registered[$name] = true;
    }
}
foreach (array_keys($registered) as $name) {
    if (! isset($rows[$name])) {
        fwrite(STDERR, "Uncatalogued registered action: {$name}\n");
        exit(1);
    }
}
foreach (array_keys($rows) as $name) {
    if (! isset($registered[$name])) {
        fwrite(STDERR, "Stale action catalog entry: {$name}\n");
        exit(1);
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

foreach (array('user.list', 'user.get', 'user.force_password_reset', 'multisite.site.list') as $action) {
    if (! isset($registrationLines[$action]) || strpos($registrationLines[$action], '$sensitive') === false) {
        fwrite(STDERR, "Sensitive registration contract missing for {$action}.\n");
        exit(1);
    }
}

echo "action catalog contract OK\n";
