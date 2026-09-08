<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class SystemAdapter
{
    public function register(Registry $registry): void
    {
        $privileged = array('privileged' => true);
        $sensitive = array('privileged' => true, 'sensitive' => true);
        $systemUpdate = array('mutation' => true, 'privileged' => true, 'system_update' => true);

        $registry->register('system.doctor', array($this, 'doctor'), array('description' => 'Run connector/runtime preflight diagnostics.'));

        $registry->register('user.list', array($this, 'userList'), $sensitive + array('description' => 'List WordPress users.'));
        $registry->register('user.get', array($this, 'userGet'), $sensitive + array('description' => 'Read a WordPress user.'));
        $registry->register('user.create', array($this, 'userCreate'), $sensitive + array('mutation' => true, 'description' => 'Create a WordPress user with a server-generated password.'));
        $registry->register('user.update', array($this, 'userUpdate'), $sensitive + array('mutation' => true, 'description' => 'Update WordPress user profile fields and roles.'));
        $registry->register('user.delete', array($this, 'userDelete'), $sensitive + array('mutation' => true, 'description' => 'Delete a WordPress user.'));

        $registry->register('role.list', array($this, 'roleList'), $privileged + array('description' => 'List roles and capabilities.'));
        $registry->register('role.create', array($this, 'roleCreate'), $privileged + array('mutation' => true, 'description' => 'Create a WordPress role.'));
        $registry->register('role.update', array($this, 'roleUpdate'), $privileged + array('mutation' => true, 'description' => 'Add or remove capabilities from a role.'));
        $registry->register('role.delete', array($this, 'roleDelete'), $privileged + array('mutation' => true, 'description' => 'Delete a WordPress role.'));

        $registry->register('plugin.list', array($this, 'pluginList'), $privileged + array('description' => 'List installed plugins and activation state.'));
        $registry->register('plugin.activate', array($this, 'pluginActivate'), $privileged + array('mutation' => true, 'description' => 'Activate an installed plugin.'));
        $registry->register('plugin.deactivate', array($this, 'pluginDeactivate'), $privileged + array('mutation' => true, 'description' => 'Deactivate an installed plugin.'));
        $registry->register('plugin.install', array($this, 'pluginInstall'), $systemUpdate + array('description' => 'Install a plugin from WordPress.org by slug.'));
        $registry->register('plugin.update', array($this, 'pluginUpdate'), $systemUpdate + array('description' => 'Update one installed plugin.'));
        $registry->register('plugin.delete', array($this, 'pluginDelete'), $systemUpdate + array('description' => 'Delete an inactive installed plugin.'));

        $registry->register('theme.list', array($this, 'themeList'), $privileged + array('description' => 'List installed themes.'));
        $registry->register('theme.switch', array($this, 'themeSwitch'), $privileged + array('mutation' => true, 'description' => 'Switch the active theme.'));
        $registry->register('theme.install', array($this, 'themeInstall'), $systemUpdate + array('description' => 'Install a theme from WordPress.org by slug.'));
        $registry->register('theme.update', array($this, 'themeUpdate'), $systemUpdate + array('description' => 'Update one installed theme.'));
        $registry->register('theme.delete', array($this, 'themeDelete'), $systemUpdate + array('description' => 'Delete an inactive theme.'));

        $registry->register('core.check_updates', array($this, 'coreCheckUpdates'), $privileged + array('description' => 'Check WordPress core updates.'));
        $registry->register('core.update', array($this, 'coreUpdate'), $systemUpdate + array('description' => 'Update WordPress core. Non-rollbackable.'));

        $registry->register('cron.list', array($this, 'cronList'), $privileged + array('description' => 'List scheduled cron events.'));
        $registry->register('cron.run', array($this, 'cronRun'), $privileged + array('mutation' => true, 'description' => 'Run one cron hook immediately.'));
        $registry->register('cron.delete', array($this, 'cronDelete'), $privileged + array('mutation' => true, 'description' => 'Delete scheduled events for one hook.'));
        $registry->register('cron.schedule', array($this, 'cronSchedule'), $privileged + array('mutation' => true, 'description' => 'Schedule a cron event.'));

        $registry->register('rewrite.flush', array($this, 'rewriteFlush'), $privileged + array('mutation' => true, 'description' => 'Flush rewrite rules.'));
        $registry->register('cache.flush', array($this, 'cacheFlush'), $privileged + array('mutation' => true, 'description' => 'Flush WordPress object cache.'));

        $registry->register('multisite.site.list', array($this, 'siteList'), $sensitive + array('description' => 'List multisite network sites.'));
        $registry->register('multisite.site.create', array($this, 'siteCreate'), $sensitive + array('mutation' => true, 'description' => 'Create a multisite site.'));
        $registry->register('multisite.site.update', array($this, 'siteUpdate'), $sensitive + array('mutation' => true, 'description' => 'Update multisite site fields.'));
        $registry->register('multisite.site.delete', array($this, 'siteDelete'), $sensitive + array('mutation' => true, 'system_update' => true, 'description' => 'Delete a multisite site.'));
    }

    public function doctor(): array
    {
        global $wpdb, $wp_version;
        $checks = array(
            'wordpress_bootstrap' => defined('ABSPATH'),
            'database' => $wpdb instanceof \wpdb && null !== $wpdb->dbh,
            'wp_cli' => defined('WP_CLI') && WP_CLI,
            'uploads_writable' => wp_is_writable(wp_get_upload_dir()['basedir']),
            'plugin_dir_writable' => wp_is_writable(WP_PLUGIN_DIR),
            'wordpress_version' => (string) $wp_version,
            'php_version' => PHP_VERSION,
            'woocommerce' => class_exists('WooCommerce'),
            'elementor' => class_exists('Elementor\\Plugin'),
            'acf' => function_exists('get_fields'),
            'multisite' => is_multisite(),
        );
        $checks['ok'] = $checks['wordpress_bootstrap'] && $checks['database'];
        return $checks;
    }

    public function userList(array $payload): array
    {
        $users = get_users(array(
            'number' => isset($payload['per_page']) ? max(1, min(100, (int) $payload['per_page'])) : 50,
            'offset' => isset($payload['offset']) ? max(0, (int) $payload['offset']) : 0,
            'search' => isset($payload['search']) ? '*' . sanitize_text_field((string) $payload['search']) . '*' : '',
            'role' => isset($payload['role']) ? sanitize_key((string) $payload['role']) : '',
        ));
        return array('users' => array_map(array($this, 'userSnapshot'), $users));
    }

    public function userGet(array $payload): array
    {
        $user = $this->user($payload);
        $snapshot = $this->userSnapshot($user);
        return array('user' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function userCreate(array $payload, array $context): array
    {
        $login = isset($payload['login']) ? sanitize_user((string) $payload['login'], true) : '';
        $email = isset($payload['email']) ? sanitize_email((string) $payload['email']) : '';
        if ('' === $login || '' === $email) throw new RuntimeException('login and email are required.');
        $plan = array('login' => $login, 'email' => $email, 'role' => isset($payload['role']) ? sanitize_key((string) $payload['role']) : get_option('default_role'));
        if (! empty($context['dry_run'])) return array('would_create' => $plan, '_current_fingerprint' => Fingerprint::make(array('new_user' => $login)));
        $password = wp_generate_password(32, true, true);
        $id = wp_create_user($login, $password, $email);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        $user = get_user_by('id', $id);
        if (isset($payload['role'])) $user->set_role(sanitize_key((string) $payload['role']));
        $this->applyUserFields($id, $payload);
        return array('user' => $this->userSnapshot(get_user_by('id', $id)), 'password_generated_server_side' => true, '_rollback' => array('action' => 'user.delete', 'payload' => array('id' => (int) $id)));
    }

    public function userUpdate(array $payload, array $context): array
    {
        $user = $this->user($payload);
        $before = $this->userSnapshot($user);
        $after = $before;
        foreach (array('email', 'display_name', 'first_name', 'last_name', 'description', 'url') as $key) if (array_key_exists($key, $payload)) $after[$key] = $payload[$key];
        if (isset($payload['role'])) $after['roles'] = array(sanitize_key((string) $payload['role']));
        $result = array('before' => $before, 'after' => $after, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;
        $this->applyUserFields((int) $user->ID, $payload);
        if (isset($payload['role'])) $user->set_role(sanitize_key((string) $payload['role']));
        if (isset($payload['add_roles'])) foreach ((array) $payload['add_roles'] as $role) $user->add_role(sanitize_key((string) $role));
        if (isset($payload['remove_roles'])) foreach ((array) $payload['remove_roles'] as $role) $user->remove_role(sanitize_key((string) $role));
        $result['after'] = $this->userSnapshot(get_user_by('id', $user->ID));
        $result['_rollback'] = array('action' => 'user.update', 'payload' => array('id' => (int) $user->ID, 'email' => $before['email'], 'display_name' => $before['display_name'], 'first_name' => $before['first_name'], 'last_name' => $before['last_name'], 'description' => $before['description'], 'url' => $before['url'], 'role' => $before['roles'][0] ?? 'subscriber'));
        return $result;
    }

    public function userDelete(array $payload, array $context): array
    {
        $user = $this->user($payload);
        $before = $this->userSnapshot($user);
        if (1 === (int) $user->ID) throw new RuntimeException('Refusing to delete user ID 1.');
        $reassign = isset($payload['reassign']) ? (int) $payload['reassign'] : null;
        if (! empty($context['dry_run'])) return array('before' => $before, 'reassign' => $reassign, '_current_fingerprint' => Fingerprint::make($before));
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (! wp_delete_user((int) $user->ID, $reassign)) throw new RuntimeException('User deletion failed.');
        return array('deleted' => (int) $user->ID, 'rollback_supported' => false);
    }

    public function roleList(): array
    {
        global $wp_roles;
        return array('roles' => $wp_roles->roles);
    }

    public function roleCreate(array $payload, array $context): array
    {
        $role = isset($payload['role']) ? sanitize_key((string) $payload['role']) : '';
        $name = isset($payload['name']) ? sanitize_text_field((string) $payload['name']) : '';
        $caps = isset($payload['capabilities']) && is_array($payload['capabilities']) ? $payload['capabilities'] : array();
        if ('' === $role || '' === $name) throw new RuntimeException('role and name are required.');
        if (! empty($context['dry_run'])) return array('would_create' => array('role' => $role, 'name' => $name, 'capabilities' => $caps), '_current_fingerprint' => Fingerprint::make(array('new_role' => $role)));
        if (! add_role($role, $name, $caps)) throw new RuntimeException('Could not create role.');
        return array('role' => $role, '_rollback' => array('action' => 'role.delete', 'payload' => array('role' => $role)));
    }

    public function roleUpdate(array $payload, array $context): array
    {
        $roleName = isset($payload['role']) ? sanitize_key((string) $payload['role']) : '';
        $role = get_role($roleName);
        if (! $role) throw new RuntimeException('Role not found.');
        $before = $role->capabilities;
        if (! empty($context['dry_run'])) return array('before' => $before, 'add' => $payload['add'] ?? array(), 'remove' => $payload['remove'] ?? array(), '_current_fingerprint' => Fingerprint::make($before));
        foreach ((array) ($payload['add'] ?? array()) as $cap => $grant) {
            if (is_int($cap)) $role->add_cap((string) $grant, true); else $role->add_cap((string) $cap, (bool) $grant);
        }
        foreach ((array) ($payload['remove'] ?? array()) as $cap) $role->remove_cap((string) $cap);
        return array('role' => $roleName, 'capabilities' => get_role($roleName)->capabilities, 'rollback_supported' => false);
    }

    public function roleDelete(array $payload, array $context): array
    {
        $roleName = isset($payload['role']) ? sanitize_key((string) $payload['role']) : '';
        $role = get_role($roleName);
        if (! $role) throw new RuntimeException('Role not found.');
        if (in_array($roleName, array('administrator', 'editor', 'author', 'contributor', 'subscriber'), true)) throw new RuntimeException('Refusing to delete a WordPress core role.');
        $before = $role->capabilities;
        if (! empty($context['dry_run'])) return array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        remove_role($roleName);
        return array('deleted' => $roleName, 'rollback_supported' => false);
    }

    public function pluginList(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $items = array();
        foreach (get_plugins() as $file => $data) {
            $items[] = array('file' => $file, 'name' => $data['Name'] ?? $file, 'version' => $data['Version'] ?? '', 'active' => is_plugin_active($file), 'network_active' => is_multisite() ? is_plugin_active_for_network($file) : false);
        }
        return array('plugins' => $items);
    }

    public function pluginActivate(array $payload, array $context): array
    {
        $file = $this->pluginFile($payload);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $before = is_plugin_active($file);
        if (! empty($context['dry_run'])) return array('before_active' => $before, 'after_active' => true, '_current_fingerprint' => Fingerprint::make($before));
        $result = activate_plugin($file, '', ! empty($payload['network_wide']), true);
        if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
        return array('file' => $file, 'active' => true, '_rollback' => array('action' => 'plugin.deactivate', 'payload' => array('file' => $file, 'network_wide' => ! empty($payload['network_wide']))));
    }

    public function pluginDeactivate(array $payload, array $context): array
    {
        $file = $this->pluginFile($payload);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $before = is_plugin_active($file);
        if (! empty($context['dry_run'])) return array('before_active' => $before, 'after_active' => false, '_current_fingerprint' => Fingerprint::make($before));
        deactivate_plugins($file, false, ! empty($payload['network_wide']));
        return array('file' => $file, 'active' => false, '_rollback' => $before ? array('action' => 'plugin.activate', 'payload' => array('file' => $file, 'network_wide' => ! empty($payload['network_wide']))) : null);
    }

    public function pluginInstall(array $payload, array $context): array
    {
        $slug = isset($payload['slug']) ? sanitize_key((string) $payload['slug']) : '';
        if ('' === $slug) throw new RuntimeException('Plugin slug is required.');
        if (! empty($context['dry_run'])) return array('would_install' => $slug, '_current_fingerprint' => Fingerprint::make(array('plugin_install' => $slug)));
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $api = plugins_api('plugin_information', array('slug' => $slug, 'fields' => array('sections' => false)));
        if (is_wp_error($api) || empty($api->download_link)) throw new RuntimeException(is_wp_error($api) ? $api->get_error_message() : 'Plugin package not found.');
        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $installed = $upgrader->install($api->download_link);
        if (true !== $installed) throw new RuntimeException('Plugin installation failed.');
        return array('installed' => $slug, 'rollback_supported' => false);
    }

    public function pluginUpdate(array $payload, array $context): array
    {
        $file = $this->pluginFile($payload);
        if (! empty($context['dry_run'])) return array('would_update' => $file, '_current_fingerprint' => Fingerprint::make($this->pluginVersion($file)));
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        wp_update_plugins();
        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $updated = $upgrader->upgrade($file);
        if (false === $updated || is_wp_error($updated)) throw new RuntimeException(is_wp_error($updated) ? $updated->get_error_message() : 'Plugin update failed or no update is available.');
        return array('updated' => $file, 'version' => $this->pluginVersion($file), 'rollback_supported' => false);
    }

    public function pluginDelete(array $payload, array $context): array
    {
        $file = $this->pluginFile($payload);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_plugin_active($file)) throw new RuntimeException('Plugin must be inactive before deletion.');
        if (! empty($context['dry_run'])) return array('would_delete' => $file, '_current_fingerprint' => Fingerprint::make($this->pluginVersion($file)));
        $deleted = delete_plugins(array($file));
        if (is_wp_error($deleted)) throw new RuntimeException($deleted->get_error_message());
        return array('deleted' => $file, 'rollback_supported' => false);
    }

    public function themeList(): array
    {
        $active = get_stylesheet();
        $items = array();
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $items[] = array('stylesheet' => $stylesheet, 'name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'active' => $stylesheet === $active, 'template' => $theme->get_template());
        }
        return array('themes' => $items);
    }

    public function themeSwitch(array $payload, array $context): array
    {
        $stylesheet = isset($payload['stylesheet']) ? sanitize_text_field((string) $payload['stylesheet']) : '';
        $theme = wp_get_theme($stylesheet);
        if (! $theme->exists() || $theme->errors()) throw new RuntimeException('Theme not found or invalid.');
        $before = get_stylesheet();
        if (! empty($context['dry_run'])) return array('before' => $before, 'after' => $stylesheet, '_current_fingerprint' => Fingerprint::make($before));
        switch_theme($stylesheet);
        return array('active' => get_stylesheet(), '_rollback' => array('action' => 'theme.switch', 'payload' => array('stylesheet' => $before)));
    }

    public function themeInstall(array $payload, array $context): array
    {
        $slug = isset($payload['slug']) ? sanitize_key((string) $payload['slug']) : '';
        if ('' === $slug) throw new RuntimeException('Theme slug is required.');
        if (! empty($context['dry_run'])) return array('would_install' => $slug, '_current_fingerprint' => Fingerprint::make(array('theme_install' => $slug)));
        require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $api = themes_api('theme_information', array('slug' => $slug));
        if (is_wp_error($api) || empty($api->download_link)) throw new RuntimeException(is_wp_error($api) ? $api->get_error_message() : 'Theme package not found.');
        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $installed = $upgrader->install($api->download_link);
        if (true !== $installed) throw new RuntimeException('Theme installation failed.');
        return array('installed' => $slug, 'rollback_supported' => false);
    }

    public function themeUpdate(array $payload, array $context): array
    {
        $stylesheet = isset($payload['stylesheet']) ? sanitize_text_field((string) $payload['stylesheet']) : '';
        $theme = wp_get_theme($stylesheet);
        if (! $theme->exists()) throw new RuntimeException('Theme not found.');
        if (! empty($context['dry_run'])) return array('would_update' => $stylesheet, '_current_fingerprint' => Fingerprint::make($theme->get('Version')));
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        wp_update_themes();
        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $updated = $upgrader->upgrade($stylesheet);
        if (false === $updated || is_wp_error($updated)) throw new RuntimeException(is_wp_error($updated) ? $updated->get_error_message() : 'Theme update failed or no update is available.');
        return array('updated' => $stylesheet, 'version' => wp_get_theme($stylesheet)->get('Version'), 'rollback_supported' => false);
    }

    public function themeDelete(array $payload, array $context): array
    {
        $stylesheet = isset($payload['stylesheet']) ? sanitize_text_field((string) $payload['stylesheet']) : '';
        if ($stylesheet === get_stylesheet() || $stylesheet === get_template()) throw new RuntimeException('Refusing to delete the active theme or its parent.');
        $theme = wp_get_theme($stylesheet);
        if (! $theme->exists()) throw new RuntimeException('Theme not found.');
        if (! empty($context['dry_run'])) return array('would_delete' => $stylesheet, '_current_fingerprint' => Fingerprint::make($theme->get('Version')));
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        $deleted = delete_theme($stylesheet);
        if (is_wp_error($deleted)) throw new RuntimeException($deleted->get_error_message());
        return array('deleted' => $stylesheet, 'rollback_supported' => false);
    }

    public function coreCheckUpdates(): array
    {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_version_check();
        $updates = get_core_updates(array('dismissed' => false));
        $items = array();
        if (is_array($updates)) foreach ($updates as $update) $items[] = array('response' => $update->response ?? null, 'current' => $update->current ?? null, 'locale' => $update->locale ?? null, 'package' => ! empty($update->package));
        return array('updates' => $items);
    }

    public function coreUpdate(array $payload, array $context): array
    {
        global $wp_version;
        if (! empty($context['dry_run'])) return array('current_version' => $wp_version, 'would_update' => true, '_current_fingerprint' => Fingerprint::make($wp_version));
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        wp_version_check();
        $updates = get_core_updates(array('dismissed' => false));
        if (! is_array($updates)) throw new RuntimeException('No core update information available.');
        $candidate = null;
        foreach ($updates as $update) if (isset($update->response) && 'upgrade' === $update->response) { $candidate = $update; break; }
        if (! $candidate) return array('updated' => false, 'message' => 'No core update available.');
        $upgrader = new \Core_Upgrader(new \Automatic_Upgrader_Skin());
        $updated = $upgrader->upgrade($candidate);
        if (is_wp_error($updated) || false === $updated) throw new RuntimeException(is_wp_error($updated) ? $updated->get_error_message() : 'Core update failed.');
        return array('updated' => true, 'target_version' => $candidate->current ?? null, 'rollback_supported' => false);
    }

    public function cronList(): array
    {
        $cron = _get_cron_array();
        $items = array();
        foreach ((array) $cron as $timestamp => $hooks) foreach ((array) $hooks as $hook => $events) foreach ((array) $events as $key => $event) $items[] = array('timestamp' => (int) $timestamp, 'hook' => (string) $hook, 'schedule' => $event['schedule'] ?? false, 'args' => $event['args'] ?? array(), 'interval' => $event['interval'] ?? null, 'key' => (string) $key);
        return array('events' => $items);
    }

    public function cronRun(array $payload, array $context): array
    {
        $hook = isset($payload['hook']) ? sanitize_key((string) $payload['hook']) : '';
        if ('' === $hook) throw new RuntimeException('hook is required.');
        if (! empty($context['dry_run'])) return array('would_run' => $hook, '_current_fingerprint' => Fingerprint::make(array('hook' => $hook)));
        do_action_ref_array($hook, isset($payload['args']) && is_array($payload['args']) ? $payload['args'] : array());
        return array('ran' => $hook, 'rollback_supported' => false);
    }

    public function cronDelete(array $payload, array $context): array
    {
        $hook = isset($payload['hook']) ? sanitize_key((string) $payload['hook']) : '';
        if ('' === $hook) throw new RuntimeException('hook is required.');
        if (! empty($context['dry_run'])) return array('would_clear' => $hook, '_current_fingerprint' => Fingerprint::make(wp_next_scheduled($hook)));
        $count = wp_clear_scheduled_hook($hook, isset($payload['args']) && is_array($payload['args']) ? $payload['args'] : array());
        if (is_wp_error($count)) throw new RuntimeException($count->get_error_message());
        return array('cleared' => (int) $count, 'rollback_supported' => false);
    }

    public function cronSchedule(array $payload, array $context): array
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

    public function rewriteFlush(array $payload, array $context): array
    {
        if (! empty($context['dry_run'])) return array('would_flush' => true, '_current_fingerprint' => Fingerprint::make(get_option('rewrite_rules', array())));
        flush_rewrite_rules(! empty($payload['hard']));
        return array('flushed' => true, 'rollback_supported' => false);
    }

    public function cacheFlush(array $payload, array $context): array
    {
        if (! empty($context['dry_run'])) return array('would_flush' => true, '_current_fingerprint' => Fingerprint::make(array('cache' => 'current')));
        return array('flushed' => wp_cache_flush(), 'rollback_supported' => false);
    }

    public function siteList(array $payload): array
    {
        if (! is_multisite()) throw new RuntimeException('Not a multisite installation.');
        $sites = get_sites(array('number' => isset($payload['per_page']) ? max(1, min(100, (int) $payload['per_page'])) : 50, 'offset' => isset($payload['offset']) ? max(0, (int) $payload['offset']) : 0));
        return array('sites' => array_map(array($this, 'siteSnapshot'), $sites));
    }

    public function siteCreate(array $payload, array $context): array
    {
        if (! is_multisite()) throw new RuntimeException('Not a multisite installation.');
        $domain = isset($payload['domain']) ? strtolower(trim((string) $payload['domain'])) : '';
        $path = isset($payload['path']) ? '/' . trim((string) $payload['path'], '/') . '/' : '/';
        if ('' === $domain) throw new RuntimeException('domain is required.');
        if (! empty($context['dry_run'])) return array('would_create' => compact('domain', 'path'), '_current_fingerprint' => Fingerprint::make(array('new_site' => $domain . $path)));
        $siteId = wp_insert_site(array('domain' => $domain, 'path' => $path, 'network_id' => isset($payload['network_id']) ? (int) $payload['network_id'] : get_current_network_id(), 'public' => isset($payload['public']) ? (int) (bool) $payload['public'] : 1));
        if (is_wp_error($siteId)) throw new RuntimeException($siteId->get_error_message());
        return array('site' => $this->siteSnapshot(get_site($siteId)), '_rollback' => array('action' => 'multisite.site.delete', 'payload' => array('id' => (int) $siteId)));
    }

    public function siteUpdate(array $payload, array $context): array
    {
        if (! is_multisite()) throw new RuntimeException('Not a multisite installation.');
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $site = get_site($id);
        if (! $site) throw new RuntimeException('Site not found.');
        $before = $this->siteSnapshot($site);
        $data = array();
        foreach (array('domain', 'path', 'registered', 'last_updated') as $field) if (array_key_exists($field, $payload)) $data[$field] = (string) $payload[$field];
        foreach (array('public', 'archived', 'mature', 'spam', 'deleted') as $field) if (array_key_exists($field, $payload)) $data[$field] = (int) (bool) $payload[$field];
        if (! empty($context['dry_run'])) return array('before' => $before, 'changes' => $data, '_current_fingerprint' => Fingerprint::make($before));
        $updated = wp_update_site($id, $data);
        if (is_wp_error($updated)) throw new RuntimeException($updated->get_error_message());
        return array('site' => $this->siteSnapshot(get_site($id)), '_rollback' => array('action' => 'multisite.site.update', 'payload' => $before));
    }

    public function siteDelete(array $payload, array $context): array
    {
        if (! is_multisite()) throw new RuntimeException('Not a multisite installation.');
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        if ($id === get_main_site_id()) throw new RuntimeException('Refusing to delete the main site.');
        $site = get_site($id);
        if (! $site) throw new RuntimeException('Site not found.');
        $before = $this->siteSnapshot($site);
        if (! empty($context['dry_run'])) return array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        $deleted = wp_delete_site($id);
        if (is_wp_error($deleted)) throw new RuntimeException($deleted->get_error_message());
        return array('deleted' => $id, 'rollback_supported' => false);
    }

    private function user(array $payload): \WP_User
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $user = get_user_by('id', $id);
        if (! $user instanceof \WP_User) throw new RuntimeException('User not found.');
        return $user;
    }

    private function userSnapshot(\WP_User $user): array
    {
        return array('id' => (int) $user->ID, 'login' => (string) $user->user_login, 'email' => (string) $user->user_email, 'display_name' => (string) $user->display_name, 'first_name' => (string) $user->first_name, 'last_name' => (string) $user->last_name, 'description' => (string) $user->description, 'url' => (string) $user->user_url, 'roles' => array_values($user->roles), 'capabilities' => $user->allcaps);
    }

    private function applyUserFields(int $id, array $payload): void
    {
        $fields = array('ID' => $id);
        $map = array('email' => 'user_email', 'display_name' => 'display_name', 'first_name' => 'first_name', 'last_name' => 'last_name', 'description' => 'description', 'url' => 'user_url');
        foreach ($map as $source => $target) if (array_key_exists($source, $payload)) $fields[$target] = (string) $payload[$source];
        if (count($fields) > 1) {
            $result = wp_update_user($fields);
            if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
        }
    }

    private function pluginFile(array $payload): string
    {
        $file = isset($payload['file']) ? str_replace('\\', '/', (string) $payload['file']) : '';
        if ('' === $file || false !== strpos($file, '..') || 0 === strpos($file, '/')) throw new RuntimeException('A safe relative plugin file is required.');
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (! isset(get_plugins()[$file])) throw new RuntimeException('Plugin file not found.');
        return $file;
    }

    private function pluginVersion(string $file): string
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        return isset($plugins[$file]['Version']) ? (string) $plugins[$file]['Version'] : '';
    }

    private function siteSnapshot(\WP_Site $site): array
    {
        return array('id' => (int) $site->blog_id, 'network_id' => (int) $site->site_id, 'domain' => (string) $site->domain, 'path' => (string) $site->path, 'registered' => (string) $site->registered, 'last_updated' => (string) $site->last_updated, 'public' => (int) $site->public, 'archived' => (int) $site->archived, 'mature' => (int) $site->mature, 'spam' => (int) $site->spam, 'deleted' => (int) $site->deleted);
    }
}
