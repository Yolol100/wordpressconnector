<?php
/**
 * Registers and handles Plugin Package connector actions.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

require_once __DIR__ . '/trait-pluginpackageadapteractions.php';

/**
 * Registers and handles Plugin Package connector actions.
 */
final class PluginPackageAdapter {
	use PluginPackageAdapterActions {
		register_theme_package as registerThemePackage;
		install_theme_package as installThemePackage;
		install_package as installPackage;
	}

	private const MAX_PACKAGE_BYTES      = 20971520;
	private const MAX_UNCOMPRESSED_BYTES = 104857600;
	private const MAX_ENTRIES            = 2500;

	/**
	 * Register the PluginPackage adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register(
			'plugin.install_package',
			array( $this, 'installPackage' ),
			array(
				'mutation'      => true,
				'privileged'    => true,
				'system_update' => true,
				'capability'    => 'install_plugins',
				'description'   => 'Install or overwrite a plugin from a verified ZIP uploaded to the trusted request asset root.',
			)
		);
		$this->registerThemePackage( $registry );
	}

	/**
	 * Inspect theme package for the PluginPackage adapter.
	 *
	 * @param string $package Input for the operation.
	 * @param string $expected Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function inspect_theme_package( string $package, string $expected ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'PHP ZipArchive is required for theme package inspection.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $package, \ZipArchive::RDONLY ) ) {
			throw new RuntimeException( 'Invalid theme ZIP archive.' );
		}
		try {
			$entry_count = $zip->count();
			if ( $entry_count < 2 || $entry_count > self::MAX_ENTRIES ) {
				throw new RuntimeException( 'Theme ZIP has an invalid number of files.' );
			}
			$seen  = array();
			$total = 0;
			$style = '';
			for ( $i = 0; $i < $entry_count; ++$i ) {
				$stat = $zip->statIndex( $i, \ZipArchive::FL_UNCHANGED );
				if ( ! is_array( $stat ) || ! isset( $stat['name'] ) ) {
					throw new RuntimeException( 'Unreadable theme ZIP member.' );
				}
				$path = (string) $stat['name'];
				if ( strlen( $path ) > 512 || false !== strpos( $path, "\0" )
					|| preg_match( '/[\x01-\x1F\x7F]/', $path )
					|| false !== strpos( $path, '\\' ) || '/' === substr( $path, 0, 1 )
					|| preg_match( '/^[A-Za-z]:/', $path ) ) {
					throw new RuntimeException( 'Unsafe theme ZIP path.' );
				}
				$normal   = rtrim( $path, '/' );
				$segments = explode( '/', $normal );
				if ( count( $segments ) < 1 || $segments[0] !== $expected || isset( $seen[ $normal ] ) ) {
					throw new RuntimeException( 'Theme ZIP folder or duplicate path mismatch.' );
				}
				foreach ( $segments as $segment ) {
					if ( '' === $segment || '.' === $segment || '..' === $segment ) {
						throw new RuntimeException( 'Theme ZIP contains traversal or invalid path.' );
					}
				}
				$seen[ $normal ] = true;
				$bytes           = isset( $stat['size'] ) ? (int) $stat['size'] : -1;
				if ( $bytes < 0 ) {
					throw new RuntimeException( 'Invalid theme ZIP entry size.' );
				}
				$total += $bytes;
				if ( $total > self::MAX_UNCOMPRESSED_BYTES ) {
					throw new RuntimeException( 'Theme ZIP exceeds extracted size budget.' );
				}
				$opsys      = 0;
				$attributes = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attributes )
					&& 0xA000 === ( ( $attributes >> 16 ) & 0xF000 ) ) {
					throw new RuntimeException( 'Theme ZIP must not contain symbolic links.' );
				}
				if ( $normal === $expected . '/style.css' && '/' !== substr( $path, -1 ) ) {
					$style = (string) $zip->getFromIndex( $i, min( 8192, $bytes ), \ZipArchive::FL_UNCHANGED );
				}
			}
			if ( '' === $style || ! preg_match( '/(?:^|\n)[ \t\/\*#@]*Theme Name:\s*\S/i', $style ) ) {
				throw new RuntimeException( 'Theme ZIP lacks a valid root style.css theme header.' );
			}
			return array(
				'entries'            => $entry_count,
				'uncompressed_bytes' => $total,
			);
		} finally {
			$zip->close();
		}
	}

	/**
	 * Inspect package for the PluginPackage adapter.
	 *
	 * @param string $package Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function inspect_package( string $package ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'PHP ZipArchive is required for safe plugin package inspection.' );
		}

		$zip    = new \ZipArchive();
		$opened = $zip->open( $package, \ZipArchive::RDONLY );
		if ( true !== $opened ) {
			throw new RuntimeException( 'Plugin ZIP could not be opened.' );
		}

		try {
			$entry_count = $zip->count();
			if ( $entry_count < 1 || $entry_count > self::MAX_ENTRIES ) {
				throw new RuntimeException( 'Plugin ZIP has an invalid number of entries.' );
			}

			$roots              = array();
			$paths              = array();
			$headers            = array();
			$uncompressed_bytes = 0;

			for ( $index = 0; $index < $entry_count; ++$index ) {
				$stat = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
				if ( ! is_array( $stat ) || ! isset( $stat['name'] ) ) {
					throw new RuntimeException( 'Plugin ZIP contains an unreadable entry.' );
				}

				$name = (string) $stat['name'];
				if ( '' === $name || strlen( $name ) > 512 || false !== strpos( $name, "\0" ) || preg_match( '/[\x01-\x1F\x7F]/', $name ) || false !== strpos( $name, '\\' ) || '/' === $name[0] || preg_match( '/^[A-Za-z]:/', $name ) ) {
					throw new RuntimeException( 'Plugin ZIP contains an unsafe entry path.' );
				}

				$directory  = '/' === substr( $name, -1 );
				$normalized = $directory ? rtrim( $name, '/' ) : $name;
				if ( '' === $normalized ) {
					throw new RuntimeException( 'Plugin ZIP contains an invalid root entry.' );
				}

				$segments = explode( '/', $normalized );
				foreach ( $segments as $segment ) {
					if ( '' === $segment || '.' === $segment || '..' === $segment ) {
						throw new RuntimeException( 'Plugin ZIP contains path traversal or an invalid path segment.' );
					}
				}
				$roots[ $segments[0] ] = true;

				if ( isset( $paths[ $normalized ] ) ) {
					throw new RuntimeException( 'Plugin ZIP contains duplicate entry paths.' );
				}
				$paths[ $normalized ] = true;

				$entry_bytes = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
				if ( $entry_bytes < 0 ) {
					throw new RuntimeException( 'Plugin ZIP contains an invalid entry size.' );
				}
				$uncompressed_bytes += $entry_bytes;
				if ( $uncompressed_bytes > self::MAX_UNCOMPRESSED_BYTES ) {
					throw new RuntimeException( 'Plugin ZIP exceeds the uncompressed size limit.' );
				}

				$opsys      = 0;
				$attributes = 0;
				if ( $zip->getExternalAttributesIndex( $index, $opsys, $attributes ) ) {
					$mode = ( $attributes >> 16 ) & 0xF000;
					if ( 0xA000 === $mode ) {
						throw new RuntimeException( 'Plugin ZIP symlink entries are not allowed.' );
					}
				}

				if ( $directory || 1 !== substr_count( $normalized, '/' ) || 'php' !== strtolower( (string) pathinfo( $normalized, PATHINFO_EXTENSION ) ) ) {
					continue;
				}

				$header = $zip->getFromIndex( $index, min( 8192, max( 1, $entry_bytes ) ), \ZipArchive::FL_UNCHANGED );
				if ( is_string( $header ) && preg_match( '/(?:^|\n)[ \t\/*#@]*Plugin Name:\s*(.+?)(?:\r?\n|$)/i', $header, $matches ) ) {
					$headers[] = array(
						'path' => $normalized,
						'name' => trim( (string) $matches[1] ),
					);
				}
			}

			if ( 1 !== count( $roots ) ) {
				throw new RuntimeException( 'Plugin ZIP must contain exactly one top-level plugin directory.' );
			}
			if ( 1 !== count( $headers ) ) {
				throw new RuntimeException( 'Plugin ZIP must contain exactly one detectable main plugin file directly inside that directory.' );
			}

			$plugin_file = (string) $headers[0]['path'];
			if ( ! preg_match( '/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+\.php\z/', $plugin_file ) ) {
				throw new RuntimeException( 'Main plugin file must use a safe plugin directory and PHP filename.' );
			}

			return array(
				'plugin_file'        => $plugin_file,
				'plugin_name'        => sanitize_text_field( (string) $headers[0]['name'] ),
				'entries'            => (int) $entry_count,
				'uncompressed_bytes' => $uncompressed_bytes,
			);
		} finally {
			$zip->close();
		}
	}

	/**
	 * Ensure capabilities for the PluginPackage adapter.
	 *
	 * @param bool $overwrite Input for the operation.
	 * @param bool $activate Input for the operation.
	 * @param bool $network_wide Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_capabilities( bool $overwrite, bool $activate, bool $network_wide ): void {
		if ( ! current_user_can( 'install_plugins' ) ) {
			throw new RuntimeException( 'Current user is not allowed to install plugins.' );
		}
		if ( $overwrite && ! current_user_can( 'update_plugins' ) ) {
			throw new RuntimeException( 'Current user is not allowed to update plugins.' );
		}
		if ( $activate && ! current_user_can( 'activate_plugins' ) ) {
			throw new RuntimeException( 'Current user is not allowed to activate plugins.' );
		}
		if ( $network_wide ) {
			if ( ! is_multisite() ) {
				throw new RuntimeException( 'network_wide is only valid on multisite.' );
			}
			if ( ! current_user_can( 'manage_network_plugins' ) ) {
				throw new RuntimeException( 'Current user is not allowed to manage network plugins.' );
			}
		}
	}

	/**
	 * Ensure not self for the PluginPackage adapter.
	 *
	 * @param string $plugin_file Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_not_self( string $plugin_file ): void {
		if ( defined( 'WPCONNECTOR_FILE' ) && function_exists( 'plugin_basename' ) && hash_equals( str_replace( '\\', '/', plugin_basename( WPCONNECTOR_FILE ) ), $plugin_file ) ) {
			throw new RuntimeException( 'WordPress Connector cannot replace its own active runtime through plugin.install_package. Use the repository release/deployment path.' );
		}
	}

	/**
	 * Process the snapshot operation in the PluginPackage adapter.
	 *
	 * @param string $plugin_file Input for the operation.
	 * @param array  $plugins Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function snapshot( string $plugin_file, array $plugins ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return array(
				'file'           => $plugin_file,
				'installed'      => false,
				'version'        => null,
				'active'         => false,
				'network_active' => false,
			);
		}

		return array(
			'file'           => $plugin_file,
			'installed'      => true,
			'name'           => isset( $plugins[ $plugin_file ]['Name'] ) ? (string) $plugins[ $plugin_file ]['Name'] : $plugin_file,
			'version'        => isset( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '',
			'active'         => is_plugin_active( $plugin_file ),
			'network_active' => is_multisite() ? is_plugin_active_for_network( $plugin_file ) : false,
		);
	}
}
