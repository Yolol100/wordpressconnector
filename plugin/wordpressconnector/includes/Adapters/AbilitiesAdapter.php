<?php
/**
 * Registers and handles Abilities connector actions.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

/**
 * Registers and handles Abilities connector actions.
 */
final class AbilitiesAdapter {
	/**
	 * Register the Abilities adapter actions and handlers.
	 *
	 * @param Registry $registry Connector action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register( 'wordpress.abilities', array( $this, 'catalog' ), array(
			'privileged'  => true,
			'description' => 'Discover exposed WordPress Abilities API entries and their schemas without executing them.',
		) );
		$registry->register( 'wordpress.ability.read', array( $this, 'readAbility' ), array(
			'privileged'  => true,
			'sensitive'   => true,
			'capability'  => 'manage_options',
			'description' => 'Read through an explicitly read-only, client-exposed WordPress Ability; native validation and permission callbacks still run, and mutation-capable abilities remain blocked from this route.',
		) );
		$registry->register( 'wordpress.ability.execute', array( $this, 'executeAbility' ), array(
			'mutation'    => true,
			'privileged'  => true,
			'capability'  => 'manage_options',
			'description' => 'Execute one explicitly mutating, MCP-exposed native Elementor Core/Pro Ability after verified provider provenance; native schema validation, permission callbacks and provider guards remain authoritative. Dry-run never invokes the Ability.',
		) );
		$registry->register( 'wordpress.ability.execute_approved', array( $this, 'executeApprovedAbility' ), array(
			'mutation'    => true,
			'privileged'  => true,
			'sensitive'   => true,
			'capability'  => 'manage_options',
			'description' => 'Execute an expressly approved, non-destructive native Ability from a verified active third-party plugin on private authenticated transport only.',
		) );
	}

	/**
	 * List available abilities and their metadata.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 */
	public function catalog( array $payload, array $context ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array(
				'available' => false,
				'abilities' => array(),
				'reason'    => 'WordPress Abilities API is not available on this site.',
			);
		}

		$per_page  = $this->catalogPositiveInteger( $payload['per_page'] ?? 10, 10, 'per_page' );
		$page      = $this->catalogPositiveInteger( $payload['page'] ?? 1, 100000, 'page' );
		$namespace = $this->catalogNamespace( $payload['namespace'] ?? null );

		$registered = (array) wp_get_abilities();
		if ( count( $registered ) > 10000 ) {
			throw new \RuntimeException( 'WordPress Ability catalog exceeds the discovery limit.' );
		}

		$eligible = array();
		foreach ( $registered as $name => $ability ) {
			if ( ! is_object( $ability ) ) {
				continue;
			}

			if ( ! is_string( $name ) || '' === $name ) {
				$name = method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
			}

			if ( ! $this->isValidName( $name ) || ! $this->isExposed( $ability ) ) {
				continue;
			}
			if ( null !== $namespace && 0 !== strpos( $name, $namespace . '/' ) ) {
				continue;
			}

			$eligible[ $name ] = $ability;
		}

		ksort( $eligible, SORT_STRING );
		$total    = count( $eligible );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$selected = array_slice( $eligible, ( $page - 1 ) * $per_page, $per_page, true );

		$result   = array();
		foreach ( $selected as $name => $ability ) {
			$result[ $name ] = $this->descriptor( $name, $ability );
		}

		$response = array(
			'available' => true,
			'abilities' => $result,
			'page'      => $page,
			'per_page'  => $per_page,
			'total'     => $total,
			'pages'     => $pages,
		);
		if ( null !== $namespace ) {
			$response['namespace'] = $namespace;
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $response ) : json_encode( $response );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 262144 ) {
			throw new \RuntimeException( 'WordPress Ability catalog page exceeds the output limit.' );
		}

		return $response;
	}

	/**
	 * Read the requested WordPress ability without modifying state.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 */
	public function readAbility( array $payload, array $context ): array {
		list( $name, $ability ) = $this->resolveAbility( $payload, false );
		$input                  = array_key_exists( 'input', $payload ) ? $payload['input'] : null;
		return $this->executeAndSanitize( $name, $ability, $input, $this->safeAnnotations( $ability ), false );
	}

	/**
	 * Execute the authorized WordPress ability.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 */
	public function executeAbility( array $payload, array $context ): array {
		return $this->executeMutation( $payload, $context, false );
	}

	/**
	 * Execute a provider-approved WordPress ability after authorization.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 */
	public function executeApprovedAbility( array $payload, array $context ): array {
		return $this->executeMutation( $payload, $context, true );
	}

	/**
	 * Process the execute mutation operation in the Abilities adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 * @param bool $approvedProvider Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function executeMutation( array $payload, array $context, bool $approvedProvider ): array {
		list( $name, $ability ) = $this->resolveAbility( $payload, true, $approvedProvider );
		$input                  = array_key_exists( 'input', $payload ) ? $payload['input'] : null;
		$annotations            = $this->safeAnnotations( $ability );

		if ( ! empty( $context['dry_run'] ) ) {
			list( $input_schema, $input_schema_omitted ) = $this->boundedCatalogValue(
				method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null
			);
			return array(
				'name'                         => $name,
				'would_execute'                => true,
				'annotations'                  => $annotations,
				'input_schema'                 => $input_schema,
				'input_schema_omitted'         => $input_schema_omitted,
				'native_validation_on_confirm' => true,
				'rollback_supported'           => false,
				'note'                         => 'Dry-run does not invoke the Ability. Native input validation, permission callbacks and provider-specific conflict guards run on confirmed execution.',
			);
		}

		$result                       = $this->executeAndSanitize( $name, $ability, $input, $annotations, true );
		$result['rollback_supported'] = false;
		return $result;
	}

	/**
	 * Resolve ability in the Abilities adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param bool $mutation Value used by this operation.
	 * @param bool $approvedProvider Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function resolveAbility( array $payload, bool $mutation, bool $approvedProvider = false ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			throw new \RuntimeException( 'WordPress Abilities API is not available on this site.' );
		}
		$name = isset( $payload['name'] ) && is_string( $payload['name'] ) ? $payload['name'] : '';
		if ( ! $this->isValidName( $name ) ) {
			throw new \RuntimeException( 'A valid namespace/ability name is required.' );
		}
		$ability  = wp_get_ability( $name );
		$eligible = $mutation
			? ( $approvedProvider ? $this->isApprovedPluginMutationEligible( $name, $ability )
				: $this->isElementorMutationEligible( $name, $ability ) )
			: $this->isReadEligible( $ability );
		if ( ! is_object( $ability ) || ! $eligible ) {
			throw new \RuntimeException( 'The requested client-exposed WordPress Ability was not found.' );
		}
		return array( $name, $ability );
	}

	/**
	 * Process the execute and sanitize operation in the Abilities adapter.
	 *
	 * @param string $name Value used by this operation.
	 * @param object $ability Value used by this operation.
	 * @param mixed $input Value used by this operation.
	 * @param array $annotations Value used by this operation.
	 * @param bool $mutation Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function executeAndSanitize( string $name, object $ability, $input, array $annotations, bool $mutation ): array {
		$execution_started               = false;
		$provider_pre_execution_rejected = false;
		$execution_tracker               = null;
		$guard_tracker                   = null;
		$provider                        = null;

		if ( $mutation ) {
			$callback = $this->abilityExecuteCallback( $ability );
			if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ) {
				$provider = $callback[0];
			}
		}

		if ( $mutation && function_exists( 'add_action' ) && function_exists( 'remove_action' ) ) {
			$execution_tracker = static function ( $abilityName, $normalizedInput, $executingAbility = null ) use ( &$execution_started, &$provider_pre_execution_rejected, $name, $ability, $provider ): void {
				if ( $abilityName !== $name ) {
					return;
				}
				if ( null !== $executingAbility && $executingAbility !== $ability ) {
					return;
				}

				$execution_started = true;
				if ( ! is_object( $provider ) || ! method_exists( $provider, 'is_available' ) ) {
					return;
				}

				try {
					$availability = $provider->is_available();
					if ( function_exists( 'is_wp_error' ) && is_wp_error( $availability ) ) {
						$provider_pre_execution_rejected = true;
					}
				} catch ( \Throwable $error ) {
					$provider_pre_execution_rejected = true;
				}
			};
			add_action( 'wp_before_execute_ability', $execution_tracker, PHP_INT_MAX, 3 );
		}

		if ( $mutation && function_exists( 'add_filter' ) && function_exists( 'remove_filter' ) ) {
			$guard_tracker = static function ( $guard ) use ( &$execution_started, &$provider_pre_execution_rejected ) {
				if ( $execution_started && function_exists( 'is_wp_error' ) && is_wp_error( $guard ) ) {
					$provider_pre_execution_rejected = true;
				}
				return $guard;
			};
			add_filter( 'elementor/mcp/pre_execute_guard', $guard_tracker, PHP_INT_MAX, 1 );
		}

		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $error ) {
			if ( $mutation && $execution_started && ! $provider_pre_execution_rejected ) {
				return $this->terminalMutationFailure( $name, $annotations, null );
			}
			throw new \RuntimeException( 'WordPress Ability failed.' );
		} finally {
			if ( null !== $execution_tracker ) {
				remove_action( 'wp_before_execute_ability', $execution_tracker, PHP_INT_MAX );
			}
			if ( null !== $guard_tracker ) {
				remove_filter( 'elementor/mcp/pre_execute_guard', $guard_tracker, PHP_INT_MAX );
			}
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			if ( $mutation && $execution_started && ! $provider_pre_execution_rejected ) {
				$error_code = method_exists( $result, 'get_error_code' ) ? (string) $result->get_error_code() : null;
				return $this->terminalMutationFailure( $name, $annotations, $error_code );
			}
			throw new \RuntimeException( 'WordPress Ability failed.' );
		}

		if ( $mutation && is_array( $result ) && 'error' === ( $result['status'] ?? null ) ) {
			$error_code = null;
			foreach ( array( 'code', 'error_code' ) as $key ) {
				if ( isset( $result[ $key ] ) && ( is_string( $result[ $key ] ) || is_int( $result[ $key ] ) ) ) {
					$error_code = (string) $result[ $key ];
					break;
				}
			}
			return $this->terminalMutationFailure( $name, $annotations, $error_code );
		}

		try {
			$budget  = array( 'nodes' => 0, 'bytes' => 0 );
			$safe    = $this->redactAbilityResult( $result, 0, new \SplObjectStorage(), $budget );
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $safe ) : json_encode( $safe );
			if ( ! is_string( $encoded ) || strlen( $encoded ) > 262144 ) {
				throw new \RuntimeException( 'WordPress Ability result exceeds the output limit.' );
			}
		} catch ( \Throwable $error ) {
			if ( ! $mutation ) {
				if ( $error instanceof \RuntimeException ) {
					throw $error;
				}
				throw new \RuntimeException( 'WordPress Ability result exceeds the connector output limits.' );
			}

			return array(
				'name'                   => $name,
				'annotations'            => $annotations,
				'execution_completed'    => true,
				'result'                 => null,
				'result_omitted'         => true,
				'result_omission_reason' => 'Ability completed but its result exceeded connector output limits.',
			);
		}

		$response = array(
			'name'        => $name,
			'annotations' => $annotations,
			'result'      => $safe,
		);
		if ( $mutation ) {
			$response['execution_completed'] = true;
			$response['result_omitted']      = false;
		}
		return $response;
	}

	/**
	 * Process the terminal mutation failure operation in the Abilities adapter.
	 *
	 * @param string $name Value used by this operation.
	 * @param array $annotations Value used by this operation.
	 * @param string|null $providerErrorCode Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function terminalMutationFailure( string $name, array $annotations, ?string $providerErrorCode ): array {
		$safe_code = null;
		if ( is_string( $providerErrorCode ) && 1 === preg_match( '/^[A-Za-z0-9._-]{1,120}$/D', $providerErrorCode ) ) {
			$safe_code = $providerErrorCode;
		}

		return array(
			'name'                       => $name,
			'annotations'                => $annotations,
			'execution_may_have_started' => true,
			'provider_error_code'        => $safe_code,
			'result'                     => null,
			'result_omitted'             => true,
			'_terminal_error'            => 'Native WordPress Ability execution ended with a terminal provider failure; this request_id will not execute again.',
		);
	}

	/**
	 * Process the redact ability result operation in the Abilities adapter.
	 *
	 * @param mixed $value Value used by this operation.
	 * @param int $depth Value used by this operation.
	 * @param \SplObjectStorage $seen Value used by this operation.
	 * @param array $budget Value updated by reference.
	 *
	 * @return mixed Result of the operation.
	 */
	private function redactAbilityResult( $value, int $depth, \SplObjectStorage $seen, array &$budget ) {
		if ( ++$budget['nodes'] > 10000 || $depth > 20 ) throw new \RuntimeException( 'WordPress Ability result exceeds the traversal limit.' );
		$object = null;
		if ( is_object( $value ) ) {
			if ( $seen->contains( $value ) ) return '[circular object omitted]';
			$seen->attach( $value );
			$object = $value;
			$value  = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) ) {
					$budget['bytes'] += strlen( $key );
					if ( $budget['bytes'] > 262144 ) throw new \RuntimeException( 'WordPress Ability result exceeds the output limit.' );
				}
				$value[ $key ] = $this->redactAbilityResult( $item, $depth + 1, $seen, $budget );
			}
			$value = Policy::redact( $value );
			if ( null !== $object ) $seen->detach( $object );
			return $value;
		}
		if ( is_string( $value ) ) {
			$budget['bytes'] += strlen( $value );
			if ( $budget['bytes'] > 262144 ) throw new \RuntimeException( 'WordPress Ability result exceeds the output limit.' );
			return $value;
		}
		return is_scalar( $value ) || null === $value ? $value : null;
	}

	/**
	 * Check whether valid name in the Abilities adapter.
	 *
	 * @param string $name Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function isValidName( string $name ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]*\/[a-z0-9][a-z0-9_-]*$/D', $name );
	}

	/**
	 * Process the catalog namespace operation in the Abilities adapter.
	 *
	 * @param mixed $value Value used by this operation.
	 *
	 * @return string|null Result of the operation.
	 */
	private function catalogNamespace( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $value ) ) {
			throw new \RuntimeException( 'namespace must be a valid Ability namespace.' );
		}
		return $value;
	}

	/**
	 * Check whether read eligible in the Abilities adapter.
	 *
	 * @param mixed $ability Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function isReadEligible( $ability ): bool {
		if ( ! is_object( $ability ) || ! $this->isExecutionExposed( $ability ) || ! method_exists( $ability, 'execute' ) ) {
			return false;
		}
		$annotations = $this->safeAnnotations( $ability );
		return true === ( $annotations['readonly'] ?? null ) && false === ( $annotations['destructive'] ?? null );
	}

	/**
	 * Check whether mutation eligible in the Abilities adapter.
	 *
	 * @param mixed $ability Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function isMutationEligible( $ability ): bool {
		if ( ! is_object( $ability ) || ! $this->isExecutionExposed( $ability ) || ! method_exists( $ability, 'execute' ) ) {
			return false;
		}
		$annotations = $this->safeAnnotations( $ability );
		return false === ( $annotations['readonly'] ?? null ) && is_bool( $annotations['destructive'] ?? null );
	}

	/**
	 * Check whether elementor mutation eligible in the Abilities adapter.
	 *
	 * @param string $name Value used by this operation.
	 * @param mixed $ability Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function isElementorMutationEligible( string $name, $ability ): bool {
		if ( ! is_object( $ability ) || ! $this->isElementorAbilityName( $name ) ) {
			return false;
		}

		if ( ! $this->isExplicitMcpPublic( $ability ) || ! $this->isMutationEligible( $ability ) ) {
			return false;
		}

		return $this->isNativeElementorAbilityProvider( $name, $ability );
	}


	/**
     * Other plugins may opt into the native Abilities API, but arbitrary
     * abilities never become callable just because they exist. A server
     * operator must approve the exact ability AND exact active plugin file
     * using a JSON mapping in wp-config.php.
     */
	private function isApprovedPluginMutationEligible( string $name, $ability ): bool {
		if ( $this->isElementorAbilityName( $name ) || ! is_object( $ability )
			|| ! Policy::flag( 'WPCONNECTOR_ALLOW_APPROVED_ABILITY_WRITES' )
			|| ! $this->isExplicitMcpPublic( $ability ) || ! $this->isMutationEligible( $ability ) ) {
			return false;
		}

		$annotations = $this->safeAnnotations( $ability );
		if ( false !== ( $annotations['destructive'] ?? null ) ) {
			return false;
		}

		$approved = $this->approvedAbilityProviders();
		if ( ! isset( $approved[ $name ] ) ) {
			return false;
		}
		$plugin_file = $approved[ $name ];

		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'get_option' ) ) {
			return false;
		}
		$active         = get_option( 'active_plugins', array() );
		$active         = is_array( $active ) ? $active : array();
		$network_active = function_exists( 'get_site_option' )
			? get_site_option( 'active_sitewide_plugins', array() ) : array();
		$network_active = is_array( $network_active ) ? array_keys( $network_active ) : array();
		if ( ! in_array( $plugin_file, $active, true ) && ! in_array( $plugin_file, $network_active, true ) ) {
			return false;
		}

		$plugin_root = rtrim( (string) WP_PLUGIN_DIR, '/\\' );
		$main_file   = realpath( $plugin_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $plugin_file ) );
		if ( ! is_string( $main_file ) || ! is_file( $main_file ) ) {
			return false;
		}
		$callback = $this->abilityExecuteCallback( $ability );
		$file     = $this->callableSourceFile( $callback );
		if ( null === $file ) {
			return false;
		}
		$canonical_callback = realpath( $file );
		$canonical_plugin   = realpath( dirname( $main_file ) );
		return is_string( $canonical_callback ) && is_string( $canonical_plugin )
			&& $this->pathWithinRoot( $canonical_callback, $canonical_plugin );
	}

	/**
	 * Process the approved ability providers operation in the Abilities adapter.
	 *
	 * @return array Result of the operation.
	 */
	private function approvedAbilityProviders(): array {
		$json = defined( 'WPCONNECTOR_APPROVED_ABILITY_PROVIDERS' )
			? constant( 'WPCONNECTOR_APPROVED_ABILITY_PROVIDERS' ) : false;
		if ( ! is_string( $json ) || strlen( $json ) > 8192 ) {
			return array();
		}
		$value = json_decode( $json, true );
		if ( ! is_array( $value ) || count( $value ) > 30 ) {
			return array();
		}
		$approved = array();
		foreach ( $value as $name => $plugin_file ) {
			if ( ! is_string( $name ) || ! $this->isValidName( $name )
				|| ! is_string( $plugin_file )
				|| ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9._-]{0,79}/[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.php\z#D', $plugin_file ) ) {
				return array();
			}
			$approved[ $name ] = $plugin_file;
		}
		return $approved;
	}

	/**
	 * Process the callable source file operation in the Abilities adapter.
	 *
	 * @param mixed $callback Value used by this operation.
	 *
	 * @return string|null Result of the operation.
	 */
	private function callableSourceFile( $callback ): ?string {
		try {
			if ( $callback instanceof \Closure || is_string( $callback ) ) {
				$file = ( new \ReflectionFunction( $callback ) )->getFileName();
			} elseif ( is_array( $callback ) && count( $callback ) === 2 ) {
				$file = ( new \ReflectionMethod( $callback[0], $callback[1] ) )->getFileName();
			} elseif ( is_object( $callback ) && is_callable( $callback ) ) {
				$file = ( new \ReflectionMethod( $callback, '__invoke' ) )->getFileName();
			} else {
				return null;
			}
		} catch ( \Throwable $error ) {
			return null;
		}
		return is_string( $file ) && '' !== $file ? $file : null;
	}

	/**
	 * Check whether elementor ability name in the Abilities adapter.
	 *
	 * @param string $name Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function isElementorAbilityName( string $name ): bool {
		return 0 === strpos( $name, 'elementor/' ) || 0 === strpos( $name, 'elementor-pro/' );
	}

	/**
	 * Check whether explicit mcp public in the Abilities adapter.
	 *
	 * @param object $ability Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function isExplicitMcpPublic( object $ability ): bool {
		$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
		return is_array( $meta )
			&& isset( $meta['mcp'] )
			&& is_array( $meta['mcp'] )
			&& true === ( $meta['mcp']['public'] ?? false );
	}

	/**
	 * Check whether native elementor ability provider in the Abilities adapter.
	 *
	 * @param string $name Value used by this operation.
	 * @param object $ability Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function isNativeElementorAbilityProvider( string $name, object $ability ): bool {
		$callback = $this->abilityExecuteCallback( $ability );
		if ( ! is_array( $callback )
			|| 2 !== count( $callback )
			|| ! is_object( $callback[0] )
			|| 'execute_guarded' !== (string) $callback[1] ) {
			return false;
		}

		$provider = $callback[0];
		if ( ! method_exists( $provider, 'get_id' ) || $name !== (string) $provider->get_id() ) {
			return false;
		}

		try {
			$provider_file = ( new \ReflectionObject( $provider ) )->getFileName();
		} catch ( \Throwable $error ) {
			return false;
		}
		if ( ! is_string( $provider_file ) || '' === $provider_file ) {
			return false;
		}

		$provider_path = realpath( $provider_file );
		if ( false === $provider_path ) {
			return false;
		}

		foreach ( $this->trustedElementorPluginRoots() as $root ) {
			if ( $this->pathWithinRoot( $provider_path, $root ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Process the ability execute callback operation in the Abilities adapter.
	 *
	 * @param object $ability Value used by this operation.
	 *
	 * @return mixed Result of the operation.
	 */
	private function abilityExecuteCallback( object $ability ) {
		try {
			$reflection = new \ReflectionObject( $ability );
			if ( ! $reflection->hasProperty( 'execute_callback' ) ) {
				return null;
			}
			$property = $reflection->getProperty( 'execute_callback' );
			if ( method_exists( $property, 'setAccessible' ) ) {
				$property->setAccessible( true );
			}
			return $property->getValue( $ability );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Process the trusted elementor plugin roots operation in the Abilities adapter.
	 *
	 * @return array Result of the operation.
	 */
	private function trustedElementorPluginRoots(): array {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'get_option' ) ) {
			return array();
		}

		$active = get_option( 'active_plugins', array() );
		$active = is_array( $active ) ? array_values( $active ) : array();

		$network_active = array();
		if ( function_exists( 'get_site_option' ) ) {
			$value          = get_site_option( 'active_sitewide_plugins', array() );
			$network_active = is_array( $value ) ? array_keys( $value ) : array();
		}

		$roots = array();
		foreach ( array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ) as $plugin_file ) {
			if ( ! in_array( $plugin_file, $active, true ) && ! in_array( $plugin_file, $network_active, true ) ) {
				continue;
			}

			$main_file = realpath( rtrim( (string) WP_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $plugin_file ) );
			if ( false === $main_file || ! is_file( $main_file ) ) {
				continue;
			}

			$root = realpath( dirname( $main_file ) );
			if ( false !== $root && ! in_array( $root, $roots, true ) ) {
				$roots[] = $root;
			}
		}

		return $roots;
	}

	/**
	 * Process the path within root operation in the Abilities adapter.
	 *
	 * @param string $file Value used by this operation.
	 * @param string $root Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function pathWithinRoot( string $file, string $root ): bool {
		$root = rtrim( $root, DIRECTORY_SEPARATOR );
		return $file === $root || 0 === strpos( $file, $root . DIRECTORY_SEPARATOR );
	}

	/**
	 * Check whether execution exposed in the Abilities adapter.
	 *
	 * @param object $ability Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function isExecutionExposed( object $ability ): bool {
		$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
		if ( ! is_array( $meta ) ) {
			return false;
		}

		if ( isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) && array_key_exists( 'public', $meta['mcp'] ) ) {
			return true === $meta['mcp']['public'];
		}

		return true === ( $meta['public'] ?? false ) || true === ( $meta['show_in_rest'] ?? false );
	}

	/**
	 * Check whether exposed in the Abilities adapter.
	 *
	 * @param object $ability Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function isExposed( object $ability ): bool {
		$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
		if ( ! is_array( $meta ) ) {
			return false;
		}

		if ( isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) && array_key_exists( 'public', $meta['mcp'] ) ) {
			return true === $meta['mcp']['public'];
		}

		return true === ( $meta['public'] ?? false ) || true === ( $meta['show_in_rest'] ?? false );
	}

	/**
	 * Process the safe annotations operation in the Abilities adapter.
	 *
	 * @param object $ability Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function safeAnnotations( object $ability ): array {
		$meta             = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
		$annotations      = is_array( $meta ) && isset( $meta['annotations'] ) && is_array( $meta['annotations'] )
			? $meta['annotations']
			: array();
		$safe_annotations = array();
		foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $key ) {
			if ( array_key_exists( $key, $annotations ) && ( is_bool( $annotations[ $key ] ) || null === $annotations[ $key ] ) ) {
				$safe_annotations[ $key ] = $annotations[ $key ];
			}
		}
		return $safe_annotations;
	}

	/**
	 * Process the descriptor operation in the Abilities adapter.
	 *
	 * @param string $name Value used by this operation.
	 * @param object $ability Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function descriptor( string $name, object $ability ): array {
		$safe_annotations           = $this->safeAnnotations( $ability );
		$read_eligible              = $this->isReadEligible( $ability );
		$mutation_eligible          = $this->isElementorMutationEligible( $name, $ability );
		$approved_mutation_eligible = $this->isApprovedPluginMutationEligible( $name, $ability );

		list( $input_schema, $input_schema_omitted ) = $this->boundedCatalogValue(
			method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null
		);
		list( $output_schema, $output_schema_omitted ) = $this->boundedCatalogValue(
			method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null
		);

		return array(
			'name'                       => $name,
			'label'                      => $this->boundedCatalogText( method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $name, 512 ),
			'description'                => $this->boundedCatalogText( method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '', 4096 ),
			'category'                   => $this->boundedCatalogText( method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '', 512 ),
			'input_schema'               => $input_schema,
			'input_schema_omitted'       => $input_schema_omitted,
			'output_schema'              => $output_schema,
			'output_schema_omitted'      => $output_schema_omitted,
			'annotations'                => $safe_annotations,
			'execution_exposed'          => $read_eligible,
			'mutation_execution_exposed' => $mutation_eligible || $approved_mutation_eligible,
			'connector_action'           => $read_eligible ? 'wordpress.ability.read'
				: ( $mutation_eligible ? 'wordpress.ability.execute'
					: ( $approved_mutation_eligible ? 'wordpress.ability.execute_approved' : null ) ),
		);
	}

	/**
	 * Process the catalog positive integer operation in the Abilities adapter.
	 *
	 * @param mixed $value Value used by this operation.
	 * @param int $max Value used by this operation.
	 * @param string $field Value used by this operation.
	 *
	 * @return int Result of the operation.
	 */
	private function catalogPositiveInteger( $value, int $max, string $field ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
			throw new \RuntimeException( $field . ' must be a positive integer.' );
		}
		$validated = filter_var( (string) $value, FILTER_VALIDATE_INT );
		if ( false === $validated || $validated < 1 ) {
			throw new \RuntimeException( $field . ' must be a positive integer.' );
		}
		return min( $max, (int) $validated );
	}

	/**
	 * Process the bounded catalog text operation in the Abilities adapter.
	 *
	 * @param string $value Value used by this operation.
	 * @param int $maxBytes Value used by this operation.
	 *
	 * @return string Result of the operation.
	 */
	private function boundedCatalogText( string $value, int $maxBytes ): string {
		if ( strlen( $value ) <= $maxBytes ) {
			return $value;
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $maxBytes, 'UTF-8' );
		}
		$cut = $maxBytes;
		while ( $cut > 0 && isset( $value[ $cut ] ) && ( ord( $value[ $cut ] ) & 0xC0 ) === 0x80 ) {
			--$cut;
		}
		return substr( $value, 0, $cut );
	}

	/**
	 * Process the bounded catalog value operation in the Abilities adapter.
	 *
	 * @param mixed $value Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function boundedCatalogValue( $value ): array {
		$budget = array( 'nodes' => 0, 'bytes' => 0 );
		try {
			$safe = $this->boundedCatalogWalk( $value, 0, new \SplObjectStorage(), $budget );
		} catch ( \RuntimeException $error ) {
			return array( null, true );
		}

		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $safe ) : json_encode( $safe );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 8192 ) {
			return array( null, true );
		}
		return array( $safe, false );
	}

	/**
	 * Process the bounded catalog walk operation in the Abilities adapter.
	 *
	 * @param mixed $value Value used by this operation.
	 * @param int $depth Value used by this operation.
	 * @param \SplObjectStorage $seen Value used by this operation.
	 * @param array $budget Value updated by reference.
	 *
	 * @return mixed Result of the operation.
	 */
	private function boundedCatalogWalk( $value, int $depth, \SplObjectStorage $seen, array &$budget ) {
		if ( ++$budget['nodes'] > 2000 || $depth > 12 ) {
			throw new \RuntimeException( 'catalog value limit' );
		}

		$object = null;
		if ( is_object( $value ) ) {
			if ( $seen->contains( $value ) ) {
				return '[circular object omitted]';
			}
			$seen->attach( $value );
			$object = $value;
			$value  = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) ) {
					$budget['bytes'] += strlen( $key );
				}
				if ( $budget['bytes'] > 8192 ) {
					throw new \RuntimeException( 'catalog value limit' );
				}
				$value[ $key ] = $this->boundedCatalogWalk( $item, $depth + 1, $seen, $budget );
			}
			$value = Policy::redact( $value );
			if ( null !== $object ) {
				$seen->detach( $object );
			}
			return $value;
		}

		if ( is_string( $value ) ) {
			$budget['bytes'] += strlen( $value );
			if ( $budget['bytes'] > 8192 ) {
				throw new \RuntimeException( 'catalog value limit' );
			}
			return $value;
		}

		return is_scalar( $value ) || null === $value ? $value : null;
	}
}
