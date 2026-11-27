<?php
/**
 * Canonical public method implementations for Controller.
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

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait RESTControllerMethods {
	/**
	 * Process the register routes operation in the Controller component.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/presence',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'presence' ),
				'permission_callback' => array( $this, 'allowPresence' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'health' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/assets',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'uploadAsset' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'request_id' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'validateRequestId' ),
					),
					'asset_path' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'validateAssetPath' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/execute',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * Process the upload asset operation in the Controller component.
	 *
	 * @param \WP_REST_Request $request Input for the operation.
	 *
	 * @return mixed Operation result.
	 */
	public function upload_asset( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( ! isset( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new \WP_Error( 'wpconnector_asset_missing', 'Multipart field "file" is required.', array( 'status' => 400 ) );
		}
		try {
			$this->assets->cleanupExpired();
			$stored = $this->assets->put(
				(string) $request->get_param( 'request_id' ),
				(string) $request->get_param( 'asset_path' ),
				$files['file']
			);
			return new \WP_REST_Response(
				array(
					'ok'    => true,
					'asset' => $stored,
				),
				201
			);
		} catch ( Throwable $error ) {
			// Storage and provider exceptions may expose server paths.
			$reason       = $error->getMessage();
			$client_error = $error instanceof RuntimeException && (
				in_array(
					$reason,
					array(
						'Invalid asset upload metadata.',
						'Asset upload is not a valid HTTP upload.',
						'Asset exceeds the per-file size limit.',
						'Request asset limit exceeded (max 10 files / 25 MiB total).',
						'Asset extension is not an allowed WordPress media type or connector plugin/theme package.',
						'asset_path must be a short relative path without whitespace or control characters.',
						'asset_path contains an invalid path segment.',
						'asset_path contains unsupported characters.',
						'request_id must be 8-100 safe characters.',
					),
					true
				)
			|| 1 === preg_match( '/\AAsset upload failed with PHP upload error -?[0-9]+\.\z/', $reason )
			|| 1 === preg_match( '#\ADuplicate request asset path: [A-Za-z0-9._/-]{1,240}\z#', $reason )
			);
			if ( $client_error ) {
				return new \WP_Error( 'wpconnector_asset_rejected', $reason, array( 'status' => 400 ) );
			}
			return new \WP_Error(
				'wpconnector_asset_storage_unavailable',
				'Private request asset storage is unavailable.',
				array( 'status' => 503 )
			);
		}
	}

	/**
	 * Validate asset path for the Controller component.
	 *
	 * @param mixed $value Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	public function validate_asset_path( $value ): bool {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 240 || '/' === $value[0] || false !== strpos( $value, '\\' ) ) {
			return false; }
		foreach ( explode( '/', $value ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/', $segment ) ) {
				return false; }
		}
		return true;
	}

	/**
	 * Validate request id for the Controller component.
	 *
	 * @param mixed $value Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	public function validate_request_id( $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $value );
	}

	/**
	 * Process the allow presence operation in the Controller component.
	 *
	 * @param \WP_REST_Request $request Input for the operation.
	 *
	 * @return mixed Operation result.
	 */
	public function allow_presence( \WP_REST_Request $request ) {
		// The REST callback signature includes the request; only transport security is required here.
		unset( $request );
		if ( ! is_ssl() ) {
			return new \WP_Error( 'wpconnector_https_required', 'WordPress Connector presence requires HTTPS.', array( 'status' => 403 ) ); }
		return true;
	}
}
