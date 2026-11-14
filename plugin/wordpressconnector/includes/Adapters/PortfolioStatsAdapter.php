<?php
/**
 * Registers and handles Portfolio Stats connector actions.
 *
 * @package WordPressConnector
 */

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Registers and handles Portfolio Stats connector actions.
 */
final class PortfolioStatsAdapter {
	private const ALLOWED_FIELD_NAMES = array(
		'portfolio_stat_1_value',
		'portfolio_stat_1_label',
		'portfolio_stat_2_value',
		'portfolio_stat_2_label',
		'portfolio_stat_3_value',
		'portfolio_stat_3_label',
		'portfolio_stat_4_value',
		'portfolio_stat_4_label',
	);

	private const ALLOWED_POST_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Register the PortfolioStats adapter actions and handlers.
	 *
	 * @param Registry $registry Action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register( 'acf.portfolio_stats_update', array( $this, 'update' ), array(
			'mutation' => true,
			'privileged' => true,
			'description' => 'Update only the eight existing portfolio-stat ACF text fields on one standard WordPress post, including unpublished targets.',
		) );
		$registry->register( 'portfolio.case_text_update', array( $this, 'updateCaseText' ), array(
			'mutation' => true,
			'privileged' => true,
			'description' => 'Update only portfolio intro content plus description_1 and description_2 on one standard WordPress post, including unpublished targets.',
		) );
	}


	/**
	 * Update case text for the PortfolioStats adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 * @throws \Throwable If the operation cannot be completed safely.
	 */
	public function updateCaseText( array $payload, array $context ): array {
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'post_id', 'content', 'description_1', 'description_2' ), true ) ) {
				throw new RuntimeException( 'Portfolio case text update contains unsupported payload key: ' . (string) $key );
			}
		}
		if ( ! isset( $payload['post_id'] ) || ! is_int( $payload['post_id'] ) || $payload['post_id'] <= 0 ) {
			throw new RuntimeException( 'Portfolio case text update requires a positive integer post_id.' );
		}
		$post_id = $payload['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Portfolio case text target post was not found.' );
		}
		if ( 'post' !== (string) $post->post_type || ! in_array( (string) $post->post_status, self::ALLOWED_POST_STATUSES, true ) ) {
			throw new RuntimeException( 'Portfolio case text target must be a normal WordPress post in an allowed content status.' );
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			throw new RuntimeException( 'Current user lacks permission to edit the portfolio case text target.' );
		}

		$requested = array();
		if ( array_key_exists( 'content', $payload ) ) {
			$value = $payload['content'];
			if ( ! is_string( $value ) || strlen( $value ) > 12000 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
				throw new RuntimeException( 'Portfolio case content must be text up to 12000 bytes without control characters.' );
			}
			$requested['content'] = $value;
		}

		$field_map        = array(
			'description_1' => 'field_69deb8ddbcd8d',
			'description_2' => 'field_69deb8e4bcd8e',
		);
		$description_keys = array_intersect( array_keys( $field_map ), array_keys( $payload ) );
		if ( $description_keys ) {
			foreach ( array( 'acf_get_field_groups', 'acf_get_field', 'get_field', 'update_field' ) as $function ) {
				if ( ! function_exists( $function ) ) {
					throw new RuntimeException( 'Portfolio case text ACF API is unavailable: ' . $function );
				}
			}
			$allowed_parents = array();
			foreach ( (array) acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
				if ( ! is_array( $group ) ) continue;
				if ( ! empty( $group['key'] ) ) $allowed_parents[(string) $group['key']] = true;
				if ( ! empty( $group['ID'] ) ) $allowed_parents[(string) $group['ID']] = true;
			}
			if ( ! $allowed_parents ) {
				throw new RuntimeException( 'No ACF field group applies to the portfolio case text target.' );
			}
			foreach ( $description_keys as $name ) {
				$value = $payload[ $name ];
				if ( ! is_string( $value ) || strlen( $value ) > 5000 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) || false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) {
					throw new RuntimeException( 'Portfolio case descriptions must be plain text up to 5000 bytes without HTML or control characters.' );
				}
				$field_key = $field_map[ $name ];
				$field     = acf_get_field( $field_key );
				if ( ! is_array( $field ) || (string) ( $field['name'] ?? '' ) !== $name || ! in_array( (string) ( $field['type'] ?? '' ), array( 'text','textarea','wysiwyg' ), true ) || ! isset( $allowed_parents[(string) ( $field['parent'] ?? '' )] ) ) {
					throw new RuntimeException( 'Portfolio case description field is missing or outside the target field group: ' . $name );
				}
				$requested[ $name ] = $value;
			}
		}
		if ( ! $requested ) {
			throw new RuntimeException( 'Portfolio case text update requires content and/or description fields.' );
		}

		$before = array();
		foreach ( $requested as $name => $value ) {
			if ( 'content' === $name ) {
				$before[ $name ] = (string) $post->post_content;
			} else {
				$before[ $name ] = get_field( $field_map[ $name ], $post_id, false );
			}
		}
		$result = array( 'post_id' => $post_id, 'before' => $before, 'after' => $requested, '_current_fingerprint' => Fingerprint::make( $before ) );
		if ( ! empty( $context['dry_run'] ) ) return $result;

		try {
			if ( array_key_exists( 'content', $requested ) ) {
				$updated = wp_update_post( array( 'ID' => $post_id, 'post_content' => $requested['content'] ), true );
				if ( is_wp_error( $updated ) ) throw new RuntimeException( 'Portfolio case content update failed: ' . $updated->get_error_message() );
			}
			foreach ( $description_keys as $name ) {
				$field_key = $field_map[ $name ];
				if ( false === update_field( $field_key, $requested[ $name ], $post_id ) && get_field( $field_key, $post_id, false ) !== $requested[ $name ] ) {
					throw new RuntimeException( 'Portfolio case ACF update failed for: ' . $name );
				}
			}
			$after_post = get_post( $post_id );
			$after      = array();
			foreach ( $requested as $name => $value ) {
				$actual = 'content' === $name ? ( $after_post instanceof \WP_Post ? (string) $after_post->post_content : null ) : get_field( $field_map[ $name ], $post_id, false );
				if ( $actual !== $value ) throw new RuntimeException( 'Portfolio case readback mismatch for: ' . $name );
				$after[ $name ] = $actual;
			}
			$result['after'] = $after;
		} catch ( Throwable $error ) {
			$failures = array();
			if ( array_key_exists( 'content', $before ) ) {
				$restore       = wp_update_post( array( 'ID' => $post_id, 'post_content' => $before['content'] ), true );
				$restored_post = get_post( $post_id );
				if ( is_wp_error( $restore ) || ! $restored_post instanceof \WP_Post || (string) $restored_post->post_content !== $before['content'] ) $failures[] = 'content';
			}
			foreach ( $description_keys as $name ) {
				$field_key = $field_map[ $name ];
				update_field( $field_key, $before[ $name ], $post_id );
				if ( get_field( $field_key, $post_id, false ) !== $before[ $name ] ) $failures[] = $name;
			}
			if ( $failures ) throw new RuntimeException( 'Portfolio case compensation failed for: ' . implode( ', ', $failures ) . '. Original error: ' . $error->getMessage(), 0, $error );
			throw $error;
		}

		$rollback_payload = array( 'post_id' => $post_id );
		foreach ( $before as $name => $value ) $rollback_payload[ $name ] = $value;
		$result['_rollback'] = array( 'action' => 'portfolio.case_text_update', 'payload' => $rollback_payload );
		return $result;
	}


	/**
	 * Process the update operation in the PortfolioStats adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 * @throws \Throwable If the operation cannot be completed safely.
	 */
	public function update( array $payload, array $context ): array {
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'post_id', 'fields' ), true ) ) {
				throw new RuntimeException( 'Portfolio stats update contains unsupported payload key: ' . (string) $key );
			}
		}

		if ( ! isset( $payload['post_id'] ) || ! is_int( $payload['post_id'] ) || $payload['post_id'] <= 0 ) {
			throw new RuntimeException( 'Portfolio stats update requires a positive integer post_id.' );
		}
		$post_id = $payload['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Portfolio stats target post was not found.' );
		}
		if ( 'post' !== (string) $post->post_type ) {
			throw new RuntimeException( 'Portfolio stats public exception is limited to the standard WordPress post type.' );
		}
		if ( ! in_array( (string) $post->post_status, self::ALLOWED_POST_STATUSES, true ) ) {
			throw new RuntimeException( 'Portfolio stats target has an unsupported post status.' );
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			throw new RuntimeException( 'Current user lacks permission to edit the portfolio stats target post.' );
		}

		foreach ( array( 'acf_get_field_groups', 'acf_get_field', 'get_field', 'update_field' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				throw new RuntimeException( 'Portfolio stats ACF API is unavailable: ' . $function );
			}
		}

		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		if ( ! $fields || count( $fields ) > 8 || array_keys( $fields ) === range( 0, count( $fields ) - 1 ) ) {
			throw new RuntimeException( 'Portfolio stats update requires a 1-8 field object.' );
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
		if ( ! $allowed_parents ) {
			throw new RuntimeException( 'No ACF field group applies to the portfolio stats target post.' );
		}

		$before     = array();
		$seen_names = array();
		foreach ( $fields as $field_key => $value ) {
			$field_key = (string) $field_key;
			if ( ! preg_match( '/^field_[A-Za-z0-9_-]{6,80}\z/', $field_key ) ) {
				throw new RuntimeException( 'Portfolio stats update requires ACF field keys, not field names.' );
			}
			Policy::assertKeyAllowed( $field_key );
			if ( ! is_string( $value ) || strlen( $value ) > 1000 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
				throw new RuntimeException( 'Portfolio stats values must be text strings up to 1000 bytes without control characters.' );
			}
			$field = acf_get_field( $field_key );
			if ( ! is_array( $field ) || 'text' !== (string) ( $field['type'] ?? '' ) ) {
				throw new RuntimeException( 'Portfolio stats field is missing or is not a text field: ' . $field_key );
			}
			$name   = (string) ( $field['name'] ?? '' );
			$parent = (string) ( $field['parent'] ?? '' );
			if ( ! in_array( $name, self::ALLOWED_FIELD_NAMES, true ) ) {
				throw new RuntimeException( 'Field is outside the fixed portfolio stats allowlist: ' . $field_key );
			}
			if ( isset( $seen_names[ $name ] ) ) {
				throw new RuntimeException( 'Duplicate portfolio stats field name in one request: ' . $name );
			}
			if ( ! isset( $allowed_parents[ $parent ] ) ) {
				throw new RuntimeException( 'Portfolio stats field does not belong to a field group for the target post: ' . $field_key );
			}
			Policy::assertKeyAllowed( $name );
			$seen_names[ $name ]  = true;
			$before[ $field_key ] = get_field( $field_key, $post_id, false );
		}

		$result = array(
			'post_id' => $post_id,
			'before' => $before,
			'after' => $fields,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		try {
			foreach ( $fields as $field_key => $value ) {
				if ( false === update_field( (string) $field_key, $value, $post_id ) ) {
					$current = get_field( (string) $field_key, $post_id, false );
					if ( $current !== $value ) {
						throw new RuntimeException( 'Portfolio stats ACF update failed for field: ' . (string) $field_key );
					}
				}
			}

			$after = array();
			foreach ( $fields as $field_key => $value ) {
				$actual = get_field( (string) $field_key, $post_id, false );
				if ( $actual !== $value ) {
					throw new RuntimeException( 'Portfolio stats readback mismatch for field: ' . (string) $field_key );
				}
				$after[(string) $field_key] = $actual;
			}
			$result['after'] = $after;
		} catch ( Throwable $error ) {
			$restore_failures = array();
			foreach ( $before as $field_key => $old_value ) {
				update_field( (string) $field_key, $old_value, $post_id );
				$restored = get_field( (string) $field_key, $post_id, false );
				if ( $restored !== $old_value ) {
					$restore_failures[] = (string) $field_key;
				}
			}
			if ( $restore_failures ) {
				throw new RuntimeException(
					'Portfolio stats compensation readback failed for: ' . implode( ', ', $restore_failures ) . '. Original error: ' . $error->getMessage(),
					0,
					$error
				);
			}
			throw $error;
		}

		$result['_rollback'] = array(
			'action' => 'acf.portfolio_stats_update',
			'payload' => array( 'post_id' => $post_id, 'fields' => $before ),
		);
		return $result;
	}
}
