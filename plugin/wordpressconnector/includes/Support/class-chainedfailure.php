<?php
/**
 * Creates display-safe diagnostic exceptions while preserving their cause.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Support;

use RuntimeException;
use Throwable;

/**
 * Normalizes human-readable failure text independently of the causal object.
 *
 * Not for structured JSON payloads. The original exception remains available
 * through getPrevious(); its message and trace are not serialized by this helper.
 */
final class ChainedFailure {

	/**
	 * Create a native RuntimeException with a safe display message.
	 *
	 * @param string    $message Human-readable diagnostic text.
	 * @param Throwable $cause   Original exception, preserved unchanged.
	 * @return RuntimeException The chained exception.
	 */
	public static function create( string $message, Throwable $cause ): RuntimeException {
		// Standalone JSON/CLI consumers may run without a WordPress bootstrap.
		$display = function_exists( 'esc_html' )
			? esc_html( $message )
			: htmlspecialchars( $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
		return new RuntimeException( $display, 0, $cause );
	}
}
