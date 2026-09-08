<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Admin;

final class Settings
{
    private const PAGE = 'wordpressconnector';

    private const OPTIONS = array(
        'wpconnector_rest_enabled' => array(
            'label' => 'Enable HTTPS REST transport',
            'default' => 1,
            'help' => 'Allows authenticated administrators to use connector REST endpoints over HTTPS.',
        ),
        'wpconnector_allow_writes' => array(
            'label' => 'Allow confirmed writes',
            'default' => 0,
            'help' => 'Required for non-dry-run mutations. Requests must still set confirm=true.',
        ),
        'wpconnector_allow_privileged' => array(
            'label' => 'Allow privileged actions',
            'default' => 0,
            'help' => 'Required for administrative and broadly scoped connector actions.',
        ),
        'wpconnector_allow_sensitive' => array(
            'label' => 'Allow sensitive data actions',
            'default' => 0,
            'help' => 'Keep disabled unless an explicitly approved workflow needs sensitive records.',
        ),
        'wpconnector_allow_system_updates' => array(
            'label' => 'Allow plugin/theme/core system updates',
            'default' => 0,
            'help' => 'Controls connector actions that install, update or delete system components.',
        ),
        'wpconnector_allow_filesystem_writes' => array(
            'label' => 'Allow controlled plugin/theme file writes',
            'default' => 0,
            'help' => 'Allows filesystem.write_text only for existing plugin/theme text files with expected SHA-256, parser validation, readback and rollback.',
        ),
    );

    public function register(): void
    {
        if (! is_admin()) { return; }
        add_action('admin_init', array($this, 'registerSettings'));
        add_action('admin_menu', array($this, 'registerPage'));
    }

    public function registerSettings(): void
    {
        foreach (self::OPTIONS as $name => $definition) {
            register_setting(self::PAGE, $name, array(
                'type' => 'boolean',
                'default' => (bool) $definition['default'],
                'sanitize_callback' => array($this, 'sanitizeBoolean'),
            ));
        }
        add_settings_section('wpconnector_security', '2. Transport and execution gates', array($this, 'renderSection'), self::PAGE);
        foreach (self::OPTIONS as $name => $definition) {
            add_settings_field($name, (string) $definition['label'], array($this, 'renderCheckbox'), self::PAGE, 'wpconnector_security', array('name' => $name, 'help' => (string) $definition['help']));
        }
    }

    public function registerPage(): void
    {
        add_options_page('WordPress Connector', 'WordPress Connector', 'manage_options', self::PAGE, array($this, 'renderPage'));
    }

    public function sanitizeBoolean($value): int { return empty($value) ? 0 : 1; }

    public function renderSection(): void
    {
        echo '<p>' . esc_html__('Start read-only. Enable confirmed writes only after connector.discover and dry-run verification. Keep sensitive, filesystem and system-update gates off unless explicitly required.', 'wordpressconnector') . '</p>';
    }

    public function renderCheckbox(array $args): void
    {
        $name = isset($args['name']) ? (string) $args['name'] : '';
        if (! isset(self::OPTIONS[$name])) { return; }
        $default = (int) self::OPTIONS[$name]['default'];
        $value = (int) get_option($name, $default);
        printf(
            '<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr($name),
            checked(1, $value, false),
            esc_html((string) $args['help'])
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) { return; }

        $healthUrl = rest_url('webactueel-wordpress-connector/v1/health');
        $legacyActive = $this->legacyBridgeActive();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WordPress Connector', 'wordpressconnector') . '</h1>';
        echo '<p>' . esc_html__('Canonical WordPress and Elementor runtime bridge for approved authenticated HTTPS clients, including the guarded GitHub runtime.', 'wordpressconnector') . '</p>';

        if ($legacyActive) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Legacy Elementor JSON Bridge detected.', 'wordpressconnector') . '</strong> ';
            echo esc_html__('WordPress Connector is the canonical bridge. Keep the legacy plugin only until the staging parity and rollback checklist has passed, then deactivate it.', 'wordpressconnector');
            echo '</p></div>';
        }

        echo '<h2>' . esc_html__('1. Connection', 'wordpressconnector') . '</h2>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><td><strong>' . esc_html__('Connector version', 'wordpressconnector') . '</strong></td><td><code>' . esc_html(defined('WPCONNECTOR_VERSION') ? WPCONNECTOR_VERSION : '') . '</code></td></tr>';
        echo '<tr><td><strong>' . esc_html__('REST health endpoint', 'wordpressconnector') . '</strong></td><td><code>' . esc_html($healthUrl) . '</code></td></tr>';
        echo '<tr><td><strong>' . esc_html__('Preferred client', 'wordpressconnector') . '</strong></td><td>' . esc_html__('Approved authenticated HTTPS client / guarded GitHub runtime', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Runtime coverage', 'wordpressconnector') . '</strong></td><td>' . esc_html__('WordPress · Elementor · Gutenberg · WooCommerce · ACF · Yoast SEO · Media · Plugin settings · Controlled filesystem', 'wordpressconnector') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Legacy bridge', 'wordpressconnector') . '</strong></td><td>' . esc_html($legacyActive ? __('Active — staging removal check still required', 'wordpressconnector') : __('Not active', 'wordpressconnector')) . '</td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" action="options.php" style="max-width:900px">';
        settings_fields(self::PAGE);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form>';

        echo '<h2>' . esc_html__('3. First test', 'wordpressconnector') . '</h2>';
        echo '<p><code>' . esc_html__('health -> connector.discover -> system.doctor -> elementor.capabilities -> dry-run mutation -> staging write + rollback', 'wordpressconnector') . '</code></p>';
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
