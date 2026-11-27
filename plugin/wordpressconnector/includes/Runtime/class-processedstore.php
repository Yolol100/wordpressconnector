<?php
/**
 * Provides Processed Store behavior for connector request processing.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Runtime;

require_once __DIR__ . '/trait-processedstoremethods.php';

/**
 * Provides Processed Store behavior for connector request processing.
 */
final class ProcessedStore {
	use ProcessedStoreMethods {
		mutation_lock_status as mutationLockStatus;
		acquire_mutation_lock as acquireMutationLock;
		release_mutation_lock as releaseMutationLock;
	}

	private const PREFIX   = 'wpconnector_processed_';
	private const LOCK_KEY = 'wpconnector_mutation_lock';
	private const LOCK_TTL = 600;

	/**
	 * Reserve the request ID durably BEFORE calling a mutating provider.
	 * add_option() relies on the WordPress options table's unique option_name,
	 * so two simultaneous requests cannot both claim the same ID.
	 *
	 * @param string $requestId Request identifier for durable execution state.
	 * @param string $fingerprint Expected request fingerprint.
	 * @param string $action Registered connector action.
	 *
	 * @throws \RuntimeException When the request reservation cannot be created.
	 */
	public function begin( string $requestId, string $fingerprint, string $action ): void {
		$key    = self::key( $requestId );
		$record = array(
			'created_at'     => time(),
			'fingerprint'    => $fingerprint,
			'action'         => $action,
			'state'          => 'pending',
			'result_hash'    => null,
			'ok'             => null,
			'terminal_error' => null,
		);
		if ( ! add_option( $key, $record, '', false ) || get_option( $key, null ) !== $record ) {
			throw new \RuntimeException( 'Mutation request could not be reserved. No mutation was started.' );
		}
	}

	/**
	 * Process the put operation in the ProcessedStore component.
	 *
	 * @param string      $requestId Input for the operation.
	 * @param string      $fingerprint Input for the operation.
	 * @param string      $action Input for the operation.
	 * @param string      $resultHash Input for the operation.
	 * @param bool        $ok Input for the operation.
	 * @param string|null $terminalError Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function put( string $requestId, string $fingerprint, string $action, string $resultHash, bool $ok = true, ?string $terminalError = null ): void {
		$key      = self::key( $requestId );
		$previous = $this->get( $requestId );
		if ( ! is_array( $previous )
		|| ( $previous['state'] ?? null ) !== 'pending'
		|| ! is_string( $previous['fingerprint'] ?? null )
		|| ! hash_equals( $previous['fingerprint'], $fingerprint )
		|| ( $previous['action'] ?? null ) !== $action ) {
			throw new \RuntimeException( 'Mutation result cannot be finalized without its original pending reservation.' );
		}
		$record = array(
			'created_at'     => (int) $previous['created_at'],
			'fingerprint'    => $fingerprint,
			'action'         => $action,
			'state'          => 'completed',
			'result_hash'    => $resultHash,
			'ok'             => $ok,
			'terminal_error' => $ok ? null : $this->bounded_terminal_error( $terminalError ),
		);
		update_option( $key, $record, false );
		if ( get_option( $key, null ) !== $record ) {
			// Retain the pending reservation: repeating this request could
			// execute a successful mutation twice after a database error.
			throw new \RuntimeException( 'Mutation result did not persist; execution outcome is uncertain. Do not retry automatically.' );
		}
	}

	/**
	 * Process the get operation in the ProcessedStore component.
	 *
	 * @param string $requestId Input for the operation.
	 *
	 * @return array|null Operation result.
	 */
	public function get( string $requestId ): ?array {
		$value = get_option( self::key( $requestId ), null );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Process the cleanup operation in the ProcessedStore component.
	 *
	 * @param int $olderThanSeconds Input for the operation.
	 *
	 * @return int Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function cleanup( int $olderThanSeconds ): int {
		global $wpdb;
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared cleanup scan must see latest idempotency reservations; caching is unsafe.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		if ( ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Mutation cleanup could not inspect WordPress options storage.' );
		}
		$deleted = 0;
		foreach ( $rows as $row ) {
			$name = isset( $row->option_name ) && is_string( $row->option_name ) ? $row->option_name : '';
			if ( 1 !== preg_match( '/^wpconnector_processed_[a-f0-9]{64}\z/', $name ) ) {
				continue;
			}
			$value = maybe_unserialize( $row->option_value );
			if ( ! is_array( $value ) || ! is_int( $value['created_at'] ?? null )
			|| $value['created_at'] >= time() - $olderThanSeconds ) {
				continue;
			}
			// Only proven terminal states may expire. An unknown or malformed
			// record is a replay barrier, not safe garbage to discard.
			$completed = ( $value['state'] ?? null ) === 'completed'
			|| ( ! array_key_exists( 'state', $value ) && is_bool( $value['ok'] ?? null ) );
			if ( ! $completed
			|| ! is_bool( $value['ok'] ?? null )
			|| ! is_string( $value['result_hash'] ?? null )
			|| '' === $value['result_hash'] ) {
				continue;
			}
			if ( delete_option( (string) $row->option_name ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Process the compare and swap lock operation in the ProcessedStore component.
	 *
	 * @param string $expected Input for the operation.
	 * @param string $replacement Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function compare_and_swap_lock( string $expected, string $replacement ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-swap on the options row is necessary for the mutation lock; cache invalidated below.
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $replacement ),
			array(
				'option_name'  => self::LOCK_KEY,
				'option_value' => $expected,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( 1 !== $updated ) {
			return false;
		}

		wp_cache_delete( self::LOCK_KEY, 'options' );
		return true;
	}

	/**
	 * Delete lock if value for the ProcessedStore component.
	 *
	 * @param string $expected Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function delete_lock_if_value( string $expected ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Conditional lock deletion must be atomic; the options cache is invalidated on success.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::LOCK_KEY,
				'option_value' => $expected,
			),
			array( '%s', '%s' )
		);
		if ( 1 !== $deleted ) {
			return false;
		}

		wp_cache_delete( self::LOCK_KEY, 'options' );
		return true;
	}

	/**
	 * Process the bounded terminal error operation in the ProcessedStore component.
	 *
	 * @param string|null $message Input for the operation.
	 *
	 * @return string Operation result.
	 */
	private function bounded_terminal_error( ?string $message ): string {
		$message = is_string( $message ) ? trim( $message ) : '';
		if ( '' === $message ) {
			return 'Completed mutation ended with a terminal execution failure.';
		}
		return strlen( $message ) > 500 ? substr( $message, 0, 500 ) : $message;
	}

	/**
	 * Process the key operation in the ProcessedStore component.
	 *
	 * @param string $request_id Input for the operation.
	 *
	 * @return string Operation result.
	 */
	private static function key( string $request_id ): string {
		return self::PREFIX . hash( 'sha256', $request_id );
	}
}
