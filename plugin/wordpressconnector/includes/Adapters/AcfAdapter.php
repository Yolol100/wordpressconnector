<?php
/**
 * Registers and handles Acf connector actions.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Registers and handles Acf connector actions.
 */
final class AcfAdapter {
	/**
	 * Register the ACF adapter actions and handlers.
	 *
	 * @param Registry $registry Connector action registry.
	 */
	public function register( Registry $registry ): void {
		$registry->register(
			'acf.get',
			array( $this, 'get' ),
			array(
				'privileged'  => true,
				'description' => 'Read ACF values for a post, term, user or options target.',
			)
		);
		$registry->register(
			'acf.update',
			array( $this, 'update' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Update one or more ACF fields through update_field().',
			)
		);
		$registry->register(
			'acf.delete',
			array( $this, 'delete' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'description' => 'Delete one or more ACF field values through delete_field().',
			)
		);
		$registry->register(
			'acf.field_groups',
			array( $this, 'fieldGroups' ),
			array(
				'privileged'             => true,
				'public_repository_safe' => true,
				'capability'             => 'manage_options',
				'description'            => 'List active ACF field groups and fields for discovery.',
			)
		);
		$registry->register(
			'acf.schema.ensure_text_fields',
			array( $this, 'ensureTextFields' ),
			array(
				'mutation'               => true,
				'privileged'             => true,
				'public_repository_safe' => true,
				'capability'             => 'manage_options',
				'description'            => 'Create a bounded set of new ACF text fields in an existing field group without altering existing fields.',
			)
		);
		$registry->register(
			'acf.schema.remove_text_fields',
			array( $this, 'removeTextFields' ),
			array(
				'mutation'               => true,
				'privileged'             => true,
				'public_repository_safe' => true,
				'capability'             => 'manage_options',
				'description'            => 'Remove exact ACF text fields created by a prior bounded schema ensure operation.',
			)
		);
		$registry->register(
			'acf.schema.ensure_fields',
			array( $this, 'ensureFields' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'capability'  => 'manage_options',
				'description' => 'Create bounded complex ACF fields in an existing field group without altering existing fields.',
			)
		);
		$registry->register(
			'acf.schema.remove_fields',
			array( $this, 'removeFields' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'capability'  => 'manage_options',
				'description' => 'Remove exact complex ACF fields created by a prior bounded schema ensure operation.',
			)
		);
		$registry->register(
			'acf.schema.create_field_group',
			array( $this, 'createFieldGroup' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'capability'  => 'manage_options',
				'description' => 'Create a new bounded ACF field group with nested fields and location rules.',
			)
		);
		$registry->register(
			'acf.schema.delete_field_group',
			array( $this, 'deleteFieldGroup' ),
			array(
				'mutation'    => true,
				'privileged'  => true,
				'capability'  => 'manage_options',
				'description' => 'Delete an exact connector-created ACF field group only when its schema still matches.',
			)
		);
	}

	/**
	 * Retrieve values for the requested target through the ACF adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Result of the operation.
	 */
	public function get( array $payload ): array {
		$this->assert_acf();
		$target = $this->target( $payload );
		$fields = get_fields( $target, false );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		if ( isset( $payload['fields'] ) && is_array( $payload['fields'] ) ) {
			$selected = array();
			foreach ( $payload['fields'] as $field ) {
				$key              = (string) $field;
				$selected[ $key ] = get_field( $key, $target, false );
			}
			$fields = $selected;
		}

		return array(
			'target'      => $target,
			'fields'      => $fields,
			'fingerprint' => Fingerprint::make( $fields ),
		);
	}

	/**
	 * Update the requested values through the ACF adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	public function update( array $payload, array $context ): array {
		$this->assert_acf();
		$target = $this->target( $payload );
		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		if ( ! $fields ) {
			throw new RuntimeException( 'acf.update requires payload.fields.' );
		}

		$before = array();
		foreach ( $fields as $field => $value ) {
			$before[ (string) $field ] = get_field( (string) $field, $target, false );
		}

		$result = array(
			'target'               => $target,
			'before'               => $before,
			'after'                => $fields,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);

		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		foreach ( $fields as $field => $value ) {
			if ( false === update_field( (string) $field, $value, $target ) ) {
				$current = get_field( (string) $field, $target, false );
				if ( $current !== $value ) {
					throw new RuntimeException( 'ACF update failed for a requested field.' );
				}
			}
		}

		$result['after'] = array();
		foreach ( $fields as $field => $value ) {
			$result['after'][ (string) $field ] = get_field( (string) $field, $target, false );
		}
		$result['_rollback'] = array(
			'action'  => 'acf.update',
			'payload' => array(
				'target' => $target,
				'fields' => $before,
			),
		);
		return $result;
	}

	/**
	 * Delete the requested values through the ACF adapter.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	public function delete( array $payload, array $context ): array {
		$this->assert_acf();
		$target = $this->target( $payload );
		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? array_values( $payload['fields'] ) : array();
		if ( ! $fields ) {
			throw new RuntimeException( 'acf.delete requires payload.fields.' );
		}

		$before = array();
		foreach ( $fields as $field ) {
			$before[ (string) $field ] = get_field( (string) $field, $target, false );
		}
		$result = array(
			'target'               => $target,
			'before'               => $before,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		foreach ( $fields as $field ) {
			delete_field( (string) $field, $target );
		}
		$result['_rollback'] = array(
			'action'  => 'acf.update',
			'payload' => array(
				'target' => $target,
				'fields' => $before,
			),
		);
		return $result;
	}

	/**
	 * List the available ACF field groups and their fields.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	public function fieldGroups( array $payload ): array {
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
	public function ensureTextFields( array $payload, array $context ): array {
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
			'would_create'         => array_values(
				array_map(
					static function ( array $field ): string {
						return $field['key']; },
					$would_create
				)
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
		$result['created'] = array_values(
			array_map(
				static function ( array $field ): string {
					return $field['key'];
				},
				$created
			)
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
	public function removeTextFields( array $payload, array $context ): array {
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
	 * Ensure the requested complex ACF fields exist.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 * @throws \Throwable When the guarded operation cannot be completed safely.
	 */
	public function ensureFields( array $payload, array $context ): array {
		$this->assert_acf_complex_schema();
		$post_id   = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$group_key = isset( $payload['group_key'] ) ? (string) $payload['group_key'] : '';
		$requested = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? array_values( $payload['fields'] ) : array();
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, array( 'post_id', 'group_key', 'fields' ), true ) ) {
				throw new RuntimeException( 'Unknown ACF complex schema payload property.' );
			}
		}
		if ( $post_id <= 0 || ! preg_match( '/^group_[A-Za-z0-9_-]{6,80}\z/', $group_key ) ) {
			throw new RuntimeException( 'acf.schema.ensure_fields requires a valid post_id and group_key.' );
		}
		if ( ! $requested || count( $requested ) > 12 ) {
			throw new RuntimeException( 'acf.schema.ensure_fields requires 1-12 top-level field definitions.' );
		}
		$this->assert_group_applies_to_post( $group_key, $post_id );
		$group_id         = $this->group_id( $group_key );
		$total            = 0;
		$keys             = array();
		$normalized       = $this->normalize_schema_fields( $requested, 0, $total, $keys );
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
		foreach ( $normalized as $definition ) {
			$key     = $definition['key'];
			$name    = $definition['name'];
			$by_key  = $existing_by_key[ $key ] ?? null;
			$by_name = $existing_by_name[ $name ] ?? null;
			if ( is_array( $by_key ) ) {
				if ( (int) ( $by_key['parent'] ?? 0 ) !== $group_id ) {
					throw new RuntimeException( 'Existing ACF field key belongs to a different parent: ' . esc_html( $key ) );
				}
				$tree = $this->hydrate_field_tree( $by_key );
				if ( ! $this->schema_contains( $definition, $tree ) ) {
					throw new RuntimeException( 'Existing ACF field key conflicts with requested complex field: ' . esc_html( $key ) );
				}
				if ( is_array( $by_name ) && (string) ( $by_name['key'] ?? '' ) !== $key ) {
					throw new RuntimeException( 'Existing ACF field name belongs to a different field: ' . esc_html( $name ) );
				}
				$before[ $key ] = $tree;
				continue;
			}
			if ( is_array( $by_name ) ) {
				throw new RuntimeException( 'Existing ACF field name conflicts with requested complex field: ' . esc_html( $name ) );
			}
			$this->assert_field_tree_keys_available( $definition );
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
			'requested'            => $normalized,
			'before'               => $before,
			'would_create'         => array_values(
				array_map(
					static function ( array $field ): string {
						return $field['key']; },
					$would_create
				)
			),
			'_current_fingerprint' => Fingerprint::make( $state ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$created       = array();
		$created_nodes = array();
		try {
			foreach ( $would_create as $definition ) {
				$this->create_field_tree( $definition, $group_id, $next_menu_order++, $created_nodes );
				$created[] = $definition;
			}
			$after = array();
			foreach ( $normalized as $definition ) {
				$field = acf_get_field( $definition['key'] );
				if ( ! is_array( $field ) ) {
					throw new RuntimeException( 'ACF complex field readback failed for: ' . esc_html( $definition['key'] ) );
				}
				$tree = $this->hydrate_field_tree( $field );
				if ( ! $this->schema_contains( $definition, $tree ) ) {
					throw new RuntimeException( 'ACF complex field readback did not match requested schema: ' . esc_html( $definition['key'] ) );
				}
				$after[ $definition['key'] ] = $tree;
			}
		} catch ( \Throwable $error ) {
			$cleanup_failures = $this->cleanup_created_field_nodes( $created_nodes );
			if ( $cleanup_failures ) {
				throw new RuntimeException(
					'Compensation failed for created ACF fields: ' . esc_html( implode( ', ', $cleanup_failures ) ),
					0,
					$error
				);
			}
			throw $error;
		}

		$result['after']   = $after;
		$result['created'] = array_values(
			array_map(
				static function ( array $field ): string {
					return $field['key'];
				},
				$created
			)
		);
		if ( $created ) {
			$result['_rollback'] = array(
				'action'  => 'acf.schema.remove_fields',
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
	 * Remove matching connector-managed complex ACF fields.
	 *
	 * @param array $payload Requested action payload.
	 * @param array $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	public function removeFields( array $payload, array $context ): array {
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
	public function createFieldGroup( array $payload, array $context ): array {
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
	public function deleteFieldGroup( array $payload, array $context ): array {
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
	 * Ensure acf in the ACF adapter.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function assert_acf(): void {
		if ( ! function_exists( 'get_fields' ) || ! function_exists( 'update_field' ) ) {
			throw new RuntimeException( 'ACF is not active.' );
		}
	}

	/**
	 * Ensure acf schema in the ACF adapter.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function assert_acf_schema(): void {
		$this->assert_acf();
		foreach ( array( 'acf_get_field_groups', 'acf_get_fields', 'acf_get_field_group', 'acf_get_field', 'acf_update_field', 'acf_delete_field' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				throw new RuntimeException( 'ACF schema API is unavailable: ' . esc_html( $function ) );
			}
		}
	}

	/**
	 * Ensure group applies to post in the ACF adapter.
	 *
	 * @param string $group_key Field or lookup key.
	 * @param int    $post_id Identifier of the requested object.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function assert_group_applies_to_post( string $group_key, int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'ACF schema target post was not found.' );
		}
		if ( Policy::publicRepositoryContext() ) {
			Policy::assertPostReadable( $post );
		}
		$group = acf_get_field_group( $group_key );
		if ( ! is_array( $group ) || empty( $group['active'] ) ) {
			throw new RuntimeException( 'ACF field group is missing or inactive: ' . esc_html( $group_key ) );
		}
		$applies = false;
		foreach ( (array) acf_get_field_groups( array( 'post_id' => $post_id ) ) as $candidate ) {
			if ( is_array( $candidate ) && (string) ( $candidate['key'] ?? '' ) === $group_key ) {
				$applies = true;
				break;
			}
		}
		if ( ! $applies ) {
			throw new RuntimeException( 'ACF field group does not apply to the target post.' );
		}
	}

	/**
	 * Process the group id operation in the ACF adapter.
	 *
	 * @param string $group_key Field or lookup key.
	 *
	 * @return int Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function group_id( string $group_key ): int {
		$group    = acf_get_field_group( $group_key );
		$group_id = is_array( $group ) && isset( $group['ID'] ) ? (int) $group['ID'] : 0;
		if ( $group_id <= 0 ) {
			throw new RuntimeException( 'ACF field group has no persistent database ID: ' . esc_html( $group_key ) );
		}
		return $group_id;
	}

	/**
	 * Process the group fields operation in the ACF adapter.
	 *
	 * @param string $group_key Field or lookup key.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function group_fields( string $group_key ): array {
		$group = acf_get_field_group( $group_key );
		if ( ! is_array( $group ) ) {
			throw new RuntimeException( 'ACF field group was not found: ' . esc_html( $group_key ) );
		}
		$fields = acf_get_fields( $group );
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Normalize requested text fields in the ACF adapter.
	 *
	 * @param array $requested Value used by this operation.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_requested_text_fields( array $requested ): array {
		$normalized = array();
		$keys       = array();
		$names      = array();
		foreach ( array_values( $requested ) as $index => $field ) {
			if ( ! is_array( $field ) ) {
				throw new RuntimeException( 'ACF schema field at index ' . (int) $index . ' must be an object.' );
			}
			foreach ( array_keys( $field ) as $key ) {
				if ( ! in_array( (string) $key, array( 'key', 'name', 'label' ), true ) ) {
					throw new RuntimeException( 'Unknown ACF schema field property.' );
				}
			}
			$key   = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
			$name  = isset( $field['name'] ) && is_string( $field['name'] ) ? $field['name'] : '';
			$label = isset( $field['label'] ) && is_scalar( $field['label'] ) ? trim( (string) $field['label'] ) : '';
			if ( ! preg_match( '/^field_[A-Za-z0-9_-]{6,80}\z/', $key ) ) {
				throw new RuntimeException( 'Invalid ACF field key.' );
			}
			if ( ! preg_match( '/^[a-z][a-z0-9_]{2,63}\z/', $name ) ) {
				throw new RuntimeException( 'Invalid ACF field name.' );
			}
			if ( '' === $label || strlen( $label ) > 80 || preg_match( '/[\x00-\x1F\x7F]/', $label ) ) {
				throw new RuntimeException( 'Invalid ACF field label for: ' . esc_html( $key ) );
			}
			if ( isset( $keys[ $key ] ) || isset( $names[ $name ] ) ) {
				throw new RuntimeException( 'Duplicate ACF field key or name in schema request.' );
			}
			Policy::assertKeyAllowed( $key );
			Policy::assertKeyAllowed( $name );
			$keys[ $key ]   = true;
			$names[ $name ] = true;
			$normalized[]   = array(
				'key'   => $key,
				'name'  => $name,
				'label' => $label,
				'type'  => 'text',
			);
		}
		return $normalized;
	}

	/**
	 * Process the field summary operation in the ACF adapter.
	 *
	 * @param array $field Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function field_summary( array $field ): array {
		return array(
			'key'      => isset( $field['key'] ) ? (string) $field['key'] : '',
			'name'     => isset( $field['name'] ) ? (string) $field['name'] : '',
			'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
			'type'     => isset( $field['type'] ) ? (string) $field['type'] : '',
			'parent'   => isset( $field['parent'] ) ? (string) $field['parent'] : '',
			'required' => ! empty( $field['required'] ),
		);
	}

	/**
	 * Process the field matches definition operation in the ACF adapter.
	 *
	 * @param array $summary Value used by this operation.
	 * @param array $definition Value used by this operation.
	 * @param int   $group_id Identifier of the requested object.
	 *
	 * @return bool Result of the operation.
	 */
	private function field_matches_definition( array $summary, array $definition, int $group_id ): bool {
		return (string) ( $summary['key'] ?? '' ) === (string) $definition['key']
		&& (string) ( $summary['name'] ?? '' ) === (string) $definition['name']
		&& (string) ( $summary['label'] ?? '' ) === (string) $definition['label']
		&& 'text' === (string) ( $summary['type'] ?? '' )
		&& (string) ( $summary['parent'] ?? '' ) === (string) $group_id
		&& empty( $summary['required'] );
	}

	/**
	 * Ensure acf complex schema in the ACF adapter.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function assert_acf_complex_schema(): void {
		$this->assert_acf_schema();
		foreach ( array(
			'acf_get_field_type',
			'acf_import_field_group',
			'acf_delete_field_group',
			'acf_get_location_rule_types',
			'acf_get_location_rule_operators',
		) as $function ) {
			if ( ! function_exists( $function ) ) {
				throw new RuntimeException( 'ACF complex schema API is unavailable: ' . esc_html( $function ) );
			}
		}
	}

	/**
	 * Normalize field group in the ACF adapter.
	 *
	 * @param array $group Value used by this operation.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_field_group( array $group ): array {
		$allowed = array(
			'key',
			'title',
			'location',
			'description',
			'position',
			'style',
			'label_placement',
			'instruction_placement',
			'hide_on_screen',
			'active',
			'show_in_rest',
		);
		foreach ( array_keys( $group ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				throw new RuntimeException( 'Unknown ACF field-group property.' );
			}
		}
		$key   = isset( $group['key'] ) && is_string( $group['key'] ) ? $group['key'] : '';
		$title = isset( $group['title'] ) ? trim( (string) $group['title'] ) : '';
		if ( ! preg_match( '/^group_[A-Za-z0-9_-]{6,80}\z/', $key ) ) {
			throw new RuntimeException( 'Invalid ACF field-group key.' );
		}
		Policy::assertKeyAllowed( $key );
		if ( '' === $title || strlen( $title ) > 120 || $this->has_invalid_control( $title, false ) ) {
			throw new RuntimeException( 'Invalid ACF field-group title.' );
		}
		$location = isset( $group['location'] ) && is_array( $group['location'] )
			? $this->normalize_location_rules( $group['location'] )
			: array();
		if ( ! $location ) {
			throw new RuntimeException( 'ACF field-group location requires at least one rule group.' );
		}

		$normalized = array(
			'key'                   => $key,
			'title'                 => $title,
			'location'              => $location,
			'position'              => isset( $group['position'] ) ? (string) $group['position'] : 'normal',
			'style'                 => isset( $group['style'] ) ? (string) $group['style'] : 'default',
			'label_placement'       => isset( $group['label_placement'] ) ? (string) $group['label_placement'] : 'top',
			'instruction_placement' => isset( $group['instruction_placement'] ) ? (string) $group['instruction_placement'] : 'label',
			'active'                => array_key_exists( 'active', $group ) ? $this->require_boolean( $group['active'], 'group.active' ) : true,
			'show_in_rest'          => array_key_exists( 'show_in_rest', $group ) ? $this->require_boolean( $group['show_in_rest'], 'group.show_in_rest' ) : false,
		);
		if ( ! in_array( $normalized['position'], array( 'normal', 'side', 'acf_after_title' ), true ) ) {
			throw new RuntimeException( 'Invalid ACF field-group position.' );
		}
		if ( ! in_array( $normalized['style'], array( 'default', 'seamless' ), true ) ) {
			throw new RuntimeException( 'Invalid ACF field-group style.' );
		}
		if ( ! in_array( $normalized['label_placement'], array( 'top', 'left' ), true ) ) {
			throw new RuntimeException( 'Invalid ACF field-group label placement.' );
		}
		if ( ! in_array( $normalized['instruction_placement'], array( 'label', 'field' ), true ) ) {
			throw new RuntimeException( 'Invalid ACF field-group instruction placement.' );
		}
		if ( isset( $group['description'] ) ) {
			$description = (string) $group['description'];
			if ( strlen( $description ) > 1000 || $this->has_invalid_control( $description, true ) ) {
				throw new RuntimeException( 'Invalid ACF field-group description.' );
			}
			$normalized['description'] = $description;
		}
		if ( isset( $group['hide_on_screen'] ) ) {
			if ( ! is_array( $group['hide_on_screen'] ) || count( $group['hide_on_screen'] ) > 20 ) {
				throw new RuntimeException( 'Invalid ACF field-group hide_on_screen list.' );
			}
			$allowed_hidden = array(
				'permalink',
				'the_content',
				'excerpt',
				'custom_fields',
				'discussion',
				'comments',
				'revisions',
				'slug',
				'author',
				'format',
				'page_attributes',
				'featured_image',
				'categories',
				'tags',
				'send-trackbacks',
			);
			foreach ( $group['hide_on_screen'] as $item ) {
				if ( ! is_string( $item ) || ! in_array( $item, $allowed_hidden, true ) ) {
					throw new RuntimeException( 'Invalid ACF hide_on_screen item.' );
				}
			}
			$normalized['hide_on_screen'] = array_values( array_unique( $group['hide_on_screen'] ) );
		}
		return $normalized;
	}

	/**
	 * Normalize location rules in the ACF adapter.
	 *
	 * @param array $location Value used by this operation.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_location_rules( array $location ): array {
		if ( ! $location || count( $location ) > 8 ) {
			throw new RuntimeException( 'ACF field-group location requires 1-8 OR groups.' );
		}
		$types = array();
		foreach ( (array) acf_get_location_rule_types() as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}
			foreach ( $category as $param => $label ) {
				$types[ (string) $param ] = true;
			}
		}
		$normalized = array();
		foreach ( array_values( $location ) as $group_index => $rules ) {
			if ( ! is_array( $rules ) || ! $rules || count( $rules ) > 8 ) {
				throw new RuntimeException( 'ACF location group at index ' . (int) $group_index . ' requires 1-8 rules.' );
			}
			$normalized_rules = array();
			foreach ( array_values( $rules ) as $rule_index => $rule ) {
				if ( ! is_array( $rule ) ) {
					throw new RuntimeException( 'ACF location rule must be an object.' );
				}
				foreach ( array_keys( $rule ) as $key ) {
					if ( ! in_array( (string) $key, array( 'param', 'operator', 'value' ), true ) ) {
						throw new RuntimeException( 'Unknown ACF location rule property.' );
					}
				}
				$param    = isset( $rule['param'] ) && is_string( $rule['param'] ) ? $rule['param'] : '';
				$operator = isset( $rule['operator'] ) && is_string( $rule['operator'] ) ? $rule['operator'] : '';
				$value    = isset( $rule['value'] ) && is_scalar( $rule['value'] ) ? (string) $rule['value'] : '';
				if ( ! isset( $types[ $param ] ) ) {
					throw new RuntimeException( 'Unsupported ACF location parameter.' );
				}
				$operators = (array) acf_get_location_rule_operators( array( 'param' => $param ) );
				if ( ! isset( $operators[ $operator ] ) ) {
					throw new RuntimeException( 'Unsupported ACF location operator.' );
				}
				if ( '' === $value || strlen( $value ) > 200 || $this->has_invalid_control( $value, false ) ) {
					throw new RuntimeException( 'Invalid ACF location value at group ' . (int) $group_index . ', rule ' . (int) $rule_index . '.' );
				}
				Policy::assertKeyAllowed( $param );
				$normalized_rules[] = array(
					'param'    => $param,
					'operator' => $operator,
					'value'    => $value,
				);
			}
			$normalized[] = $normalized_rules;
		}
		return $normalized;
	}

	/**
	 * Normalize schema fields in the ACF adapter.
	 *
	 * @param array $requested Value used by this operation.
	 * @param int   $depth Value used by this operation.
	 * @param int   $total Value updated by reference.
	 * @param array $keys Value updated by reference.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_schema_fields( array $requested, int $depth, int &$total, array &$keys ): array {
		if ( $depth > 3 ) {
			throw new RuntimeException( 'ACF nested field depth exceeds the maximum of 3.' );
		}
		if ( ! $requested || count( $requested ) > 20 ) {
			throw new RuntimeException( 'Each ACF field level requires 1-20 definitions.' );
		}
		$normalized = array();
		$names      = array();
		foreach ( array_values( $requested ) as $index => $field ) {
			if ( ! is_array( $field ) ) {
				throw new RuntimeException( 'ACF schema field at index ' . (int) $index . ' must be an object.' );
			}
			++$total;
			if ( $total > 50 ) {
				throw new RuntimeException( 'ACF schema request exceeds the maximum of 50 total fields.' );
			}
			$definition = $this->normalize_schema_field( $field, $depth, $total, $keys );
			if ( isset( $names[ $definition['name'] ] ) ) {
				throw new RuntimeException( 'Duplicate ACF field name at the same schema level: ' . esc_html( $definition['name'] ) );
			}
			$names[ $definition['name'] ] = true;
			$normalized[]                 = $definition;
		}
		return $normalized;
	}

	/**
	 * Normalize schema field in the ACF adapter.
	 *
	 * @param array $field Value used by this operation.
	 * @param int   $depth Value used by this operation.
	 * @param int   $total Value updated by reference.
	 * @param array $keys Value updated by reference.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_schema_field( array $field, int $depth, int &$total, array &$keys ): array {
		$type            = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
		$type_properties = array(
			'text'         => array( 'default_value', 'placeholder', 'prepend', 'append', 'maxlength' ),
			'textarea'     => array( 'default_value', 'placeholder', 'maxlength', 'rows', 'new_lines' ),
			'number'       => array( 'default_value', 'placeholder', 'prepend', 'append', 'min', 'max', 'step' ),
			'email'        => array( 'default_value', 'placeholder', 'prepend', 'append' ),
			'url'          => array( 'default_value', 'placeholder' ),
			'image'        => array( 'return_format', 'preview_size', 'library', 'min_width', 'min_height', 'min_size', 'max_width', 'max_height', 'max_size', 'mime_types' ),
			'relationship' => array( 'post_type', 'taxonomy', 'min', 'max', 'filters', 'elements', 'return_format' ),
			'group'        => array( 'sub_fields', 'layout' ),
			'repeater'     => array( 'sub_fields', 'min', 'max', 'layout', 'button_label', 'collapsed', 'rows_per_page' ),
		);
		if ( ! isset( $type_properties[ $type ] ) ) {
			throw new RuntimeException( 'Unsupported ACF complex schema field type.' );
		}
		if ( ! acf_get_field_type( $type ) ) {
			throw new RuntimeException( 'ACF field type is unavailable in the installed runtime: ' . esc_html( $type ) );
		}

		$allowed = array_merge(
			array( 'key', 'name', 'label', 'type', 'instructions', 'required', 'conditional_logic', 'wrapper' ),
			$type_properties[ $type ]
		);
		foreach ( array_keys( $field ) as $property ) {
			if ( ! in_array( (string) $property, $allowed, true ) ) {
				throw new RuntimeException( 'Unknown ACF field property.' );
			}
		}
		$key   = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
		$name  = isset( $field['name'] ) && is_string( $field['name'] ) ? $field['name'] : '';
		$label = isset( $field['label'] ) && is_scalar( $field['label'] ) ? trim( (string) $field['label'] ) : '';
		if ( ! preg_match( '/^field_[A-Za-z0-9_-]{6,80}\z/', $key ) ) {
			throw new RuntimeException( 'Invalid ACF field key.' );
		}
		if ( ! preg_match( '/^[a-z][a-z0-9_]{2,63}\z/', $name ) ) {
			throw new RuntimeException( 'Invalid ACF field name.' );
		}
		if ( '' === $label || strlen( $label ) > 120 || $this->has_invalid_control( $label, false ) ) {
			throw new RuntimeException( 'Invalid ACF field label for: ' . esc_html( $key ) );
		}
		Policy::assertKeyAllowed( $key );
		Policy::assertKeyAllowed( $name );
		if ( isset( $keys[ $key ] ) ) {
			throw new RuntimeException( 'Duplicate ACF field key in schema request: ' . esc_html( $key ) );
		}
		$keys[ $key ] = true;

		$normalized = array(
			'key'               => $key,
			'name'              => $name,
			'label'             => $label,
			'type'              => $type,
			'instructions'      => isset( $field['instructions'] ) ? $this->bounded_text( $field['instructions'], 2000, true, 'instructions' ) : '',
			'required'          => array_key_exists( 'required', $field ) ? $this->require_boolean( $field['required'], $key . '.required' ) : false,
			'conditional_logic' => array_key_exists( 'conditional_logic', $field )
				? $this->normalize_conditional_logic( $field['conditional_logic'] )
				: false,
			'wrapper'           => isset( $field['wrapper'] ) ? $this->normalize_wrapper( $field['wrapper'] ) : array(
				'width' => '',
				'class' => '',
				'id'    => '',
			),
		);

		$this->normalize_scalar_schema_properties( $field, $type, $key, $normalized );
		$this->normalize_image_schema_properties( $field, $type, $key, $normalized );
		$this->normalize_relationship_schema_properties( $field, $type, $key, $normalized );
		$this->normalize_nested_schema_properties( $field, $type, $key, $depth, $total, $keys, $normalized );
		return $normalized;
	}

	/**
	 * Normalize scalar schema properties in the ACF adapter.
	 *
	 * @param array  $field Value used by this operation.
	 * @param string $type Requested type.
	 * @param string $key Field or lookup key.
	 * @param array  $normalized Value updated by reference.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_scalar_schema_properties( array $field, string $type, string $key, array &$normalized ): void {
		if ( in_array( $type, array( 'text', 'textarea', 'email', 'url', 'number' ), true ) ) {
			foreach ( array( 'default_value', 'placeholder', 'prepend', 'append' ) as $property ) {
				if ( ! array_key_exists( $property, $field ) ) {
					continue;
				}
				if ( 'number' === $type && 'default_value' === $property && is_numeric( $field[ $property ] ) ) {
					$normalized[ $property ] = $field[ $property ] + 0;
				} else {
					$normalized[ $property ] = $this->bounded_text( $field[ $property ], 2000, true, $key . '.' . $property );
				}
			}
		}
		if ( array_key_exists( 'maxlength', $field ) ) {
			$normalized['maxlength'] = $this->bounded_integer( $field['maxlength'], 1, 100000, $key . '.maxlength' );
		}
		if ( 'textarea' === $type ) {
			if ( array_key_exists( 'rows', $field ) ) {
				$normalized['rows'] = $this->bounded_integer( $field['rows'], 1, 100, $key . '.rows' );
			}
			if ( array_key_exists( 'new_lines', $field ) ) {
				$new_lines = (string) $field['new_lines'];
				if ( ! in_array( $new_lines, array( '', 'wpautop', 'br' ), true ) ) {
					throw new RuntimeException( 'Invalid textarea new_lines setting for: ' . esc_html( $key ) );
				}
				$normalized['new_lines'] = $new_lines;
			}
		}
		if ( 'number' === $type ) {
			foreach ( array( 'min', 'max', 'step' ) as $property ) {
				if ( array_key_exists( $property, $field ) ) {
					$normalized[ $property ] = $this->bounded_number( $field[ $property ], -1000000000, 1000000000, $key . '.' . $property );
				}
			}
		}
	}

	/**
	 * Normalize image schema properties in the ACF adapter.
	 *
	 * @param array  $field Value used by this operation.
	 * @param string $type Requested type.
	 * @param string $key Field or lookup key.
	 * @param array  $normalized Value updated by reference.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_image_schema_properties( array $field, string $type, string $key, array &$normalized ): void {
		if ( 'image' === $type ) {
			if ( array_key_exists( 'return_format', $field ) ) {
				$normalized['return_format'] = $this->enum_value( $field['return_format'], array( 'array', 'url', 'id' ), $key . '.return_format' );
			}
			if ( array_key_exists( 'preview_size', $field ) ) {
				$preview = (string) $field['preview_size'];
				if ( ! preg_match( '/^[A-Za-z0-9_-]{1,80}\z/', $preview ) ) {
					throw new RuntimeException( 'Invalid image preview_size for: ' . esc_html( $key ) );
				}
				$normalized['preview_size'] = $preview;
			}
			if ( array_key_exists( 'library', $field ) ) {
				$normalized['library'] = $this->enum_value( $field['library'], array( 'all', 'uploadedTo' ), $key . '.library' );
			}
			foreach ( array( 'min_width', 'min_height', 'max_width', 'max_height' ) as $property ) {
				if ( array_key_exists( $property, $field ) ) {
					$normalized[ $property ] = $this->bounded_integer( $field[ $property ], 0, 100000, $key . '.' . $property );
				}
			}
			foreach ( array( 'min_size', 'max_size' ) as $property ) {
				if ( array_key_exists( $property, $field ) ) {
					$normalized[ $property ] = $this->bounded_number( $field[ $property ], 0, 10000, $key . '.' . $property );
				}
			}
			if ( array_key_exists( 'mime_types', $field ) ) {
				$mime = $this->bounded_text( $field['mime_types'], 250, false, $key . '.mime_types' );
				if ( '' !== $mime && ! preg_match( '/^[A-Za-z0-9.+_\/,\s-]+\z/', $mime ) ) {
					throw new RuntimeException( 'Invalid image mime_types for: ' . esc_html( $key ) );
				}
				$normalized['mime_types'] = $mime;
			}
		}
	}

	/**
	 * Normalize relationship schema properties in the ACF adapter.
	 *
	 * @param array  $field Value used by this operation.
	 * @param string $type Requested type.
	 * @param string $key Field or lookup key.
	 * @param array  $normalized Value updated by reference.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_relationship_schema_properties( array $field, string $type, string $key, array &$normalized ): void {
		if ( 'relationship' === $type ) {
			if ( array_key_exists( 'post_type', $field ) ) {
				$normalized['post_type'] = $this->normalize_key_list( $field['post_type'], 30, $key . '.post_type' );
			}
			if ( array_key_exists( 'taxonomy', $field ) ) {
				$normalized['taxonomy'] = $this->normalize_bounded_string_list( $field['taxonomy'], 50, 120, $key . '.taxonomy' );
			}
			foreach ( array( 'min', 'max' ) as $property ) {
				if ( array_key_exists( $property, $field ) ) {
					$normalized[ $property ] = $this->bounded_integer( $field[ $property ], 0, 1000, $key . '.' . $property );
				}
			}
			if ( array_key_exists( 'filters', $field ) ) {
				if ( ! is_array( $field['filters'] ) || count( $field['filters'] ) > 3 ) {
					throw new RuntimeException( 'Invalid relationship filters for: ' . esc_html( $key ) );
				}
				$filters = array_values( array_unique( array_map( 'strval', $field['filters'] ) ) );
				foreach ( $filters as $filter ) {
					if ( ! in_array( $filter, array( 'search', 'post_type', 'taxonomy' ), true ) ) {
						throw new RuntimeException( 'Invalid relationship filter for: ' . esc_html( $key ) );
					}
				}
				$normalized['filters'] = $filters;
			}
			if ( array_key_exists( 'elements', $field ) ) {
				$normalized['elements'] = $this->normalize_key_list( $field['elements'], 10, $key . '.elements' );
			}
			if ( array_key_exists( 'return_format', $field ) ) {
				$normalized['return_format'] = $this->enum_value( $field['return_format'], array( 'object', 'id' ), $key . '.return_format' );
			}
		}
	}

	/**
	 * Normalize nested schema properties in the ACF adapter.
	 *
	 * @param array  $field Value used by this operation.
	 * @param string $type Requested type.
	 * @param string $key Field or lookup key.
	 * @param int    $depth Value used by this operation.
	 * @param int    $total Value updated by reference.
	 * @param array  $keys Value updated by reference.
	 * @param array  $normalized Value updated by reference.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_nested_schema_properties( array $field, string $type, string $key, int $depth, int &$total, array &$keys, array &$normalized ): void {
		if ( in_array( $type, array( 'group', 'repeater' ), true ) ) {
			$sub_fields = isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? array_values( $field['sub_fields'] ) : array();
			if ( ! $sub_fields ) {
				throw new RuntimeException( 'ACF ' . esc_html( $type ) . ' field requires sub_fields: ' . esc_html( $key ) );
			}
			$normalized['sub_fields'] = $this->normalize_schema_fields( $sub_fields, $depth + 1, $total, $keys );
			$layouts                  = 'group' === $type ? array( 'block', 'table', 'row' ) : array( 'table', 'block', 'row' );
			if ( array_key_exists( 'layout', $field ) ) {
				$normalized['layout'] = $this->enum_value( $field['layout'], $layouts, $key . '.layout' );
			}
		}
		if ( 'repeater' === $type ) {
			foreach ( array( 'min', 'max' ) as $property ) {
				if ( array_key_exists( $property, $field ) ) {
					$normalized[ $property ] = $this->bounded_integer( $field[ $property ], 0, 1000, $key . '.' . $property );
				}
			}
			if ( array_key_exists( 'button_label', $field ) ) {
				$normalized['button_label'] = $this->bounded_text( $field['button_label'], 80, false, $key . '.button_label' );
			}
			if ( array_key_exists( 'rows_per_page', $field ) ) {
				$normalized['rows_per_page'] = $this->bounded_integer( $field['rows_per_page'], 1, 200, $key . '.rows_per_page' );
			}
			if ( array_key_exists( 'collapsed', $field ) ) {
				$collapsed = (string) $field['collapsed'];
				if ( '' !== $collapsed && ! preg_match( '/^field_[A-Za-z0-9_-]{6,80}\z/', $collapsed ) ) {
					throw new RuntimeException( 'Invalid repeater collapsed field key for: ' . esc_html( $key ) );
				}
				if ( '' !== $collapsed ) {
					$direct_keys = array_map(
						static function ( array $sub_field ): string {
							return (string) $sub_field['key'];
						},
						$normalized['sub_fields']
					);
					if ( ! in_array( $collapsed, $direct_keys, true ) ) {
						throw new RuntimeException( 'Repeater collapsed field must reference a direct sub field: ' . esc_html( $key ) );
					}
				}
				$normalized['collapsed'] = $collapsed;
			}
		}
	}

	/**
	 * Normalize wrapper in the ACF adapter.
	 *
	 * @param mixed $wrapper Value used by this operation.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_wrapper( $wrapper ): array {
		if ( ! is_array( $wrapper ) ) {
			throw new RuntimeException( 'ACF field wrapper must be an object.' );
		}
		foreach ( array_keys( $wrapper ) as $key ) {
			if ( ! in_array( (string) $key, array( 'width', 'class', 'id' ), true ) ) {
				throw new RuntimeException( 'Unknown ACF wrapper property.' );
			}
		}
		$width = isset( $wrapper['width'] ) ? (string) $wrapper['width'] : '';
		$class = isset( $wrapper['class'] ) ? (string) $wrapper['class'] : '';
		$id    = isset( $wrapper['id'] ) ? (string) $wrapper['id'] : '';
		if ( '' !== $width && ( ! ctype_digit( $width ) || (int) $width < 1 || (int) $width > 100 ) ) {
			throw new RuntimeException( 'ACF wrapper width must be between 1 and 100.' );
		}
		if ( strlen( $class ) > 200 || $this->has_invalid_control( $class, false ) ) {
			throw new RuntimeException( 'Invalid ACF wrapper class.' );
		}
		if ( '' !== $id && ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,99}\z/', $id ) ) {
			throw new RuntimeException( 'Invalid ACF wrapper id.' );
		}
		return array(
			'width' => $width,
			'class' => $class,
			'id'    => $id,
		);
	}

	/**
	 * Normalize conditional logic in the ACF adapter.
	 *
	 * @param mixed $logic Value used by this operation.
	 *
	 * @return mixed Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_conditional_logic( $logic ) {
		if ( false === $logic || 0 === $logic || '0' === $logic || null === $logic ) {
			return false;
		}
		if ( ! is_array( $logic ) || ! $logic || count( $logic ) > 8 ) {
			throw new RuntimeException( 'ACF conditional_logic must be false or 1-8 rule groups.' );
		}
		$normalized = array();
		foreach ( array_values( $logic ) as $rules ) {
			if ( ! is_array( $rules ) || ! $rules || count( $rules ) > 8 ) {
				throw new RuntimeException( 'Each ACF conditional_logic group requires 1-8 rules.' );
			}
			$group = array();
			foreach ( array_values( $rules ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					throw new RuntimeException( 'ACF conditional_logic rule must be an object.' );
				}
				foreach ( array_keys( $rule ) as $key ) {
					if ( ! in_array( (string) $key, array( 'field', 'operator', 'value' ), true ) ) {
						throw new RuntimeException( 'Unknown ACF conditional_logic property.' );
					}
				}
				$field    = isset( $rule['field'] ) ? (string) $rule['field'] : '';
				$operator = isset( $rule['operator'] ) ? (string) $rule['operator'] : '';
				$value    = isset( $rule['value'] ) ? (string) $rule['value'] : '';
				if ( ! preg_match( '/^field_[A-Za-z0-9_-]{6,80}\z/', $field ) || ! in_array( $operator, array( '==', '!=' ), true ) ) {
					throw new RuntimeException( 'Invalid ACF conditional_logic rule.' );
				}
				if ( strlen( $value ) > 500 || $this->has_invalid_control( $value, true ) ) {
					throw new RuntimeException( 'Invalid ACF conditional_logic value.' );
				}
				$group[] = array(
					'field'    => $field,
					'operator' => $operator,
					'value'    => $value,
				);
			}
			$normalized[] = $group;
		}
		return $normalized;
	}

	/**
	 * Create field tree in the ACF adapter.
	 *
	 * @param array $definition Value used by this operation.
	 * @param int   $parent_id Identifier of the requested object.
	 * @param int   $menu_order Value used by this operation.
	 * @param array $created_nodes Value updated by reference.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function create_field_tree( array $definition, int $parent_id, int $menu_order, array &$created_nodes ): void {
		$children = isset( $definition['sub_fields'] ) && is_array( $definition['sub_fields'] ) ? $definition['sub_fields'] : array();
		$field    = $definition;
		unset( $field['sub_fields'] );
		$field['parent']     = $parent_id;
		$field['menu_order'] = $menu_order;
		$saved               = acf_update_field( $field );
		if ( ! is_array( $saved ) ) {
			throw new RuntimeException( 'ACF complex field creation failed for: ' . esc_html( $definition['key'] ) );
		}
		$saved_id = isset( $saved['ID'] ) ? (int) $saved['ID'] : 0;
		if ( $saved_id <= 0 ) {
			$readback = acf_get_field( $definition['key'] );
			$saved_id = is_array( $readback ) && isset( $readback['ID'] ) ? (int) $readback['ID'] : 0;
		}
		if ( $saved_id <= 0 ) {
			throw new RuntimeException( 'ACF complex field creation returned no persistent ID: ' . esc_html( $definition['key'] ) );
		}
		$created_nodes[] = array(
			'key' => (string) $definition['key'],
			'id'  => $saved_id,
		);
		foreach ( array_values( $children ) as $index => $child ) {
			$this->create_field_tree( $child, $saved_id, $index, $created_nodes );
		}
	}

	/**
	 * Clean up created field nodes in the ACF adapter.
	 *
	 * @param array $created_nodes Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function cleanup_created_field_nodes( array $created_nodes ): array {
		$failures = array();
		foreach ( array_reverse( $created_nodes ) as $node ) {
			$key = isset( $node['key'] ) ? (string) $node['key'] : '';
			$id  = isset( $node['id'] ) ? (int) $node['id'] : 0;
			if ( '' === $key || $id <= 0 ) {
				$failures[] = '' !== $key ? $key : '(unknown)';
				continue;
			}
			$field = acf_get_field( $key );
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( (int) ( $field['ID'] ?? 0 ) !== $id ) {
				$failures[] = $key;
				continue;
			}
			if ( false === acf_delete_field( $id ) ) {
				$failures[] = $key;
				continue;
			}
			$readback = acf_get_field( $key );
			if ( is_array( $readback ) && (int) ( $readback['ID'] ?? 0 ) === $id ) {
				$failures[] = $key;
			}
		}
		return array_values( array_unique( $failures ) );
	}

	/**
	 * Delete field tree by key in the ACF adapter.
	 *
	 * @param string $key Field or lookup key.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function delete_field_tree_by_key( string $key ): void {
		$field = acf_get_field( $key );
		if ( ! is_array( $field ) ) {
			return;
		}
		$children = acf_get_fields( $field );
		if ( is_array( $children ) ) {
			foreach ( array_reverse( $children ) as $child ) {
				if ( is_array( $child ) && ! empty( $child['key'] ) ) {
					$this->delete_field_tree_by_key( (string) $child['key'] );
				}
			}
		}
		$id = isset( $field['ID'] ) ? (int) $field['ID'] : 0;
		if ( $id <= 0 || false === acf_delete_field( $id ) ) {
			throw new RuntimeException( 'ACF complex field deletion failed for: ' . esc_html( $key ) );
		}
	}

	/**
	 * Process the hydrate field tree operation in the ACF adapter.
	 *
	 * @param array $field Value used by this operation.
	 *
	 * @return array Result of the operation.
	 */
	private function hydrate_field_tree( array $field ): array {
		$tree     = $field;
		$children = acf_get_fields( $field );
		if ( is_array( $children ) && $children ) {
			$tree['sub_fields'] = array_values(
				array_map(
					function ( array $child ): array {
						return $this->hydrate_field_tree( $child );
					},
					$children
				)
			);
		} else {
			unset( $tree['sub_fields'] );
		}
		return $tree;
	}

	/**
	 * Process the field group snapshot operation in the ACF adapter.
	 *
	 * @param string $group_key Field or lookup key.
	 *
	 * @return array|null Result of the operation.
	 */
	private function field_group_snapshot( string $group_key ): ?array {
		$group = acf_get_field_group( $group_key );
		if ( ! is_array( $group ) ) {
			return null;
		}
		$fields = acf_get_fields( $group );
		$trees  = array();
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			if ( is_array( $field ) ) {
				$trees[] = $this->hydrate_field_tree( $field );
			}
		}
		return array(
			'group'  => $group,
			'fields' => $trees,
		);
	}

	/**
	 * Ensure field tree keys available in the ACF adapter.
	 *
	 * @param array $definition Value used by this operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function assert_field_tree_keys_available( array $definition ): void {
		$key = (string) $definition['key'];
		if ( is_array( acf_get_field( $key ) ) ) {
			throw new RuntimeException( 'Existing global ACF field conflicts with requested schema field: ' . esc_html( $key ) );
		}
		foreach ( isset( $definition['sub_fields'] ) && is_array( $definition['sub_fields'] ) ? $definition['sub_fields'] : array() as $child ) {
			$this->assert_field_tree_keys_available( $child );
		}
	}

	/**
	 * Process the schema contains operation in the ACF adapter.
	 *
	 * @param mixed $expected Value used by this operation.
	 * @param mixed $actual Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function schema_contains( $expected, $actual ): bool {
		if ( is_array( $expected ) ) {
			if ( ! is_array( $actual ) ) {
				return false;
			}
			$is_list = array_values( $expected ) === $expected;
			if ( $is_list ) {
				if ( count( $expected ) !== count( $actual ) ) {
					return false;
				}
				$actual_values = array_values( $actual );
				foreach ( $expected as $index => $value ) {
					if ( ! $this->schema_contains( $value, $actual_values[ $index ] ) ) {
						return false;
					}
				}
				return true;
			}
			foreach ( $expected as $key => $value ) {
				if ( ! array_key_exists( $key, $actual ) || ! $this->schema_contains( $value, $actual[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_bool( $expected ) ) {
			return (bool) $actual === $expected;
		}
		if ( is_int( $expected ) ) {
			return is_numeric( $actual ) && (int) $actual === $expected;
		}
		if ( is_float( $expected ) ) {
			return is_numeric( $actual ) && abs( (float) $actual - $expected ) < 0.0000001;
		}
		return $expected === $actual;
	}

	/**
	 * Process the bounded text operation in the ACF adapter.
	 *
	 * @param mixed  $value Value used by this operation.
	 * @param int    $max_length Value used by this operation.
	 * @param bool   $allow_newlines Value used by this operation.
	 * @param string $context Authorized execution context.
	 *
	 * @return string Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function bounded_text( $value, int $max_length, bool $allow_newlines, string $context ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			throw new RuntimeException( 'ACF ' . esc_html( $context ) . ' must be text.' );
		}
		$text = (string) $value;
		if ( strlen( $text ) > $max_length || $this->has_invalid_control( $text, $allow_newlines ) ) {
			throw new RuntimeException( 'Invalid ACF text value for ' . esc_html( $context ) . '.' );
		}
		return $text;
	}

	/**
	 * Check whether invalid control in the ACF adapter.
	 *
	 * @param string $value Value used by this operation.
	 * @param bool   $allow_newlines Value used by this operation.
	 *
	 * @return bool Result of the operation.
	 */
	private function has_invalid_control( string $value, bool $allow_newlines ): bool {
		$pattern = $allow_newlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
		return (bool) preg_match( $pattern, $value );
	}

	/**
	 * Process the require boolean operation in the ACF adapter.
	 *
	 * @param mixed  $value Value used by this operation.
	 * @param string $context Authorized execution context.
	 *
	 * @return bool Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function require_boolean( $value, string $context ): bool {
		if ( ! is_bool( $value ) ) {
			throw new RuntimeException( 'ACF ' . esc_html( $context ) . ' must be boolean.' );
		}
		return $value;
	}

	/**
	 * Process the bounded integer operation in the ACF adapter.
	 *
	 * @param mixed  $value Value used by this operation.
	 * @param int    $min Value used by this operation.
	 * @param int    $max Value used by this operation.
	 * @param string $context Authorized execution context.
	 *
	 * @return int Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function bounded_integer( $value, int $min, int $max, string $context ): int {
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw new RuntimeException( 'ACF ' . esc_html( $context ) . ' must be an integer between ' . intval( $min ) . ' and ' . intval( $max ) . '.' );
		}
		return $value;
	}

	/**
	 * Process the bounded number operation in the ACF adapter.
	 *
	 * @param mixed  $value Value used by this operation.
	 * @param float  $min Value used by this operation.
	 * @param float  $max Value used by this operation.
	 * @param string $context Authorized execution context.
	 *
	 * @return mixed Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function bounded_number( $value, float $min, float $max, string $context ) {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			throw new RuntimeException( 'ACF ' . esc_html( $context ) . ' must be numeric.' );
		}
		$number = (float) $value;
		if ( $number < $min || $number > $max ) {
			throw new RuntimeException( 'ACF ' . esc_html( $context ) . ' is outside the supported range.' );
		}
		return $value;
	}

	/**
	 * Process the enum value operation in the ACF adapter.
	 *
	 * @param mixed  $value Value used by this operation.
	 * @param array  $allowed Value used by this operation.
	 * @param string $context Authorized execution context.
	 *
	 * @return string Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function enum_value( $value, array $allowed, string $context ): string {
		$value = (string) $value;
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new RuntimeException( 'Invalid ACF enum value for ' . esc_html( $context ) . '.' );
		}
		return $value;
	}

	/**
	 * Normalize key list in the ACF adapter.
	 *
	 * @param mixed  $value Value used by this operation.
	 * @param int    $max_items Value used by this operation.
	 * @param string $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_key_list( $value, int $max_items, string $context ): array {
		if ( ! is_array( $value ) || count( $value ) > $max_items ) {
			throw new RuntimeException( 'Invalid ACF list for ' . esc_html( $context ) . '.' );
		}
		$result = array();
		foreach ( array_values( $value ) as $item ) {
			$item = (string) $item;
			if ( ! preg_match( '/^[A-Za-z0-9_-]{1,80}\z/', $item ) ) {
				throw new RuntimeException( 'Invalid ACF key in ' . esc_html( $context ) . '.' );
			}
			Policy::assertKeyAllowed( $item );
			$result[] = $item;
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * Normalize bounded string list in the ACF adapter.
	 *
	 * @param mixed  $value Value used by this operation.
	 * @param int    $max_items Value used by this operation.
	 * @param int    $max_length Value used by this operation.
	 * @param string $context Authorized execution context.
	 *
	 * @return array Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function normalize_bounded_string_list( $value, int $max_items, int $max_length, string $context ): array {
		if ( ! is_array( $value ) || count( $value ) > $max_items ) {
			throw new RuntimeException( 'Invalid ACF list for ' . esc_html( $context ) . '.' );
		}
		$result = array();
		foreach ( array_values( $value ) as $item ) {
			if ( ! is_string( $item ) || '' === $item || strlen( $item ) > $max_length || $this->has_invalid_control( $item, false ) ) {
				throw new RuntimeException( 'Invalid ACF string in ' . esc_html( $context ) . '.' );
			}
			Policy::assertKeyAllowed( $item );
			$result[] = $item;
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * Process the target operation in the ACF adapter.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return mixed Result of the operation.
	 *
	 * @throws \RuntimeException When the guarded operation cannot be completed safely.
	 */
	private function target( array $payload ) {
		if ( array_key_exists( 'target', $payload ) ) {
			$target = $payload['target'];
			if ( is_int( $target ) || is_string( $target ) ) {
				return $target;
			}
		}
		if ( isset( $payload['post_id'] ) ) {
			return (int) $payload['post_id'];
		}
		throw new RuntimeException( 'ACF target is required. Use payload.target or payload.post_id.' );
	}
}
