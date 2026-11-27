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

/**
 * Provides Request behavior for connector request processing.
 */
final class Request {
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
	 * Process the from file operation in the Request component.
	 *
	 * @param string $path Input for the operation.
	 *
	 * @return self Operation result.
	 */
	public static function fromFile( string $path ): self {
		return self::fromArray( Json::readFile( $path ) );
	}

	/**
	 * Process the from array operation in the Request component.
	 *
	 * @param array $data Input for the operation.
	 *
	 * @return self Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function fromArray( array $data ): self {
		$allowed = array(
			'version',
			'request_id',
			'action',
			'dry_run',
			'confirm',
			'expected_fingerprint',
			'expected_state_token',
			'payload',
		);
		foreach ( array_keys( $data ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				throw new RuntimeException( 'Unknown top-level request key.' );
			}
		}

		if ( ! array_key_exists( 'version', $data ) || 1 !== $data['version'] ) {
			throw new RuntimeException( 'version must be integer 1.' );
		}

		if ( ! array_key_exists( 'request_id', $data ) || ! is_string( $data['request_id'] ) ) {
			throw new RuntimeException( 'request_id must be a string.' );
		}
		$id = $data['request_id'];
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $id ) ) {
			throw new RuntimeException( 'request_id must be 8-100 safe characters.' );
		}

		if ( ! array_key_exists( 'action', $data ) || ! is_string( $data['action'] ) ) {
			throw new RuntimeException( 'action must be a string.' );
		}
		$action = $data['action'];
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*\z/', $action ) ) {
			throw new RuntimeException( 'action is invalid.' );
		}

		if ( ! array_key_exists( 'payload', $data ) || ! is_array( $data['payload'] ) ) {
			throw new RuntimeException( 'payload must be an object.' );
		}
		$payload = $data['payload'];

		$dry_run = true;
		if ( array_key_exists( 'dry_run', $data ) ) {
			if ( ! is_bool( $data['dry_run'] ) ) {
				throw new RuntimeException( 'dry_run must be boolean.' );
			}
			$dry_run = $data['dry_run'];
		}

		$confirm = false;
		if ( array_key_exists( 'confirm', $data ) ) {
			if ( ! is_bool( $data['confirm'] ) ) {
				throw new RuntimeException( 'confirm must be boolean.' );
			}
			$confirm = $data['confirm'];
		}

		$expected_fingerprint = self::hex_guard( $data, 'expected_fingerprint' );
		$expected_state_token = self::hex_guard( $data, 'expected_state_token' );

		if ( class_exists( Policy::class ) && Policy::publicRepositoryContext() ) {
			if ( 'connector.read_batch' === $action ) {
				$operations = isset( $payload['operations'] ) && is_array( $payload['operations'] ) ? $payload['operations'] : array();
				$safe_reads = array( 'post.list', 'post.get', 'acf.field_groups', 'elementor.inspect', 'connector.update.check', 'maintenance.cache_capabilities', 'custom_css.inspect' );
				if ( array_keys( $payload ) !== array( 'operations' ) || ! $operations || count( $operations ) > 25 ) {
					throw new RuntimeException( 'Public read batch requires 1-25 operations.' );
				}
				foreach ( array_values( $operations ) as $index => $operation ) {
					$leaf = is_array( $operation ) && isset( $operation['action'] ) && is_string( $operation['action'] ) ? $operation['action'] : '';
					if ( ! in_array( $leaf, $safe_reads, true ) || ! isset( $operation['payload'] ) || ! is_array( $operation['payload'] )
					|| array_diff( array_keys( $operation ), array( 'action', 'payload' ) ) ) {
						throw new RuntimeException( 'Unsafe public read batch operation at index ' . (int) $index . '.' );
					}
				}
			}

			if ( 'connector.batch' === $action ) {
				$operations = isset( $payload['operations'] ) && is_array( $payload['operations'] ) ? $payload['operations'] : array();
				foreach ( array_values( $operations ) as $index => $operation ) {
					$leaf_action = is_array( $operation ) && isset( $operation['action'] ) && is_string( $operation['action'] ) ? $operation['action'] : '';
					if ( ! in_array( $leaf_action, array( 'post.update', 'acf.update' ), true ) ) {
						throw new RuntimeException( 'Public connector.batch operation is not allowed at index ' . (int) $index . '.' );
					}
				}
			}

			$fingerprint_required = array(
				'acf.schema.ensure_text_fields',
				'elementor.patch_element',
				'connector.update.apply',
				'plugin.install_package',
			);
			if ( ! $dry_run && in_array( $action, $fingerprint_required, true ) && null === $expected_fingerprint ) {
				throw new RuntimeException( 'Confirmed public ' . $action . ' requires expected_fingerprint from the preceding dry-run.' );
			}
		}

		return new self(
			$id,
			$action,
			$payload,
			$dry_run,
			$confirm,
			$expected_fingerprint,
			$expected_state_token
		);
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
			throw new RuntimeException( $key . ' must be a SHA-256 hex string.' );
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
	 * Process the dry run operation in the Request component.
	 *
	 * @return bool Operation result.
	 */
	public function dryRun(): bool {
		return $this->dry_run;
	}

	/**
	 * Process the confirm operation in the Request component.
	 *
	 * @return bool Operation result.
	 */
	public function confirm(): bool {
		return $this->confirm;
	}

	/**
	 * Process the expected fingerprint operation in the Request component.
	 *
	 * @return string|null Operation result.
	 */
	public function expectedFingerprint(): ?string {
		return $this->expected_fingerprint;
	}

	/**
	 * Process the expected state token operation in the Request component.
	 *
	 * @return string|null Operation result.
	 */
	public function expectedStateToken(): ?string {
		return $this->expected_state_token;
	}
}
