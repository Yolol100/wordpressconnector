<?php
/**
 * Provides Request behavior for connector request processing.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Runtime;

use RuntimeException;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Json;

require_once __DIR__ . '/trait-requestmethods.php';

/**
 * Provides Request behavior for connector request processing.
 */
final class Request {
	use RequestMethods {
		from_file as fromFile;
		from_array as fromArray;
		dry_run as dryRun;
		expected_fingerprint as expectedFingerprint;
		expected_state_token as expectedStateToken;
	}

	/**
	 * Id maintained by the connector.
	 *
	 * @var string
	 */
	private string $id;
	/**
	 * Action maintained by the connector.
	 *
	 * @var string
	 */
	private string $action;
	/**
	 * Payload maintained by the connector.
	 *
	 * @var array
	 */
	private array $payload;
	/**
	 * Dry run maintained by the connector.
	 *
	 * @var bool
	 */
	private bool $dry_run;
	/**
	 * Confirm maintained by the connector.
	 *
	 * @var bool
	 */
	private bool $confirm;
	/**
	 * Expected fingerprint maintained by the connector.
	 *
	 * @var ?string
	 */
	private ?string $expected_fingerprint;
	/**
	 * Expected state token maintained by the connector.
	 *
	 * @var ?string
	 */
	private ?string $expected_state_token;

	/**
	 * Store an already validated, immutable connector request.
	 *
	 * @param string      $id                  Stable request identifier.
	 * @param string      $action              Registered connector action name.
	 * @param array       $payload             Validated action payload.
	 * @param bool        $dry_run              Whether mutations are disabled.
	 * @param bool        $confirm             Explicit mutation confirmation flag.
	 * @param string|null $expected_fingerprint Expected object-state fingerprint.
	 * @param string|null $expected_state_token  Site-scoped stale-state token.
	 */
	private function __construct(
		string $id,
		string $action,
		array $payload,
		bool $dry_run,
		bool $confirm,
		?string $expected_fingerprint,
		?string $expected_state_token
	) {
		$this->id                   = $id;
		$this->action               = $action;
		$this->payload              = $payload;
		$this->dry_run              = $dry_run;
		$this->confirm              = $confirm;
		$this->expected_fingerprint = $expected_fingerprint;
		$this->expected_state_token = $expected_state_token;
	}

	/**
	 * Process the hex guard operation in the Request component.
	 *
	 * @param array  $data Input for the operation.
	 * @param string $key Input for the operation.
	 *
	 * @return string|null Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private static function hex_guard( array $data, string $key ): ?string {
		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
			return null;
		}
		if ( ! is_string( $data[ $key ] ) || ! preg_match( '/^[a-f0-9]{64}\z/', $data[ $key ] ) ) {
			throw new RuntimeException( esc_html( $key ) . ' must be a SHA-256 hex string.' );
		}
		return $data[ $key ];
	}

	/**
	 * Process the id operation in the Request component.
	 *
	 * @return string Operation result.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Process the action operation in the Request component.
	 *
	 * @return string Operation result.
	 */
	public function action(): string {
		return $this->action;
	}

	/**
	 * Process the payload operation in the Request component.
	 *
	 * @return array Operation result.
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Process the confirm operation in the Request component.
	 *
	 * @return bool Operation result.
	 */
	public function confirm(): bool {
		return $this->confirm;
	}
}
