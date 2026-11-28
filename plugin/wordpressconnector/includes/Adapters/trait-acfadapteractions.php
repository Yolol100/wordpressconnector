<?php
/**
 * Canonical public method implementations for AcfAdapter.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait AcfAdapterActions {
	/**
	 * List the available ACF field groups and their fields.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	public function field_groups( array $payload ): array {
		$this->assert_acf_schema();
		$args = array();
		if ( isset( $payload['post_id'] ) ) {
			$post_id = (int) $payload['post_id'];
			if ( $post_id <= 0 ) {
				throw new RuntimeException( 'acf.field_groups post_id must be a positive integer.' );
			}
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				throw new RuntimeException( 'ACF field-group target post was not found.' );
			}
			if ( Policy::publicRepositoryContext() ) {
				Policy::assertPostReadable( $post );
			}
			$args['post_id'] = $post_id;
		} elseif ( Policy::publicRepositoryContext() ) {
			throw new RuntimeException( 'Public-repository ACF field-group discovery requires payload.post_id.' );
		}

		$groups = acf_get_field_groups( $args );
		$result = array();
		foreach ( (array) $groups as $group ) {
			$fields   = acf_get_fields( $group );
			$result[] = array(
				'key'    => $group['key'] ?? null,
				'title'  => $group['title'] ?? null,
				'fields' => array_map(
					static function ( array $field ): array {
						return array(
							'key'      => $field['key'] ?? null,
							'name'     => $field['name'] ?? null,
							'label'    => $field['label'] ?? null,
							'type'     => $field['type'] ?? null,
							'required' => ! empty( $field['required'] ),
						);
					},
					is_array( $fields ) ? $fields : array()
				),
			);
		}
		return array( 'field_groups' => $result );
	}

	/**
	 * Ensure the requested ACF text fields exist.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	public function ensure_text_fields( array $payload, array $context ): array {
		$this->assert_acf_schema();
		$post_id   = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$group_key = isset( $payload['group_key'] ) ? (string) $payload['group_key'] : '';
		$requested = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? array_values( $payload['fields'] ) : array();
		if ( $post_id <= 0 || ! preg_match( '/^group_[A-Za-z0-9_-]{6,80}\z/', $group_key ) ) {
			throw new RuntimeException( 'acf.schema.ensure_text_fields requires a valid post_id and group_key.' );
		}
		if ( ! $requested || count( $requested ) > 12 ) {
			throw new RuntimeException( 'acf.schema.ensure_text_fields requires 1-12 text field definitions.' );
		}
		$this->assert_group_applies_to_post( $group_key, $post_id );
		$group_id = $this->group_id( $group_key );

		$normalized       = $this->normalize_requested_text_fields( $requested );
		$existing_fields  = $this->group_fields( $group_key );
		$existing_by_key  = array();
		$existing_by_name = array();
		$next_menu_order  = 0;
		foreach ( $existing_fields as $field ) {
			if ( ! empty( $field['key'] ) ) {
				$existing_by_key[ (string) $field['key'] ] = $field;
			}
			if ( ! empty( $field['name'] ) ) {
				$existing_by_name[ (string) $field['name'] ] = $field;
			}
			$next_menu_order = max( $next_menu_order, ( (int) ( $field['menu_order'] ?? -1 ) ) + 1 );
		}

		$before       = array();
		$would_create = array();
		$recoverable  = array();
		foreach ( $normalized as $definition ) {
			$key     = $definition['key'];
			$name    = $definition['name'];
			$by_key  = $existing_by_key[ $key ] ?? null;
			$by_name = $existing_by_name[ $name ] ?? null;
			if ( is_array( $by_key ) ) {
				$summary = $this->field_summary( $by_key );
				if ( ! $this->field_matches_definition( $summary, $definition, $group_id ) ) {
					throw new RuntimeException( 'Existing ACF field key conflicts with requested text field: ' . esc_html( $key ) );
				}
				if ( is_array( $by_name ) && (string) ( $by_name['key'] ?? '' ) !== $key ) {
					throw new RuntimeException( 'Existing ACF field name belongs to a different field: ' . esc_html( $name ) );
				}
				$before[ $key ] = $summary;
				continue;
			}
			if ( is_array( $by_name ) ) {
				throw new RuntimeException( 'Existing ACF field name conflicts with requested text field: ' . esc_html( $name ) );
			}

			$global = acf_get_field( $key );
			if ( is_array( $global ) ) {
				$summary   = $this->field_summary( $global );
				$global_id = isset( $global['ID'] ) ? (int) $global['ID'] : 0;
				if ( $global_id <= 0
				|| 0 !== (int) ( $summary['parent'] ?? 0 )
				|| (string) ( $summary['name'] ?? '' ) !== $name
				|| 'text' !== (string) ( $summary['type'] ?? '' )
				|| ! empty( $summary['required'] ) ) {
					throw new RuntimeException( 'Existing global ACF field conflicts with requested text field: ' . esc_html( $key ) );
				}
				$before[ $key ]      = $summary;
				$recoverable[ $key ] = $global_id;
				$would_create[]      = $definition;
				continue;
			}

			$before[ $key ] = null;
			$would_create[] = $definition;
		}

		$state  = array(
			'post_id'   => $post_id,
			'group_key' => $group_key,
			'fields'    => $before,
		);
		$result = array(
			'post_id'              => $post_id,
			'group_key'            => $group_key,
			'before'               => $before,
			'requested'            => $normalized,
			'would_create'         => array_map(
				static function ( array $field ): string {
					return $field['key'];
				},
				$would_create
			),
			'_current_fingerprint' => Fingerprint::make( $state ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$created = array();
		foreach ( $would_create as $definition ) {
			$field = array(
				'key'               => $definition['key'],
				'label'             => $definition['label'],
				'name'              => $definition['name'],
				'type'              => 'text',
				'instructions'      => '',
				'required'          => 0,
				'conditional_logic' => 0,
				'wrapper'           => array(
					'width' => '',
					'class' => '',
					'id'    => '',
				),
				'default_value'     => '',
				'maxlength'         => '',
				'placeholder'       => '',
				'prepend'           => '',
				'append'            => '',
				'parent'            => $group_id,
				'menu_order'        => $next_menu_order++,
			);
			if ( isset( $recoverable[ $definition['key'] ] ) ) {
				$field['ID'] = (int) $recoverable[ $definition['key'] ];
			}
			$saved = acf_update_field( $field );
			if ( ! is_array( $saved ) ) {
				throw new RuntimeException( 'ACF field creation failed for: ' . esc_html( $definition['key'] ) );
			}
			$readback = acf_get_field( $definition['key'] );
			if ( ! is_array( $readback ) || ! $this->field_matches_definition( $this->field_summary( $readback ), $definition, $group_id ) ) {
				throw new RuntimeException( 'ACF field creation readback failed for: ' . esc_html( $definition['key'] ) );
			}
			$created[] = $definition;
		}

		$after = array();
		foreach ( $normalized as $definition ) {
			$readback                    = acf_get_field( $definition['key'] );
			$after[ $definition['key'] ] = is_array( $readback ) ? $this->field_summary( $readback ) : null;
		}
		$result['after']   = $after;
		$result['created'] = array_map(
			static function ( array $field ): string {
				return $field['key'];
			},
			$created
		);
		if ( $created ) {
			$result['_rollback'] = array(
				'action'  => 'acf.schema.remove_text_fields',
				'payload' => array(
					'post_id'   => $post_id,
					'group_key' => $group_key,
					'fields'    => $created,
				),
			);
		}
		return $result;
	}

	/**
	 * Remove matching connector-managed ACF text fields.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	public function remove_text_fields( array $payload, array $context ): array {
		$this->assert_acf_schema();
		$post_id   = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$group_key = isset( $payload['group_key'] ) ? (string) $payload['group_key'] : '';
		$requested = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? array_values( $payload['fields'] ) : array();
		if ( $post_id <= 0 || ! preg_match( '/^group_[A-Za-z0-9_-]{6,80}\z/', $group_key ) || ! $requested || count( $requested ) > 12 ) {
			throw new RuntimeException( 'acf.schema.remove_text_fields requires a valid post_id, group_key and 1-12 field definitions.' );
		}
		$this->assert_group_applies_to_post( $group_key, $post_id );
		$group_id   = $this->group_id( $group_key );
		$normalized = $this->normalize_requested_text_fields( $requested );

		$before = array();
		foreach ( $normalized as $definition ) {
			$existing = acf_get_field( $definition['key'] );
			if ( ! is_array( $existing ) ) {
				$before[ $definition['key'] ] = null;
				continue;
			}
			$summary = $this->field_summary( $existing );
			if ( ! $this->field_matches_definition( $summary, $definition, $group_id ) ) {
				throw new RuntimeException( 'Refusing to remove an ACF field that no longer matches the rollback definition: ' . esc_html( $definition['key'] ) );
			}
			$before[ $definition['key'] ] = $summary;
		}
		$state  = array(
			'post_id'   => $post_id,
			'group_key' => $group_key,
			'fields'    => $before,
		);
		$result = array(
			'post_id'              => $post_id,
			'group_key'            => $group_key,
			'before'               => $before,
			'_current_fingerprint' => Fingerprint::make( $state ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$removed = array();
		foreach ( $normalized as $definition ) {
			$existing = acf_get_field( $definition['key'] );
			if ( ! is_array( $existing ) ) {
				continue;
			}
			$id = isset( $existing['ID'] ) ? (int) $existing['ID'] : 0;
			if ( $id <= 0 || false === acf_delete_field( $id ) ) {
				throw new RuntimeException( 'ACF field removal failed for: ' . esc_html( $definition['key'] ) );
			}
			$removed[] = $definition['key'];
		}
		$result['removed'] = $removed;
		return $result;
	}

	/**
	 * Remove matching connector-managed complex ACF fields.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	public function remove_fields( array $payload, array $context ): array {
		$this->assert_acf_complex_schema();
		$post_id   = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$group_key = isset( $payload['group_key'] ) ? (string) $payload['group_key'] : '';
		$requested = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? array_values( $payload['fields'] ) : array();
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'post_id', 'group_key', 'fields' ), true ) ) {
				throw new RuntimeException( 'Unknown ACF complex schema rollback property.' );
			}
		}
		if ( $post_id <= 0 || ! preg_match( '/^group_[A-Za-z0-9_-]{6,80}\z/', $group_key ) || ! $requested || count( $requested ) > 12 ) {
			throw new RuntimeException( 'acf.schema.remove_fields requires a valid post_id, group_key and 1-12 field definitions.' );
		}
		$this->assert_group_applies_to_post( $group_key, $post_id );
		$group_id   = $this->group_id( $group_key );
		$total      = 0;
		$keys       = array();
		$normalized = $this->normalize_schema_fields( $requested, 0, $total, $keys );

		$before = array();
		foreach ( $normalized as $definition ) {
			$field = acf_get_field( $definition['key'] );
			if ( ! is_array( $field ) ) {
				$before[ $definition['key'] ] = null;
				continue;
			}
			if ( (int) ( $field['parent'] ?? 0 ) !== $group_id ) {
				throw new RuntimeException( 'Refusing to remove an ACF field that moved to another parent: ' . esc_html( $definition['key'] ) );
			}
			$tree = $this->hydrate_field_tree( $field );
			if ( ! $this->schema_contains( $definition, $tree ) ) {
				throw new RuntimeException( 'Refusing to remove an ACF field that no longer matches the rollback schema: ' . esc_html( $definition['key'] ) );
			}
			$before[ $definition['key'] ] = $tree;
		}

		$state  = array(
			'post_id'   => $post_id,
			'group_key' => $group_key,
			'fields'    => $before,
		);
		$result = array(
			'post_id'              => $post_id,
			'group_key'            => $group_key,
			'before'               => $before,
			'_current_fingerprint' => Fingerprint::make( $state ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$removed = array();
		foreach ( $normalized as $definition ) {
			if ( ! is_array( acf_get_field( $definition['key'] ) ) ) {
				continue;
			}
			$this->delete_field_tree_by_key( $definition['key'] );
			if ( is_array( acf_get_field( $definition['key'] ) ) ) {
				throw new RuntimeException( 'ACF complex field removal readback failed for: ' . esc_html( $definition['key'] ) );
			}
			$removed[] = $definition['key'];
		}
		$result['removed'] = $removed;
		if ( $removed ) {
			$result['_rollback'] = array(
				'action'  => 'acf.schema.ensure_fields',
				'payload' => array(
					'post_id'   => $post_id,
					'group_key' => $group_key,
					'fields'    => $normalized,
				),
			);
		}
		return $result;
	}

	/**
	 * Create an ACF field group using the validated schema.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 * @throws \Throwable When the guarded operation cannot be completed safely.
	 */
	public function create_field_group( array $payload, array $context ): array {
		$this->assert_acf_complex_schema();
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'group', 'fields' ), true ) ) {
				throw new RuntimeException( 'Unknown ACF field-group schema payload property.' );
			}
		}
		$group_input  = isset( $payload['group'] ) && is_array( $payload['group'] ) ? $payload['group'] : array();
		$fields_input = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? array_values( $payload['fields'] ) : array();
		$group        = $this->normalize_field_group( $group_input );
		if ( ! $fields_input || count( $fields_input ) > 20 ) {
			throw new RuntimeException( 'acf.schema.create_field_group requires 1-20 top-level fields.' );
		}
		$total     = 0;
		$keys      = array();
		$fields    = $this->normalize_schema_fields( $fields_input, 0, $total, $keys );
		$group_key = $group['key'];
		if ( is_array( acf_get_field_group( $group_key ) ) ) {
			throw new RuntimeException( 'Refusing to overwrite an existing ACF field group: ' . esc_html( $group_key ) );
		}
		foreach ( $fields as $definition ) {
			$this->assert_field_tree_keys_available( $definition );
		}

		$state  = array(
			'group_key' => $group_key,
			'current'   => null,
		);
		$schema = array(
			'group'  => $group,
			'fields' => $fields,
		);
		$result = array(
			'group_key'            => $group_key,
			'requested_schema'     => $schema,
			'would_create'         => true,
			'_current_fingerprint' => Fingerprint::make( $state ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$import           = $group;
		$import['fields'] = $fields;
		try {
			$saved = acf_import_field_group( $import );
			if ( ! is_array( $saved ) || (int) ( $saved['ID'] ?? 0 ) <= 0 ) {
				throw new RuntimeException( 'ACF field group creation failed: ' . esc_html( $group_key ) );
			}
			$after = $this->field_group_snapshot( $group_key );
			if ( ! is_array( $after ) || ! $this->schema_contains( $schema, $after ) ) {
				throw new RuntimeException( 'ACF field group creation readback did not match the requested schema: ' . esc_html( $group_key ) );
			}
		} catch ( \Throwable $error ) {
			if ( is_array( acf_get_field_group( $group_key ) ) ) {
				acf_delete_field_group( $group_key );
			}
			throw $error;
		}

		$result['after']     = $after;
		$result['created']   = true;
		$result['_rollback'] = array(
			'action'  => 'acf.schema.delete_field_group',
			'payload' => array(
				'group_key'       => $group_key,
				'expected_schema' => $schema,
			),
		);
		return $result;
	}

	/**
	 * Delete a matching connector-managed ACF field group.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	public function delete_field_group( array $payload, array $context ): array {
		$this->assert_acf_complex_schema();
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'group_key', 'expected_schema' ), true ) ) {
				throw new RuntimeException( 'Unknown ACF field-group deletion payload property.' );
			}
		}
		$group_key = isset( $payload['group_key'] ) ? (string) $payload['group_key'] : '';
		$expected  = isset( $payload['expected_schema'] ) && is_array( $payload['expected_schema'] ) ? $payload['expected_schema'] : array();
		if ( ! preg_match( '/^group_[A-Za-z0-9_-]{6,80}\z/', $group_key )
		|| ! isset( $expected['group'], $expected['fields'] )
		|| ! is_array( $expected['group'] )
		|| ! is_array( $expected['fields'] ) ) {
			throw new RuntimeException( 'acf.schema.delete_field_group requires group_key and expected_schema.' );
		}
		$group = $this->normalize_field_group( $expected['group'] );
		if ( $group['key'] !== $group_key ) {
			throw new RuntimeException( 'ACF field-group deletion schema key does not match group_key.' );
		}
		$total               = 0;
		$keys                = array();
		$fields              = $this->normalize_schema_fields( array_values( $expected['fields'] ), 0, $total, $keys );
		$normalized_expected = array(
			'group'  => $group,
			'fields' => $fields,
		);
		$current             = $this->field_group_snapshot( $group_key );
		if ( is_array( $current ) && ! $this->schema_contains( $normalized_expected, $current ) ) {
			throw new RuntimeException( 'Refusing to delete an ACF field group that no longer matches the expected schema: ' . esc_html( $group_key ) );
		}

		$result = array(
			'group_key'            => $group_key,
			'before'               => $current,
			'_current_fingerprint' => Fingerprint::make(
				array(
					'group_key' => $group_key,
					'current'   => $current,
				)
			),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		if ( ! is_array( $current ) ) {
			$result['deleted'] = false;
			return $result;
		}
		if ( ! acf_delete_field_group( $group_key ) || is_array( acf_get_field_group( $group_key ) ) ) {
			throw new RuntimeException( 'ACF field group deletion readback failed: ' . esc_html( $group_key ) );
		}
		$result['deleted']   = true;
		$result['_rollback'] = array(
			'action'  => 'acf.schema.create_field_group',
			'payload' => $normalized_expected,
		);
		return $result;
	}

	/**
	 * Preserve the legacy PHP entrypoint; delegate to the canonical implementation.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 * @return array Result of the operation.
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 * @throws \Throwable When the guarded operation cannot be completed safely.
	 */
	private function ensure_fields_compat( array $payload, array $context ): array {
		return $this->ensure_fields( $payload, $context );
	}
}
