<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Admin;

final class Settings
{
    private const PAGE = 'wordpressconnector';

    public function register(): void
    {
        if (! is_admin()) { return; }
        add_action('admin_menu', array($this, 'registerPage'));
    }

    public function registerPage(): void
    {
        add_options_page('WordPress Connector', 'WordPress Connector', 'manage_options', self::PAGE, array($this, 'renderPage'));
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) { return; }

        $presenceUrl = rest_url('webactueel-wordpress-connector/v1/presence');
        $healthUrl = rest_url('webactueel-wordpress-connector/v1/health');
        $legacyActive = $this->legacyBridgeActive();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WordPress Connector', 'wordpressconnector') . '</h1>';
        echo '<p>' . esc_html__('Zero-config runtime bridge. Activating the plugin immediately enables the guarded GitHub runtime over HTTPS; no connector checkboxes, GitHub repository variables, WordPress usernames or Application Passwords are required.', 'wordpressconnector') . '</p>';

        if ($legacyActive) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Legacy Elementor JSON Bridge detected.', 'wordpressconnector') . '</strong> ';
            echo esc_html__('WordPress Connector is the canonical bridge. Keep the legacy plugin only until the staging parity and rollback checklist has passed, then deactivate it.', 'wordpressconnector');
            echo '</p></div>';
        }

        echo '<h2>' . esc_html__('Connection', 'wordpressconnector') . '</h2>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><td><strong>' . esc_html__('Connector version', 'wordpressconnector') . '</strong></td><td><code>' . esc_html(defined('WPCONNECTOR_VERSION') ? WPCONNECTOR_VERSION : '') . '</code></td></tr>';
        echo '<tr><td><strong>' . esc_html__('Presence endpoint', 'wordpressconnector') . '</strong></td><td><code>' . esc_html($presenceUrl) . '</code></td></tr>';
        echo '<tr><td><strong>' . esc_html__('REST health endpoint', 'wordpressconnector') . '</strong></td><td><code>' . esc_html($healthUrl) . '</code></td></tr>';
        echo '<tr><td><strong>' . esc_html__('GitHub authentication', 'wordpressconnector') . '</strong></td><td>' . esc_html__('Short-lived GitHub Actions OIDC token bound to the canonical repository, main workflow and this site URL', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Persistent connector secrets', 'wordpressconnector') . '</strong></td><td>' . esc_html__('None required', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Execution safety', 'wordpressconnector') . '</strong></td><td>' . esc_html__('Capability checks, dry-run, request confirmation, stale-state guards, idempotency, readback and rollback remain active.', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Runtime coverage', 'wordpressconnector') . '</strong></td><td>' . esc_html__('WordPress · Elementor · Gutenberg · WooCommerce · ACF · Yoast SEO · Media · Plugin settings · Controlled filesystem', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Legacy bridge', 'wordpressconnector') . '</strong></td><td>' . esc_html($legacyActive ? __('Active — staging removal check still required', 'wordpressconnector') : __('Not active', 'wordpressconnector')) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('First test', 'wordpressconnector') . '</h2>';
        echo '<p><code>' . esc_html__('presence -> OIDC health -> connector.discover -> system.doctor -> dry-run mutation -> confirmed staging write + rollback', 'wordpressconnector') . '</code></p>';
        echo '<p>' . esc_html__('Optional emergency policy overrides can still be set server-side with WPCONNECTOR_ALLOW_* constants or environment variables. No WordPress admin setup is needed for normal use.', 'wordpressconnector') . '</p>';
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
