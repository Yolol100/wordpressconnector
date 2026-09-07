<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\CLI;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Support\Json;

final class Command
{
    private Runner $runner;

    public function __construct(Runner $runner)
    {
        $this->runner = $runner;
    }

    /**
     * Execute one connector request file for local diagnostics or recovery.
     *
     * ## OPTIONS
     *
     * <request>
     * : Path to a local request JSON file.
     *
     * [--output=<path>]
     * : Write the result JSON atomically to this path.
     *
     * [--asset-root=<path>]
     * : Trusted local root used by media.import source_path.
     *
     * ## EXAMPLES
     *
     *     wp wordpress-connector run /tmp/connector-request.json --output=/tmp/connector-result.json
     *
     * @subcommand run
     */
    public function run(array $args, array $assocArgs): void
    {
        $requestPath = isset($args[0]) ? (string) $args[0] : '';
        if ('' === $requestPath) {
            \WP_CLI::error('Request file is required.');
        }

        try {
            $request = Request::fromFile($requestPath);
            $context = array(
                'asset_root' => isset($assocArgs['asset-root']) ? (string) $assocArgs['asset-root'] : '',
            );
            $result = $this->runner->run($request, $context);

            if (! empty($assocArgs['output'])) {
                Json::writeFileAtomic((string) $assocArgs['output'], $result);
                \WP_CLI::log((string) $assocArgs['output']);
            } else {
                \WP_CLI::line(Json::encode($result));
            }

            if (empty($result['ok'])) {
                \WP_CLI::halt(1);
            }
        } catch (RuntimeException $error) {
            \WP_CLI::error($error->getMessage());
        }
    }

    /**
     * Print the registered action catalog.
     *
     * @subcommand actions
     */
    public function actions(array $args, array $assocArgs): void
    {
        $request = Request::fromArray(array(
            'request_id' => 'actions-list-0001',
            'action' => 'connector.actions',
            'dry_run' => true,
            'payload' => array(),
        ));
        $result = $this->runner->run($request);
        \WP_CLI::line(Json::encode($result));
    }

    /**
     * Run read-only runtime diagnostics.
     *
     * @subcommand doctor
     */
    public function doctor(array $args, array $assocArgs): void
    {
        $request = Request::fromArray(array(
            'request_id' => 'doctor-run-00001',
            'action' => 'system.doctor',
            'dry_run' => true,
            'payload' => array(),
        ));
        $result = $this->runner->run($request);
        \WP_CLI::line(Json::encode($result));
        if (empty($result['ok']) || empty($result['data']['ok'])) {
            \WP_CLI::halt(1);
        }
    }
}
