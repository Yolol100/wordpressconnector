<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector;

use Webactueel\WordPressConnector\Adapters\AbilitiesAdapter;
use Webactueel\WordPressConnector\Adapters\AcfAdapter;
use Webactueel\WordPressConnector\Adapters\AutoImageAttributesAdapter;
use Webactueel\WordPressConnector\Adapters\CoreAdapter;
use Webactueel\WordPressConnector\Adapters\DiscoveryAdapter;
use Webactueel\WordPressConnector\Adapters\ElementorAdapter;
use Webactueel\WordPressConnector\Adapters\ElementorCapabilitiesAdapter;
use Webactueel\WordPressConnector\Adapters\ElementorFormsAdapter;
use Webactueel\WordPressConnector\Adapters\FilesystemAdapter;
use Webactueel\WordPressConnector\Adapters\GutenbergAdapter;
use Webactueel\WordPressConnector\Adapters\MediaAdapter;
use Webactueel\WordPressConnector\Adapters\PluginSettingsAdapter;
use Webactueel\WordPressConnector\Adapters\SystemAdapter;
use Webactueel\WordPressConnector\Adapters\WooCommerceAdapter;
use Webactueel\WordPressConnector\Adapters\YoastAdapter;
use Webactueel\WordPressConnector\Admin\Settings;
use Webactueel\WordPressConnector\CLI\Command;
use Webactueel\WordPressConnector\REST\AssetStore;
use Webactueel\WordPressConnector\REST\Controller;
use Webactueel\WordPressConnector\Runtime\ProcessedStore;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Runtime\SnapshotStore;

final class Plugin
{
    public static function boot(): void
    {
        $registry = new Registry();
        $adapters = array(
            new DiscoveryAdapter(),
            new AbilitiesAdapter(),
            new CoreAdapter(),
            new GutenbergAdapter(),
            new ElementorCapabilitiesAdapter(),
            new ElementorAdapter(),
            new ElementorFormsAdapter(),
            new WooCommerceAdapter(),
            new AcfAdapter(),
            new YoastAdapter(),
            new MediaAdapter(),
            new AutoImageAttributesAdapter(),
            new PluginSettingsAdapter(),
            new FilesystemAdapter(),
            new SystemAdapter(),
        );

        foreach ($adapters as $adapter) {
            $adapter->register($registry);
        }

        do_action('wpconnector_register_actions', $registry);

        $runner = new Runner($registry, new SnapshotStore(), new ProcessedStore());
        (new Settings())->register();
        (new Controller($runner, new AssetStore()))->register();

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('wordpress-connector', new Command($runner));
        }
    }
}
