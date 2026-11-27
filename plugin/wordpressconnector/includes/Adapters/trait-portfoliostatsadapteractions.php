<?php
/**
 * Backward-compatible method aliases for PortfolioStatsAdapter.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

/**
 * Delegation-only legacy entrypoints; implementations remain in the host.
 */
trait PortfolioStatsAdapterActions {

	/**
	 * Preserve the legacy PHP entrypoint; delegate to the canonical implementation.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 * @return array Operation result.
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 * @throws \Throwable If the operation cannot be completed safely.
	 */
	private function update_case_text_compat( array $payload, array $context ): array {
		return $this->update_case_text( $payload, $context );
	}
}
