<?php
/**
 * Canonical public method implementations for CodeSnippetsAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait CodeSnippetsAdapterActions {
	/**
	 * Process the catalog only ensure operation in the CodeSnippets adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function catalog_only_ensure( array $payload, array $context ): array {
		if ( $payload ) {
			throw new RuntimeException( 'Preset creation never accepts PHP or caller configuration.' );
		}
		$this->catalog_provider();
		$code     = $this->catalog_code();
		$existing = $this->catalog_find();
		$this->catalog_guard( $existing, $code );
		$before = self::catalog_state( $existing );
		$result = array(
			'before'               => $before,
			'source_sha256'        => hash( 'sha256', $code ),
			'would_create'         => null === $existing,
			'would_activate'       => null === $existing || ! $before['active'],
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) || ( $existing && $before['active'] ) ) {
			if ( $existing && $before['active'] ) {
				$result['snippet']           = $before;
				$result['readback_verified'] = true;
			}
			return $result;
		}

		$created = null === $existing;
		$id      = $created ? 0 : (int) $existing->id;
		if ( $created ) {
			// Only source shipped in the verified connector ZIP reaches this provider API.
			$snippet = new \Code_Snippets\Model\Snippet(
				array(
					'id'       => 0,
					'name'     => self::CATALOG_NAME,
					'desc'     => 'Existing WooCommerce prices visible, cart and checkout disabled.',
					'code'     => $code,
					'scope'    => 'global',
					'priority' => 10,
					'active'   => false,
					'network'  => false,
				)
			);
			$saved   = \Code_Snippets\save_snippet( $snippet );
			if ( ! is_object( $saved ) || (int) ( $saved->id ?? 0 ) <= 0 ) {
				throw new RuntimeException( 'Code Snippets provider could not save the audited preset.' );
			}
			$id = (int) $saved->id;
		}
		try {
			$new = $this->get_snippet( $id );
			$this->catalog_guard( $new, $code );
			if ( empty( $new->active ) ) {
				$activated = \Code_Snippets\activate_snippet( $id, false );
				if ( ! is_object( $activated ) ) {
					throw new RuntimeException( 'Code Snippets provider could not activate the preset.' );
				}
			}
			$after = $this->get_snippet( $id );
			$this->catalog_guard( $after, $code );
			if ( empty( $after->active ) ) {
				throw new RuntimeException( 'Catalogue snippet did not activate on WordPress readback.' );
			}
		} catch ( \Throwable $error ) {
			if ( $created ) {
				if ( ! \Code_Snippets\delete_snippet( $id, false ) ) {
					throw new RuntimeException( 'Failed preset activation; manually remove new inactive snippet.' );
				}
			} else {
				\Code_Snippets\deactivate_snippet( $id, false );
			}
			throw new RuntimeException( 'Catalogue preset activation failed; prior state restored.' );
		}
		$result['snippet']           = self::catalog_state( $after );
		$result['readback_verified'] = true;
		$result['_rollback']         = array(
			'action'  => 'code_snippets.catalog_only.undo',
			'payload' => array(
				'snippet_id'           => $id,
				'expected_code_sha256' => hash( 'sha256', $code ),
				'created'              => $created,
			),
		);
		return $result;
	}

	/**
	 * Process the catalog only undo operation in the CodeSnippets adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function catalog_only_undo( array $payload, array $context ): array {
		if ( array_keys( $payload ) !== array( 'snippet_id', 'expected_code_sha256', 'created' ) ||
			! is_int( $payload['snippet_id'] ) || $payload['snippet_id'] <= 0 ||
			! is_string( $payload['expected_code_sha256'] ) || ! is_bool( $payload['created'] ) ) {
			throw new RuntimeException( 'Invalid internal catalogue rollback request.' );
		}
		$this->catalog_provider();
		$code = $this->catalog_code();
		if ( ! hash_equals( hash( 'sha256', $code ), $payload['expected_code_sha256'] ) ) {
			throw new RuntimeException( 'Stored catalogue rollback does not match vetted preset.' );
		}
		$snippet = $this->catalog_find();
		$this->catalog_guard( $snippet, $code );
		if ( ! $snippet || (int) $snippet->id !== $payload['snippet_id'] || empty( $snippet->active ) ) {
			throw new RuntimeException( 'Catalogue snippet changed since creation; rollback refused.' );
		}
		$state = self::catalog_state( $snippet );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_undo'           => $state,
				'_current_fingerprint' => Fingerprint::make( $state ),
			);
		}
		$ok = $payload['created']
			? \Code_Snippets\delete_snippet( (int) $snippet->id, false )
			: (bool) \Code_Snippets\deactivate_snippet( (int) $snippet->id, false );
		if ( ! $ok ) {
			throw new RuntimeException( 'Code Snippets rejected managed preset rollback.' );
		}
		$after = $this->catalog_find();
		if ( $payload['created'] ? null !== $after : ( null === $after || ! empty( $after->active ) ) ) {
			throw new RuntimeException( 'Managed catalogue rollback readback failed.' );
		}
		return array(
			'undone'            => true,
			'readback_verified' => true,
		);
	}

	/**
	 * Restore code for the CodeSnippets adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function restore_code( array $payload, array $context ): array {
		$this->assert_available();

		$snippet_id = Input::positive_id( $payload['snippet_id'] ?? null );
		$code       = isset( $payload['code'] ) && is_string( $payload['code'] ) ? $payload['code'] : null;
		$expected   = isset( $payload['expected_code_fingerprint'] ) && is_string( $payload['expected_code_fingerprint'] ) ? strtolower( $payload['expected_code_fingerprint'] ) : '';

		if ( null === $code || strlen( $code ) > self::MAX_CODE_BYTES ) {
			throw new RuntimeException( 'Code Snippets rollback payload is invalid.' );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}\z/', $expected ) ) {
			throw new RuntimeException( 'Code Snippets rollback requires a valid expected_code_fingerprint.' );
		}

		$before = $this->snapshot( $this->get_snippet( $snippet_id ) );
		if ( ! empty( $before['locked'] ) ) {
			throw new RuntimeException( 'Code Snippets rollback target is locked.' );
		}
		if ( ! hash_equals( $expected, hash( 'sha256', (string) $before['code'] ) ) ) {
			throw new RuntimeException( 'Stale target: Code Snippets rollback fingerprint does not match current code.' );
		}

		$result = array(
			'snippet'                 => $this->public_snapshot( $before ),
			'before_code_fingerprint' => hash( 'sha256', (string) $before['code'] ),
			'after_code_fingerprint'  => hash( 'sha256', $code ),
			'_current_fingerprint'    => Fingerprint::make( $before ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			$result['readback_verified'] = null;
			return $result;
		}

		$this->write_code( $snippet_id, $code );
		$after = $this->snapshot( $this->get_snippet( $snippet_id ) );

		if ( ! $this->same_metadata( $before, $after ) || $code !== (string) $after['code'] ) {
			throw new RuntimeException( 'Code Snippets rollback readback verification failed.' );
		}

		$result['snippet']           = $this->public_snapshot( $after );
		$result['readback_verified'] = true;
		return $result;
	}

	/**
	 * Process the catalog only inspect operation in the CodeSnippets adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function catalog_only_inspect( array $payload, array $context = array() ): array {
		// Registry supplies execution context; this read does not consume it.
		unset( $context );

		if ( $payload ) {
			throw new RuntimeException( 'Catalogue preset inspection accepts no payload fields.' );
		}
		$this->catalog_provider();
		$code    = $this->catalog_code();
		$snippet = $this->catalog_find();
		$this->catalog_guard( $snippet, $code );
		$state = self::catalog_state( $snippet );
		return array(
			'snippet'              => $state,
			'source_sha256'        => hash( 'sha256', $code ),
			'fingerprint'          => Fingerprint::make( $state ),
			'_current_fingerprint' => Fingerprint::make( $state ),
		);
	}
}
