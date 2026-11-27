<?php
/**
 * Canonical public method implementations for FilesystemAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use ParseError;
use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\FilesystemPolicy;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait FilesystemAdapterActions {
	/**
	 * List path for the Filesystem adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function list_path( array $payload, array $context = array() ): array {
		// Registry supplies execution context; this read does not consume it.
		unset( $context );

		$relative = FilesystemPolicy::assertListable( isset( $payload['path'] ) ? (string) $payload['path'] : '.' );
		$absolute = $this->resolve_existing( $relative, true );
		if ( ! is_dir( $absolute ) ) {
			throw new RuntimeException( 'Filesystem path is not a directory.' );
		}

		$names = scandir( $absolute );
		if ( false === $names ) {
			throw new RuntimeException( 'Could not list filesystem directory.' );
		}
		natcasesort( $names );
		$items     = array();
		$truncated = false;
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = '.' === $relative ? $name : $relative . '/' . $name;
			try {
				$child          = FilesystemPolicy::assertListable( $child );
				$child_absolute = $this->resolve_existing( $child, true );
			} catch ( RuntimeException $error ) {
				continue;
			}
			if ( count( $items ) >= self::MAX_LIST_ENTRIES ) {
				$truncated = true;
				break;
			}
			$items[] = array(
				'name'                => $name,
				'path'                => $child,
				'type'                => is_dir( $child_absolute ) ? 'directory' : 'file',
				'bytes'               => is_file( $child_absolute ) ? (int) filesize( $child_absolute ) : null,
				'modified_gmt'        => $this->modified_gmt( $child_absolute ),
				'writable_text_scope' => is_file( $child_absolute ) && FilesystemPolicy::isWritableText( $child ),
			);
		}

		return array(
			'path'        => $relative,
			'entries'     => $items,
			'truncated'   => $truncated,
			'max_entries' => self::MAX_LIST_ENTRIES,
		);
	}
}
