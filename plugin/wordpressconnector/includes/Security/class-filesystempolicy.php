<?php
/**
 * Applies Filesystem Policy safeguards to connector operations.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

require_once __DIR__ . '/trait-filesystempolicymethods.php';

/**
 * Applies Filesystem Policy safeguards to connector operations.
 */
final class FilesystemPolicy {
	use FilesystemPolicyMethods {
		assert_listable as assertListable;
		assert_readable_text as assertReadableText;
		assert_writable_text as assertWritableText;
		assert_lifecycle_text as assertLifecycleText;
		affects_browser_execution as affectsBrowserExecution;
		is_writable_text as isWritableText;
	}

	private const TEXT_EXTENSIONS = array(
		'css',
		'htm',
		'html',
		'inc',
		'js',
		'json',
		'md',
		'mjs',
		'php',
		'txt',
		'xml',
		'yaml',
		'yml',
	);

	private const WRITE_PREFIXES = array(
		'wp-content/plugins/',
		'wp-content/themes/',
	);

	/**
	 * Process the normalize operation in the FilesystemPolicy component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function normalize( string $path ): string {
		$path = trim( $path );
		if ( '.' === $path ) {
			return '.';
		}
		if ( '' === $path || strlen( $path ) > 240 || false !== strpos( $path, "\0" ) || '/' === $path[0] || false !== strpos( $path, '\\' ) ) {
			throw new RuntimeException( 'Filesystem path must be a short forward-slash relative path.' );
		}
		if ( preg_match( '/^[A-Za-z]:\//', $path ) ) {
			throw new RuntimeException( 'Absolute filesystem paths are not allowed.' );
		}

		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw new RuntimeException( 'Filesystem path contains an invalid segment.' );
			}
			if ( preg_match( '/[\x00-\x1F\x7F]/', $segment ) ) {
				throw new RuntimeException( 'Filesystem path contains control characters.' );
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Ensure text extension for the FilesystemPolicy component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private static function assert_text_extension( string $path ): void {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $extension || ! in_array( $extension, self::TEXT_EXTENSIONS, true ) ) {
			throw new RuntimeException( 'Unsupported text file extension for connector filesystem access.' );
		}
	}

	/**
	 * Ensure no managed data path for the FilesystemPolicy component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private static function assert_no_managed_data_path( string $path ): void {
		$normalized = strtolower( rtrim( $path, '/' ) );
		foreach ( array(
			'wp-content/uploads',
			'wp-content/cache',
			'wp-content/wflogs',
			'wp-content/updraft',
			'wp-content/ai1wm-backups',
			'wp-content/upgrade',
		) as $prefix ) {
			if ( $normalized === $prefix || 0 === strpos( $normalized, $prefix . '/' ) ) {
				throw new RuntimeException( 'Managed uploads, cache, backup, upgrade and security-log paths are not exposed through filesystem actions. Use the dedicated WordPress/media/system workflow instead.' );
			}
		}
	}

	/**
	 * Ensure no hidden or secret path for the FilesystemPolicy component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private static function assert_no_hidden_or_secret_path( string $path ): void {
		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( isset( $segment[0] ) && '.' === $segment[0] ) {
				throw new RuntimeException( 'Hidden filesystem paths are not exposed through the connector.' );
			}
		}

		$lower = strtolower( $path );
		$base  = strtolower( (string) basename( $path ) );
		if ( 'wp-config.php' === $lower || 'debug.log' === $base || 'error_log' === $base || 'php_errors.log' === $base ) {
			throw new RuntimeException( 'Sensitive WordPress/configuration log files are not exposed through the connector.' );
		}
		if ( preg_match( '/(^|\/)(\.env[^\/]*|auth\.json|composer-auth\.json)$/i', $path ) ) {
			throw new RuntimeException( 'Credential-bearing files are not exposed through the connector.' );
		}
		if ( preg_match( '/\.(key|pem|p12|pfx)$/i', $path ) ) {
			throw new RuntimeException( 'Private key/certificate bundle files are not exposed through the connector.' );
		}
	}
}
