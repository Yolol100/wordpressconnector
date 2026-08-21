<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector;

use Webactueel\WordPressConnector\Adapters\AcfAdapter;
use Webactueel\WordPressConnector\Adapters\CoreAdapter;
use Webactueel\WordPressConnector\Adapters\DiscoveryAdapter;
use Webactueel\WordPressConnector\Adapters\ElementorAdapter;
use Webactueel\WordPressConnector\Adapters\GutenbergAdapter;
use Webactueel\WordPressConnector\Adapters\MediaAdapter;
use Webactueel\WordPressConnector\Adapters\SystemAdapter;
use Webactueel\WordPressConnector\Adapters\WooCommerceAdapter;
use Webactueel\WordPressConnector\CLI\Command;
use Webactueel\WordPressConnector\Runtime\ProcessedStore;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Runtime\SnapshotStore;

final class Plugin
{
    public static function boot(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI || ! class_exists('WP_CLI')) {
            return;
        }

        $registry = new Registry();
        $adapters = array(
            new DiscoveryAdapter(),
            new CoreAdapter(),
            new GutenbergAdapter(),
            new ElementorAdapter(),
            new WooCommerceAdapter(),
            new AcfAdapter(),
            new MediaAdapter(),
            new SystemAdapter(),
        );

        foreach ($adapters as $adapter) {
            $adapter->register($registry);
        }

        do_action('wpconnector_register_actions', $registry);

        $runner = new Runner($registry, new SnapshotStore(), new ProcessedStore());
        \WP_CLI::add_command('wordpress-connector', new Command($runner));
    }
}
