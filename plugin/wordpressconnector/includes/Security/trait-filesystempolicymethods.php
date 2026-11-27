<?php
/**
 * Applies Filesystem Policy safeguards to connector operations.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

/**
 * Canonical implementations of the public methods.
 *
 * The host class retains legacy PHP method aliases for compatibility.
 */
trait FilesystemPolicyMethods {
	/**
	 * Ensure listable for the FilesystemPolicy component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @return string Operation result.
	 */
	public static function assert_listable( string $path ): string {
		$path = self::normalize( $path );
		if ( '.' !== $path ) {
			self::assert_no_hidden_or_secret_path( $path );
			self::assert_no_managed_data_path( $path );
		}
		return $path;
	}

	/**
	 * Ensure readable text for the FilesystemPolicy component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assert_readable_text( string $path ): string {
		$path = self::normalize( $path );
		if ( '.' === $path ) {
			throw new RuntimeException( 'A file path is required.' );
		}
		self::assert_no_hidden_or_secret_path( $path );
		self::assert_no_managed_data_path( $path );
		self::assert_text_extension( $path );
		return $path;
	}

	/**
	 * Ensure writable text for the FilesystemPolicy component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assert_writable_text( string $path ): string {
		$path    = self::assert_readable_text( $path );
		$allowed = false;
		foreach ( self::WRITE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			throw new RuntimeException( 'Filesystem writes are limited to existing plugin and theme text files.' );
		}
		if ( 0 === strpos( $path, 'wp-content/plugins/wordpressconnector/' ) ) {
			throw new RuntimeException( 'The connector cannot rewrite its own runtime files. Deploy connector changes through GitHub/release flow.' );
		}
		return $path;
	}

	/**
	 * Creation/deletion is narrower than replacement: executable PHP and
	 * plugin/theme identity, build and dependency manifests stay deployment-only.
	 *
	 * @param string $path Filesystem path to validate.
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	public static function assert_lifecycle_text( string $path ): string {
		$path      = self::assert_writable_text( $path );
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'css', 'htm', 'html', 'js', 'json', 'md', 'mjs', 'txt', 'xml', 'yaml', 'yml' ), true ) ) {
			throw new RuntimeException( 'Create/delete supports only non-PHP theme/plugin text assets.' );
		}
		$base = strtolower( (string) basename( $path ) );
		if ( in_array(
			$base,
			array(
				'style.css',
				'theme.json',
				'block.json',
				'package.json',
				'package-lock.json',
				'composer.json',
				'composer.lock',
				'readme.txt',
				'license.txt',
			),
			true
		) ) {
			throw new RuntimeException( 'Package and theme identity files require reviewed deployment rather than direct create/delete.' );
		}
		return $path;
	}

	/**
	 * HTML and JavaScript execute in browsers; CSS also changes the rendered
	 * interface. Treat these as active code assets, not inert text.
	 *
	 * @param string $path Filesystem path to validate.
	 */
	public static function affects_browser_execution( string $path ): bool {
		// Replacement also edits active browser assets; PHP/INC remain separately
		// parser-validated under the existing plugin/theme capability gate.
		$path = self::assert_writable_text( $path );
		return in_array(
			strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ),
			array( 'html', 'htm', 'js', 'mjs', 'css' ),
			true
		);
	}

	/**
	 * Check whether writable text for the FilesystemPolicy component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	public static function is_writable_text( string $path ): bool {
		try {
			self::assert_writable_text( $path );
			return true;
		} catch ( RuntimeException $error ) {
			return false;
		}
	}
}
