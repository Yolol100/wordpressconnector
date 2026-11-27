<?php
/**
 * Canonical public method implementations for AbilitiesAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use Webactueel\WordPressConnector\Security\Policy;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait AbilitiesAdapterActions {
	/**
	 * Execute the authorized WordPress ability.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 */
	public function execute_ability( array $payload, array $context ): array {
		return $this->execute_mutation( $payload, $context, false );
	}

	/**
	 * Execute a provider-approved WordPress ability after authorization.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 */
	public function execute_approved_ability( array $payload, array $context ): array {
		return $this->execute_mutation( $payload, $context, true );
	}

	/**
	 * Read the requested WordPress ability without modifying state.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 */
	public function read_ability( array $payload, array $context ): array {
		// Registry supplies execution context; this read does not consume it.
		unset( $context );

		list( $name, $ability ) = $this->resolve_ability( $payload, false );
		$input                  = array_key_exists( 'input', $payload ) ? $payload['input'] : null;
		return $this->execute_and_sanitize( $name, $ability, $input, $this->safe_annotations( $ability ), false );
	}
}
