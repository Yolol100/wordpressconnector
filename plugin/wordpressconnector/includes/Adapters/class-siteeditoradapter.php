<?php
/**
 * Registers and handles Site Editor connector actions.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

require_once __DIR__ . '/trait-siteeditoractions.php';

/**
 * Native WordPress Site Editor support. Never interpret arbitrary REST
 * paths from client input: only Core's two template controllers are used.
 */
final class SiteEditorAdapter {
	use SiteEditorActions {
		list_templates as listTemplates;
		global_styles as globalStyles;
	}

	private const MAX_CONTENT_BYTES = 65536;

	/**
	 * Register the SiteEditor adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$read  = array(
			'privileged' => true,
			'capability' => 'edit_theme_options',
		);
		$write = $read + array( 'mutation' => true );
		$registry->register(
			'site_editor.template.list',
			array( $this, 'list_templates' ),
			$read + array( 'description' => 'List active-theme Site Editor templates or template parts via core REST permissions.' )
		);
		$registry->register(
			'site_editor.template.inspect',
			array( $this, 'inspect' ),
			$read + array( 'description' => 'Inspect one exact active-theme template or part, with source and fingerprint.' )
		);
		$registry->register(
			'site_editor.template.update',
			array( $this, 'update' ),
			$write + array( 'description' => 'Guarded update of a previously customized Site Editor template or part using core REST validation and exact rollback.' )
		);
		$registry->register(
			'site_editor.template.restore',
			array( $this, 'restore' ),
			$write + array( 'description' => 'Rollback-only restore for an exact previously customized Site Editor template/part.' )
		);
		$registry->register(
			'site_editor.global_styles.inspect',
			array( $this, 'global_styles' ),
			$read + array( 'description' => 'Read the current block theme global style configuration via core REST without changing it.' )
		);
	}

	/**
	 * Process the inspect operation in the SiteEditor adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Operation result.
	 */
	public function inspect( array $payload ): array {
		$this->keys( $payload, array( 'kind', 'id' ) );
		$kind   = $this->kind( $payload );
		$id     = $this->id( $payload );
		$record = $this->record( $kind, $id );
		$state  = $this->state( $kind, $id, $record );
		return $state + array( 'content' => $this->raw_content( $record ) );
	}

	/**
	 * Process the update operation in the SiteEditor adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function update( array $payload, array $context ): array {
		$this->keys( $payload, array( 'kind', 'id', 'content', 'expected_before_fingerprint' ) );
		$kind    = $this->kind( $payload );
		$id      = $this->id( $payload );
		$content = $this->content( $payload );
		$before  = $this->state( $kind, $id, $this->record( $kind, $id ) );
		$this->writable( $before );
		$this->expected( $payload['expected_before_fingerprint'] ?? null, $before['fingerprint'] );

		$result = array(
			'kind'                 => $kind,
			'id'                   => $id,
			'theme'                => get_stylesheet(),
			'would_change'         => hash( 'sha256', $content ) !== $before['content_sha256'],
			'before'               => array(
				'content_sha256' => $before['content_sha256'],
				'modified'       => $before['modified'],
			),
			'_current_fingerprint' => $before['fingerprint'],
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$this->write_allowed();
		// Use Core's exact template route, validation, sanitization and
		// permission callback. Do not update wp_template database rows directly.
		$current = $this->record( $kind, $id );
		$fresh   = $this->state( $kind, $id, $current );
		if ( ! hash_equals( $before['fingerprint'], $fresh['fingerprint'] ) ) {
			throw new RuntimeException( 'Site Editor template changed immediately before the write.' );
		}
		$previous = $this->content( array( 'content' => $this->raw_content( $current ) ) );
		try {
			$this->request( 'POST', $this->route( $kind ) . '/' . $id, array( 'content' => $content ) );
			$after = $this->state( $kind, $id, $this->record( $kind, $id ) );
			if ( hash( 'sha256', $content ) !== $after['content_sha256'] ) {
				throw new RuntimeException( 'WordPress did not retain the requested template content.' );
			}
		} catch ( \Throwable $failure ) {
			$this->restore_after_failure( $kind, $id, $previous );
			throw new RuntimeException( 'Template update was not confirmed; original content restored and verified.' );
		}
		$result['updated']   = true;
		$result['after']     = array(
			'content_sha256' => $after['content_sha256'],
			'modified'       => $after['modified'],
		);
		$result['_rollback'] = array(
			'action'  => 'site_editor.template.restore',
			'payload' => array(
				'kind'                       => $kind,
				'id'                         => $id,
				'content'                    => $previous,
				'expected_after_fingerprint' => $after['fingerprint'],
			),
		);
		return $result;
	}

	/**
	 * Process the restore operation in the SiteEditor adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function restore( array $payload, array $context ): array {
		$this->keys( $payload, array( 'kind', 'id', 'content', 'expected_after_fingerprint' ) );
		if ( empty( $context['rollback_mode'] ) ) {
			throw new RuntimeException( 'Site Editor restore is available only to a stored connector rollback.' );
		}
		$kind    = $this->kind( $payload );
		$id      = $this->id( $payload );
		$content = $this->content( $payload );
		$before  = $this->state( $kind, $id, $this->record( $kind, $id ) );
		$this->writable( $before );
		$this->expected( $payload['expected_after_fingerprint'] ?? null, $before['fingerprint'] );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_restore'        => $id,
				'_current_fingerprint' => $before['fingerprint'],
			);
		}
		$this->write_allowed();
		$this->request( 'POST', $this->route( $kind ) . '/' . $id, array( 'content' => $content ) );
		$after = $this->state( $kind, $id, $this->record( $kind, $id ) );
		if ( hash( 'sha256', $content ) !== $after['content_sha256'] ) {
			throw new RuntimeException( 'Site Editor rollback readback failed.' );
		}
		return array(
			'restored'          => $id,
			'readback_verified' => true,
		);
	}

	/**
	 * Restore after failure for the SiteEditor adapter.
	 *
	 * @param string $kind Input for the operation.
	 * @param string $id Input for the operation.
	 * @param string $previous Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function restore_after_failure( string $kind, string $id, string $previous ): void {
		try {
			$this->request( 'POST', $this->route( $kind ) . '/' . $id, array( 'content' => $previous ) );
			$after = $this->record( $kind, $id );
			if ( hash( 'sha256', $this->raw_content( $after ) ) !== hash( 'sha256', $previous ) ) {
				throw new RuntimeException( 'Content mismatch after restoration.' );
			}
		} catch ( \Throwable $error ) {
			throw new RuntimeException( 'Template update and recovery failed; manual staging restore required.' );
		}
	}

	/**
	 * Process the state operation in the SiteEditor adapter.
	 *
	 * @param string $kind Input for the operation.
	 * @param string $id Input for the operation.
	 * @param array  $record Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function state( string $kind, string $id, array $record ): array {
		if ( ( $record['id'] ?? null ) !== $id || ( $record['theme'] ?? null ) !== get_stylesheet() ) {
			throw new RuntimeException( 'WordPress Site Editor returned an unexpected theme/template identity.' );
		}
		$content              = $this->raw_content( $record );
		$state                = array(
			'kind'           => $kind,
			'id'             => $id,
			'theme'          => get_stylesheet(),
			'source'         => (string) ( $record['source'] ?? '' ),
			'wp_id'          => (int) ( $record['wp_id'] ?? 0 ),
			'modified'       => (string) ( $record['modified'] ?? '' ),
			'content_sha256' => hash( 'sha256', $content ),
			'customized'     => 'custom' === ( $record['source'] ?? '' ) && (int) ( $record['wp_id'] ?? 0 ) > 0,
		);
		$state['fingerprint'] = Fingerprint::make( $state );
		return $state;
	}

	/**
	 * Process the writable operation in the SiteEditor adapter.
	 *
	 * @param array $state Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function writable( array $state ): void {
		if ( ! $state['customized'] || $state['wp_id'] <= 0
		|| ! current_user_can( 'edit_post', $state['wp_id'] ) ) {
			throw new RuntimeException( 'Only previously customized, editable Site Editor templates can be modified.' );
		}
	}

	/**
	 * Write allowed for the SiteEditor adapter.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function write_allowed(): void {
		if ( Policy::publicRepositoryContext() || ! Policy::flag( 'WPCONNECTOR_ALLOW_SITE_EDITOR_WRITES' ) ) {
			throw new RuntimeException( 'Site Editor writes require explicitly enabled private server policy.' );
		}
	}

	/**
	 * Process the expected operation in the SiteEditor adapter.
	 *
	 * @param mixed  $value Input for the operation.
	 * @param string $actual Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function expected( $value, string $actual ): void {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}\z/', $value )
		|| ! hash_equals( $actual, $value ) ) {
			throw new RuntimeException( 'Template content or metadata changed since the exact dry-run fingerprint.' );
		}
	}

	/**
	 * Process the record operation in the SiteEditor adapter.
	 *
	 * @param string $kind Input for the operation.
	 * @param string $id Input for the operation.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function record( string $kind, string $id ): array {
		$data = $this->data(
			$this->request(
				'GET',
				$this->route( $kind ) . '/' . $id,
				array( 'context' => 'edit' )
			)
		);
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'WordPress returned an invalid template.' );
		}
		return $data;
	}

	/**
	 * Process the request operation in the SiteEditor adapter.
	 *
	 * @param string $method Input for the operation.
	 * @param string $path Input for the operation.
	 * @param array  $params Input for the operation.
	 *
	 * @return mixed Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function request( string $method, string $path, array $params = array() ) {
		if ( ! function_exists( 'rest_do_request' ) || ! class_exists( '\WP_REST_Request' ) ) {
			throw new RuntimeException( 'The WordPress REST API is not initialized.' );
		}
		$request = new \WP_REST_Request( $method, $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_status' )
		|| $response->get_status() < 200 || $response->get_status() >= 300 ) {
			throw new RuntimeException( 'Native WordPress Site Editor API denied or could not complete the request.' );
		}
		return $response;
	}

	/**
	 * Process the data operation in the SiteEditor adapter.
	 *
	 * @param mixed $response Input for the operation.
	 *
	 * @return mixed Operation result.
	 */
	private function data( $response ) {
		return $response->get_data();
	}

	/**
	 * Process the raw content operation in the SiteEditor adapter.
	 *
	 * @param array $record Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function raw_content( array $record ): string {
		$value = $record['content'] ?? null;
		$raw   = is_array( $value ) ? ( $value['raw'] ?? null ) : $value;
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_CONTENT_BYTES ) {
			throw new RuntimeException( 'Native Site Editor template content cannot be safely represented.' );
		}
		return $raw;
	}

	/**
	 * Process the content operation in the SiteEditor adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function content( array $payload ): string {
		$content = $payload['content'] ?? null;
		if ( ! is_string( $content ) || strlen( $content ) > self::MAX_CONTENT_BYTES
		|| strpos( $content, '<?' ) !== false
		|| preg_match( '/<\s*script\b|on(?:load|error|click)\s*=/i', $content ) ) {
			throw new RuntimeException( 'Site Editor content is oversized or contains executable markup.' );
		}
		return $content;
	}

	/**
	 * Process the title operation in the SiteEditor adapter.
	 *
	 * @param mixed $value Input for the operation.
	 *
	 * @return string Operation result.
	 */
	private function title( $value ): string {
		if ( is_array( $value ) ) {
			$value = $value['raw'] ?? $value['rendered'] ?? '';
		}
		return is_string( $value ) ? substr( wp_strip_all_tags( $value ), 0, 140 ) : '';
	}

	/**
	 * Process the id operation in the SiteEditor adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function id( array $payload ): string {
		$id    = $payload['id'] ?? null;
		$theme = get_stylesheet();
		if ( ! is_string( $id ) || ! is_string( $theme )
		|| ! preg_match( '/^[A-Za-z0-9_-]{1,80}\z/', $theme )
		|| ! preg_match( '/^[A-Za-z0-9_-]{1,80}\/\/[A-Za-z0-9_-]{1,80}\z/', $id )
		|| strpos( $id, $theme . '//' ) !== 0 ) {
			throw new RuntimeException( 'Template ID must belong exactly to the active theme.' );
		}
		return $id;
	}

	/**
	 * Process the kind operation in the SiteEditor adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function kind( array $payload ): string {
		$kind = $payload['kind'] ?? null;
		if ( ! in_array( $kind, array( 'template', 'template_part' ), true ) ) {
			throw new RuntimeException( 'Site Editor kind must be template or template_part.' );
		}
		return $kind;
	}

	/**
	 * Process the route operation in the SiteEditor adapter.
	 *
	 * @param string $kind Input for the operation.
	 *
	 * @return string Operation result.
	 */
	private function route( string $kind ): string {
		return 'template' === $kind ? '/wp/v2/templates' : '/wp/v2/template-parts';
	}

	/**
	 * Process the keys operation in the SiteEditor adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $allowed Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function keys( array $payload, array $allowed ): void {
		if ( array_diff( array_keys( $payload ), $allowed ) ) {
			throw new RuntimeException( 'Site Editor payload contains unsupported fields.' );
		}
	}
}
