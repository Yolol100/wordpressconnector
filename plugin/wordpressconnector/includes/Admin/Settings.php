<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Admin;

use Webactueel\WordPressConnector\Security\SitePairing;

final class Settings
{
    private const PAGE = 'wordpressconnector';
    private const DIRECT_MCP_PASSWORD_LABEL = 'WordPress Connector - Direct MCP';

    public function register(): void
    {
        if (! is_admin()) { return; }
        add_action('admin_menu', array($this, 'registerPage'));
        add_action('admin_notices', array($this, 'unpairedNotice'));
        add_action('admin_post_wpconnector_site_pairing', array($this, 'saveSitePairing'));
        add_action('admin_post_wpconnector_create_application_password', array($this, 'createApplicationPassword'));
    }

    public function unpairedNotice(): void
    {
        if (! current_user_can('manage_options') || SitePairing::pairedAdministratorId()) {
            return;
        }
        $url = admin_url('options-general.php?page=' . self::PAGE);
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('WordPress Connector: GitHub connection is not active. Normal administrator activation connects automatically; CLI, network-wide or older installations can be connected here.', 'wordpressconnector') . ' ';
        echo '<a href="' . esc_url($url) . '">' . esc_html__('Review site authorization', 'wordpressconnector') . '</a>';
        echo '</p></div>';
    }

    public function registerPage(): void
    {
        add_options_page('WordPress Connector', 'WordPress Connector', 'manage_options', self::PAGE, array($this, 'renderPage'));
    }

    public function saveSitePairing(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Only a site administrator can authorize the Connector.', 'wordpressconnector'));
        }
        check_admin_referer('wpconnector_site_pairing');
        $decision = isset($_POST['pairing_decision']) && is_string($_POST['pairing_decision'])
            ? sanitize_key(wp_unslash($_POST['pairing_decision']))
            : '';
        if ('enable' === $decision) {
            SitePairing::enableForAdministrator(get_current_user_id());
        } elseif ('disable' === $decision) {
            SitePairing::disable();
        } else {
            wp_die(esc_html__('Unsupported GitHub authorization decision.', 'wordpressconnector'));
        }
        wp_safe_redirect(add_query_arg('pairing_saved', '1', admin_url('options-general.php?page=' . self::PAGE)));
        exit;
    }


    /**
     * A WordPress administrator may explicitly generate one dedicated
     * Application Password for their OWN account. Only this one response
     * contains the plaintext credential; GitHub OIDC never uses it.
     */
    public function createApplicationPassword(): void
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
            wp_die(esc_html__('Use the WordPress Connector settings form to create a credential.', 'wordpressconnector'),
                esc_html__('Method not allowed', 'wordpressconnector'), array('response' => 405));
        }
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Only the signed-in site administrator can create a Connector credential.', 'wordpressconnector'),
                esc_html__('Permission denied', 'wordpressconnector'), array('response' => 403));
        }
        check_admin_referer('wpconnector_create_application_password');
        if ('1' !== ($_POST['confirm_direct_access'] ?? null)) {
            wp_die(esc_html__('Confirm that the credential will have your WordPress account permissions.', 'wordpressconnector'),
                esc_html__('Confirmation required', 'wordpressconnector'), array('response' => 400));
        }

        $userId = get_current_user_id();
        $user = wp_get_current_user();
        if ($userId <= 0 || ! is_object($user) || (int) $user->ID !== $userId ||
            ! current_user_can('create_app_password', $userId)) {
            wp_die(esc_html__('Application Password permission is not available for the signed-in account.', 'wordpressconnector'),
                esc_html__('Permission denied', 'wordpressconnector'), array('response' => 403));
        }

        $mcpUrl = rest_url('webactueel-wordpress-connector/v1/mcp');
        if (! is_ssl() || 'https' !== strtolower((string) wp_parse_url($mcpUrl, PHP_URL_SCHEME)) ||
            ! function_exists('wp_is_application_passwords_available_for_user') ||
            ! wp_is_application_passwords_available_for_user($userId)) {
            wp_die(esc_html__('Application Passwords require HTTPS and must be enabled for this WordPress account.', 'wordpressconnector'),
                esc_html__('Secure connection unavailable', 'wordpressconnector'), array('response' => 403));
        }

        if (\WP_Application_Passwords::application_name_exists_for_user($userId, self::DIRECT_MCP_PASSWORD_LABEL)) {
            wp_die(esc_html__('A direct MCP credential already exists for this account. Revoke it under Users > Profile before generating a replacement.', 'wordpressconnector'),
                esc_html__('Credential already exists', 'wordpressconnector'), array('response' => 409));
        }

        $created = \WP_Application_Passwords::create_new_application_password(
            $userId,
            array('name' => self::DIRECT_MCP_PASSWORD_LABEL)
        );
        if (is_wp_error($created) || ! is_array($created) || ! isset($created[0]) || ! is_string($created[0])) {
            wp_die(esc_html__('WordPress could not create the application password.', 'wordpressconnector'),
                esc_html__('Credential creation failed', 'wordpressconnector'), array('response' => 500));
        }

        // No redirects, query parameters, options, transient storage or
        // GitHub requests may carry the one-time plaintext value.
        nocache_headers();
        $message = '<h1>' . esc_html__('Direct MCP credential created', 'wordpressconnector') . '</h1>';
        $message .= '<p>' . esc_html__('Copy this password now. WordPress will not show it again.', 'wordpressconnector') . '</p>';
        $message .= '<p><label for="wpconnector-mcp-url">' . esc_html__('MCP endpoint', 'wordpressconnector') . '</label>';
        $message .= '<input id="wpconnector-mcp-url" type="text" readonly="readonly" value="' . esc_attr($mcpUrl) . '" style="width:100%" /></p>';
        $message .= '<p><label for="wpconnector-mcp-user">' . esc_html__('WordPress username', 'wordpressconnector') . '</label>';
        $message .= '<input id="wpconnector-mcp-user" type="text" readonly="readonly" value="' . esc_attr((string) $user->user_login) . '" style="width:100%" /></p>';
        $message .= '<p><label for="wpconnector-mcp-password">' . esc_html__('One-time application password', 'wordpressconnector') . '</label>';
        $message .= '<input id="wpconnector-mcp-password" type="text" readonly="readonly" autocomplete="off" value="' . esc_attr($created[0]) . '" style="width:100%" /></p>';
        $message .= '<p>' . esc_html__('Enter the details only into a trusted HTTPS REST/MCP client that supports WordPress Application Passwords. ChatGPT web connections may require OAuth instead. Never put this password in a GitHub branch, workflow, chat or URL. GitHub Actions already uses password-free OIDC.', 'wordpressconnector') . '</p>';
        $message .= '<p><a href="' . esc_url(admin_url('profile.php')) . '">' . esc_html__('Manage or revoke this password in your profile', 'wordpressconnector') . '</a></p>';
        wp_die($message, esc_html__('WordPress Connector direct MCP setup', 'wordpressconnector'),
            array('response' => 200, 'back_link' => false));
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) { return; }

        $presenceUrl = rest_url('webactueel-wordpress-connector/v1/presence');
        $healthUrl = rest_url('webactueel-wordpress-connector/v1/health');
        $legacyActive = $this->legacyBridgeActive();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WordPress Connector', 'wordpressconnector') . '</h1>';
        echo '<p>' . esc_html__('After a WordPress administrator clicks Activate, this plugin connects to the pinned Yolol100/wordpressconnector GitHub Actions workflow automatically. No separate permission screen is needed. Remote actions retain authenticated WordPress capability and request safety checks.', 'wordpressconnector') . '</p>';

        if ($legacyActive) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Legacy Elementor JSON Bridge detected.', 'wordpressconnector') . '</strong> ';
            echo esc_html__('WordPress Connector is the canonical bridge. Keep the legacy plugin only until the staging parity and rollback checklist has passed, then deactivate it.', 'wordpressconnector');
            echo '</p></div>';
        }

        $pairedAdministrator = SitePairing::pairedAdministratorId();
        echo '<h2>' . esc_html__('GitHub connection', 'wordpressconnector') . '</h2>';
        echo '<p><strong>' . esc_html($pairedAdministrator ? __('Connected to trusted GitHub Actions', 'wordpressconnector') : __('Not connected', 'wordpressconnector')) . '</strong></p>';
        echo '<p>' . esc_html__('Activating this plugin as a WordPress administrator authorizes the pinned GitHub Actions workflow to manage this website with administrator permissions. A separate setup click is normally unnecessary. Keep this installed only on websites you manage. Access can be revoked here; a cloned or moved site must be reconnected.', 'wordpressconnector') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpconnector_site_pairing');
        echo '<input type="hidden" name="action" value="wpconnector_site_pairing" />';
        if ($pairedAdministrator) {
            echo '<input type="hidden" name="pairing_decision" value="disable" />';
            submit_button(__('Revoke GitHub Actions access for this site', 'wordpressconnector'), 'secondary');
        } else {
            echo '<input type="hidden" name="pairing_decision" value="enable" />';
            submit_button(__('Reconnect trusted GitHub Actions', 'wordpressconnector'), 'primary');
        }
        echo '</form>';


        echo '<h2>' . esc_html__('Direct AI / MCP connection', 'wordpressconnector') . '</h2>';
        echo '<p>' . esc_html__('Connect a trusted direct REST/MCP client using an Application Password. This does not change the GitHub Actions connection, which already uses password-free OIDC.', 'wordpressconnector') . '</p>';
        echo '<p><strong>' . esc_html__('MCP endpoint', 'wordpressconnector') . ':</strong> <code>' . esc_html(rest_url('webactueel-wordpress-connector/v1/mcp')) . '</code></p>';
        echo '<p>' . esc_html__('The currently signed-in WordPress administrator is used. No first-user search, automatic administrator selection or password sharing with GitHub takes place.', 'wordpressconnector') . '</p>';
        $currentId = get_current_user_id();
        $canIssue = $currentId > 0 && current_user_can('create_app_password', $currentId)
            && is_ssl()
            && 'https' === strtolower((string) wp_parse_url(rest_url('webactueel-wordpress-connector/v1/mcp'), PHP_URL_SCHEME))
            && function_exists('wp_is_application_passwords_available_for_user')
            && wp_is_application_passwords_available_for_user($currentId);
        if ($canIssue) {
            $exists = \WP_Application_Passwords::application_name_exists_for_user($currentId, self::DIRECT_MCP_PASSWORD_LABEL);
            if ($exists) {
                echo '<p>' . esc_html__('A direct MCP Application Password already exists for your account. WordPress cannot show its value again; revoke it in your profile before requesting another.', 'wordpressconnector') . '</p>';
            } else {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('wpconnector_create_application_password');
                echo '<input type="hidden" name="action" value="wpconnector_create_application_password" />';
                echo '<p><label><input type="checkbox" name="confirm_direct_access" value="1" required="required" /> ';
                echo esc_html__('I understand this password grants the same WordPress rights as my account and will only be shown once.', 'wordpressconnector') . '</label></p>';
                submit_button(__('Generate my direct MCP password', 'wordpressconnector'), 'secondary');
                echo '</form>';
            }
        } else {
            echo '<p>' . esc_html__('Direct Application Password setup is unavailable: check HTTPS, your account permissions and WordPress Application Password settings.', 'wordpressconnector') . '</p>';
        }
        echo '<p><a href="' . esc_url(admin_url('profile.php')) . '">' . esc_html__('Manage or revoke Application Passwords', 'wordpressconnector') . '</a></p>';

        echo '<h2>' . esc_html__('Installation readiness', 'wordpressconnector') . '</h2>';
        $checks = array(
            __('WordPress 6.4 or newer', 'wordpressconnector') => version_compare(get_bloginfo('version'), '6.4', '>='),
            __('PHP 7.4 or newer', 'wordpressconnector') => version_compare(PHP_VERSION, '7.4', '>='),
            __('PHP OpenSSL extension', 'wordpressconnector') => extension_loaded('openssl'),
            __('WordPress sees HTTPS', 'wordpressconnector') => is_ssl(),
            __('REST endpoint uses HTTPS', 'wordpressconnector') => 'https' === strtolower((string) wp_parse_url(rest_url('webactueel-wordpress-connector/v1/presence'), PHP_URL_SCHEME)),
        );
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        foreach ($checks as $label => $ready) {
            echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html($ready ? __('Ready', 'wordpressconnector') : __('Needs attention', 'wordpressconnector')) . '</td></tr>';
        }
        if (is_multisite()) {
            echo '<tr><td>' . esc_html__('WordPress multisite blog ID', 'wordpressconnector') . '</td><td><code>' . esc_html((string) get_current_blog_id()) . '</code></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>' . esc_html__('Hosting/WAF blocks (401/403/429), outbound GitHub JWKS access, permalinks and third-party plugin compatibility still require a real end-to-end test. An installed plugin alone cannot fix a firewall, missing user permissions or absent dependencies.', 'wordpressconnector') . '</p>';

        echo '<h2>' . esc_html__('GitHub plugin updates', 'wordpressconnector') . '</h2>';
        echo '<p>' . esc_html__('The Plugins screen checks the canonical GitHub release at most once every two minutes while a plugin administrator views it. If a verified newer release exists, WordPress shows its standard Update Now action. Use the Check GitHub update now link beside this plugin for an immediate manual refresh. A commit on GitHub main is not an installable update until CI passes and the versioned release ZIP is published.', 'wordpressconnector') . '</p>';
        echo '<h2>' . esc_html__('Connection', 'wordpressconnector') . '</h2>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><td><strong>' . esc_html__('Connector version', 'wordpressconnector') . '</strong></td><td><code>' . esc_html(defined('WPCONNECTOR_VERSION') ? WPCONNECTOR_VERSION : '') . '</code></td></tr>';
        echo '<tr><td><strong>' . esc_html__('Presence endpoint', 'wordpressconnector') . '</strong></td><td><code>' . esc_html($presenceUrl) . '</code></td></tr>';
        echo '<tr><td><strong>' . esc_html__('REST health endpoint', 'wordpressconnector') . '</strong></td><td><code>' . esc_html($healthUrl) . '</code></td></tr>';
        echo '<tr><td><strong>' . esc_html__('GitHub authentication', 'wordpressconnector') . '</strong></td><td>' . esc_html__('Short-lived GitHub Actions OIDC token, normally enabled upon administrator activation', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Persistent connector secrets', 'wordpressconnector') . '</strong></td><td>' . esc_html__('None required', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Execution safety', 'wordpressconnector') . '</strong></td><td>' . esc_html__('Capability checks, dry-run, request confirmation, stale-state guards, idempotency, readback and rollback remain active.', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Runtime coverage', 'wordpressconnector') . '</strong></td><td>' . esc_html__('WordPress · Elementor · Gutenberg · WooCommerce · ACF · Yoast SEO · Media · Plugin settings · Controlled filesystem', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Legacy bridge', 'wordpressconnector') . '</strong></td><td>' . esc_html($legacyActive ? __('Active — staging removal check still required', 'wordpressconnector') : __('Not active', 'wordpressconnector')) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('First test', 'wordpressconnector') . '</h2>';
        echo '<p><code>' . esc_html__('presence -> OIDC health -> connector.discover -> system.doctor -> dry-run mutation -> confirmed staging write + rollback', 'wordpressconnector') . '</code></p>';
        echo '<p>' . esc_html__('Optional emergency policy overrides can disable connector actions. WordPress-native REST/MCP needs an authenticated WordPress user; GitHub OIDC is automatically paired on ordinary administrator activation, and never bypasses policy gates.', 'wordpressconnector') . '</p>';
        echo '</div>';
    }

    private function legacyBridgeActive(): bool
    {
        if (class_exists('Webactueel\\ElementorJsonBridge\\Plugin')) { return true; }
        if (! function_exists('is_plugin_active')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        foreach (array('elementor-json-bridge/elementor-json-bridge.php', 'Elementorconnector/elementor-json-bridge.php') as $pluginFile) {
            if (function_exists('is_plugin_active') && is_plugin_active($pluginFile)) { return true; }
        }
        return false;
    }
}
