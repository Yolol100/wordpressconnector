<?php
/**
 * Canonical public method implementations for YoastSnippetAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait YoastSnippetAdapterActions {
	/**
	 * Validate fields for the YoastSnippet adapter.
	 *
	 * @param mixed $fields Input for the operation.
	 * @param bool  $restore Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function validate_fields( $fields, bool $restore = false ): array {
		if ( ! is_array( $fields ) || ! $fields || count( $fields ) > 2 || array_keys( $fields ) === range( 0, count( $fields ) - 1 ) ) {
			throw new RuntimeException( 'Snippet fields must be a nonempty title/description object.' );
		}
		$validated = array();
		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, array( 'title', 'description' ), true ) ) {
				throw new RuntimeException( 'Unsupported public snippet field.' );
			}
			if ( $restore && null === $value ) {
				$validated[ $key ] = null;
				continue;
			}
			$max = 'title' === $key ? 190 : 320;
			if ( ! is_string( $value ) || strlen( $value ) < 10 || strlen( $value ) > $max ||
				trim( $value ) !== $value || preg_match( '/[\x00-\x1f\x7f<>]/', $value ) ||
				strpos( $value, '%%' ) !== false ) {
				throw new RuntimeException( 'Snippet text must be plain, bounded and nonempty.' );
			}
			$clean = sanitize_text_field( $value );
			if ( ! hash_equals( $value, $clean ) ) {
				throw new RuntimeException( 'Snippet text changed after sanitization.' );
			}
			$validated[ $key ] = $clean;
		}
		return $validated;
	}
}
