<?php
/**
 * Implements Controller handling for authenticated REST requests.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\REST;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Security\GitHubOidc;
use Webactueel\WordPressConnector\Security\Policy;

require_once __DIR__ . '/trait-restcontrollermethods.php';

/**
 * Implements Controller handling for authenticated REST requests.
 */
final class Controller {
	use RESTControllerMethods {
		register_routes as registerRoutes;
		upload_asset as uploadAsset;
		validate_asset_path as validateAssetPath;
	}

	private const NAMESPACE         = 'webactueel-wordpress-connector/v1';
	private const MAX_REQUEST_BYTES = 262144;

	/**
	 * Runner maintained by the connector.
	 *
	 * @var Runner
	 */
	private Runner $runner;
	/**
	 * Assets maintained by the connector.
	 *
	 * @var AssetStore
	 */
	private AssetStore $assets;

	/**
	 * Initialize the Controller component dependencies.
	 *
	 * @param Runner     $runner Input for the operation.
	 * @param AssetStore $assets Input for the operation.
	 */
	public function __construct( Runner $runner, AssetStore $assets ) {
		$this->runner = $runner;
		$this->assets = $assets;
	}

	/**
	 * Register the Controller component actions and handlers.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) ); }

	/**
	 * Process the allow presence operation in the Controller component.
	 *
	 * @param \WP_REST_Request $request Input for the operation.
	 *
	 * @return mixed Operation result.
	 */
	public function allowPresence( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST permission callback receives the request; preserve the public callback signature.
		if ( ! is_ssl() ) {
			return new \WP_Error( 'wpconnector_https_required', 'WordPress Connector presence requires HTTPS.', array( 'status' => 403 ) ); }
		return true;
	}

	/**
	 * Process the authorize operation in the Controller component.
	 *
	 * @param \WP_REST_Request $request Input for the operation.
	 *
	 * @return mixed Operation result.
	 */
	public function authorize( \WP_REST_Request $request ) {
		if ( ! is_ssl() ) {
			return new \WP_Error( 'wpconnector_https_required', 'WordPress Connector REST transport requires HTTPS.', array( 'status' => 403 ) ); }
		if ( ! is_user_logged_in() ) {
			try {
				$user_id = ( new GitHubOidc() )->authenticate( $request );
				if ( $user_id > 0 ) {
					wp_set_current_user( $user_id ); }
			} catch ( RuntimeException $error ) {
				return new \WP_Error( 'wpconnector_oidc_rejected', 'GitHub OIDC authentication was rejected.', array( 'status' => 401 ) );
			}
		}
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'wpconnector_auth_required', 'Authentication is required.', array( 'status' => 401 ) ); }
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wpconnector_forbidden', 'Administrator capability is required.', array( 'status' => 403 ) ); }
		$public_context = (string) $request->get_header( 'x-wpconnector-public-repository' );
		if ( '' !== $public_context ) {
			if ( '1' !== $public_context ) {
				return new \WP_Error( 'wpconnector_public_context_invalid', 'Public-repository mode header must be exactly 1.', array( 'status' => 400 ) );
			}
			Policy::setPublicRepositoryContext( true );
		}
		return true;
	}

	/**
	 * Process the presence operation in the Controller component.
	 *
	 * @param \WP_REST_Request $request Input for the operation.
	 *
	 * @return \WP_REST_Response Operation result.
	 */
	public function presence( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST endpoint callback must preserve the request parameter.
		return new \WP_REST_Response(
			array(
				'ok'          => true,
				'connector'   => 'webactueel-wordpress-connector',
				'zero_config' => true,
				'auth'        => 'github-actions-oidc',
			),
			200
		);
	}

	/**
	 * Process the health operation in the Controller component.
	 *
	 * @param \WP_REST_Request $request Input for the operation.
	 *
	 * @return \WP_REST_Response Operation result.
	 */
	public function health( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST endpoint callback must preserve the request parameter.
		return new \WP_REST_Response(
			array(
				'ok'                     => true,
				'transport'              => 'rest',
				'version'                => defined( 'WPCONNECTOR_VERSION' ) ? WPCONNECTOR_VERSION : null,
				'user_id'                => get_current_user_id(),
				'authentication'         => 'wordpress-or-github-oidc',
				'public_repository_mode' => Policy::publicRepositoryContext(),
				'gates'                  => array(
					'writes'            => Policy::flag( 'WPCONNECTOR_ALLOW_WRITES' ),
					'privileged'        => Policy::flag( 'WPCONNECTOR_ALLOW_PRIVILEGED' ),
					'sensitive'         => Policy::flag( 'WPCONNECTOR_ALLOW_SENSITIVE' ),
					'system_updates'    => Policy::flag( 'WPCONNECTOR_ALLOW_SYSTEM_UPDATES' ),
					'filesystem_writes' => Policy::flag( 'WPCONNECTOR_ALLOW_FILESYSTEM_WRITES' ),
				),
				'limits'                 => array(
					'request_bytes'     => self::MAX_REQUEST_BYTES,
					'asset_files'       => 10,
					'asset_total_bytes' => 26214400,
				),
			),
			200
		);
	}

	/**
	 * Process the execute operation in the Controller component.
	 *
	 * @param \WP_REST_Request $request Input for the operation.
	 *
	 * @return mixed Operation result.
	 */
	public function execute( \WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( '' === $body || strlen( $body ) > self::MAX_REQUEST_BYTES ) {
			return new \WP_Error( 'wpconnector_request_size', 'Request JSON is empty or exceeds 256 KiB.', array( 'status' => 413 ) );
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'wpconnector_invalid_json', 'Request body must be a JSON object.', array( 'status' => 400 ) );
		}
		try {
			$connector_request = Request::fromArray( $data );
		} catch ( RuntimeException $error ) {
			return new \WP_Error( 'wpconnector_invalid_request', $error->getMessage(), array( 'status' => 400 ) );
		}
		try {
			$this->assets->cleanupExpired();
			$asset_root = $this->assets->rootForRequest( $connector_request->id(), false );
		} catch ( Throwable $storage_error ) {
			return new \WP_Error(
				'wpconnector_asset_storage_unavailable',
				'Private request asset storage is unavailable.',
				array( 'status' => 503 )
			);
		}
		try {
			$result = $this->runner->run(
				$connector_request,
				array(
					'transport'             => 'rest',
					'authenticated_user_id' => get_current_user_id(),
					'asset_root'            => $asset_root,
				)
			);
			return new \WP_REST_Response( $result, 200 );
		} finally {
			try {
				$this->assets->cleanup( $connector_request->id() );
			} catch ( Throwable $cleanup_error ) {
				// Log a constant, never an upload path, request ID or credential.
				error_log( 'WordPress Connector could not clear private request assets.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Constant operational security failure signal with no path, token, or credential; not temporary debugging.
			}
		}
	}

	/**
	 * Validate request id for the Controller component.
	 *
	 * @param mixed $value Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	public function validateRequestId( $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $value ); }

}
