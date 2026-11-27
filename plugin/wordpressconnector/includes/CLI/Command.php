<?php
/**
 * Exposes Command commands for WordPress CLI.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\CLI;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Support\Json;

/**
 * Exposes Command commands for WordPress CLI.
 */
final class Command {
	/**
	 * Runner maintained by the connector.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Initialize the Command component dependencies.
	 *
	 * @param Runner $runner Input for the operation.
	 */
	public function __construct( Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Execute one connector request file.
	 *
	 * ## OPTIONS
	 *
	 * <request>
	 * : Path to a request JSON file.
	 *
	 * [--output=<path>]
	 * : Write the result JSON atomically to this path.
	 *
	 * [--asset-root=<path>]
	 * : Trusted root used by media.import source_path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wordpress-connector run requests/example.json --output=results/example.json --asset-root=assets/inbox
	 *
	 * @subcommand run
	 *
	 * @param array $args Command-line arguments.
	 * @param array $assoc_args Associative command-line arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$request_path = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $request_path ) {
			\WP_CLI::error( 'Request file is required.' );
		}

		try {
			$request = Request::from_file( $request_path );
			$context = array(
				'asset_root' => isset( $assoc_args['asset-root'] ) ? (string) $assoc_args['asset-root'] : '',
			);
			$result  = $this->runner->run( $request, $context );

			if ( ! empty( $assoc_args['output'] ) ) {
				Json::writeFileAtomic( (string) $assoc_args['output'], $result );
				\WP_CLI::log( (string) $assoc_args['output'] );
			} else {
				\WP_CLI::line( Json::encode( $result ) );
			}

			if ( empty( $result['ok'] ) ) {
				\WP_CLI::halt( 1 );
			}
		} catch ( RuntimeException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Print the registered action catalog.
	 *
	 * @subcommand actions
	 *
	 * @param array $args Command-line arguments.
	 * @param array $assoc_args Associative command-line arguments.
	 */
	public function actions( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI passes both positional and associative arguments; preserve the public signature.
		$request = Request::from_array(
			array(
				'version'    => 1,
				'request_id' => 'actions-list-0001',
				'action'     => 'connector.actions',
				'dry_run'    => true,
				'payload'    => array(),
			)
		);
		$result  = $this->runner->run( $request );
		\WP_CLI::line( Json::encode( $result ) );
	}

	/**
	 * Run read-only runtime diagnostics.
	 *
	 * @subcommand doctor
	 *
	 * @param array $args Command-line arguments.
	 * @param array $assoc_args Associative command-line arguments.
	 */
	public function doctor( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI passes both positional and associative arguments; preserve the public signature.
		$request = Request::from_array(
			array(
				'version'    => 1,
				'request_id' => 'doctor-run-00001',
				'action'     => 'system.doctor',
				'dry_run'    => true,
				'payload'    => array(),
			)
		);
		$result  = $this->runner->run( $request );
		\WP_CLI::line( Json::encode( $result ) );
		if ( empty( $result['ok'] ) || empty( $result['data']['ok'] ) ) {
			\WP_CLI::halt( 1 );
		}
	}
}
