<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

final class DiscoveryAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('connector.discover', array($this, 'discover'), array(
            'description' => 'Discover WordPress, plugins, post types, taxonomies, builders, media sizes and connector capabilities.',
        ));
    }

    public function discover(array $payload, array $context): array
    {
        global $wp_version;

        $postTypes = array();
        foreach (get_post_types(array(), 'objects') as $name => $object) {
            $postTypes[$name] = array(
                'label' => $object->label,
                'public' => (bool) $object->public,
                'show_ui' => (bool) $object->show_ui,
                'show_in_rest' => (bool) $object->show_in_rest,
                'hierarchical' => (bool) $object->hierarchical,
                'supports' => get_all_post_type_supports($name),
                'taxonomies' => get_object_taxonomies($name),
            );
        }

        $taxonomies = array();
        foreach (get_taxonomies(array(), 'objects') as $name => $object) {
            $taxonomies[$name] = array(
                'label' => $object->label,
                'public' => (bool) $object->public,
                'show_ui' => (bool) $object->show_ui,
                'show_in_rest' => (bool) $object->show_in_rest,
                'hierarchical' => (bool) $object->hierarchical,
                'object_type' => array_values((array) $object->object_type),
            );
        }

        $plugins = array();
        $theme = wp_get_theme();
        if (! Policy::publicRepositoryContext()) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            foreach (get_plugins() as $file => $data) {
                $plugins[$file] = array(
                    'name' => $data['Name'] ?? $file,
                    'version' => $data['Version'] ?? '',
                    'active' => is_plugin_active($file),
                    'network_active' => is_multisite() ? is_plugin_active_for_network($file) : false,
                );
            }
        }

        $imageSizes = array();
        foreach (wp_get_registered_image_subsizes() as $name => $size) {
            $imageSizes[$name] = array(
                'width' => (int) $size['width'],
                'height' => (int) $size['height'],
                'crop' => $size['crop'],
            );
        }

        $yoastVersion = defined('WPSEO_VERSION')
            ? WPSEO_VERSION
            : (defined('YOAST_SEO_VERSION') ? YOAST_SEO_VERSION : null);

        $builders = array(
            'gutenberg' => function_exists('parse_blocks'),
            'elementor' => class_exists('Elementor\\Plugin'),
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            'elementor_pro' => defined('ELEMENTOR_PRO_VERSION'),
            'elementor_pro_version' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
            'woocommerce' => class_exists('WooCommerce'),
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : null,
            'acf' => function_exists('get_fields'),
            'acf_version' => defined('ACF_VERSION') ? ACF_VERSION : null,
            'yoast' => null !== $yoastVersion || class_exists('WPSEO_Options'),
            'yoast_version' => $yoastVersion,
            'wordpress_abilities' => function_exists('wp_get_abilities'),
        );

        return array(
            'wordpress' => array(
                'version' => (string) $wp_version,
                'php_version' => PHP_VERSION,
                'multisite' => is_multisite(),
                'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : null,
                'site_url' => Policy::publicRepositoryContext() ? null : site_url(),
                'home_url' => Policy::publicRepositoryContext() ? null : home_url(),
            ),
            'theme' => array(
                'stylesheet' => (string) $theme->get_stylesheet(),
                'template' => (string) $theme->get_template(),
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
            ),
            'plugins' => $plugins,
            'post_types' => $postTypes,
            'taxonomies' => $taxonomies,
            'post_statuses' => get_post_stati(array(), 'objects'),
            'image_sizes' => $imageSizes,
            'builders' => $builders,
            'menus' => array(
                'registered_locations' => get_registered_nav_menus(),
                'assigned_locations' => get_nav_menu_locations(),
            ),
            'actions' => isset($context['registry']) && $context['registry'] instanceof Registry ? $context['registry']->catalog() : array(),
            'public_repository_mode' => Policy::publicRepositoryContext(),
            'connector_mode' => 'single_canonical_bridge',
        );
    }
}
