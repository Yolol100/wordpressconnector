<?php
/**
 * Provides Input utilities shared by connector services.
 *
 * @package WordPressConnector
 */

declare( strict_types=1 );

namespace Webactueel\WordPressConnector\Support;

use RuntimeException;

/**
 * Provides Input utilities shared by connector services.
 */
final class Input
{
	/**
	 * Read an optional boolean input using the validated default.
	 *
	 * @param array  $input   Input field values.
	 * @param string $key     Field key to inspect.
	 * @param bool   $default Default value when absent.
	 *
	 * @return bool Validated boolean value.
	 */
	public static function bool( array $input, string $key, bool $default = false ): bool
	{
		if ( ! array_key_exists( $key, $input ) ) {
			return $default;
		}

		return self::boolValue( $input[ $key ], $key );
	}

	/**
	 * Reject values that are not true PHP booleans.
	 *
	 * @param mixed  $value Value to validate.
	 * @param string $label Input field label used for errors.
	 *
	 * @return bool Validated input value.
	 * @throws \RuntimeException When the input is not boolean.
	 */
	public static function boolValue( $value, string $label ): bool
	{
		if ( ! is_bool( $value ) ) {
			throw new RuntimeException( $label . ' must be boolean.' );
		}

		return $value;
	}
}
