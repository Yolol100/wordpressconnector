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
            'help' => 'Allows authenticated administrators to use the connector REST endpoints over HTTPS.',
        ),
        'wpconnector_allow_writes' => array(
            'label' => 'Allow confirmed writes',
            'default' => 0,
            'help' => 'Required for non-dry-run mutations. Requests must still set confirm=true.',
        ),
        'wpconnector_allow_privileged' => array(
            'label' => 'Allow privileged actions',
            'default' => 0,
            'help' => 'Required for options, post meta, users and other privileged connector actions.',
        ),
        'wpconnector_allow_sensitive' => array(
            'label' => 'Allow sensitive data actions',
            'default' => 0,
            'help' => 'Keep disabled unless a private, explicitly approved workflow needs sensitive records.',
        ),
        'wpconnector_allow_system_updates' => array(
            'label' => 'Allow plugin/theme/core system updates',
            'default' => 0,
            'help' => 'Controls connector actions that install, update or delete system components.',
        ),
    );

    public function register(): void
    {
        if (! is_admin()) {
            return;
        }
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

        add_settings_section(
            'wpconnector_security',
            'Transport and execution gates',
            array($this, 'renderSection'),
            self::PAGE
        );

        foreach (self::OPTIONS as $name => $definition) {
            add_settings_field(
                $name,
                (string) $definition['label'],
                array($this, 'renderCheckbox'),
                self::PAGE,
                'wpconnector_security',
                array('name' => $name, 'help' => (string) $definition['help'])
            );
        }
    }

    public function registerPage(): void
    {
        add_options_page(
            'WordPress Connector',
            'WordPress Connector',
            'manage_options',
            self::PAGE,
            array($this, 'renderPage')
        );
    }

    public function sanitizeBoolean($value): int
    {
        return empty($value) ? 0 : 1;
    }

    public function renderSection(): void
    {
        echo '<p>' . esc_html__('The REST transport requires HTTPS and an authenticated WordPress administrator. Use a dedicated Application Password for GitHub; keep the sensitive and system-update gates disabled unless they are explicitly needed.', 'wordpressconnector') . '</p>';
    }

    public function renderCheckbox(array $args): void
    {
        $name = isset($args['name']) ? (string) $args['name'] : '';
        if (! isset(self::OPTIONS[$name])) {
            return;
        }

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
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WordPress Connector', 'wordpressconnector') . '</h1>';
        echo '<p><code>' . esc_html(rest_url('webactueel-wordpress-connector/v1/health')) . '</code></p>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::PAGE);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
