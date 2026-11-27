<?php
/**
 * Registers and handles Filesystem connector actions.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use ParseError;
use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\ChainedFailure;
use Webactueel\WordPressConnector\Security\FilesystemPolicy;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\LocalFileIdentity;

require_once __DIR__ . '/../Support/class-chainedfailure.php';

require_once __DIR__ . '/trait-filesystemadapteractions.php';
require_once __DIR__ . '/../Support/class-localfileidentity.php';

/**
 * Registers and handles Filesystem connector actions.
 */
final class FilesystemAdapter {
	use FilesystemAdapterActions {
		read_text_compat as public readText;
		write_text_compat as public writeText;
		create_text_compat as public createText;
		delete_text_compat as public deleteText;
		list_path as listPath;
	}

	private const MAX_TEXT_BYTES   = 131072;
	private const MAX_LIST_ENTRIES = 200;

	/**
	 * Register the Filesystem adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register(
			'filesystem.inspect',
			array( $this, 'inspect' ),
			array(
				'privileged'  => true,
				'description' => 'Inspect safe WordPress filesystem capabilities and WP File Manager integration state without exposing absolute paths.',
			)
		);
		$registry->register(
			'filesystem.list',
			array( $this, 'listPath' ),
			array(
				'privileged'  => true,
				'description' => 'List a bounded directory inside the WordPress root while hiding secret and dotfile paths.',
			)
		);
		$registry->register(
			'filesystem.read_text',
			array( $this, 'readText' ),
			array(
				'privileged'  => true,
				'description' => 'Read a bounded non-secret text file inside the WordPress root.',
			)
		);
		$registry->register(
			'filesystem.write_text',
			array( $this, 'writeText' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Replace an existing plugin/theme text file with checksum guard, syntax validation, readback and rollback.',
			)
		);
		$registry->register(
			'filesystem.create_text',
			array( $this, 'createText' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Create a new non-PHP plugin/theme text asset in an existing directory with dry-run, readback and rollback.',
			)
		);
		$registry->register(
			'filesystem.delete_text',
			array( $this, 'deleteText' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Delete a guarded non-PHP plugin/theme text asset with exact SHA-256, readback and rollback.',
			)
		);
	}

	/**
	 * Process the inspect operation in the Filesystem adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 */
	public function inspect( array $payload = array(), array $context = array() ): array {
		// This read is independent of request input; retain the common registry callback signature.
		unset( $payload, $context );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugins           = get_plugins();
		$file_manager_file = 'wp-file-manager/file_folder_manager.php';
		$file_manager      = isset( $plugins[ $file_manager_file ] ) ? $plugins[ $file_manager_file ] : array();
		$settings          = get_option( 'wp_file_manager_settings', array() );
		$configured_path   = is_array( $settings ) && isset( $settings['public_path'] ) ? (string) $settings['public_path'] : '';

		return array(
			'root'              => 'wordpress_root',
			'filesystem_method' => (string) get_filesystem_method( array(), ABSPATH ),
			'write_gate'        => Policy::flag( 'WPCONNECTOR_ALLOW_FILESYSTEM_WRITES' ),
			'read'              => array(
				'root_listing'       => true,
				'text_max_bytes'     => self::MAX_TEXT_BYTES,
				'secret_files'       => 'blocked',
				'managed_data_paths' => 'use dedicated WordPress/media/system actions',
			),
			'write'             => array(
				'scope'                 => array( 'replace existing wp-content/plugins/* and wp-content/themes/* text files', 'create/delete non-PHP text assets in existing plugin/theme directories' ),
				'core'                  => false,
				'uploads'               => false,
				'connector_self_update' => false,
				'requires'              => array( 'privileged gate', 'writes gate', 'filesystem-writes gate', 'confirm=true', 'expected_sha256 for delete/replace', 'no existing file for create' ),
				'parser_validation'     => array( 'php', 'inc', 'json' ),
				'readback'              => 'sha256',
				'rollback'              => true,
			),
			'wp_file_manager'   => array(
				'installed'            => ! empty( $file_manager ),
				'active'               => function_exists( 'is_plugin_active' ) && is_plugin_active( $file_manager_file ),
				'version'              => isset( $file_manager['Version'] ) ? (string) $file_manager['Version'] : null,
				'configured_root_mode' => $this->file_manager_root_mode( $configured_path ),
				'connector_transport'  => 'WP_Filesystem',
				'private_ajax_reused'  => false,
			),
		);
	}

	/**
	 * Read text for the Filesystem adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function read_text( array $payload, array $context = array() ): array {
		// Registry supplies execution context; this read does not consume it.
		unset( $context );

		$relative = FilesystemPolicy::assertReadableText( isset( $payload['path'] ) ? (string) $payload['path'] : '' );
		$absolute = $this->resolve_existing( $relative, false );
		if ( ! is_file( $absolute ) ) {
			throw new RuntimeException( 'Filesystem path is not a regular file.' );
		}
		$content = $this->read_bounded_local_text( $absolute );
		if ( is_string( $content ) && strlen( $content ) > self::MAX_TEXT_BYTES ) {
			throw new RuntimeException( 'Text file exceeds the connector read limit.' );
		}
		if ( ! is_string( $content ) || ! $this->is_utf8( $content ) ) {
			throw new RuntimeException( 'Filesystem file is not readable UTF-8 text.' );
		}
		$this->assert_no_embedded_secrets( $content );
		$state = $this->state( $relative, $content, $absolute );

		return array(
			'file'        => $state,
			'content'     => $content,
			'fingerprint' => Fingerprint::make( $state ),
		);
	}

	/**
	 * Write text for the Filesystem adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function write_text( array $payload, array $context ): array {
		if ( empty( $context['dry_run'] ) && ! Policy::flag( 'WPCONNECTOR_ALLOW_FILESYSTEM_WRITES' ) ) {
			throw new RuntimeException( 'Filesystem writes are disabled. Enable the dedicated filesystem-writes gate first.' );
		}

		$relative            = FilesystemPolicy::assertWritableText( isset( $payload['path'] ) ? (string) $payload['path'] : '' );
		$active_content      = FilesystemPolicy::affectsBrowserExecution( $relative );
		$required_capability = 0 === strpos( $relative, 'wp-content/plugins/' ) ? 'edit_plugins' : 'edit_themes';
		if ( ! current_user_can( $required_capability ) ) {
			throw new RuntimeException( 'Current user lacks the required filesystem capability: ' . esc_html( $required_capability ) . '.' );
		}
		$absolute = $this->resolve_existing( $relative, false );
		if ( ! is_file( $absolute ) ) {
			throw new RuntimeException( 'filesystem.write_text only replaces existing regular files.' );
		}
		$before = $this->read_bounded_local_text( $absolute );
		if ( is_string( $before ) && strlen( $before ) > self::MAX_TEXT_BYTES ) {
			throw new RuntimeException( 'Existing file exceeds the connector write limit.' );
		}
		if ( ! is_string( $before ) || ! $this->is_utf8( $before ) ) {
			throw new RuntimeException( 'Existing file is not readable UTF-8 text.' );
		}
		$this->assert_no_embedded_secrets( $before );
		if ( ! array_key_exists( 'content', $payload ) || ! is_string( $payload['content'] ) ) {
			throw new RuntimeException( 'filesystem.write_text requires payload.content as UTF-8 text.' );
		}
		$content = $payload['content'];
		if ( strlen( $content ) > self::MAX_TEXT_BYTES || ! $this->is_utf8( $content ) ) {
			throw new RuntimeException( 'Replacement content must be UTF-8 text within the connector write limit.' );
		}

		$this->validate_text( $relative, $content );
		$this->assert_no_embedded_secrets( $content );
		$before_state = $this->state( $relative, $before, $absolute );
		$after_state  = array(
			'path'   => $relative,
			'bytes'  => strlen( $content ),
			'sha256' => hash( 'sha256', $content ),
		);
		$result       = array(
			'before'                                  => $before_state,
			'after'                                   => $after_state,
			'_current_fingerprint'                    => Fingerprint::make( $before_state ),
			'requires_active_content_acknowledgement' => $active_content,
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		if ( $active_content && ( $payload['acknowledge_active_content'] ?? null ) !== true ) {
			throw new RuntimeException( 'Browser-active HTML, JavaScript and CSS file replacements require acknowledge_active_content=true.' );
		}
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'connector_filesystem' ) ) {
			throw new RuntimeException( 'WordPress or a policy plugin disallows file modifications; check DISALLOW_FILE_MODS and file_mod_allowed.' );
		}

		$expected_sha = isset( $payload['expected_sha256'] ) ? strtolower( (string) $payload['expected_sha256'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_sha ) ) {
			throw new RuntimeException( 'Non-dry-run filesystem writes require payload.expected_sha256.' );
		}
		if ( ! hash_equals( $expected_sha, $before_state['sha256'] ) ) {
			throw new RuntimeException( 'Stale filesystem target: expected_sha256 does not match the current file.' );
		}

		$filesystem = $this->filesystem();
		$mode       = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		try {
			if ( ! $filesystem->put_contents( $absolute, $content, $mode ) ) {
				throw new RuntimeException( 'WordPress Filesystem API could not write the target file.' );
			}

			clearstatcache( true, $absolute );
			$readback = $filesystem->get_contents( $absolute );
			if ( false === $readback || ! hash_equals( hash( 'sha256', $content ), hash( 'sha256', $readback ) ) ) {
				throw new RuntimeException( 'Filesystem write readback verification failed.' );
			}
		} catch ( Throwable $error ) {
			// A failed write can still truncate the file. Recover before the
			// Runner loses this local before-image when the action fails.
			try {
				$recovery_reported = true === $filesystem->put_contents( $absolute, $before, $mode );
			} catch ( Throwable $recovery_error ) {
				// A backend can throw after restoring all bytes.
				$recovery_reported = false;
			}
			try {
				clearstatcache( true, $absolute );
				$restored = $filesystem->get_contents( $absolute );
			} catch ( Throwable $verification_error ) {
				// No verified rollback can be claimed without authoritative readback.
				$detail  = $recovery_reported ? ' Recovery write reported success, but verification failed.' : ' Recovery write reported failure and verification failed.';
				$failure = ChainedFailure::create( $error->getMessage() . $detail . ' Original file state is unverified; manual recovery required.', $error );
				throw $failure;
			}
			if ( ! is_string( $restored ) || ! hash_equals( $before_state['sha256'], hash( 'sha256', $restored ) ) ) {
				$failure = ChainedFailure::create( $error->getMessage() . ' Recovery failed; original file could not be restored; manual recovery required.', $error );
				throw $failure;
			}
			$failure = ChainedFailure::create( $error->getMessage() . ' Recovery complete; original file restored and verified.', $error );
			throw $failure;
		}

		$result['after']     = $this->state( $relative, $readback, $absolute );
		$result['_rollback'] = array(
			'action'  => 'filesystem.write_text',
			'payload' => array(
				'path'                       => $relative,
				'content'                    => $before,
				'expected_sha256'            => hash( 'sha256', $readback ),
				'acknowledge_active_content' => $active_content,
			),
		);
		return $result;
	}

	/**
	 * Create text for the Filesystem adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function create_text( array $payload, array $context ): array {
		$this->assert_lifecycle_permission( $payload, $context );
		$relative = FilesystemPolicy::assertLifecycleText( (string) ( $payload['path'] ?? '' ) );
		if ( ! isset( $payload['content'] ) || ! is_string( $payload['content'] ) ) {
			throw new RuntimeException( 'filesystem.create_text requires UTF-8 content.' );
		}
		$content = $payload['content'];
		if ( strlen( $content ) > self::MAX_TEXT_BYTES || ! $this->is_utf8( $content ) ) {
			throw new RuntimeException( 'New text asset exceeds the UTF-8 text limit.' );
		}
		$this->assert_no_embedded_secrets( $content );
		$this->validate_text( $relative, $content );
		$absolute       = $this->resolve_new_text_path( $relative );
		$expected_state = array(
			'path'   => $relative,
			'exists' => false,
		);
		$result         = array(
			'before'                                  => $expected_state,
			'after'                                   => array(
				'path'   => $relative,
				'bytes'  => strlen( $content ),
				'sha256' => hash( 'sha256', $content ),
			),
			'requires_active_content_acknowledgement' => FilesystemPolicy::affectsBrowserExecution( $relative ),
			'_current_fingerprint'                    => Fingerprint::make( $expected_state ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$filesystem = $this->filesystem();
		// Exclusive binary create: never overwrite another writer's file.
		// SPL reports open failures as exceptions, including under strict
		// host error handlers; the failed opener does not own the target.
		try {
			$handle = new \SplFileObject( $absolute, 'xb' );
		} catch ( Throwable $error ) {
			throw new RuntimeException( 'New file could not be created exclusively; target may now exist or filesystem permissions may block it.' );
		}
		$created_stat = null;
		$ok           = true;
		$offset       = 0;
		$size         = strlen( $content );
		try {
			try {
				$created_stat = $handle->fstat();
				if ( ! is_array( $created_stat ) || 0 === $created_stat['ino'] ) {
					throw new RuntimeException( 'New file identity could not be verified.' );
				}
				while ( $offset < $size ) {
					$written = $handle->fwrite( substr( $content, $offset ) );
					if ( ! is_int( $written ) || $written <= 0 ) {
						$ok = false;
						break;
					}
					$offset += $written;
				}
				if ( ! $handle->fflush() ) {
					$ok = false;
				}
			} catch ( Throwable $error ) {
				$ok = false;
			}
			if ( ! $ok || $offset !== $size ) {
				$this->remove_failed_create( $filesystem, $absolute, is_array( $created_stat ) ? $created_stat : null, $handle );
				throw new RuntimeException( 'New file write failed; partial content was removed.' );
			}
			if ( ! LocalFileIdentity::matches( $absolute, $created_stat ) ) {
				throw new RuntimeException( 'New file identity changed before permissions could be set; manual recovery required.' );
			}
			try {
				$permissions_ok = $filesystem->chmod( $absolute, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
			} catch ( Throwable $error ) {
				$permissions_ok = false;
			}
			if ( ! $permissions_ok ) {
				$this->remove_failed_create( $filesystem, $absolute, is_array( $created_stat ) ? $created_stat : null, $handle );
				throw new RuntimeException( 'New file permissions could not be set; incomplete file was removed.' );
			}
			clearstatcache( true, $absolute );
			$readback = $this->read_bounded_local_text( $absolute );
			if ( ! is_string( $readback ) || ! hash_equals( hash( 'sha256', $content ), hash( 'sha256', $readback ) ) ||
				! LocalFileIdentity::matches( $absolute, $created_stat ) ) {
				$this->remove_failed_create( $filesystem, $absolute, is_array( $created_stat ) ? $created_stat : null, $handle );
				throw new RuntimeException( 'New file readback failed; incomplete file was removed.' );
			}
			$result['after']     = $this->state( $relative, $readback, $absolute );
			$result['_rollback'] = array(
				'action'  => 'filesystem.delete_text',
				'payload' => array(
					'path'                       => $relative,
					'expected_sha256'            => hash( 'sha256', $readback ),
					'acknowledge_active_content' => FilesystemPolicy::affectsBrowserExecution( $relative ),
				),
			);
			return $result;
		} finally {
			// Keep the original inode alive until identity checks finish.
			unset( $handle );
		}
	}

	/**
	 * Read a validated local file, limiting memory even if the file grows.
	 *
	 * One excess byte distinguishes an oversized file from an exact-limit
	 * file. Callers reject oversize before using the content or its hash.
	 * Missing, replaced or unreadable paths fail without native diagnostics.
	 *
	 * @param string $absolute Previously resolved local file path.
	 * @return string|null File bytes (at most the limit plus one), or null.
	 */
	private function read_bounded_local_text( string $absolute ): ?string {
		clearstatcache( true, $absolute );
		if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
			return null;
		}
		try {
			$handle = new \SplFileObject( $absolute, 'rb' );
			$stat   = $handle->fstat();
			if ( ! is_array( $stat ) || ( $stat['mode'] & 0170000 ) !== 0100000 ) {
				return null;
			}
			$content   = '';
			$remaining = self::MAX_TEXT_BYTES + 1;
			while ( ! $handle->eof() && $remaining > 0 ) {
				$chunk = $handle->fread( $remaining );
				if ( ! is_string( $chunk ) || ( '' === $chunk && ! $handle->eof() ) ) {
					return null;
				}
				$content   .= $chunk;
				$remaining -= strlen( $chunk );
			}
			// Refuse a path which disappeared, became a link or was replaced
			// while the handle was open. Some filesystems report inode zero.
			clearstatcache( true, $absolute );
			if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
				return null;
			}
			if ( 0 !== $stat['ino'] && ( new \SplFileInfo( $absolute ) )->getInode() !== $stat['ino'] ) {
				return null;
			}
			return $content;
		} catch ( Throwable $error ) {
			return null;
		}
	}

	/**
	 * Verify cleanup of a failed exclusive file creation.
	 *
	 * @param \WP_Filesystem_Base $filesystem Initialized direct filesystem backend.
	 * @param string              $absolute     Previously validated file path.
	 * @param array|null          $created_stat Identity captured from the exclusive handle.
	 * @param \SplFileObject|null $handle       Original exclusive handle, closed after removal.
	 * @throws \RuntimeException When cleanup cannot be independently verified.
	 */
	private function remove_failed_create( \WP_Filesystem_Base $filesystem, string $absolute, ?array $created_stat, ?\SplFileObject &$handle ): void {
		clearstatcache( true, $absolute );
		if ( ! file_exists( $absolute ) && ! is_link( $absolute ) ) {
			return;
		}
		if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
			throw new RuntimeException( 'Failed create target is no longer a regular file; manual recovery required.' );
		}
		if ( ! LocalFileIdentity::matches( $absolute, $created_stat ) ) {
			throw new RuntimeException( 'Failed create target identity changed; manual recovery required.' );
		}

		try {
			$removed = $filesystem->delete( $absolute, false, 'f' );
		} catch ( Throwable $error ) {
			$removed = false;
		}
		// Windows may defer deletion until the original handle is closed.
		$handle = null;
		// A backend can return false after deletion, or true without deleting.
		// The independent absence check determines the actual recovery outcome.
		clearstatcache( true, $absolute );
		if ( file_exists( $absolute ) || is_link( $absolute ) ) {
			if ( ! $removed ) {
				throw new RuntimeException( 'New file cleanup failed; manual recovery required.' );
			}
			throw new RuntimeException( 'New file cleanup readback failed; manual recovery required.' );
		}
	}

	/**
	 * Delete text for the Filesystem adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function delete_text( array $payload, array $context ): array {
		$this->assert_lifecycle_permission( $payload, $context );
		$relative = FilesystemPolicy::assertLifecycleText( (string) ( $payload['path'] ?? '' ) );
		$absolute = $this->resolve_existing( $relative, false );
		if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
			throw new RuntimeException( 'Text deletion requires a regular non-symlink file.' );
		}
		$before = $this->read_bounded_local_text( $absolute );
		if ( ! is_string( $before ) || strlen( $before ) > self::MAX_TEXT_BYTES || ! $this->is_utf8( $before ) ) {
			throw new RuntimeException( 'Existing file is unreadable or exceeds safe text limit.' );
		}
		$this->assert_no_embedded_secrets( $before );
		$before_state = $this->state( $relative, $before, $absolute );
		$result       = array(
			'before'                                  => $before_state,
			'after'                                   => array(
				'path'   => $relative,
				'exists' => false,
			),
			'_current_fingerprint'                    => Fingerprint::make( $before_state ),
			'requires_active_content_acknowledgement' => FilesystemPolicy::affectsBrowserExecution( $relative ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$expected = $payload['expected_sha256'] ?? null;
		if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}\z/', $expected ) ||
			! hash_equals( $before_state['sha256'], $expected ) ) {
			throw new RuntimeException( 'Delete refused: exact current file SHA-256 is required.' );
		}
		$filesystem = $this->filesystem();
		clearstatcache( true, $absolute );
		$current = $this->read_bounded_local_text( $absolute );
		if ( ! is_string( $current ) || strlen( $current ) > self::MAX_TEXT_BYTES ||
			! hash_equals( $expected, hash( 'sha256', $current ) ) ) {
			throw new RuntimeException( 'Delete target changed before filesystem action.' );
		}
		if ( ! $filesystem->delete( $absolute, false, 'f' ) ) {
			throw new RuntimeException( 'WordPress Filesystem refused deletion; check permissions and security policies.' );
		}
		clearstatcache( true, $absolute );
		if ( file_exists( $absolute ) || is_link( $absolute ) ) {
			throw new RuntimeException( 'File still exists after attempted deletion.' );
		}
		$result['_rollback'] = array(
			'action'  => 'filesystem.create_text',
			'payload' => array(
				'path'                       => $relative,
				'content'                    => $before,
				'acknowledge_active_content' => FilesystemPolicy::affectsBrowserExecution( $relative ),
			),
		);
		return $result;
	}

	/**
	 * Ensure lifecycle permission for the Filesystem adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_lifecycle_permission( array $payload, array $context ): void {
		if ( array_diff( array_keys( $payload ), array( 'path', 'content', 'expected_sha256', 'acknowledge_active_content' ) ) ) {
			throw new RuntimeException( 'Filesystem lifecycle request contains unsupported fields.' );
		}
		if ( empty( $context['dry_run'] ) && ! Policy::flag( 'WPCONNECTOR_ALLOW_FILESYSTEM_WRITES' ) ) {
			throw new RuntimeException( 'Filesystem writes are disabled by server policy.' );
		}
		$relative = FilesystemPolicy::assertLifecycleText( (string) ( $payload['path'] ?? '' ) );
		if ( empty( $context['dry_run'] ) && FilesystemPolicy::affectsBrowserExecution( $relative ) &&
			( $payload['acknowledge_active_content'] ?? null ) !== true ) {
			throw new RuntimeException( 'Browser-active HTML, JavaScript and CSS assets require acknowledge_active_content=true and normal confirmed change approval.' );
		}
		$capability = 0 === strpos( $relative, 'wp-content/plugins/' ) ? 'edit_plugins' : 'edit_themes';
		if ( ! current_user_can( $capability ) ) {
			throw new RuntimeException( 'WordPress denied capability ' . esc_html( $capability ) . ' (file editor or server policy may be disabled).' );
		}
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'connector_filesystem' ) ) {
			throw new RuntimeException( 'WordPress or a policy plugin disallows file modifications; check DISALLOW_FILE_MODS and file_mod_allowed.' );
		}
	}

	/**
	 * Resolve new text path for the Filesystem adapter.
	 *
	 * @param string $relative Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function resolve_new_text_path( string $relative ): string {
		$parent      = dirname( $relative );
		$parent_path = $this->resolve_existing( $parent, true );
		if ( ! is_dir( $parent_path ) ) {
			throw new RuntimeException( 'Parent directory must already exist.' );
		}
		$candidate = $parent_path . DIRECTORY_SEPARATOR . basename( $relative );
		if ( file_exists( $candidate ) || is_link( $candidate ) ) {
			throw new RuntimeException( 'File already exists; use guarded filesystem.write_text.' );
		}
		return $candidate;
	}

	/**
	 * Process the filesystem operation in the Filesystem adapter.
	 *
	 * @return \WP_Filesystem_Base Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function filesystem(): \WP_Filesystem_Base {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$method = (string) get_filesystem_method( array(), ABSPATH );
		if ( 'direct' !== $method ) {
			throw new RuntimeException( 'Automated filesystem writes require WordPress direct filesystem mode; interactive FTP/SSH credentials are never accepted through GitHub.' );
		}
		if ( ! WP_Filesystem( false, ABSPATH ) ) {
			throw new RuntimeException( 'WordPress Filesystem API could not initialize without interactive filesystem credentials.' );
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			throw new RuntimeException( 'WordPress Filesystem API is unavailable.' );
		}
		return $wp_filesystem;
	}

	/**
	 * Resolve existing for the Filesystem adapter.
	 *
	 * @param string $relative Input for the operation.
	 * @param bool   $directory_allowed Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function resolve_existing( string $relative, bool $directory_allowed ): string {
		$root = realpath( ABSPATH );
		if ( false === $root ) {
			throw new RuntimeException( 'WordPress root could not be resolved.' );
		}
		$root      = rtrim( $root, DIRECTORY_SEPARATOR );
		$candidate = '.' === $relative ? $root : $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		$real      = realpath( $candidate );
		if ( false === $real ) {
			throw new RuntimeException( 'Filesystem path does not exist.' );
		}
		if ( $real !== $root && 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'Filesystem path escapes the WordPress root.' );
		}
		$this->assert_no_symlink_components( $candidate, $root );
		if ( ! $directory_allowed && is_dir( $real ) ) {
			throw new RuntimeException( 'A file path is required.' );
		}
		return $real;
	}

	/**
	 * Ensure no symlink components for the Filesystem adapter.
	 *
	 * @param string $candidate Input for the operation.
	 * @param string $root Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_no_symlink_components( string $candidate, string $root ): void {
		if ( $candidate === $root ) {
			return;
		}
		$relative = ltrim( substr( $candidate, strlen( $root ) ), DIRECTORY_SEPARATOR );
		$cursor   = $root;
		foreach ( explode( DIRECTORY_SEPARATOR, $relative ) as $segment ) {
			$cursor .= DIRECTORY_SEPARATOR . $segment;
			if ( is_link( $cursor ) ) {
				throw new RuntimeException( 'Symlink filesystem paths are not exposed through the connector.' );
			}
		}
	}

	/**
	 * Process the state operation in the Filesystem adapter.
	 *
	 * @param string $relative Input for the operation.
	 * @param string $content Input for the operation.
	 * @param string $absolute Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function state( string $relative, string $content, string $absolute ): array {
		return array(
			'path'                => $relative,
			'bytes'               => strlen( $content ),
			'sha256'              => hash( 'sha256', $content ),
			'modified_gmt'        => $this->modified_gmt( $absolute ),
			'writable_text_scope' => FilesystemPolicy::isWritableText( $relative ),
		);
	}

	/**
	 * Process the modified gmt operation in the Filesystem adapter.
	 *
	 * @param string $absolute Input for the operation.
	 *
	 * @return string|null Operation result.
	 */
	private function modified_gmt( string $absolute ): ?string {
		try {
			$modified = ( new \SplFileInfo( $absolute ) )->getMTime();
		} catch ( RuntimeException $error ) {
			return null;
		}
		return false === $modified ? null : gmdate( 'c', (int) $modified );
	}

	/**
	 * Validate text for the Filesystem adapter.
	 *
	 * @param string $relative Input for the operation.
	 * @param string $content Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function validate_text( string $relative, string $content ): void {
		$extension = strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) );
		if ( 'php' === $extension || 'inc' === $extension ) {
			try {
				token_get_all( $content, TOKEN_PARSE );
			} catch ( ParseError $error ) {
				throw new RuntimeException( 'Replacement PHP failed parser validation.' );
			}
		}
		if ( 'json' === $extension ) {
			try {
				json_decode( $content, true, 512, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $error ) {
				throw new RuntimeException( 'Replacement JSON failed validation.' );
			}
		}
	}

	/**
	 * Ensure no embedded secrets for the Filesystem adapter.
	 *
	 * @param string $content Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function assert_no_embedded_secrets( string $content ): void {
		$patterns = array(
			'/[\"\'](?:password|passwd|secret|token|api[_-]?key|client[_-]?secret|private[_-]?key|authorization)[\"\']\s*(?:=>|:)\s*[\"\']([^\"\']{8,})[\"\']/i',
			'/\$(?:password|passwd|secret|token|api[_-]?key|client[_-]?secret|private[_-]?key)\s*=\s*[\"\']([^\"\']{8,})[\"\']/i',
			'/define\(\s*[\"\'][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY)[A-Z0-9_]*[\"\']\s*,\s*[\"\']([^\"\']{8,})[\"\']/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $content, $matches ) ) {
				$value = strtolower( trim( (string) ( $matches[1] ?? '' ) ) );
				if ( ! preg_match( '/^(your|example|sample|dummy|changeme|replace|placeholder|test|xxxx|none|null)/', $value ) ) {
					throw new RuntimeException( 'File appears to contain an embedded credential or secret literal and cannot be exported or rewritten through the connector.' );
				}
			}
		}
	}

	/**
	 * Check whether utf8 for the Filesystem adapter.
	 *
	 * @param string $value Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function is_utf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}

	/**
	 * Process the file manager root mode operation in the Filesystem adapter.
	 *
	 * @param string $configured_path Input for the operation.
	 *
	 * @return string Operation result.
	 */
	private function file_manager_root_mode( string $configured_path ): string {
		if ( '' === trim( $configured_path ) ) {
			return 'wordpress_root';
		}
		$root       = realpath( ABSPATH );
		$configured = realpath( $configured_path );
		if ( false === $root || false === $configured ) {
			return 'custom_unresolved_ignored';
		}
		$root = rtrim( $root, DIRECTORY_SEPARATOR );
		if ( $configured === $root || 0 === strpos( $configured, $root . DIRECTORY_SEPARATOR ) ) {
			return 'custom_within_wordpress_root';
		}
		return 'outside_wordpress_root_ignored';
	}
}
