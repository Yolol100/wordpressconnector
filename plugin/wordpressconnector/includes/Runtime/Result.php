<?php
/**
 * Provides Result behavior for connector request processing.
 *
 * @package WordPressConnector
 */

declare( strict_types=1 );

namespace Webactueel\WordPressConnector\Runtime;

use Webactueel\WordPressConnector\Security\Policy;

/**
 * Provides Result behavior for connector request processing.
 */
final class Result
{
	/**
	 * Build a redacted successful connector response.
	 *
	 * @param Request $request Request metadata.
	 * @param array   $data    Successful operation data.
	 * @param array   $meta    Additional response metadata.
	 *
	 * @return array Redacted successful response.
	 */
	public static function success( Request $request, array $data, array $meta = array() ): array
	{
		return Policy::redact(
			array(
				'version'          => 1,
				'ok'               => true,
				'request_id'       => $request->id(),
				'action'           => $request->action(),
				'dry_run'          => $request->dryRun(),
				'completed_at_gmt' => gmdate( 'c' ),
				'data'             => $data,
				'meta'             => $meta,
			)
		);
	}

	/**
	 * Build a redacted failed connector response.
	 *
	 * @param Request $request Request metadata.
	 * @param string  $message Failure message.
	 * @param array   $meta    Additional response metadata.
	 *
	 * @return array Redacted failure response.
	 */
	public static function failure( Request $request, string $message, array $meta = array() ): array
	{
		return Policy::redact(
			array(
				'version'          => 1,
				'ok'               => false,
				'request_id'       => $request->id(),
				'action'           => $request->action(),
				'dry_run'          => $request->dryRun(),
				'completed_at_gmt' => gmdate( 'c' ),
				'error'            => $message,
				'meta'             => $meta,
			)
		);
	}
}
