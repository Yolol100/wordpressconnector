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
final class Input {

	/**
	 * Read an optional boolean input using the validated default.
	 *
	 * @param array  $input   Input field values.
	 * @param string $key     Field key to inspect.
	 * @param bool   $default Default value when absent.
	 *
	 * @return bool Validated boolean value.
	 */
	public static function bool( array $input, string $key, bool $default = false ): bool { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Preserve publicly callable PHP 8 named argument `default`.
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
	public static function boolValue( $value, string $label ): bool {
		if ( ! is_bool( $value ) ) {
			throw new RuntimeException( $label . ' must be boolean.' );
		}

		return $value;
	}

	/**
	 * Read a non-negative ID without converting malformed values.
	 * Literal zero (including zero-padded strings) is valid for create/root slots.
	 *
	 * @param mixed $value Untrusted identifier.
	 *
	 * @return int Validated non-negative ID.
	 *
	 * @throws \RuntimeException When the ID is not an exact non-negative integer.
	 */
	public static function non_negative_id( $value ): int {
		if ( 0 === $value || ( is_string( $value ) && 1 === preg_match( '/\A0+\z/', $value ) ) ) {
			return 0;
		}
		return self::positive_id( $value );
	}

	/**
	 * Resolve a positive numeric identifier without PHP's lossy casts.
	 * Clients may pass decimal digit strings with harmless leading zeroes.
	 *
	 * @param mixed $value Untrusted identifier.
	 *
	 * @return int Validated positive ID.
	 *
	 * @throws \RuntimeException When the ID is not an exact decimal integer.
	 */
	public static function positive_id( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/\A[0-9]+\z/', $value ) ) {
			$digits = ltrim( $value, '0' );
			if ( '' !== $digits && (string) (int) $digits === $digits ) {
				return (int) $digits;
			}
		}
		throw new RuntimeException( 'Identifier must be a positive integer.' );
	}
}
