from pathlib import Path


def patch(path, replacements):
    p = Path(path)
    text = p.read_text()
    for old, new, expected in replacements:
        count = text.count(old)
        if count != expected:
            raise SystemExit(f"{path}: expected {expected} match(es), got {count}: {old!r}")
        text = text.replace(old, new, expected)
    p.write_text(text)


core = Path('plugin/wordpressconnector/includes/Adapters/CoreAdapter.php')
text = core.read_text()
old = 'Policy::assertKeyAllowed($key);'
if text.count(old) != 3:
    raise SystemExit(f'CoreAdapter: expected 3 generic meta key checks, got {text.count(old)}')
text = text.replace(old, 'Policy::assertMetaKeyAllowed($key);')
old_name = 'Policy::assertKeyAllowed($name);'
if text.count(old_name) != 9:
    raise SystemExit(f'CoreAdapter: expected 9 option/theme-mod key checks, got {text.count(old_name)}')
for _ in range(6):
    text = text.replace(old_name, 'Policy::assertOptionKeyAllowed($name);', 1)
core.write_text(text)

system_path = 'plugin/wordpressconnector/includes/Adapters/SystemAdapter.php'
replacements = []

def one(old, new):
    replacements.append((old, new, 1))

registrations = {
    "$registry->register('user.list', array($this, 'userList'), $sensitive + array('description' => 'List WordPress users.'));": "$registry->register('user.list', array($this, 'userList'), $sensitive + array('capability' => 'list_users', 'description' => 'List WordPress users.'));",
    "$registry->register('user.get', array($this, 'userGet'), $sensitive + array('description' => 'Read a WordPress user.'));": "$registry->register('user.get', array($this, 'userGet'), $sensitive + array('capability' => 'list_users', 'description' => 'Read a WordPress user.'));",
    "$registry->register('user.create', array($this, 'userCreate'), $sensitive + array('mutation' => true, 'description' => 'Create a WordPress user with a server-generated password.'));": "$registry->register('user.create', array($this, 'userCreate'), $sensitive + array('mutation' => true, 'capability' => 'create_users', 'description' => 'Create a WordPress user with a server-generated password.'));",
    "$registry->register('user.update', array($this, 'userUpdate'), $sensitive + array('mutation' => true, 'description' => 'Update WordPress user profile fields and roles.'));": "$registry->register('user.update', array($this, 'userUpdate'), $sensitive + array('mutation' => true, 'capability' => 'edit_users', 'description' => 'Update WordPress user profile fields and roles.'));",
    "$registry->register('user.delete', array($this, 'userDelete'), $sensitive + array('mutation' => true, 'description' => 'Delete a WordPress user.'));": "$registry->register('user.delete', array($this, 'userDelete'), $sensitive + array('mutation' => true, 'capability' => 'delete_users', 'description' => 'Delete a WordPress user.'));",
    "$registry->register('role.list', array($this, 'roleList'), $privileged + array('description' => 'List roles and capabilities.'));": "$registry->register('role.list', array($this, 'roleList'), $privileged + array('capability' => 'promote_users', 'description' => 'List roles and capabilities.'));",
    "$registry->register('role.create', array($this, 'roleCreate'), $privileged + array('mutation' => true, 'description' => 'Create a WordPress role.'));": "$registry->register('role.create', array($this, 'roleCreate'), $privileged + array('mutation' => true, 'capability' => 'promote_users', 'description' => 'Create a WordPress role.'));",
    "$registry->register('role.update', array($this, 'roleUpdate'), $privileged + array('mutation' => true, 'description' => 'Add or remove capabilities from a role.'));": "$registry->register('role.update', array($this, 'roleUpdate'), $privileged + array('mutation' => true, 'capability' => 'promote_users', 'description' => 'Add or remove capabilities from a role.'));",
    "$registry->register('role.delete', array($this, 'roleDelete'), $privileged + array('mutation' => true, 'description' => 'Delete a WordPress role.'));": "$registry->register('role.delete', array($this, 'roleDelete'), $privileged + array('mutation' => true, 'capability' => 'promote_users', 'description' => 'Delete a WordPress role.'));",
    "$registry->register('plugin.list', array($this, 'pluginList'), $privileged + array('description' => 'List installed plugins and activation state.'));": "$registry->register('plugin.list', array($this, 'pluginList'), $privileged + array('capability' => 'activate_plugins', 'description' => 'List installed plugins and activation state.'));",
    "$registry->register('plugin.activate', array($this, 'pluginActivate'), $privileged + array('mutation' => true, 'description' => 'Activate an installed plugin.'));": "$registry->register('plugin.activate', array($this, 'pluginActivate'), $privileged + array('mutation' => true, 'capability' => 'activate_plugins', 'description' => 'Activate an installed plugin.'));",
    "$registry->register('plugin.deactivate', array($this, 'pluginDeactivate'), $privileged + array('mutation' => true, 'description' => 'Deactivate an installed plugin.'));": "$registry->register('plugin.deactivate', array($this, 'pluginDeactivate'), $privileged + array('mutation' => true, 'capability' => 'activate_plugins', 'description' => 'Deactivate an installed plugin.'));",
    "$registry->register('plugin.install', array($this, 'pluginInstall'), $systemUpdate + array('description' => 'Install a plugin from WordPress.org by slug.'));": "$registry->register('plugin.install', array($this, 'pluginInstall'), $systemUpdate + array('capability' => 'install_plugins', 'description' => 'Install a plugin from WordPress.org by slug.'));",
    "$registry->register('plugin.update', array($this, 'pluginUpdate'), $systemUpdate + array('description' => 'Update one installed plugin.'));": "$registry->register('plugin.update', array($this, 'pluginUpdate'), $systemUpdate + array('capability' => 'update_plugins', 'description' => 'Update one installed plugin.'));",
    "$registry->register('plugin.delete', array($this, 'pluginDelete'), $systemUpdate + array('description' => 'Delete an inactive installed plugin.'));": "$registry->register('plugin.delete', array($this, 'pluginDelete'), $systemUpdate + array('capability' => 'delete_plugins', 'description' => 'Delete an inactive installed plugin.'));",
    "$registry->register('theme.list', array($this, 'themeList'), $privileged + array('description' => 'List installed themes.'));": "$registry->register('theme.list', array($this, 'themeList'), $privileged + array('capability' => 'switch_themes', 'description' => 'List installed themes.'));",
    "$registry->register('theme.switch', array($this, 'themeSwitch'), $privileged + array('mutation' => true, 'description' => 'Switch the active theme.'));": "$registry->register('theme.switch', array($this, 'themeSwitch'), $privileged + array('mutation' => true, 'capability' => 'switch_themes', 'description' => 'Switch the active theme.'));",
    "$registry->register('theme.install', array($this, 'themeInstall'), $systemUpdate + array('description' => 'Install a theme from WordPress.org by slug.'));": "$registry->register('theme.install', array($this, 'themeInstall'), $systemUpdate + array('capability' => 'install_themes', 'description' => 'Install a theme from WordPress.org by slug.'));",
    "$registry->register('theme.update', array($this, 'themeUpdate'), $systemUpdate + array('description' => 'Update one installed theme.'));": "$registry->register('theme.update', array($this, 'themeUpdate'), $systemUpdate + array('capability' => 'update_themes', 'description' => 'Update one installed theme.'));",
    "$registry->register('theme.delete', array($this, 'themeDelete'), $systemUpdate + array('description' => 'Delete an inactive theme.'));": "$registry->register('theme.delete', array($this, 'themeDelete'), $systemUpdate + array('capability' => 'delete_themes', 'description' => 'Delete an inactive theme.'));",
    "$registry->register('core.check_updates', array($this, 'coreCheckUpdates'), $privileged + array('description' => 'Check WordPress core updates.'));": "$registry->register('core.check_updates', array($this, 'coreCheckUpdates'), $privileged + array('capability' => 'update_core', 'description' => 'Check WordPress core updates.'));",
    "$registry->register('core.update', array($this, 'coreUpdate'), $systemUpdate + array('description' => 'Update WordPress core. Non-rollbackable.'));": "$registry->register('core.update', array($this, 'coreUpdate'), $systemUpdate + array('capability' => 'update_core', 'description' => 'Update WordPress core. Non-rollbackable.'));",
    "$registry->register('cron.list', array($this, 'cronList'), $privileged + array('description' => 'List scheduled cron events.'));": "$registry->register('cron.list', array($this, 'cronList'), $privileged + array('capability' => 'manage_options', 'description' => 'List scheduled cron events.'));",
    "$registry->register('cron.run', array($this, 'cronRun'), $privileged + array('mutation' => true, 'description' => 'Run one cron hook immediately.'));": "$registry->register('cron.run', array($this, 'cronRun'), $privileged + array('mutation' => true, 'capability' => 'manage_options', 'description' => 'Run one existing scheduled cron event immediately.'));",
    "$registry->register('cron.delete', array($this, 'cronDelete'), $privileged + array('mutation' => true, 'description' => 'Delete scheduled events for one hook.'));": "$registry->register('cron.delete', array($this, 'cronDelete'), $privileged + array('mutation' => true, 'capability' => 'manage_options', 'description' => 'Delete scheduled events for one hook.'));",
    "$registry->register('cron.schedule', array($this, 'cronSchedule'), $privileged + array('mutation' => true, 'description' => 'Schedule a cron event.'));": "$registry->register('cron.schedule', array($this, 'cronSchedule'), $privileged + array('mutation' => true, 'capability' => 'manage_options', 'description' => 'Reschedule an existing cron hook and argument set.'));",
    "$registry->register('multisite.site.list', array($this, 'siteList'), $sensitive + array('description' => 'List multisite network sites.'));": "$registry->register('multisite.site.list', array($this, 'siteList'), $sensitive + array('capability' => 'manage_sites', 'description' => 'List multisite network sites.'));",
    "$registry->register('multisite.site.create', array($this, 'siteCreate'), $sensitive + array('mutation' => true, 'description' => 'Create a multisite site.'));": "$registry->register('multisite.site.create', array($this, 'siteCreate'), $sensitive + array('mutation' => true, 'capability' => 'create_sites', 'description' => 'Create a multisite site.'));",
    "$registry->register('multisite.site.update', array($this, 'siteUpdate'), $sensitive + array('mutation' => true, 'description' => 'Update multisite site fields.'));": "$registry->register('multisite.site.update', array($this, 'siteUpdate'), $sensitive + array('mutation' => true, 'capability' => 'manage_sites', 'description' => 'Update multisite site fields.'));",
    "$registry->register('multisite.site.delete', array($this, 'siteDelete'), $sensitive + array('mutation' => true, 'system_update' => true, 'description' => 'Delete a multisite site.'));": "$registry->register('multisite.site.delete', array($this, 'siteDelete'), $sensitive + array('mutation' => true, 'system_update' => true, 'capability' => 'delete_sites', 'description' => 'Delete a multisite site.'));",
}
for old, new in registrations.items():
    one(old, new)

one("$plan = array('login' => $login, 'email' => $email, 'role' => isset($payload['role']) ? sanitize_key((string) $payload['role']) : get_option('default_role'));\n        if (! empty($context['dry_run']))", "$plan = array('login' => $login, 'email' => $email, 'role' => isset($payload['role']) ? sanitize_key((string) $payload['role']) : get_option('default_role'));\n        if (isset($payload['role']) && ! current_user_can('promote_users')) throw new RuntimeException('Current user is not allowed to assign roles.');\n        if (! empty($context['dry_run']))")
one("$before = $this->userSnapshot($user);\n        $after = $before;", "$before = $this->userSnapshot($user);\n        if ((isset($payload['role']) || isset($payload['add_roles']) || isset($payload['remove_roles'])) && ! current_user_can('promote_users')) throw new RuntimeException('Current user is not allowed to change user roles.');\n        $after = $before;")
one("$caps = isset($payload['capabilities']) && is_array($payload['capabilities']) ? $payload['capabilities'] : array();\n        if ('' === $role || '' === $name) throw new RuntimeException('role and name are required.');", "$caps = isset($payload['capabilities']) && is_array($payload['capabilities']) ? $payload['capabilities'] : array();\n        foreach ($caps as $cap => $grant) {\n            if (! is_string($cap) || sanitize_key($cap) !== $cap || '' === $cap) throw new RuntimeException('Role capability keys must be safe strings.');\n            $caps[$cap] = Input::boolValue($grant, 'payload.capabilities.' . $cap);\n        }\n        if ('' === $role || '' === $name) throw new RuntimeException('role and name are required.');")

old_cron_run = """    public function cronRun(array $payload, array $context): array
    {
        $hook = isset($payload['hook']) ? sanitize_key((string) $payload['hook']) : '';
        if ('' === $hook) throw new RuntimeException('hook is required.');
        if (! empty($context['dry_run'])) return array('would_run' => $hook, '_current_fingerprint' => Fingerprint::make(array('hook' => $hook)));
        do_action_ref_array($hook, isset($payload['args']) && is_array($payload['args']) ? $payload['args'] : array());
        return array('ran' => $hook, 'rollback_supported' => false);
    }
"""
new_cron_run = """    public function cronRun(array $payload, array $context): array
    {
        $hook = isset($payload['hook']) ? sanitize_key((string) $payload['hook']) : '';
        $args = isset($payload['args']) && is_array($payload['args']) ? $payload['args'] : array();
        if ('' === $hook) throw new RuntimeException('hook is required.');
        $event = wp_get_scheduled_event($hook, $args);
        if (! $event) throw new RuntimeException('cron.run only accepts an existing scheduled hook with the exact argument set.');
        $state = array('hook' => $hook, 'timestamp' => (int) $event->timestamp, 'schedule' => $event->schedule, 'args' => $event->args);
        if (! empty($context['dry_run'])) return array('would_run' => $state, '_current_fingerprint' => Fingerprint::make($state));
        do_action_ref_array($hook, $event->args);
        return array('ran' => $state, 'rollback_supported' => false);
    }
"""
one(old_cron_run, new_cron_run)

old_cron_schedule = """    public function cronSchedule(array $payload, array $context): array
    {
        $hook = isset($payload['hook']) ? sanitize_key((string) $payload['hook']) : '';
        $timestamp = isset($payload['timestamp']) ? (int) $payload['timestamp'] : time();
        $recurrence = isset($payload['recurrence']) ? sanitize_key((string) $payload['recurrence']) : '';
        $args = isset($payload['args']) && is_array($payload['args']) ? $payload['args'] : array();
        if ('' === $hook) throw new RuntimeException('hook is required.');
        if (! empty($context['dry_run'])) return array('would_schedule' => compact('hook', 'timestamp', 'recurrence', 'args'), '_current_fingerprint' => Fingerprint::make(wp_next_scheduled($hook, $args)));
        $result = '' === $recurrence ? wp_schedule_single_event($timestamp, $hook, $args, true) : wp_schedule_event($timestamp, $recurrence, $hook, $args, true);
        if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
        return array('scheduled' => true, 'rollback_supported' => false);
    }
"""
new_cron_schedule = """    public function cronSchedule(array $payload, array $context): array
    {
        $hook = isset($payload['hook']) ? sanitize_key((string) $payload['hook']) : '';
        $timestamp = isset($payload['timestamp']) ? (int) $payload['timestamp'] : time();
        $recurrence = isset($payload['recurrence']) ? sanitize_key((string) $payload['recurrence']) : '';
        $args = isset($payload['args']) && is_array($payload['args']) ? $payload['args'] : array();
        if ('' === $hook) throw new RuntimeException('hook is required.');
        $existing = wp_get_scheduled_event($hook, $args);
        if (! $existing) throw new RuntimeException('cron.schedule only reschedules an existing hook with the exact argument set.');
        $state = array('hook' => $hook, 'timestamp' => $timestamp, 'recurrence' => $recurrence, 'args' => $args, 'existing_timestamp' => (int) $existing->timestamp);
        if (! empty($context['dry_run'])) return array('would_schedule' => $state, '_current_fingerprint' => Fingerprint::make(array('timestamp' => (int) $existing->timestamp, 'schedule' => $existing->schedule, 'args' => $existing->args)));
        $result = '' === $recurrence ? wp_schedule_single_event($timestamp, $hook, $args, true) : wp_schedule_event($timestamp, $recurrence, $hook, $args, true);
        if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
        return array('scheduled' => true, 'event' => $state, 'rollback_supported' => false);
    }
"""
one(old_cron_schedule, new_cron_schedule)
patch(system_path, replacements)

patch('plugin/wordpressconnector/includes/Adapters/FilesystemAdapter.php', [(
    "$relative = FilesystemPolicy::assertWritableText(isset($payload['path']) ? (string) $payload['path'] : '');\n        $absolute = $this->resolveExisting($relative, false);",
    "$relative = FilesystemPolicy::assertWritableText(isset($payload['path']) ? (string) $payload['path'] : '');\n        $requiredCapability = 0 === strpos($relative, 'wp-content/plugins/') ? 'edit_plugins' : 'edit_themes';\n        if (! current_user_can($requiredCapability)) {\n            throw new RuntimeException('Current user lacks the required filesystem capability: ' . $requiredCapability . '.');\n        }\n        $absolute = $this->resolveExisting($relative, false);",
    1,
)])
