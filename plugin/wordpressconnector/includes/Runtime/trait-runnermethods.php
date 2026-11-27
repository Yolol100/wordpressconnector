<?php
/**
 * Canonical public method implementations for Runner.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Runtime;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Security\ReleaseApproval;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait RunnerMethods {
	/**
	 * Private administrator-only recovery inspection. No recovery write or
	 * retry is possible here; results are observations, not execution proof.
	 *
	 * @param array $payload Validated action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @throws \RuntimeException When the requested operation cannot be completed safely.
	 */
	public function mutation_status( array $payload, array $context ): array {
		// Registry supplies execution context; this read does not consume it.
		unset( $context );

		if ( array_keys( $payload ) !== array( 'request_id' )
		|| ! is_string( $payload['request_id'] ?? null )
		|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $payload['request_id'] ) ) {
			throw new RuntimeException( 'Mutation status requires only a valid request_id.' );
		}
		$request_id     = $payload['request_id'];
		$record         = $this->processed->get( $request_id );
		$state          = 'not_verified';
		$outcome        = 'unknown';
		$action         = null;
		$created_at_gmt = null;

		if ( is_array( $record ) ) {
			$action    = isset( $record['action'] ) && is_string( $record['action'] )
			&& 1 === preg_match( '/^[a-z0-9][a-z0-9._-]*\z/', $record['action'] )
				? $record['action'] : null;
			$timestamp = $record['created_at'] ?? null;
			if ( is_int( $timestamp ) && $timestamp > 0 && $timestamp <= time() + 60 ) {
				$created_at_gmt = gmdate( 'c', $timestamp );
			}

			if ( ( $record['state'] ?? null ) === 'pending' ) {
				$state = 'pending';
			} elseif ( ( $record['state'] ?? null ) === 'completed'
			&& is_bool( $record['ok'] ?? null )
			&& is_string( $record['result_hash'] ?? null )
			&& '' !== $record['result_hash'] ) {
				$state   = 'completed';
				$outcome = $record['ok'] ? 'succeeded' : 'failed';
			} elseif ( ! array_key_exists( 'state', $record )
			&& is_bool( $record['ok'] ?? null ) ) {
				$state = 'legacy_terminal_unverified';
			} else {
				$state = 'unverifiable';
			}
		}

		// This is deliberately a boolean inventory observation, not
		// the rollback payload or a promise that rollback can succeed.
		return array(
			'request_id'                     => $request_id,
			'record_state'                   => $state,
			'recorded_action'                => $action,
			'recorded_at_gmt'                => $created_at_gmt,
			'outcome'                        => $outcome,
			'rollback_snapshot_recorded'     => null !== $this->snapshots->getRecord( $request_id ),
			'mutation_lock'                  => $this->processed->mutationLockStatus( $request_id ),
			'manual_reconciliation_required' => 'completed' !== $state,
			'safe_to_retry_automatically'    => false,
			'guidance'                       => 'Verify the exact site state and original request receipt before any new write or rollback. This inspection makes no changes.',
		);
	}
}
