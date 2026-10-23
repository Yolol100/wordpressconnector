<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

final class SystemAdapter
{
    public function register(Registry $registry): void
    {
        $privileged = array('privileged' => true);
        $sensitive = array('privileged' => true, 'sensitive' => true);
        $systemUpdate = array('mutation' => true, 'privileged' => true, 'system_update' => true);

        $registry->register('system.doctor', array($this, 'doctor'), array('description' => 'Run connector/runtime preflight diagnostics.'));

        $registry->register('user.list', array($this, 'userList'), $sensitive + array('capability' => 'list_users', 'description' => 'List WordPress users.'));
        $registry->register('user.get', array($this, 'userGet'), $sensitive + array('capability' => 'list_users', 'description' => 'Read a WordPress user.'));
        $registry->register('user.create', array($this, 'userCreate'), $sensitive + array('mutation' => true, 'capability' => 'create_users', 'description' => 'Create a WordPress user with a server-generated password.'));
        $registry->register('user.update', array($this, 'userUpdate'), $sensitive + array('mutation' => true, 'capability' => 'edit_users', 'description' => 'Update WordPress user profile fields and roles.'));
        $registry->register('user.force_password_reset', array($this, 'userForcePasswordReset'), $sensitive + array('mutation' => true, 'capability' => 'edit_users', 'description' => 'Immediately rotate a WordPress user password, revoke active sessions and send a dedicated security reset link without exposing secret material. Non-rollbackable.'));
        $registry->register('user.delete', array($this, 'userDelete'), $sensitive + array('mutation' => true, 'capability' => 'delete_users', 'description' => 'Delete a WordPress user.'));

        $registry->register('role.list', array($this, 'roleList'), $privileged + array('capability' => 'promote_users', 'description' => 'List roles and capabilities.'));
        $registry->register('role.create', array($this, 'roleCreate'), $privileged + array('mutation' => true, 'capability' => 'promote_users', 'description' => 'Create a WordPress role.'));
        $registry->register('role.update', array($this, 'roleUpdate'), $privileged + array('mutation' => true, 'capability' => 'promote_users', 'description' => 'Add or remove capabilities from a role.'));
        $registry->register('role.delete', array($this, 'roleDelete'), $privileged + array('mutation' => true, 'capability' => 'promote_users', 'description' => 'Delete a WordPress role.'));

        $registry->register('plugin.list', array($this, 'pluginList'), $privileged + array('capability' => 'activate_plugins', 'description' => 'List installed plugins with activation, available update state and permitted actions.'));
        $registry->register('plugin.directory.search', array($this, 'pluginDirectorySearch'), $privileged + array('capability' => 'install_plugins', 'description' => 'Search the official WordPress.org plugin directory for bounded install candidates.'));
        $registry->register('plugin.activate', array($this, 'pluginActivate'), $privileged + array('mutation' => true, 'capability' => 'activate_plugins', 'description' => 'Activate an installed plugin.'));
        $registry->register('plugin.deactivate', array($this, 'pluginDeactivate'), $privileged + array('mutation' => true, 'capability' => 'activate_plugins', 'description' => 'Deactivate an installed plugin.'));
        $registry->register('plugin.install', array($this, 'pluginInstall'), $systemUpdate + array('capability' => 'install_plugins', 'description' => 'Install a plugin from WordPress.org by slug.'));
        $registry->register('plugin.update', array($this, 'pluginUpdate'), $systemUpdate + array('capability' => 'update_plugins', 'description' => 'Update one installed plugin.'));
        $registry->register('plugin.delete', array($this, 'pluginDelete'), $systemUpdate + array('capability' => 'delete_plugins', 'description' => 'Delete an inactive installed plugin.'));

        $registry->register('theme.list', array($this, 'themeList'), $privileged + array('capability' => 'switch_themes', 'description' => 'List installed themes.'));
        $registry->register('theme.switch', array($this, 'themeSwitch'), $privileged + array('mutation' => true, 'capability' => 'switch_themes', 'description' => 'Switch the active theme.'));
        $registry->register('theme.install', array($this, 'themeInstall'), $systemUpdate + array('capability' => 'install_themes', 'description' => 'Install a theme from WordPress.org by slug.'));
        $registry->register('theme.update', array($this, 'themeUpdate'), $systemUpdate + array('capability' => 'update_themes', 'description' => 'Update one installed theme.'));
        $registry->register('theme.delete', array($this, 'themeDelete'), $systemUpdate + array('capability' => 'delete_themes', 'description' => 'Delete an inactive theme.'));

        $registry->register('core.check_updates', array($this, 'coreCheckUpdates'), $privileged + array('capability' => 'update_core', 'description' => 'Check WordPress core updates.'));
        $registry->register('core.update', array($this, 'coreUpdate'), $systemUpdate + array('capability' => 'update_core', 'description' => 'Update WordPress core. Non-rollbackable.'));

        $registry->register('cron.list', array($this, 'cronList'), $privileged + array('capability' => 'manage_options', 'description' => 'List scheduled cron events.'));
        $registry->register('cron.run', array($this, 'cronRun'), $privileged + array('mutation' => true, 'capability' => 'manage_options', 'description' => 'Run one existing scheduled cron event immediately.'));
        $registry->register('cron.delete', array($this, 'cronDelete'), $privileged + array('mutation' => true, 'capability' => 'manage_options', 'description' => 'Delete scheduled events for one hook.'));
        $registry->register('cron.schedule', array($this, 'cronSchedule'), $privileged + array('mutation' => true, 'capability' => 'manage_options', 'description' => 'Reschedule an existing cron hook and argument set.'));

        $registry->register('rewrite.flush', array($this, 'rewriteFlush'), $privileged + array('mutation' => true, 'description' => 'Flush rewrite rules.'));
        $registry->register('cache.flush', array($this, 'cacheFlush'), $privileged + array('mutation' => true, 'description' => 'Flush WordPress object cache.'));

        $registry->register('multisite.site.list', array($this, 'siteList'), $sensitive + array('capability' => 'manage_sites', 'description' => 'List multisite network sites.'));
        $registry->register('multisite.site.create', array($this, 'siteCreate'), $sensitive + array('mutation' => true, 'capability' => 'create_sites', 'description' => 'Create a multisite site.'));
        $registry->register('multisite.site.update', array($this, 'siteUpdate'), $sensitive + array('mutation' => true, 'capability' => 'manage_sites', 'description' => 'Update multisite site fields.'));
        $registry->register('multisite.site.delete', array($this, 'siteDelete'), $sensitive + array('mutation' => true, 'system_update' => true, 'capability' => 'delete_sites', 'description' => 'Delete a multisite site.'));
    }

    public function doctor(): array
    {
        global $wpdb, $wp_version;
        $checks = array(
            'wordpress_bootstrap' => defined('ABSPATH'),
            // WordPress exposes readiness publicly; dbh is protected.
            'database' => $wpdb instanceof \wpdb && (bool) $wpdb->ready,
            'wp_cli' => defined('WP_CLI') && WP_CLI,
            'uploads_writable' => wp_is_writable(wp_get_upload_dir()['basedir']),
            'plugin_dir_writable' => wp_is_writable(WP_PLUGIN_DIR),
            'wordpress_version' => (string) $wp_version,
            'php_version' => PHP_VERSION,
            'woocommerce' => class_exists('WooCommerce'),
            'elementor' => class_exists('Elementor\\Plugin'),
            'acf' => function_exists('get_fields'),
            'multisite' => is_multisite(),
            'file_modifications_allowed' => function_exists('wp_is_file_mod_allowed') ? wp_is_file_mod_allowed('plugin_update') : (! defined('DISALLOW_FILE_MODS') || ! DISALLOW_FILE_MODS),
            'file_editor_disabled' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
            'file_modifications_disabled' => defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS,
            'plugin_update_permission' => current_user_can('update_plugins'),
            'plugin_install_permission' => current_user_can('install_plugins'),
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
        if (isset($payload['role']) && ! current_user_can('promote_users')) throw new RuntimeException('Current user is not allowed to assign roles.');
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
        if ((isset($payload['role']) || isset($payload['add_roles']) || isset($payload['remove_roles'])) && ! current_user_can('promote_users')) throw new RuntimeException('Current user is not allowed to change user roles.');
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

    public function userForcePasswordReset(array $payload, array $context): array
    {
        $user = $this->user($payload);
        $userId = (int) $user->ID;
        if (! current_user_can('edit_user', $userId)) {
            throw new RuntimeException('Current user is not allowed to reset this user password.');
        }

        $before = $this->userSnapshot($user);
        $result = array(
            'user_id' => $userId,
            'login' => (string) $user->user_login,
            'would_rotate_password' => true,
            'would_revoke_sessions' => true,
            'would_send_password_reset_email' => true,
            'email_delivery_not_guaranteed' => true,
            'rollback_supported' => false,
            '_current_fingerprint' => Fingerprint::make($before),
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }

        $password = wp_generate_password(64, true, true);
        wp_set_password($password, $userId);
        unset($password);

        if (class_exists('WP_Session_Tokens')) {
            \WP_Session_Tokens::get_instance($userId)->destroy_all();
        }

        $key = get_password_reset_key($user);
        $result['password_rotated'] = true;
        $result['sessions_revoked'] = true;
        if (is_wp_error($key)) {
            $result['reset_email_accepted_by_wp_mail'] = false;
            $result['reset_email_error'] = sanitize_text_field($key->get_error_message());
            return $result;
        }

        $siteName = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        $resetUrl = network_site_url(
            'wp-login.php?action=rp&key=' . rawurlencode((string) $key) . '&login=' . rawurlencode((string) $user->user_login),
            'login'
        );
        /* translators: %s: WordPress site name. */
        $subject = sprintf(__('[%s] Security password reset', 'wordpressconnector'), $siteName);
        /* translators: %s: WordPress site name. */
        $message = sprintf(__('An administrator reset the password for your account on %s as a security measure.', 'wordpressconnector'), $siteName) . "\r\n\r\n";
        $message .= __('All active sessions have been signed out. Set a new password using this link:', 'wordpressconnector') . "\r\n\r\n";
        $message .= $resetUrl . "\r\n";

        $result['reset_email_accepted_by_wp_mail'] = (bool) wp_mail((string) $user->user_email, $subject, $message);
        if (! $result['reset_email_accepted_by_wp_mail']) {
            $result['reset_email_error'] = 'WordPress mail transport rejected the reset notification.';
        }

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
        foreach ($caps as $cap => $grant) {
            if (! is_string($cap) || sanitize_key($cap) !== $cap || '' === $cap) throw new RuntimeException('Role capability keys must be safe strings.');
            $caps[$cap] = Input::boolValue($grant, 'payload.capabilities.' . $cap);
        }
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
            if (is_int($cap)) $role->add_cap((string) $grant, true); else $role->add_cap((string) $cap, Input::boolValue($grant, 'payload.add.' . (string) $cap));
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
        $transient = get_site_transient('update_plugins');
        $offers = is_object($transient) && isset($transient->response) && is_array($transient->response)
            ? $transient->response : array();
        $checkedAt = is_object($transient) && isset($transient->last_checked)
            ? (int) $transient->last_checked : 0;
        $canModify = function_exists('wp_is_file_mod_allowed')
            ? wp_is_file_mod_allowed('plugin_update')
            : (! defined('DISALLOW_FILE_MODS') || ! DISALLOW_FILE_MODS);
        $items = array();
        foreach (get_plugins() as $file => $data) {
            // Match WordPress Core's REST plugin visibility for multisite site administrators.
            // Inactive network-only plugins are visible to network plugin managers only.
            if (is_multisite() && ! current_user_can('manage_network_plugins')
                && ! is_plugin_active($file) && is_network_only_plugin($file)) {
                continue;
            }
            $network = is_multisite() ? is_plugin_active_for_network($file) : false;
            $active = is_plugin_active($file) || $network;
            $offer = $offers[$file] ?? null;
            $newVersion = is_object($offer) ? ($offer->new_version ?? $offer->version ?? null)
                : (is_array($offer) ? ($offer['new_version'] ?? $offer['version'] ?? null) : null);
            $isConnector = 'wordpressconnector/wordpressconnector.php' === $file;
            $items[] = array(
                'file' => $file,
                'name' => sanitize_text_field((string) ($data['Name'] ?? $file)),
                'version' => (string) ($data['Version'] ?? ''),
                'active' => $active,
                'network_active' => $network,
                'update_available' => $checkedAt > 0 ? (null !== $newVersion) : null,
                'available_version' => is_string($newVersion) ? $newVersion : null,
                'can_activate' => ! $active && current_user_can('activate_plugins'),
                'can_deactivate' => $active && ! $network && ! $isConnector && current_user_can('activate_plugins'),
                'can_update' => $canModify && current_user_can('update_plugins'),
                'can_delete' => ! $active && ! $isConnector && $canModify && current_user_can('delete_plugins'),
            );
        }
        return array(
            'plugins' => $items,
            'updates_checked_gmt' => $checkedAt > 0 ? gmdate('c', $checkedAt) : null,
            'update_state' => $checkedAt > 0 ? 'cached_wordpress_update_snapshot' : 'not_checked',
            'update_listing_is_not_an_install_or_health_test' => true,
        );
    }

    public function pluginDirectorySearch(array $payload, array $context = array()): array
    {
        if (array_diff(array_keys($payload), array('search', 'page', 'per_page'))) {
            throw new RuntimeException('Plugin directory search accepts only search, page and per_page.');
        }
        $search = isset($payload['search']) && is_string($payload['search'])
            ? trim(sanitize_text_field($payload['search'])) : '';
        if (strlen($search) < 2 || strlen($search) > 70 || preg_match('/[\x00-\x1F\x7F<>]/', $search)) {
            throw new RuntimeException('Plugin directory query must contain 2–70 plain-text bytes.');
        }
        $page = isset($payload['page']) && is_int($payload['page']) ? $payload['page'] : 1;
        $limit = isset($payload['per_page']) && is_int($payload['per_page']) ? $payload['per_page'] : 10;
        if ($page < 1 || $page > 20 || $limit < 1 || $limit > 20) {
            throw new RuntimeException('Plugin directory pagination is out of bounds.');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        $result = plugins_api('query_plugins', array(
            'search' => $search,
            'page' => $page,
            'per_page' => $limit,
            'fields' => array('sections' => false, 'description' => false, 'short_description' => true),
        ));
        if (is_wp_error($result) || ! is_object($result) || ! isset($result->plugins) || ! is_array($result->plugins)) {
            throw new RuntimeException(is_wp_error($result) ? $result->get_error_message()
                : 'WordPress.org plugin directory could not be queried.');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed = get_plugins();
        $items = array();
        foreach (array_slice($result->plugins, 0, $limit) as $plugin) {
            if (! is_object($plugin)) {
                continue;
            }
            $slug = isset($plugin->slug) ? (string) $plugin->slug : '';
            if (! preg_match('/^[a-z0-9][a-z0-9-]{0,79}\z/', $slug)) {
                continue;
            }
            $matches = array();
            foreach ($installed as $file => $meta) {
                if (dirname($file) === $slug || $file === $slug . '.php') {
                    $matches[] = $file;
                }
            }
            $items[] = array(
                'slug' => $slug,
                'name' => sanitize_text_field((string) ($plugin->name ?? $slug)),
                'version' => sanitize_text_field((string) ($plugin->version ?? '')),
                'short_description' => substr(sanitize_text_field((string) ($plugin->short_description ?? '')), 0, 240),
                'requires' => sanitize_text_field((string) ($plugin->requires ?? '')),
                'requires_php' => sanitize_text_field((string) ($plugin->requires_php ?? '')),
                'tested' => sanitize_text_field((string) ($plugin->tested ?? '')),
                'active_installs' => isset($plugin->active_installs) ? (int) $plugin->active_installs : null,
                'already_installed' => ! empty($matches),
                'installed_plugin_files' => $matches,
                'directory_url' => 'https://wordpress.org/plugins/' . $slug . '/',
            );
        }
        return array(
            'source' => 'WordPress.org plugins_api (filterable by installed plugins)',
            'query' => $search,
            'page' => $page,
            'plugins' => $items,
            'results_total' => isset($result->info) && is_array($result->info) && isset($result->info['results'])
                ? (int) $result->info['results']
                : (isset($result->info) && is_object($result->info) && isset($result->info->results)
                    ? (int) $result->info->results : null),
            'read_only' => true,
        );
    }

    public function pluginActivate(array $payload, array $context): array
    {
        $file = $this->pluginFile($payload);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $before = is_plugin_active($file);
        $networkWide = Input::bool($payload, 'network_wide');
        if (! empty($context['dry_run'])) return array('before_active' => $before, 'after_active' => true, 'network_wide' => $networkWide, '_current_fingerprint' => Fingerprint::make($before));
        if ($networkWide && (! is_multisite() || ! current_user_can('manage_network_plugins'))) {
            throw new RuntimeException('Network-wide plugin activation requires network plugin administrator permission.');
        }
        // Normal activation must execute register_activation_hook callbacks.
        // Silent activation is appropriate for Core upgrades, not user-requested activation.
        $result = activate_plugin($file, '', $networkWide, false);
        if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
        $activeNow = $networkWide && is_multisite() ? is_plugin_active_for_network($file) : is_plugin_active($file);
        if (! $activeNow) throw new RuntimeException('Plugin activation did not persist; check plugin requirements or security restrictions.');
        return array('file' => $file, 'active' => true, 'readback_verified' => true, '_rollback' => array('action' => 'plugin.deactivate', 'payload' => array('file' => $file, 'network_wide' => $networkWide)));
    }

    public function pluginDeactivate(array $payload, array $context): array
    {
        $file = $this->pluginFile($payload);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ('wordpressconnector/wordpressconnector.php' === $file) {
            throw new RuntimeException('The Connector cannot deactivate its own management connection; use WordPress admin for intentional removal.');
        }
        $networkWide = Input::bool($payload, 'network_wide');
        if ($networkWide && (! is_multisite() || ! current_user_can('manage_network_plugins'))) {
            throw new RuntimeException('Network plugin deactivation requires network plugin administrator permission.');
        }
        if (! $networkWide && is_multisite() && is_plugin_active_for_network($file)) {
            throw new RuntimeException('Network-activated plugins must be managed by the network administrator.');
        }
        $before = $networkWide && is_multisite() ? is_plugin_active_for_network($file) : is_plugin_active($file);
        if (! empty($context['dry_run'])) return array('before_active' => $before, 'after_active' => false, 'network_wide' => $networkWide, '_current_fingerprint' => Fingerprint::make($before));
        deactivate_plugins($file, false, $networkWide);
        $stillActive = $networkWide && is_multisite() ? is_plugin_active_for_network($file) : is_plugin_active($file);
        if ($stillActive) throw new RuntimeException('Plugin deactivation did not persist; check dependency or security policy.');
        return array('file' => $file, 'active' => false, 'readback_verified' => true, '_rollback' => $before ? array('action' => 'plugin.activate', 'payload' => array('file' => $file, 'network_wide' => $networkWide)) : null);
    }

    public function pluginInstall(array $payload, array $context): array
    {
        $slug = isset($payload['slug']) && is_string($payload['slug']) ? $payload['slug'] : '';
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,79}\z/', $slug)) {
            throw new RuntimeException('WordPress.org plugin slug must be an exact lowercase directory slug.');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $api = plugins_api('plugin_information', array('slug' => $slug, 'fields' => array('sections' => false)));
        if (is_wp_error($api) || ! is_object($api) || ! is_string($api->download_link ?? null)) {
            throw new RuntimeException(is_wp_error($api) ? $api->get_error_message() : 'Official WordPress.org plugin package not found.');
        }
        if (isset($api->slug) && (string) $api->slug !== $slug) {
            throw new RuntimeException('Plugin directory response identity does not match requested slug.');
        }
        $url = $this->officialDirectoryPackageUrl($api->download_link, $slug, 'plugin');
        if (! empty($context['dry_run'])) {
            return array(
                'would_install' => $slug,
                'verified_package_origin' => 'downloads.wordpress.org',
                '_current_fingerprint' => Fingerprint::make(array('plugin_install' => $slug)),
            );
        }
        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $installed = $upgrader->install($url);
        if (is_wp_error($installed)) throw new RuntimeException($installed->get_error_message());
        if (true !== $installed) throw new RuntimeException('WordPress plugin installation failed; check filesystem permissions or existing destination.');
        wp_clean_plugins_cache(true);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installedFiles = array();
        foreach (get_plugins() as $installedFile => $pluginData) {
            if (dirname($installedFile) === $slug || $installedFile === $slug . '.php') {
                $installedFiles[] = $installedFile;
            }
        }
        if (! $installedFiles) throw new RuntimeException('Plugin installer returned success, but the requested WordPress.org slug was not found afterward.');
        return array('installed' => $slug, 'installed_files' => $installedFiles, 'readback_verified' => true, 'rollback_supported' => false);
    }

    public function pluginUpdate(array $payload, array $context): array
    {
        $file = $this->pluginFile($payload);
        if ('wordpressconnector/wordpressconnector.php' === $file) {
            throw new RuntimeException('Update WordPress Connector through connector.update.apply or the verified native Plugins updater.');
        }
        $previousVersion = $this->pluginVersion($file);
        if (! empty($context['dry_run'])) return array('would_update' => $file, '_current_fingerprint' => Fingerprint::make($previousVersion));
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        wp_update_plugins();
        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $updated = $upgrader->upgrade($file);
        if (false === $updated || is_wp_error($updated)) throw new RuntimeException(is_wp_error($updated) ? $updated->get_error_message() : 'Plugin update failed or no update is available.');
        wp_clean_plugins_cache(true);
        $version = $this->pluginVersion($file);
        if (! version_compare($version, $previousVersion, '>')) {
            throw new RuntimeException('Plugin update could not be verified: installed version did not increase.');
        }
        return array('updated' => $file, 'version' => $version, 'readback_verified' => true, 'rollback_supported' => false);
    }

    public function pluginDelete(array $payload, array $context): array
    {
        $file = $this->pluginFile($payload);
        if ('wordpressconnector/wordpressconnector.php' === $file) {
            throw new RuntimeException('The Connector cannot delete its own management connection.');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_plugin_active($file) || (is_multisite() && is_plugin_active_for_network($file))) {
            throw new RuntimeException('Plugin must be inactive before deletion.');
        }
        if (! empty($context['dry_run'])) return array('would_delete' => $file, '_current_fingerprint' => Fingerprint::make($this->pluginVersion($file)));
        $deleted = delete_plugins(array($file));
        if (is_wp_error($deleted)) throw new RuntimeException($deleted->get_error_message());
        if (true !== $deleted) throw new RuntimeException('WordPress could not complete plugin deletion; filesystem credentials or server permission may be required.');
        wp_clean_plugins_cache(true);
        if (isset(get_plugins()[$file])) throw new RuntimeException('Plugin deletion did not persist: plugin still appears in WordPress inventory.');
        return array('deleted' => $file, 'readback_verified' => true, 'rollback_supported' => false);
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
        if (get_stylesheet() !== $stylesheet) {
            throw new RuntimeException('Theme activation did not persist; check WordPress theme requirements and site restrictions.');
        }
        return array('active' => $stylesheet, 'readback_verified' => true,
            '_rollback' => array('action' => 'theme.switch', 'payload' => array('stylesheet' => $before)));
    }

    public function themeInstall(array $payload, array $context): array
    {
        $slug = isset($payload['slug']) && is_string($payload['slug']) ? $payload['slug'] : '';
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,79}\z/', $slug)) {
            throw new RuntimeException('WordPress.org theme slug must be an exact lowercase directory slug.');
        }
        require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $api = themes_api('theme_information', array('slug' => $slug));
        if (is_wp_error($api) || ! is_object($api) || ! is_string($api->download_link ?? null)) {
            throw new RuntimeException(is_wp_error($api) ? $api->get_error_message() : 'Official WordPress.org theme package not found.');
        }
        if (isset($api->slug) && (string) $api->slug !== $slug) {
            throw new RuntimeException('Theme directory response identity does not match requested slug.');
        }
        $url = $this->officialDirectoryPackageUrl($api->download_link, $slug, 'theme');
        if (! empty($context['dry_run'])) {
            return array('would_install' => $slug, 'verified_package_origin' => 'downloads.wordpress.org',
                '_current_fingerprint' => Fingerprint::make(array('theme_install' => $slug)));
        }
        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $installed = $upgrader->install($url);
        if (is_wp_error($installed)) throw new RuntimeException($installed->get_error_message());
        if (true !== $installed) throw new RuntimeException('WordPress theme installation failed; check filesystem permissions.');
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
        if (true !== $deleted) {
            throw new RuntimeException('Theme deletion was not confirmed; filesystem credentials or permissions may be required.');
        }
        wp_clean_themes_cache();
        if (wp_get_theme($stylesheet)->exists()) {
            throw new RuntimeException('Theme deletion readback failed: the theme is still installed.');
        }
        return array('deleted' => $stylesheet, 'readback_verified' => true, 'rollback_supported' => false);
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
        $args = isset($payload['args']) && is_array($payload['args']) ? $payload['args'] : array();
        if ('' === $hook) throw new RuntimeException('hook is required.');
        $event = wp_get_scheduled_event($hook, $args);
        if (! $event) throw new RuntimeException('cron.run only accepts an existing scheduled hook with the exact argument set.');
        $state = array('hook' => $hook, 'timestamp' => (int) $event->timestamp, 'schedule' => $event->schedule, 'args' => $event->args);
        if (! empty($context['dry_run'])) return array('would_run' => $state, '_current_fingerprint' => Fingerprint::make($state));
        do_action_ref_array($hook, $event->args);
        return array('ran' => $state, 'rollback_supported' => false);
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
        $existing = wp_get_scheduled_event($hook, $args);
        if (! $existing) throw new RuntimeException('cron.schedule only reschedules an existing hook with the exact argument set.');
        $state = array('hook' => $hook, 'timestamp' => $timestamp, 'recurrence' => $recurrence, 'args' => $args, 'existing_timestamp' => (int) $existing->timestamp);
        if (! empty($context['dry_run'])) return array('would_schedule' => $state, '_current_fingerprint' => Fingerprint::make(array('timestamp' => (int) $existing->timestamp, 'schedule' => $existing->schedule, 'args' => $existing->args)));
        $result = '' === $recurrence ? wp_schedule_single_event($timestamp, $hook, $args, true) : wp_schedule_event($timestamp, $recurrence, $hook, $args, true);
        if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
        return array('scheduled' => true, 'event' => $state, 'rollback_supported' => false);
    }

    public function rewriteFlush(array $payload, array $context): array
    {
        $hard = Input::bool($payload, 'hard');
        if (! empty($context['dry_run'])) return array('would_flush' => true, 'hard' => $hard, '_current_fingerprint' => Fingerprint::make(get_option('rewrite_rules', array())));
        flush_rewrite_rules($hard);
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
        $public = Input::bool($payload, 'public', true);
        if (! empty($context['dry_run'])) return array('would_create' => compact('domain', 'path', 'public'), '_current_fingerprint' => Fingerprint::make(array('new_site' => $domain . $path)));
        $siteId = wp_insert_site(array('domain' => $domain, 'path' => $path, 'network_id' => isset($payload['network_id']) ? (int) $payload['network_id'] : get_current_network_id(), 'public' => (int) $public));
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
        foreach (array('public', 'archived', 'mature', 'spam', 'deleted') as $field) if (array_key_exists($field, $payload)) $data[$field] = (int) Input::bool($payload, $field);
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

    /**
     * A plugin can filter plugins_api()/themes_api() responses. Never install an
     * untrusted package URL merely because that API returned it. Custom ZIPs
     * use plugin.install_package with independent SHA-256 and archive checks.
     */
    private function officialDirectoryPackageUrl(string $url, string $slug, string $kind): string
    {
        if (strlen($url) > 320 || ! in_array($kind, array('plugin', 'theme'), true)) {
            throw new RuntimeException('Invalid official directory package reference.');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'downloads.wordpress.org' ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) ||
            isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new RuntimeException('Directory package URL is not a canonical HTTPS WordPress.org download.');
        }
        $pattern = '#\A/' . $kind . '/' . preg_quote($slug, '#') .
            '(?:\.[A-Za-z0-9][A-Za-z0-9._+-]{0,95})?\.zip\z#D';
        if (! preg_match($pattern, (string) ($parts['path'] ?? ''))) {
            throw new RuntimeException('Directory package URL does not match the requested slug and archive type.');
        }
        return $url;
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
