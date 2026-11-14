<?php
/**
 * Provides Runner behavior for connector request processing.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Runtime;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep isolated REST/MCP/CLI and contract harness entrypoints consistent.
require_once __DIR__ . '/Diagnostics.php';

/**
 * Provides Runner behavior for connector request processing.
 */
final class Runner {
	private Registry $registry;
	private SnapshotStore $snapshots;
	private ProcessedStore $processed;

	public function __construct( Registry $registry, SnapshotStore $snapshots, ProcessedStore $processed ) {
		$this->registry = $registry;
		$this->snapshots = $snapshots;
		$this->processed = $processed;
		$this->registry->register( 'connector.actions', array( $this, 'actions' ), array( 'description' => 'List registered actions and security metadata.' ) );
		$this->registry->register( 'connector.batch', array( $this, 'batch' ), array( 'mutation' => true, 'description' => 'Execute up to 25 connector operations with compensation on failure.' ) );
		$this->registry->register( 'connector.read_batch', array( $this, 'readBatch' ), array( 'description' => 'Read up to 25 supported actions with one permission-checked request and no mutations.' ) );
		$this->registry->register( 'connector.rollback', array( $this, 'rollback' ), array( 'mutation' => true, 'privileged' => true, 'description' => 'Execute a stored rollback snapshot by request_id.' ) );
		$this->registry->register( 'connector.cleanup', array( $this, 'cleanup' ), array( 'mutation' => true, 'privileged' => true, 'description' => 'Delete expired idempotency and rollback state.' ) );
		$this->registry->register( 'connector.mutation_status', array( $this, 'mutationStatus' ), array( 'privileged' => true, 'capability' => 'manage_options', 'description' => 'Read a sanitized request outcome and lock status for private administrator reconciliation.' ) );
	}

	public function run( Request $request, array $context = array() ): array {
		$lock_token = '';
		try {
			$descriptor = $this->registry->descriptor( $request->action() );
			if ( Policy::publicRepositoryContext() ) {
				$descriptor = $this->publicDescriptor( $request->action(), $request->payload(), $descriptor, $request->dryRun(), $request->expectedFingerprint() );
			}
			Policy::assertActionAllowed( $descriptor, $request->dryRun(), $request->confirm() );
			$request_fingerprint = Fingerprint::make( array(
				'action'               => $request->action(),
				'payload'              => $request->payload(),
				'expected_fingerprint' => $request->expectedFingerprint(),
				'expected_state_token' => $request->expectedStateToken(),
			) );
			if ( ! empty( $descriptor['mutation'] ) && ! $request->dryRun() ) {
				$lock_token = $this->processed->acquireMutationLock( $request->id() );
				if ( '' === $lock_token ) {
					throw new RuntimeException( 'Another connector mutation is already in progress. Retry this request.' );
				}
				$existing = $this->processed->get( $request->id() );
				if ( $existing ) {
					if ( ! isset( $existing['fingerprint'] ) || ! hash_equals( (string) $existing['fingerprint'], $request_fingerprint ) ) {
						throw new RuntimeException( 'request_id was already used for a different mutation.' );
					}
					if ( ( $existing['state'] ?? null ) === 'pending' ) {
						throw new RuntimeException( 'Mutation request is pending or its outcome is unknown. Do not retry automatically.' );
					}
					if ( isset( $existing['state'] ) && 'completed' !== $existing['state'] ) {
						throw new RuntimeException( 'Mutation request has invalid stored state. Do not retry automatically.' );
					}
					if ( array_key_exists( 'ok', $existing ) && false === $existing['ok'] ) {
						return Result::failure(
							$request,
							isset( $existing['terminal_error'] ) && is_string( $existing['terminal_error'] )
								? $existing['terminal_error']
								: 'Completed mutation ended with a terminal execution failure.',
							array(
								'request_fingerprint'  => $request_fingerprint,
								'idempotent_replay'    => true,
								'original_result_hash' => $existing['result_hash'] ?? null,
								'terminal_mutation'    => true,
								'diagnostic'           => Diagnostics::classify( (string) ( $existing['terminal_error'] ?? '' ), true ),
							)
						);
					}
					return Result::success( $request, array( 'idempotent_replay' => true, 'original_result_hash' => $existing['result_hash'] ?? null ), array( 'request_fingerprint' => $request_fingerprint ) );
				}
				// Durable reservation precedes every provider write. If PHP
				// terminates or the result cannot be stored, future retries
				// must reconcile the original request instead of repeating it.
				$this->processed->begin( $request->id(), $request_fingerprint, $request->action() );
			}
			$context['dry_run'] = $request->dryRun();
			$context['confirm'] = $request->confirm();
			$context['request_id'] = $request->id();
			$context['registry'] = $this->registry;
			$data = $this->executeWithStateGuards( $request->action(), $request->payload(), $context, $request->expectedFingerprint(), $request->expectedStateToken() );
			if ( isset( $data['fingerprint'] ) && is_string( $data['fingerprint'] ) && preg_match( '/^[a-f0-9]{64}\z/', $data['fingerprint'] ) ) {
				$data['state_token'] = Fingerprint::siteTokenFromFingerprint( $data['fingerprint'] );
			}
			if ( isset( $data['_current_fingerprint'] ) && is_string( $data['_current_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}\z/', $data['_current_fingerprint'] ) ) {
				$data['current_state_token'] = Fingerprint::siteTokenFromFingerprint( $data['_current_fingerprint'] );
			}
			$rollback      = isset( $data['_rollback'] ) && is_array( $data['_rollback'] ) ? $data['_rollback'] : null;
			$terminal_error = isset( $data['_terminal_error'] ) && is_string( $data['_terminal_error'] )
				? trim( $data['_terminal_error'] )
				: '';
			$terminal_context = array();
			foreach ( array( 'name', 'execution_may_have_started', 'provider_error_code' ) as $key ) {
				if ( array_key_exists( $key, $data ) ) {
					$terminal_context[ $key ] = $data[ $key ];
				}
			}
			unset( $data['_rollback'], $data['_current_fingerprint'], $data['_terminal_error'] );
			if ( $rollback && ! $request->dryRun() && ! empty( $descriptor['mutation'] ) ) {
				$this->snapshots->put( $request->id(), $rollback, array(
					'source_action'          => $request->action(),
					'public_repository_mode' => Policy::publicRepositoryContext(),
				) );
				$data['rollback_request_id'] = $request->id();
			}
			if ( '' !== $terminal_error ) {
				$result = Result::failure( $request, $terminal_error, array(
					'request_fingerprint' => $request_fingerprint,
					'terminal_mutation'   => true,
					'terminal_context'    => $terminal_context,
					'diagnostic'          => Diagnostics::classify( $terminal_error, true ),
				) );
				if ( ! $request->dryRun() && ! empty( $descriptor['mutation'] ) ) {
					$this->processed->put(
						$request->id(),
						$request_fingerprint,
						$request->action(),
						Fingerprint::make( $result ),
						false,
						$terminal_error
					);
				}
				return $result;
			}

			$result = Result::success( $request, $data, array( 'request_fingerprint' => $request_fingerprint ) );
			if ( ! $request->dryRun() && ! empty( $descriptor['mutation'] ) ) {
				$this->processed->put( $request->id(), $request_fingerprint, $request->action(), Fingerprint::make( $result ), true );
			}
			return $result;
		} catch ( Throwable $error ) {
			return Result::failure( $request, $error->getMessage(), array(
				'exception'  => get_class( $error ),
				'diagnostic' => Diagnostics::classify( $error->getMessage(), isset( $descriptor ) && ! empty( $descriptor['mutation'] ) && ! $request->dryRun() ),
			) );
		} finally {
			if ( '' !== $lock_token ) {
				$this->processed->releaseMutationLock( $lock_token );
			}
		}
	}

	public function actions( array $payload, array $context ): array {
		return array( 'actions' => $this->registry->catalog() );
	}

	/**
     * Single-roundtrip fast path for independent reads. Preflight every leaf
     * before executing any to prevent a later unsafe action from partly running.
     * Mutations, sensitive exports and nested actions are never eligible.
     */
	public function readBatch( array $payload, array $context ): array {
		$operations = isset( $payload['operations'] ) && is_array( $payload['operations'] ) ? $payload['operations'] : array();
		if ( ! $operations || count( $operations ) > 25 || array_keys( $payload ) !== array( 'operations' ) ) {
			throw new RuntimeException( 'connector.read_batch requires 1-25 operations.' );
		}

		$allowed = array();
		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) || ! isset( $operation['action'] ) || ! is_string( $operation['action'] )
 || ! isset( $operation['payload'] ) || ! is_array( $operation['payload'] )
 || array_diff( array_keys( $operation ), array( 'action', 'payload' ) ) ) {
				throw new RuntimeException( 'Read batch operation at index ' . $index . ' is invalid.' );
			}
			$action = $operation['action'];
			if ( in_array( $action, array( 'connector.read_batch', 'connector.batch', 'connector.rollback', 'connector.cleanup' ), true )
 || 'wordpress.ability.execute' === $action ) {
				throw new RuntimeException( 'Nested and delegated actions are not allowed in connector.read_batch.' );
			}
			$descriptor = $this->registry->descriptor( $action );
			if ( ! empty( $descriptor['mutation'] ) || ! empty( $descriptor['sensitive'] ) || ! empty( $descriptor['system_update'] ) ) {
				throw new RuntimeException( 'Mutating, sensitive and system-update actions are not allowed in connector.read_batch.' );
			}
			if ( Policy::publicRepositoryContext() ) {
				$descriptor = $this->publicDescriptor( $action, $operation['payload'], $descriptor, true, null );
			}
			Policy::assertActionAllowed( $descriptor, true, false );
			$allowed[] = array( 'action' => $action, 'payload' => $operation['payload'] );
		}

		$results = array();
		foreach ( $allowed as $operation ) {
			$result = $this->registry->execute( $operation['action'], $operation['payload'], array_merge( $context, array(
				'dry_run' => true,
				'confirm' => false,
			) ) );
			unset( $result['_rollback'], $result['_current_fingerprint'] );
			$results[] = array( 'action' => $operation['action'], 'result' => $result );
		}
		return array( 'operations' => $results );
	}

	public function batch( array $payload, array $context ): array {
		$operations = isset( $payload['operations'] ) && is_array( $payload['operations'] ) ? $payload['operations'] : array();
		if ( ! $operations || count( $operations ) > 25 || array_keys( $payload ) !== array( 'operations' ) ) {
			throw new RuntimeException( 'connector.batch requires 1-25 operations.' );
		}

		// Validate every leaf's shape and access policy before executing the first mutation.
		// State fingerprints remain checked at execution time for sequential dependencies.
		$prepared = array();
		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) || ! isset( $operation['action'] ) || ! is_string( $operation['action'] )
 || ! array_key_exists( 'payload', $operation ) || ! is_array( $operation['payload'] )
 || array_diff( array_keys( $operation ), array( 'action', 'payload', 'expected_fingerprint', 'expected_state_token' ) ) ) {
				throw new RuntimeException( 'Batch operation at index ' . $index . ' is invalid.' );
			}
			$action = $operation['action'];
			if ( in_array( $action, array( 'connector.batch', 'connector.read_batch', 'connector.rollback', 'connector.cleanup' ), true ) ) {
				throw new RuntimeException( 'Nested batch, rollback or cleanup operations are not allowed.' );
			}
			if ( in_array( $action, array( 'wordpress.ability.execute', 'wordpress.ability.execute_approved' ), true ) ) {
				throw new RuntimeException( 'Delegated Ability mutations are not allowed inside connector.batch because they do not provide connector rollback guarantees.' );
			}
			$expected           = $operation['expected_fingerprint'] ?? null;
			$expectedStateToken = $operation['expected_state_token'] ?? null;
			foreach ( array( 'expected_fingerprint' => $expected, 'expected_state_token' => $expectedStateToken ) as $guard => $value ) {
				if ( null !== $value && ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}\\z/', $value ) ) ) {
					throw new RuntimeException( 'Invalid ' . $guard . ' at batch index ' . $index . '.' );
				}
			}
			if ( Policy::publicRepositoryContext() && empty( $context['dry_run'] ) && null === $expected ) {
				throw new RuntimeException( 'Confirmed public batch operation requires expected_fingerprint at index ' . $index . '.' );
			}
			$descriptor = $this->registry->descriptor( $action );
			if ( Policy::publicRepositoryContext() ) {
				$descriptor = $this->publicDescriptor( $action, $operation['payload'], $descriptor, ! empty( $context['dry_run'] ), $expected );
			}
			Policy::assertActionAllowed( $descriptor, ! empty( $context['dry_run'] ), ! empty( $context['confirm'] ) );
			$prepared[] = array(
				'action'               => $action,
				'payload'              => $operation['payload'],
				'expected_fingerprint' => $expected,
				'expected_state_token' => $expectedStateToken,
			);
		}

		$results   = array();
		$rollbacks = array();
		try {
			foreach ( $prepared as $operation ) {
				$action = $operation['action'];
				// Recheck authorization at execution time: a preceding operation
				// may change the current user's capabilities or target visibility.
				$descriptor = $this->registry->descriptor( $action );
				if ( Policy::publicRepositoryContext() ) {
					$descriptor = $this->publicDescriptor( $action, $operation['payload'], $descriptor, ! empty( $context['dry_run'] ), $operation['expected_fingerprint'] );
				}
				Policy::assertActionAllowed( $descriptor, ! empty( $context['dry_run'] ), ! empty( $context['confirm'] ) );
				$result = $this->executeWithStateGuards(
					$action,
					$operation['payload'],
					$context,
					$operation['expected_fingerprint'],
					$operation['expected_state_token']
				);
				if ( isset( $result['_rollback'] ) && is_array( $result['_rollback'] ) ) {
					array_unshift( $rollbacks, $result['_rollback'] );
				}
				unset( $result['_rollback'], $result['_current_fingerprint'] );
				$results[] = array( 'action' => $action, 'result' => $result );
			}
		} catch ( Throwable $error ) {
			$compensation = array();
			if ( empty( $context['dry_run'] ) ) {
				foreach ( $rollbacks as $rollback ) {
					try {
						$compensation[] = array( 'ok' => true, 'action' => $rollback['action'], 'result' => $this->registry->execute( (string) $rollback['action'], (array) $rollback['payload'], array_merge( $context, array( 'rollback_mode' => true, 'dry_run' => false ) ) ) );
					} catch ( Throwable $rollback_error ) {
						$compensation[] = array( 'ok' => false, 'action' => $rollback['action'] ?? null, 'error' => $rollback_error->getMessage() );
					}
				}
			}
			throw new RuntimeException( 'Batch failed at operation ' . count( $results ) . ': ' . $error->getMessage() . ( $compensation ? ' Compensation attempted: ' . wp_json_encode( $compensation ) : '' ), 0, $error );
		}
		$output = array( 'operations' => $results );
		if ( $rollbacks ) {
			$output['_rollback'] = array( 'action' => 'connector.batch', 'payload' => array( 'operations' => array_map( static function ( array $rollback ): array {
				return array( 'action' => $rollback['action'], 'payload' => $rollback['payload'] );
			}, $rollbacks ) ) );
		}
		return $output;
	}

	public function rollback( array $payload, array $context ): array {
		$request_id = isset( $payload['request_id'] ) ? (string) $payload['request_id'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $request_id ) ) {
			throw new RuntimeException( 'rollback requires a valid payload.request_id.' );
		}
		$record = $this->snapshots->getRecord( $request_id );
		if ( ! $record || empty( $record['rollback'] ) || ! is_array( $record['rollback'] ) ) {
			throw new RuntimeException( 'Rollback snapshot was not found.' );
		}
		$rollback = $record['rollback'];
		if ( empty( $rollback['action'] ) || ! isset( $rollback['payload'] ) || ! is_array( $rollback['payload'] ) ) {
			throw new RuntimeException( 'Rollback snapshot was not found.' );
		}
		if ( Policy::publicRepositoryContext() ) {
			$this->assertPublicRollbackRecord( $record );
			$fingerprint = $this->publicRollbackFingerprint( $rollback, $context );
			if ( ! preg_match( '/^[a-f0-9]{64}\z/', $fingerprint ) ) {
				throw new RuntimeException( 'Public rollback target cannot enforce stale-state protection.' );
			}
			if ( ! empty( $context['dry_run'] ) ) {
				return array(
					'would_execute_action' => (string) $rollback['action'],
					'source_action'        => (string) ( $record['source_action'] ?? '' ),
					'_current_fingerprint' => $fingerprint,
				);
			}
		} elseif ( ! empty( $context['dry_run'] ) ) {
			return array( 'would_execute' => $rollback );
		}
		if ( in_array( $rollback['action'], array( 'connector.rollback', 'connector.cleanup' ), true ) ) {
			throw new RuntimeException( 'Recursive rollback is not allowed.' );
		}
		$descriptor = $this->registry->descriptor( (string) $rollback['action'] );
		if ( ! Policy::publicRepositoryContext() ) {
			Policy::assertActionAllowed( $descriptor, false, true );
		}
		$result = $this->registry->execute( (string) $rollback['action'], $rollback['payload'], array_merge( $context, array( 'rollback_mode' => true, 'expected_fingerprint' => null ) ) );
		unset( $result['_rollback'], $result['_current_fingerprint'] );
		return array( 'restored_request_id' => $requestId, 'restored_with' => $rollback['action'], 'result' => $result );
	}

	/**
     * Private administrator-only recovery inspection. No recovery write or
     * retry is possible here; results are observations, not execution proof.
     */
	public function mutationStatus( array $payload, array $context ): array {
		if ( array_keys( $payload ) !== array( 'request_id' )
 || ! is_string( $payload['request_id'] ?? null )
 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $payload['request_id'] ) ) {
			throw new RuntimeException( 'Mutation status requires only a valid request_id.' );
		}
		$request_id    = $payload['request_id'];
		$record       = $this->processed->get( $request_id );
		$state        = 'not_verified';
		$outcome      = 'unknown';
		$action       = null;
		$created_at_gmt = null;

		if ( is_array( $record ) ) {
			$action = isset( $record['action'] ) && is_string( $record['action'] )
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
			'request_id'                     => $requestId,
			'record_state'                   => $state,
			'recorded_action'                => $action,
			'recorded_at_gmt'                => $created_at_gmt,
			'outcome'                        => $outcome,
			'rollback_snapshot_recorded'     => null !== $this->snapshots->getRecord( $requestId ),
			'mutation_lock'                  => $this->processed->mutationLockStatus( $requestId ),
			'manual_reconciliation_required' => 'completed' !== $state,
			'safe_to_retry_automatically'    => false,
			'guidance'                       => 'Verify the exact site state and original request receipt before any new write or rollback. This inspection makes no changes.',
		);
	}

	public function cleanup( array $payload, array $context ): array {
		$days = isset( $payload['older_than_days'] ) ? max( 1, min( 365, (int) $payload['older_than_days'] ) ) : 30;
		if ( ! empty( $context['dry_run'] ) ) {
			return array( 'would_delete_state_older_than_days' => $days );
		}
		$seconds           = $days * DAY_IN_SECONDS;
		$oidc_replay_deleted = class_exists( \Webactueel\WordPressConnector\Security\GitHubOidc::class )
			? \Webactueel\WordPressConnector\Security\GitHubOidc::cleanupReplayClaims( $seconds )
			: 0;
		return array(
			'snapshots_deleted'          => $this->snapshots->cleanup( $seconds ),
			'processed_deleted'          => $this->processed->cleanup( $seconds ),
			'oidc_replay_claims_deleted' => $oidc_replay_deleted,
		);
	}

	private function publicDescriptor( string $action, array $payload, array $descriptor, bool $dryRun, ?string $expectedFingerprint ): array {
		if ( 'elementor.header_links.inspect' === $action ) {
			return $this->publicHeaderLinksInspectDescriptor( $payload, $descriptor, $dryRun );
		}
		if ( 'elementor.header_links.replace' === $action ) {
			return $this->publicHeaderLinksReplaceDescriptor( $payload, $descriptor, $dryRun, $expectedFingerprint );
		}
		if ( 'yoast.snippet.inspect' === $action || 'yoast.snippet.update' === $action ) {
			$inspect = 'yoast.snippet.inspect' === $action;
			if ( $inspect ) {
				if ( array_keys( $payload ) !== array( 'id' ) || ! is_int( $payload['id'] ) || $payload['id'] < 1 || ! $dryRun ) {
					throw new RuntimeException( 'Public snippet inspection requires only an id and dry-run.' );
				}
			} else {
				if ( array_diff( array_keys( $payload ), array( 'id', 'fields' ) ) || 
					! is_int( $payload['id'] ?? null ) || $payload['id'] < 1 ) {
					throw new RuntimeException( 'Public snippet edit accepts only id and fields.' );
				}
				\Webactueel\WordPressConnector\Adapters\YoastSnippetAdapter::validateFields( $payload['fields'] ?? null );
				if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\z/', $expectedFingerprint ) ) ) {
					throw new RuntimeException( 'Confirmed snippet edit requires expected_fingerprint from dry-run.' );
				}
			}
			return $descriptor;
		}
		if ( 'yoast.templates.inspect' === $action || 'yoast.templates.update' === $action ) {
			if ( 'yoast.templates.inspect' === $action ) {
				if ( $payload ) {
					throw new RuntimeException( 'Public Yoast templates inspection requires an empty payload.' );
				}
			} else {
				if ( array_diff( array_keys( $payload ), array( 'fields' ) ) ) {
					throw new RuntimeException( 'Public Yoast template update accepts only fields.' );
				}
				$fields           = $payload['fields'] ?? null;
				$allowedVariables = array(
					'category_title'         => '%%term_title%%',
					'product_category_title' => '%%term_title%%',
					'product_title'          => '%%title%%',
					'product_description'    => '%%excerpt%%',
				);
				if ( ! is_array( $fields ) || ! $fields || count( $fields ) > count( $allowed_variables ) ) {
					throw new RuntimeException( 'Public Yoast templates require one to four allowlisted fields.' );
				}
				foreach ( $fields as $key => $value ) {
					$required_variable = is_string( $key ) && array_key_exists( $key, $allowed_variables )
						? $allowed_variables[ $key ] : null;
					if ( null === $required_variable || ! is_string( $value )
 || strlen( $value ) > 150 || strlen( $value ) < 10
 || false === strpos( $value, $requiredVariable )
 || preg_match( '/[\\x00-\\x1f\\x7f<>]/', $value ) ) {
						throw new RuntimeException( 'Public Yoast template field or value is not allowed.' );
					}
				}
				if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\\z/', $expectedFingerprint ) ) ) {
					throw new RuntimeException( 'Confirmed public Yoast templates require a dry-run fingerprint.' );
				}
			}
			$descriptor['privileged'] = false;
			$descriptor['public_repository_safe'] = false;
			return $descriptor;
		}
		if ( in_array( $action, array( 'gtranslate.settings.update', 'yoast.site_representation.update' ), true ) ) {
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed public provider settings update requires expected_fingerprint from dry-run.' );
			}
			return $descriptor;
		}

		if ( 'site.identity.update' === $action ) {
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed site identity update requires expected_fingerprint from the preceding dry-run.' );
			}
			return $descriptor;
		}

		if ( 'post.literal_replace' === $action ) {
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed literal replacement requires expected_fingerprint from the preceding dry-run.' );
			}
			return $descriptor;
		}

		if ( 'acf.update' === $action ) {
			$this->assertPublicAcfUpdatePayload( $payload );
			$descriptor['privileged'] = false;
			return $descriptor;
		}
		if ( 'acf.portfolio_stats_update' === $action ) {
			$this->assertPublicPortfolioStatsPayload( $payload );
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed public portfolio stats update requires expected_fingerprint from the preceding dry-run.' );
			}
			$descriptor['privileged'] = false;
			$descriptor['public_repository_safe'] = false;
			return $descriptor;
		}
		if ( 'portfolio.case_text_update' === $action ) {
			$this->assertPublicPortfolioCaseTextPayload( $payload );
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed public portfolio case text update requires expected_fingerprint from the preceding dry-run.' );
			}
			$descriptor['privileged'] = false;
			$descriptor['public_repository_safe'] = false;
			return $descriptor;
		}
		if ( 'custom_css.inspect' === $action ) {
			$this->assertPublicCustomCssPayload( $payload, false );
			$descriptor['privileged'] = false;
			return $descriptor;
		}
		if ( 'custom_css.patch' === $action ) {
			$this->assertPublicCustomCssPayload( $payload, true );
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed public custom_css.patch requires expected_fingerprint from the preceding dry-run.' );
			}
			$descriptor['privileged'] = false;
			$descriptor['public_repository_safe'] = false;
			return $descriptor;
		}
		if ( 'maintenance.cache_flush' === $action ) {
			$this->assertPublicCacheFlushPayload( $payload );
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed public cache flush requires expected_fingerprint from the preceding dry-run.' );
			}
			$descriptor['privileged'] = false;
			$descriptor['public_repository_safe'] = false;
			return $descriptor;
		}
		if ( 'code_snippets.patch' === $action ) {
			$this->assertPublicCodeSnippetsPatchPayload( $payload );
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed public Code Snippets patch requires expected_fingerprint from the preceding dry-run.' );
			}
			$descriptor['privileged'] = false;
			$descriptor['public_repository_safe'] = false;
			return $descriptor;
		}
		if ( 'theme.install_package' === $action ) {
			$allowed = array( 'source_path', 'sha256', 'expected_stylesheet', 'overwrite' );
			if ( array_diff( array_keys( $payload ), $allowed ) || count( $payload ) !== 4
 || ! isset( $payload['source_path'] ) || ! is_string( $payload['source_path'] )
 || ! preg_match( '#^theme-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.zip\z#', $payload['source_path'] )
 || ! isset( $payload['sha256'] ) || ! is_string( $payload['sha256'] )
 || ! preg_match( '/^[a-f0-9]{64}\z/', $payload['sha256'] )
 || ! isset( $payload['expected_stylesheet'] ) || ! is_string( $payload['expected_stylesheet'] )
 || ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,79}\z/', $payload['expected_stylesheet'] )
 || $payload['overwrite'] !== false ) {
				throw new RuntimeException( 'Public theme packages require a new theme, exact ZIP checksum and overwrite=false.' );
			}
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed public theme install requires preceding dry-run fingerprint.' );
			}
			$descriptor['public_repository_safe'] = true;
			return $descriptor;
		}
		if ( 'plugin.install_package' === $action ) {
			$this->assertPublicPluginInstallPayload( $payload );
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed public plugin package install requires expected_fingerprint from the preceding dry-run.' );
			}
			$descriptor['public_repository_safe'] = true;
			return $descriptor;
		}
		if ( 'connector.rollback' === $action ) {
			$request_id = isset( $payload['request_id'] ) ? (string) $payload['request_id'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $request_id ) ) {
				throw new RuntimeException( 'rollback requires a valid payload.request_id.' );
			}
			$record = $this->snapshots->getRecord( $request_id );
			if ( ! $record ) {
				throw new RuntimeException( 'Rollback snapshot was not found.' );
			}
			$this->assertPublicRollbackRecord( $record );
			if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\z/', $expectedFingerprint ) ) ) {
				throw new RuntimeException( 'Confirmed public rollback requires expected_fingerprint from the preceding dry-run.' );
			}
			$descriptor['privileged'] = false;
			return $descriptor;
		}
		return $descriptor;
	}

	private function publicHeaderLinksInspectDescriptor( array $payload, array $descriptor, bool $dryRun ): array {
		if ( array_diff( array_keys( $payload ), array( 'post_id', 'checks' ) )
 || ! is_int( $payload['post_id'] ?? null ) || $payload['post_id'] < 1 ) {
			throw new RuntimeException( 'Header diagnostics require only a positive post_id and checks.' );
		}
		$checks = $payload['checks'] ?? null;
		if ( ! is_array( $checks ) || ! $checks || count( $checks ) > 4 ) {
			throw new RuntimeException( 'Header diagnostics require 1-4 checks.' );
		}
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$seen = array();
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) || array_diff( array_keys( $check ), array( 'element_id', 'from' ) ) ) {
				throw new RuntimeException( 'Invalid header diagnostic check keys.' );
			}
			$id  = $check['element_id'] ?? null;
			$url = $check['from'] ?? null;
			if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9_-]{5,64}\\z/', $id )
 || isset( $seen[ $id ] ) || ! is_string( $url ) || strlen( $url ) > 500
 || ! filter_var( $url, FILTER_VALIDATE_URL )
 || wp_parse_url( $url, PHP_URL_SCHEME ) !== 'https'
 || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
				throw new RuntimeException( 'Invalid header diagnostic widget or site URL.' );
			}
			$seen[ $id ] = true;
		}
		if ( ! $dryRun ) {
			throw new RuntimeException( 'Header diagnostics are read-only.' );
		}
		$descriptor['privileged'] = false;
		$descriptor['public_repository_safe'] = false;
		return $descriptor;
	}

	private function publicHeaderLinksReplaceDescriptor( array $payload, array $descriptor, bool $dryRun, ?string $expectedFingerprint ): array {
		if ( array_diff( array_keys( $payload ), array( 'post_id', 'changes' ) )
 || ! isset( $payload['post_id'] ) || ! is_int( $payload['post_id'] ) || $payload['post_id'] < 1 ) {
			throw new RuntimeException( 'Header link repair requires only post_id and changes.' );
		}
		$changes = $payload['changes'] ?? null;
		if ( ! is_array( $changes ) || ! $changes || count( $changes ) > 4 ) {
			throw new RuntimeException( 'Header link repair requires 1-4 replacements.' );
		}
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$seen     = array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || array_diff( array_keys( $change ), array( 'element_id', 'from', 'to' ) ) ) {
				throw new RuntimeException( 'Header link replacement keys are not allowed.' );
			}
			$element_id = $change['element_id'] ?? '';
			if ( ! is_string( $elementId ) || ! preg_match( '/^[a-zA-Z0-9_-]{5,64}\\z/', $element_id )
 || isset( $seen[ $element_id ] ) ) {
				throw new RuntimeException( 'Header link widget ID must be unique and bounded.' );
			}
			$seen[ $element_id ] = true;
			foreach ( array( 'from', 'to' ) as $field ) {
				$url = $change[ $field ] ?? null;
				if ( ! is_string( $url ) || strlen( $url ) > 500 || ! filter_var( $url, FILTER_VALIDATE_URL )
 || wp_parse_url( $url, PHP_URL_SCHEME ) !== 'https'
 || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $home_host
 || ( $field === 'to' && ( null !== wp_parse_url( $url, PHP_URL_QUERY ) || null !== wp_parse_url( $url, PHP_URL_FRAGMENT ) ) ) ) {
					throw new RuntimeException( 'Header link URLs must be exact HTTPS same-site URLs.' );
				}
			}
			if ( $change['from'] === $change['to'] ) {
				throw new RuntimeException( 'Header link source and destination must differ.' );
			}
		}
		if ( ! $dryRun && ( null === $expectedFingerprint || ! preg_match( '/^[a-f0-9]{64}\\z/', $expectedFingerprint ) ) ) {
			throw new RuntimeException( 'Confirmed public header repair requires expected_fingerprint.' );
		}
		$descriptor['privileged'] = false;
		$descriptor['public_repository_safe'] = false;
		return $descriptor;
	}

	private function assertPublicCustomCssPayload( array $payload, bool $patch ): void {
		$allowed = $patch
			? array( 'stylesheet', 'patch_id', 'operation', 'css' )
			: array( 'stylesheet' );
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				throw new RuntimeException( 'Public Additional CSS request contains unsupported payload key: ' . (string) $key );
			}
		}

		$stylesheet = isset( $payload['stylesheet'] ) ? sanitize_text_field( (string) $payload['stylesheet'] ) : (string) get_stylesheet();
		if ( '' === $stylesheet || ! hash_equals( (string) get_stylesheet(), $stylesheet ) ) {
			throw new RuntimeException( 'Public Additional CSS actions may target only the active theme stylesheet.' );
		}

		if ( ! $patch ) {
			return;
		}

		$patch_id = isset( $payload['patch_id'] ) && is_string( $payload['patch_id'] ) ? $payload['patch_id'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}\\z/', $patchId ) ) {
			throw new RuntimeException( 'Public custom_css.patch requires a safe 3-64 character patch_id.' );
		}

		$operation = isset( $payload['operation'] ) && is_string( $payload['operation'] ) ? strtolower( $payload['operation'] ) : '';
		if ( ! in_array( $operation, array( 'upsert', 'remove' ), true ) ) {
			throw new RuntimeException( 'Public custom_css.patch operation must be upsert or remove.' );
		}

		if ( 'remove' === $operation ) {
			if ( array_key_exists( 'css', $payload ) ) {
				throw new RuntimeException( 'Public custom_css.patch remove must not include css.' );
			}
			return;
		}

		$css = isset( $payload['css'] ) && is_string( $payload['css'] ) ? $payload['css'] : '';
		if ( '' === trim( $css ) || strlen( $css ) > 65536 ) {
			throw new RuntimeException( 'Public custom_css.patch css must be non-empty and at most 64 KiB.' );
		}
		if ( preg_match( '/[\\x00-\\x08\\x0B\\x0E-\\x1F\\x7F]/', $css ) ) {
			throw new RuntimeException( 'Public custom_css.patch css contains unsupported control characters.' );
		}
		if ( false !== stripos( $css, '</style' ) || false !== stripos( $css, 'wpconnector:' ) ) {
			throw new RuntimeException( 'Public custom_css.patch css contains a forbidden style boundary or connector marker.' );
		}
	}

	private function assertPublicCustomCssRollbackPayload( array $payload ): void {
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'stylesheet', 'css' ), true ) ) {
				throw new RuntimeException( 'Public Additional CSS rollback contains unsupported payload key: ' . (string) $key );
			}
		}
		$stylesheet = isset( $payload['stylesheet'] ) ? sanitize_text_field( (string) $payload['stylesheet'] ) : '';
		if ( '' === $stylesheet || ! hash_equals( (string) get_stylesheet(), $stylesheet ) ) {
			throw new RuntimeException( 'Public Additional CSS rollback may target only the active theme stylesheet.' );
		}
		if ( ! array_key_exists( 'css', $payload ) || ! is_string( $payload['css'] ) || strlen( $payload['css'] ) > 524288 ) {
			throw new RuntimeException( 'Public Additional CSS rollback css is invalid.' );
		}
		if ( false !== stripos( $payload['css'], '</style' ) ) {
			throw new RuntimeException( 'Public Additional CSS rollback contains a forbidden style boundary.' );
		}
	}

	private function assertPublicPluginInstallPayload( array $payload ): void {
		$allowed = array( 'source_path', 'sha256', 'expected_plugin', 'overwrite', 'activate', 'network_wide' );
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				throw new RuntimeException( 'Public plugin package install contains unsupported payload key: ' . (string) $key );
			}
		}

		$source_path = isset( $payload['source_path'] ) && is_string( $payload['source_path'] )
			? str_replace( '\\', '/', $payload['source_path'] )
			: '';
		if ( ! preg_match( '#^plugin-packages/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\\.zip\\z#', $sourcePath ) ) {
			throw new RuntimeException( 'Public plugin package install requires source_path plugin-packages/<safe-name>.zip.' );
		}

		$sha256 = isset( $payload['sha256'] ) && is_string( $payload['sha256'] )
			? strtolower( $payload['sha256'] )
			: '';
		if ( ! preg_match( '/^[a-f0-9]{64}\\z/', $sha256 ) ) {
			throw new RuntimeException( 'Public plugin package install requires an exact lowercase SHA-256 checksum.' );
		}

		$expected_plugin = isset( $payload['expected_plugin'] ) && is_string( $payload['expected_plugin'] )
			? str_replace( '\\', '/', $payload['expected_plugin'] )
			: '';
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\\/[A-Za-z0-9._-]+\\.php\\z/', $expectedPlugin ) ) {
			throw new RuntimeException( 'Public plugin package install requires an exact expected_plugin file.' );
		}

		if ( ! array_key_exists( 'overwrite', $payload ) || ! is_bool( $payload['overwrite'] ) ) {
			throw new RuntimeException( 'Public plugin package install requires an explicit boolean overwrite value.' );
		}
		if ( true === $payload['overwrite']
 && ! hash_equals( 'webactueel-mailbox-bridge/webactueel-mailbox-bridge.php', $expectedPlugin ) ) {
			throw new RuntimeException( 'Public plugin package overwrite is restricted to the Webactueel Mailbox Bridge.' );
		}
		if ( ! array_key_exists( 'network_wide', $payload ) || false !== $payload['network_wide'] ) {
			throw new RuntimeException( 'Public plugin package install requires network_wide=false.' );
		}
		if ( ! array_key_exists( 'activate', $payload ) || ! is_bool( $payload['activate'] ) ) {
			throw new RuntimeException( 'Public plugin package install requires an explicit boolean activate value.' );
		}
	}

	private function assertPublicCacheFlushPayload( array $payload ): string {
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'layer', 'regenerate' ), true ) ) {
				throw new RuntimeException( 'Public cache flush contains unsupported payload key: ' . (string) $key );
			}
		}

		$layer = isset( $payload['layer'] ) && is_string( $payload['layer'] )
			? sanitize_key( $payload['layer'] )
			: '';

		if ( ! in_array( $layer, array( 'elementor', 'wp_rocket', 'asset_cleanup' ), true ) ) {
			throw new RuntimeException( 'Public cache flush requires layer: elementor, wp_rocket or asset_cleanup.' );
		}

		$regenerate = array_key_exists( 'regenerate', $payload ) ? $payload['regenerate'] : false;
		if ( ! is_bool( $regenerate ) || ( $regenerate && 'elementor' !== $layer ) ) {
			throw new RuntimeException( 'Public cache regeneration must be boolean and Elementor-only.' );
		}

		$preview = $this->registry->execute( 'maintenance.cache_flush', array( 'layer' => $layer, 'regenerate' => $regenerate ), array(
			'dry_run'           => true,
			'confirm'           => true,
			'public_validation' => true,
		) );

		$fingerprint = isset( $preview['_current_fingerprint'] ) ? (string) $preview['_current_fingerprint'] : '';
		if ( empty( $preview['available'] ) || ! preg_match( '/^[a-f0-9]{64}\\z/', $fingerprint ) ) {
			throw new RuntimeException( 'Public cache flush validation did not produce an available guarded layer.' );
		}

		return $layer;
	}

	private function assertPublicCodeSnippetsPatchPayload( array $payload ): int {
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'snippet_id', 'match_code_contains', 'replacements' ), true ) ) {
				throw new RuntimeException( 'Public Code Snippets patch contains unsupported payload key: ' . (string) $key );
			}
		}

		$snippet_id = isset( $payload['snippet_id'] ) ? (int) $payload['snippet_id'] : 0;
		$marker    = isset( $payload['match_code_contains'] ) && is_string( $payload['match_code_contains'] )
			? $payload['match_code_contains']
			: '';

		if ( ( $snippet_id > 0 && '' !== $marker ) || ( $snippet_id <= 0 && '' === $marker ) ) {
			throw new RuntimeException( 'Public Code Snippets patch requires exactly one of snippet_id or match_code_contains.' );
		}
		if ( $snippet_id <= 0 && ( strlen( $marker ) > 240 || preg_match( '/[\\x00-\\x1F\\x7F]/', $marker ) ) ) {
			throw new RuntimeException( 'Public Code Snippets marker must be a printable string up to 240 bytes.' );
		}

		$replacements = isset( $payload['replacements'] ) && is_array( $payload['replacements'] )
			? array_values( $payload['replacements'] )
			: array();
		if ( ! $replacements || count( $replacements ) > 4 ) {
			throw new RuntimeException( 'Public Code Snippets patch requires 1-4 exact replacements.' );
		}

		foreach ( $replacements as $index => $replacement ) {
			if ( ! is_array( $replacement ) ) {
				throw new RuntimeException( 'Public Code Snippets replacement ' . $index . ' must be an object.' );
			}
			foreach ( array_keys( $replacement ) as $key ) {
				if ( ! in_array( (string) $key, array( 'find', 'replace' ), true ) ) {
					throw new RuntimeException( 'Public Code Snippets replacement contains unsupported key: ' . (string) $key );
				}
			}
			$find    = isset( $replacement['find'] ) && is_string( $replacement['find'] ) ? $replacement['find'] : '';
			$replace = isset( $replacement['replace'] ) && is_string( $replacement['replace'] ) ? $replacement['replace'] : '';
			if ( '' === $find || strlen( $find ) > 131072 || strlen( $replace ) > 131072 ) {
				throw new RuntimeException( 'Public Code Snippets replacement exceeds the bounded size limit.' );
			}
			if ( false !== strpos( $find, "\0" ) || false !== strpos( $replace, "\0" ) ) {
				throw new RuntimeException( 'Public Code Snippets replacement may not contain NUL bytes.' );
			}
		}

		$preview = $this->registry->execute( 'code_snippets.patch', $payload, array(
			'dry_run'           => true,
			'confirm'           => true,
			'public_validation' => true,
		) );
		$target_id    = isset( $preview['snippet']['id'] ) ? (int) $preview['snippet']['id'] : 0;
		$fingerprint = isset( $preview['_current_fingerprint'] ) ? (string) $preview['_current_fingerprint'] : '';
		if ( $target_id <= 0 || ! preg_match( '/^[a-f0-9]{64}\\z/', $fingerprint ) ) {
			throw new RuntimeException( 'Public Code Snippets validation did not produce a guarded target fingerprint.' );
		}

		return $target_id;
	}

	private function assertPublicPortfolioCaseTextPayload( array $payload ): int {
		$preview     = $this->registry->execute( 'portfolio.case_text_update', $payload, array( 'dry_run' => true, 'confirm' => true, 'public_validation' => true ) );
		$postId      = isset( $preview['post_id'] ) ? (int) $preview['post_id'] : 0;
		$fingerprint = isset( $preview['_current_fingerprint'] ) ? (string) $preview['_current_fingerprint'] : '';
		if ( $post_id <= 0 || ! preg_match( '/^[a-f0-9]{64}\z/', $fingerprint ) ) {
			throw new RuntimeException( 'Portfolio case text public validation did not produce a guarded target fingerprint.' );
		}
		return $post_id;
	}

	private function assertPublicPortfolioStatsPayload( array $payload ): int {
		$preview     = $this->registry->execute( 'acf.portfolio_stats_update', $payload, array( 'dry_run' => true, 'confirm' => true, 'public_validation' => true ) );
		$postId      = isset( $preview['post_id'] ) ? (int) $preview['post_id'] : 0;
		$fingerprint = isset( $preview['_current_fingerprint'] ) ? (string) $preview['_current_fingerprint'] : '';
		if ( $post_id <= 0 || ! preg_match( '/^[a-f0-9]{64}\z/', $fingerprint ) ) {
			throw new RuntimeException( 'Portfolio stats public validation did not produce a guarded target fingerprint.' );
		}
		return $post_id;
	}

	private function assertPublicAcfUpdatePayload( array $payload ): int {
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'post_id', 'target', 'fields' ), true ) ) {
				throw new RuntimeException( 'Public ACF update contains unsupported payload key: ' . (string) $key );
			}
		}
		if ( isset( $payload['post_id'] ) && array_key_exists( 'target', $payload ) ) {
			throw new RuntimeException( 'Public ACF update must use either post_id or target, not both.' );
		}
		if ( isset( $payload['post_id'] ) ) {
			if ( ! is_int( $payload['post_id'] ) || $payload['post_id'] <= 0 ) {
				throw new RuntimeException( 'Public ACF update post_id must be a positive integer.' );
			}
			$postId = $payload['post_id'];
		} elseif ( array_key_exists( 'target', $payload ) && is_int( $payload['target'] ) && $payload['target'] > 0 ) {
			$post_id = $payload['target'];
		} else {
			throw new RuntimeException( 'Public ACF update requires a positive integer post target.' );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Public ACF update target post was not found.' );
		}
		Policy::assertPostReadable( $post );
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_post', $postId ) ) {
			throw new RuntimeException( 'Current user lacks permission to edit the public ACF target post.' );
		}
		foreach ( array( 'acf_get_field_groups', 'acf_get_field', 'get_field', 'update_field' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				throw new RuntimeException( 'ACF public update API is unavailable: ' . $function );
			}
		}
		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		if ( ! $fields || count( $fields ) > 16 || array_keys( $fields ) === range( 0, count( $fields ) - 1 ) ) {
			throw new RuntimeException( 'Public ACF update requires a 1-16 field object.' );
		}
		$allowed_parents = array();
		foreach ( (array) acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			if ( ! empty( $group['key'] ) ) {
				$allowed_parents[(string) $group['key']] = true;
			}
			if ( ! empty( $group['ID'] ) ) {
				$allowed_parents[(string) $group['ID']] = true;
			}
		}
		if ( ! $allowedParents ) {
			throw new RuntimeException( 'No ACF field group applies to the public target post.' );
		}
		foreach ( $fields as $field_key => $value ) {
			$fieldKey = (string) $field_key;
			if ( ! preg_match( '/^field_[A-Za-z0-9_-]{6,80}\z/', $fieldKey ) ) {
				throw new RuntimeException( 'Public ACF update requires field keys, not field names.' );
			}
			Policy::assertKeyAllowed( $field_key );
			if ( ! is_string( $value ) || strlen( $value ) > 1000 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
				throw new RuntimeException( 'Public ACF values must be text strings up to 1000 bytes without control characters.' );
			}
			$field     = acf_get_field( $fieldKey );
			$field_type = is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';
			if ( ! is_array( $field ) || ! in_array( $field_type, array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
				throw new RuntimeException( 'Public ACF field is missing or is not an allowed textual field: ' . $fieldKey );
			}
			if ( in_array( $field_type, array( 'textarea', 'wysiwyg' ), true ) && ( false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) ) {
				throw new RuntimeException( 'Public multiline ACF values must be plain text without HTML markup: ' . $field_key );
			}
			$name   = (string) ( $field['name'] ?? '' );
			$parent = (string) ( $field['parent'] ?? '' );
			if ( '' === $name || ! isset( $allowedParents[ $parent ] ) ) {
				throw new RuntimeException( 'Public ACF field does not belong to a field group for the target post: ' . $field_key );
			}
			Policy::assertKeyAllowed( $name );
		}
		return $post_id;
	}

	private function assertPublicRollbackRecord( array $record ): void {
		if ( empty( $record['public_repository_mode'] ) ) {
			throw new RuntimeException( 'Rollback snapshot was not created by the guarded public runtime.' );
		}
		$source_action   = (string) ( $record['source_action'] ?? '' );
		$rollback       = isset( $record['rollback'] ) && is_array( $record['rollback'] ) ? $record['rollback'] : array();
		$rollback_action = (string) ( $rollback['action'] ?? '' );
		$allowed        = array(
			'acf.update'                       => array( 'acf.update' ),
			'acf.portfolio_stats_update'       => array( 'acf.portfolio_stats_update', 'acf.update' ),
			'portfolio.case_text_update'       => array( 'portfolio.case_text_update' ),
			'post.update'                      => array( 'post.update' ),
			'post.literal_replace'             => array( 'post.update' ),
			'site.identity.update'             => array( 'site.identity.restore' ),
			'gtranslate.settings.update'       => array( 'gtranslate.settings.restore' ),
			'yoast.site_representation.update' => array( 'yoast.site_representation.restore' ),
			'yoast.snippet.update'             => array( 'yoast.snippet.restore' ),
			'elementor.patch_element'          => array( 'elementor.replace_document' ),
			'acf.schema.ensure_text_fields'    => array( 'acf.schema.remove_text_fields' ),
			'connector.batch'                  => array( 'connector.batch' ),
			'code_snippets.patch'              => array( 'code_snippets.restore_code' ),
			'custom_css.patch'                 => array( 'custom_css.update' ),
		);
		if ( ! isset( $allowed[ $source_action ] ) || ! in_array( $rollback_action, $allowed[ $sourceAction ], true ) ) {
			throw new RuntimeException( 'Rollback snapshot is not eligible for the guarded public rollback route.' );
		}
		if ( 'acf.portfolio_stats_update' === $source_action ) {
			$this->assertPublicPortfolioStatsPayload( (array) ( $rollback['payload'] ?? array() ) );
		} elseif ( 'yoast.snippet.update' === $source_action ) {
			$rollback_payload = (array) ( $rollback['payload'] ?? array() );
			if ( array_diff( array_keys( $rollback_payload ), array( 'id', 'fields', 'expected_after_fingerprint' ) ) ) {
				throw new RuntimeException( 'Public snippet rollback has unsupported keys.' );
			}
			$this->assertPublicRollbackPostId( $rollback_payload );
			\Webactueel\WordPressConnector\Adapters\YoastSnippetAdapter::validateFields( $rollbackPayload['fields'] ?? null, true );
			if ( ! is_string( $rollbackPayload['expected_after_fingerprint'] ?? null ) || 
				! preg_match( '/^[a-f0-9]{64}\z/', $rollbackPayload['expected_after_fingerprint'] ) ) {
				throw new RuntimeException( 'Public snippet rollback requires an after-fingerprint.' );
			}
		} elseif ( 'code_snippets.patch' === $source_action ) {
			$rollback_payload = (array) ( $rollback['payload'] ?? array() );
			$snippet_id       = isset( $rollback_payload['snippet_id'] ) ? (int) $rollback_payload['snippet_id'] : 0;
			$code            = isset( $rollbackPayload['code'] ) && is_string( $rollback_payload['code'] ) ? $rollbackPayload['code'] : null;
			$expected        = isset( $rollbackPayload['expected_code_fingerprint'] ) ? (string) $rollback_payload['expected_code_fingerprint'] : '';
			if ( $snippet_id <= 0 || null === $code || strlen( $code ) > 1048576 || ! preg_match( '/^[a-f0-9]{64}\\z/', $expected ) ) {
				throw new RuntimeException( 'Public Code Snippets rollback snapshot is invalid.' );
			}
		} elseif ( 'custom_css.patch' === $source_action ) {
			$this->assertPublicCustomCssRollbackPayload( (array) ( $rollback['payload'] ?? array() ) );
		} elseif ( 'portfolio.case_text_update' === $source_action ) {
			$this->assertPublicPortfolioCaseTextPayload( (array) ( $rollback['payload'] ?? array() ) );
		} elseif ( 'acf.update' === $rollback_action ) {
			$this->assertPublicAcfUpdatePayload( (array) ( $rollback['payload'] ?? array() ) );
		} elseif ( 'post.update' === $rollbackAction ) {
			$this->assertPublicRollbackPostPayload( (array) ( $rollback['payload'] ?? array() ) );
		} elseif ( 'elementor.replace_document' === $rollback_action ) {
			$this->assertPublicRollbackPostId( (array) ( $rollback['payload'] ?? array() ) );
		} elseif ( 'acf.schema.remove_text_fields' === $rollback_action ) {
			$this->assertPublicRollbackPostId( (array) ( $rollback['payload'] ?? array() ), 'post_id' );
		} elseif ( 'connector.batch' === $rollback_action ) {
			$operations = isset( $rollback['payload']['operations'] ) && is_array( $rollback['payload']['operations'] ) ? array_values( $rollback['payload']['operations'] ) : array();
			if ( ! $operations || count( $operations ) > 25 ) {
				throw new RuntimeException( 'Public batch rollback snapshot is invalid.' );
			}
			foreach ( $operations as $operation ) {
				if ( ! is_array( $operation ) || empty( $operation['action'] ) || ! isset( $operation['payload'] ) || ! is_array( $operation['payload'] ) ) {
					throw new RuntimeException( 'Public batch rollback operation is invalid.' );
				}
				if ( 'acf.update' === $operation['action'] ) {
					$this->assertPublicAcfUpdatePayload( $operation['payload'] );
				} elseif ( 'acf.portfolio_stats_update' === $operation['action'] ) {
					$this->assertPublicPortfolioStatsPayload( $operation['payload'] );
				} elseif ( 'post.update' === $operation['action'] ) {
					$this->assertPublicRollbackPostPayload( $operation['payload'] );
				} else {
					throw new RuntimeException( 'Public batch rollback contains a non-public restore action.' );
				}
			}
		}
	}

	private function publicRollbackFingerprint( array $rollback, array $context ): string {
		$action  = (string) $rollback['action'];
		$payload = (array) $rollback['payload'];
		if ( 'connector.batch' === $action ) {
			$fingerprints = array();
			foreach ( (array) ( $payload['operations'] ?? array() ) as $operation ) {
				$preview = $this->registry->execute( (string) $operation['action'], (array) $operation['payload'], array_merge( $context, array( 'dry_run' => true, 'confirm' => true, 'rollback_mode' => true ) ) );
				$current = isset( $preview['_current_fingerprint'] ) ? (string) $preview['_current_fingerprint'] : '';
				if ( ! preg_match( '/^[a-f0-9]{64}\z/', $current ) ) {
					throw new RuntimeException( 'Public batch rollback operation cannot enforce stale-state protection.' );
				}
				$fingerprints[] = $current;
			}
			return Fingerprint::make( $fingerprints );
		}
		$preview = $this->registry->execute( $action, $payload, array_merge( $context, array( 'dry_run' => true, 'confirm' => true, 'rollback_mode' => true ) ) );
		return isset( $preview['_current_fingerprint'] ) ? (string) $preview['_current_fingerprint'] : '';
	}

	private function assertPublicRollbackPostPayload( array $payload ): void {
		$allowed = array( 'id', 'title', 'content', 'excerpt', 'status', 'slug', 'type', 'author', 'parent', 'menu_order', 'comment_status', 'ping_status' );
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				throw new RuntimeException( 'Public post rollback contains unsupported field: ' . (string) $key );
			}
		}
		$this->assertPublicRollbackPostId( $payload );
		if ( isset( $payload['status'] ) && 'publish' !== (string) $payload['status'] ) {
			throw new RuntimeException( 'Public post rollback may only restore publish status.' );
		}
	}

	private function assertPublicRollbackPostId( array $payload, string $key = 'id' ): void {
		$post_id = isset( $payload[ $key ] ) ? (int) $payload[ $key ] : 0;
		if ( $postId <= 0 ) {
			throw new RuntimeException( 'Public rollback requires a positive post id.' );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Public rollback target post was not found.' );
		}
		Policy::assertPostReadable( $post );
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_post', $postId ) ) {
			throw new RuntimeException( 'Current user lacks permission to edit the public rollback target post.' );
		}
	}

	private function executeWithStateGuards( string $action, array $payload, array $context, ?string $expectedFingerprint, ?string $expectedStateToken ): array {
		if ( ( null !== $expectedFingerprint || null !== $expectedStateToken ) && empty( $context['dry_run'] ) ) {
			$preview = $this->registry->execute( $action, $payload, array_merge( $context, array( 'dry_run' => true ) ) );
			if ( ! isset( $preview['_current_fingerprint'] ) || ! is_string( $preview['_current_fingerprint'] ) ) {
				throw new RuntimeException( 'Action cannot enforce stale-state protection: ' . $action );
			}
			$current = $preview['_current_fingerprint'];
			if ( null !== $expectedFingerprint && ! hash_equals( $expectedFingerprint, $current ) ) {
				throw new RuntimeException( 'Stale target: expected_fingerprint does not match current state.' );
			}
			if ( null !== $expectedStateToken && ! hash_equals( $expectedStateToken, Fingerprint::siteTokenFromFingerprint( $current ) ) ) {
				throw new RuntimeException( 'Stale target: expected_state_token does not match current site state.' );
			}
			$context['expected_prewrite_fingerprint'] = $current;
		}
		return $this->registry->execute( $action, $payload, $context );
	}
}
