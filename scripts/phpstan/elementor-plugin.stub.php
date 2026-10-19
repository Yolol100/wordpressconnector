<?php
/**
 * Development-only Elementor provider type declarations.
 * Elementor\Plugin::$instance and public $files_manager are declared in:
 * https://github.com/elementor/elementor/blob/main/includes/plugin.php
 * The provider is optional and this file is never loaded by WordPress.
 */
namespace Elementor {
    class Plugin {
        /** @var Plugin|null */
        public static $instance;
        /** @var object */
        public $files_manager;
    }
}
