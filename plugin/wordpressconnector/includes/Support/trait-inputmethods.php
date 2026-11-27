<?php
/**
 * Canonical public method implementations for Input.
 *
 * @package WordPressConnector
 */

declare( strict_types=1 );

namespace Webactueel\WordPressConnector\Support;

use RuntimeException;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait InputMethods {
	/**
	 * Reject values that are not true PHP booleans.
	 *
	 * @param mixed  $value Value to validate.
	 * @param string $label Input field label used for errors.
	 *
	 * @return bool Validated input value.
	 * @throws \RuntimeException When the input is not boolean.
	 */
	public static function bool_value( $value, string $label ): bool {
		if ( ! is_bool( $value ) ) {
			throw new RuntimeException( esc_html( $label ) . ' must be boolean.' );
		}

		return $value;
	}
}
