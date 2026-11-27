<?php
/**
 * Supports Elementor Json Import workflows in WordPress administration.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Admin;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Adapters\ElementorAdapter;
require_once __DIR__ . '/trait-elementorjsonimportmethods.php';

/**
 * Supports Elementor Json Import workflows in WordPress administration.
 */
final class ElementorJsonImport {
	use ElementorJsonImportMethods {
		row_actions as rowActions;
		register_import_pages as registerImportPages;
		render_bulk_page as renderBulkPage;
		preview_bulk as previewBulk;
		confirm_bulk as confirmBulk;
	}

	private const BULK_PREVIEW = 'wpconnector_elementor_bulk_preview';
	private const BULK_CONFIRM = 'wpconnector_elementor_bulk_confirm';
	private const BULK_LIMIT   = 20;
	private const BULK_BYTES   = 8388608;
	private const ACTION       = 'wpconnector_import_elementor_json';
	private const POST_TYPES   = array( 'page', 'post', 'elementor_library' );
	private const MAX_BYTES    = 5242880;

	/**
	 * Register the ElementorJsonImport component actions and handlers.
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'page_row_actions', array( $this, 'rowActions' ), 99, 2 );
		add_filter( 'post_row_actions', array( $this, 'rowActions' ), 99, 2 );
		add_action( 'admin_menu', array( $this, 'registerImportPages' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleImport' ) );
		add_action( 'admin_post_' . self::BULK_PREVIEW, array( $this, 'previewBulk' ) );
		add_action( 'admin_post_' . self::BULK_CONFIRM, array( $this, 'confirmBulk' ) );
	}

	/**
	 * Render import page for the ElementorJsonImport component.
	 *
	 * @param string $post_type Input for the operation.
	 */
	public function renderImportPage( string $post_type ): void {
		if ( ! $this->supports_post_type( $post_type ) || ! current_user_can( $this->capability_for_post_type( $post_type ) ) ) {
			wp_die( esc_html__( 'You are not allowed to import Elementor JSON here.', 'wordpressconnector' ), '', array( 'response' => 403 ) );
		}
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This is a read-only selector; the shared parser rejects noncanonical IDs and writes use a checked nonce.
			$selected_id = $this->exact_import_target_id( isset( $_GET['post_id'] ) ? wp_unslash( $_GET['post_id'] ) : '0' );
		} catch ( RuntimeException $error ) {
			wp_die( esc_html__( 'Invalid Elementor JSON import target.', 'wordpressconnector' ), '', array( 'response' => 400 ) );
			return;
		}
		$post_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$selected_post = null;
		if ( $selected_id > 0 ) {
			$selected_post = get_post( $selected_id );
			if ( ! $selected_post instanceof \WP_Post || (int) $selected_post->ID !== $selected_id || (string) $selected_post->post_type !== $post_type || ! in_array( (string) $selected_post->post_status, $post_statuses, true ) ) {
				wp_die( esc_html__( 'Invalid Elementor JSON import target.', 'wordpressconnector' ), '', array( 'response' => 400 ) );
				return;
			}
			if ( ! current_user_can( 'edit_post', $selected_id ) ) {
				wp_die( esc_html__( 'You are not allowed to edit this item.', 'wordpressconnector' ), '', array( 'response' => 403 ) );
				return;
			}
		}
		// Retain the 200-item selector while bounding each query and ordering ties.
		$posts = array();
		foreach ( array( 0, 100 ) as $offset ) {
			$page = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => $post_statuses,
					'posts_per_page' => 100,
					'offset'         => $offset,
					'orderby'        => array(
						'modified' => 'DESC',
						'ID'       => 'DESC',
					),
				)
			);
			foreach ( $page as $post ) {
				if ( $post instanceof \WP_Post ) {
					$posts[ (int) $post->ID ] = $post;
				}
			}
			if ( count( $page ) < 100 ) {
				break;
			}
		}
		$posts = array_values( $posts );
		// A row-action target can be older than the bounded recent-items list.
		// Preserve its exact identity instead of silently selecting a new draft.
		if ( $selected_post instanceof \WP_Post ) {
			$listed = false;
			foreach ( $posts as $post ) {
				if ( $post instanceof \WP_Post && (int) $post->ID === $selected_id ) {
					$listed = true;
					break;
				}
			}
			if ( ! $listed ) {
				array_unshift( $posts, $selected_post );
			}
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Import Elementor JSON', 'wordpressconnector' ) . '</h1><p>' . esc_html__( 'Upload an Elementor JSON document. Choose an existing item to replace only its Elementor structure/settings, or choose Create new to make a new draft. Existing-item writes use Elementor readback and automatic rollback.', 'wordpressconnector' ) . '</p><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="post_type" value="' . esc_attr( $post_type ) . '"><table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="wpconnector-target-post">' . esc_html__( 'Target', 'wordpressconnector' ) . '</label></th><td><select id="wpconnector-target-post" name="post_id">';
		printf( '<option value="0"%1$s>%2$s</option>', selected( $selected_id, 0, false ), esc_html__( 'Create new draft from JSON', 'wordpressconnector' ) );
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
				continue;
			}
			printf( '<option value="%1$d"%2$s>%3$s (#%1$d)</option>', (int) $post->ID, selected( $selected_id, (int) $post->ID, false ), esc_html( (string) $post->post_title ) );
		}
		echo '</select></td></tr><tr><th scope="row"><label for="wpconnector-elementor-json">' . esc_html__( 'Elementor JSON file', 'wordpressconnector' ) . '</label></th><td><input id="wpconnector-elementor-json" type="file" name="elementor_json" accept="application/json,.json" required><p class="description">' . esc_html__( 'Accepted fields: content, page_settings (or settings), version, title, type, global_classes and global_variables. Maximum file size: 5 MB.', 'wordpressconnector' ) . '</p></td></tr></tbody></table>';
		submit_button( __( 'Import Elementor JSON', 'wordpressconnector' ) );
		echo '</form></div>';
	}

	/**
	 * Process the handle import operation in the ElementorJsonImport component.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function handleImport(): void {
		check_admin_referer( self::ACTION );
		// A malformed or oversized post ID must never be coerced into another target.
		try {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Shared strict parser rejects malformed ID bytes before any import; nonce checked above.
			$post_id = $this->exact_import_target_id( isset( $_POST['post_id'] ) ? wp_unslash( $_POST['post_id'] ) : '0' );
		} catch ( RuntimeException $error ) {
			wp_die( esc_html__( 'Invalid Elementor JSON import target.', 'wordpressconnector' ), '', array( 'response' => 400 ) );
			return;
		}
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( ! $this->supports_post_type( $post_type ) || ! current_user_can( $this->capability_for_post_type( $post_type ) ) ) {
			wp_die( esc_html__( 'Invalid Elementor JSON import target.', 'wordpressconnector' ), '', array( 'response' => 400 ) );
		}
		try {
			$payload  = $this->uploaded_payload();
			$data     = $payload['content'];
			$settings = isset( $payload['page_settings'] ) && is_array( $payload['page_settings'] ) ? $payload['page_settings'] : ( isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array() );
			$adapter  = new ElementorAdapter();
			if ( $post_id > 0 ) {
				$post = get_post( $post_id );
				if ( ! $post instanceof \WP_Post || (string) $post->post_type !== $post_type ) {
					throw new RuntimeException( 'The selected import target no longer exists.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					wp_die( esc_html__( 'You are not allowed to edit this item.', 'wordpressconnector' ), '', array( 'response' => 403 ) );
				}
				$adapter_payload = array(
					'id'            => $post_id,
					'data'          => $data,
					'page_settings' => $settings,
					'edit_mode'     => 'builder',
				);
				if ( 'elementor_library' === $post_type ) {
					$adapter_payload['template_type'] = $this->template_type( $payload );
				}
				$adapter->replaceDocument( $adapter_payload, array( 'dry_run' => false ) );
				$result_id = $post_id;
			} else {
				$adapter_payload = array(
					'post_type'     => $post_type,
					'status'        => 'draft',
					'title'         => $this->import_title( $payload, $post_type ),
					'data'          => $data,
					'page_settings' => $settings,
					'edit_mode'     => 'builder',
				);
				if ( 'elementor_library' === $post_type ) {
					$adapter_payload['template_type'] = $this->template_type( $payload );
				}
				$created   = $adapter->createDocument( $adapter_payload, array( 'dry_run' => false ) );
				$result_id = isset( $created['document']['post_id'] ) ? (int) $created['document']['post_id'] : 0;
				if ( $result_id < 1 ) {
					throw new RuntimeException( 'Elementor JSON was imported but the new document ID could not be verified.' );
				}
			}
		} catch ( RuntimeException $error ) {
			wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 400 ) );
		} catch ( Throwable $error ) {
			wp_die( esc_html__( 'The Elementor JSON import failed.', 'wordpressconnector' ), '', array( 'response' => 500 ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'                      => $post_type,
					'wpconnector_elementor_imported' => 1,
					'post'                           => $result_id,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Process the uploaded payload operation in the ElementorJsonImport component.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function uploaded_payload(): array {
		if ( ! isset( $_FILES['elementor_json'] ) || ! is_array( $_FILES['elementor_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Private upload parser is reached only after handleImport() verifies its action nonce.
			throw new RuntimeException( 'No Elementor JSON file was uploaded.' );
		}
		$file = $_FILES['elementor_json']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce-checked private parser validates scalar metadata, sanitizes filename and verifies is_uploaded_file() before reading bytes.
		// PHP upload metadata must be scalar; reject nested form fields before casting.
		if ( ! isset( $file['error'], $file['size'], $file['name'], $file['tmp_name'] )
			|| ! is_int( $file['error'] ) || ! is_int( $file['size'] )
			|| ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] ) ) {
			throw new RuntimeException( 'Invalid Elementor JSON upload metadata.' );
		}
		$error = $file['error'];
		if ( UPLOAD_ERR_OK !== $error ) {
			throw new RuntimeException( 'The Elementor JSON upload failed.' );
		}
		$size = $file['size'];
		if ( $size < 1 || $size > self::MAX_BYTES ) {
			throw new RuntimeException( 'The Elementor JSON file must be between 1 byte and 5 MB.' );
		}
		$name = sanitize_file_name( $file['name'] );
		if ( 'json' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			throw new RuntimeException( 'Choose a .json Elementor export file.' );
		}
		$tmp_name = $file['tmp_name'];
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			throw new RuntimeException( 'The Elementor JSON upload is invalid.' );
		}
		$raw = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP upload temp path verified by is_uploaded_file() and size limits; HTTP requests cannot read local upload bytes.
		if ( ! is_string( $raw ) || '' === trim( $raw ) || strlen( $raw ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'The Elementor JSON file is empty or too large.' );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'The uploaded file is not valid JSON.' );
		}
		if ( isset( $decoded['format'] ) && 'wordpressconnector/elementor-site-parts-bundle' === $decoded['format'] ) {
			if ( ! isset( $decoded['document'] ) || ! is_array( $decoded['document'] ) ) {
				throw new RuntimeException( 'The Elementor site-parts bundle does not contain a document payload.' );
			}
			$decoded = $decoded['document'];
		}
		if ( ! isset( $decoded['content'] ) || ! is_array( $decoded['content'] ) || empty( $decoded['content'] ) ) {
			throw new RuntimeException( 'The JSON is not a non-empty Elementor document export.' );
		}
		return $decoded;
	}

	/**
	 * Process the bulk uploads operation in the ElementorJsonImport component.
	 *
	 * @return array Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function bulk_uploads(): array {
		$files = $_FILES['elementor_jsons'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- previewBulk() checks action nonce and capability; private parser validates all upload array shapes and paths before reading.
		if ( ! is_array( $files ) || ! isset( $files['name'] ) || ! is_array( $files['name'] ) ) {
			throw new RuntimeException( 'No files uploaded.' );
		}
		$count = count( $files['name'] );
		if ( $count < 1 || $count > self::BULK_LIMIT ) {
			throw new RuntimeException( 'Upload 1 to 20 JSON files.' );
		}
		// Reject sparse, mismatched or nested PHP upload arrays before reading files.
		if ( array_keys( $files['name'] ) !== range( 0, $count - 1 ) ) {
			throw new RuntimeException( 'Invalid Elementor JSON bulk upload indexes.' );
		}
		foreach ( array( 'error', 'size', 'tmp_name' ) as $field ) {
			if ( ! isset( $files[ $field ] ) || ! is_array( $files[ $field ] )
				|| array_keys( $files[ $field ] ) !== array_keys( $files['name'] ) ) {
				throw new RuntimeException( 'Invalid Elementor JSON bulk upload metadata.' );
			}
		}
		$total   = 0;
		$entries = array();
		for ( $i = 0;$i < $count;$i++ ) {
			if ( ! is_string( $files['name'][ $i ] ) || ! is_string( $files['tmp_name'][ $i ] )
				|| ! is_int( $files['error'][ $i ] ) || ! is_int( $files['size'][ $i ] ) ) {
				throw new RuntimeException( 'Invalid Elementor JSON bulk upload metadata.' );
			}
			if ( UPLOAD_ERR_OK !== $files['error'][ $i ] ) {
				throw new RuntimeException( 'One of the uploads failed.' );
			}
			$name   = sanitize_file_name( $files['name'][ $i ] );
			$size   = $files['size'][ $i ];
			$total += $size;
			if ( strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) !== 'json' || $size < 1 || $size > self::MAX_BYTES || $total > self::BULK_BYTES ) {
				throw new RuntimeException( 'Only JSON files up to 5 MB each and 8 MB in total are allowed.' );
			}
			$tmp = $files['tmp_name'][ $i ];
			if ( ! is_uploaded_file( $tmp ) ) {
				throw new RuntimeException( 'Invalid uploaded file.' );
			}
			$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP-uploaded local JSON file verified by is_uploaded_file() and bounded before decoding; not a URL.
			if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES ) {
				throw new RuntimeException( 'Could not read upload.' );
			}
			$payload = json_decode( $raw, true );
			if ( ! is_array( $payload ) || json_last_error() !== JSON_ERROR_NONE ) {
				throw new RuntimeException( 'Invalid JSON in ' . esc_html( $name ) );
			}
			if ( isset( $payload['format'] ) && 'wordpressconnector/elementor-site-parts-bundle' === $payload['format'] ) {
				$source  = isset( $payload['source'] ) && is_array( $payload['source'] ) ? $payload['source'] : array();
				$payload = $payload['document'] ?? array();
				if ( is_array( $payload ) && ! isset( $payload['source'] ) ) {
					$payload['source'] = $source;
				}
			}
			if ( ! isset( $payload['content'] ) || ! is_array( $payload['content'] ) || empty( $payload['content'] ) ) {
				throw new RuntimeException( 'Missing Elementor content in ' . esc_html( $name ) );
			}
			$entries[] = array(
				'name'    => $name,
				'payload' => $payload,
			);
		}
		return $entries;
	}

	/**
	 * Build bulk plan for the ElementorJsonImport component.
	 *
	 * @param array $entries Input for the operation.
	 *
	 * @return array Operation result.
	 */
	private function build_bulk_plan( array $entries ): array {
		$plan           = array();
		$seen_targets   = array();
		$template_types = array(
			'header',
			'footer',
			'section',
			'single',
			'single-post',
			'single-page',
			'archive',
			'search-results',
			'error-404',
			'popup',
			'loop-item',
			'loop',
			'kit',
		);
		foreach ( $entries as $entry ) {
			$name          = $entry['name'];
			$payload       = $entry['payload'];
			$source        = isset( $payload['source'] ) && is_array( $payload['source'] ) ? $payload['source'] : array();
			$declared_type = sanitize_key( (string) ( $source['post_type'] ?? '' ) );
			$document_type = sanitize_key( (string) ( $payload['type'] ?? '' ) );
			$inferred_type = '';
			if ( preg_match( '/^wp-([a-z0-9_-]+)$/D', $document_type, $type_match ) ) {
				$inferred_type = $type_match[1];
			} elseif ( 'page' === $document_type ) {
				$inferred_type = 'page';
			} elseif ( in_array( $document_type, $template_types, true ) ) {
				$inferred_type = 'elementor_library';
			}
			$post_type       = '' !== $declared_type ? $declared_type : $inferred_type;
			$family_conflict = '' !== $declared_type && '' !== $inferred_type
			&& $declared_type !== $inferred_type
			&& ! ( 'elementor_library' === $declared_type && 'page' === $document_type );
			if ( '' === $post_type || ! post_type_exists( $post_type )
			|| ( ! is_post_type_viewable( $post_type ) && 'elementor_library' !== $post_type ) ) {
				$post_type = '';
			}
			$slug = isset( $source['slug'] ) ? sanitize_title( (string) $source['slug'] ) : '';
			if ( '' === $slug ) {
				$slug = $this->bulk_filename_slug( $name );
			}
			$row = array(
				'name'          => $name,
				'post_type'     => $post_type,
				'slug'          => $slug,
				'post_id'       => 0,
				'target_label'  => '-',
				'status'        => 'conflict: unknown type or target',
				'content'       => $payload['content'],
				'settings'      => isset( $payload['page_settings'] ) && is_array( $payload['page_settings'] )
				? $payload['page_settings']
				: ( isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array() ),
				'template_type' => $document_type,
				'fingerprint'   => '',
			);
			if ( $family_conflict ) {
				$row['status'] = 'conflict: source post type does not match Elementor document type';
			} elseif ( '' !== $post_type && '' !== $slug ) {
				$matches = get_posts(
					array(
						'post_type'        => $post_type,
						'post_status'      => 'any',
						'name'             => $slug,
						'posts_per_page'   => 3,
						'fields'           => 'ids',
						'suppress_filters' => true,
					)
				);
				if ( 1 === count( $matches ) ) {
					$id   = (int) $matches[0];
					$post = get_post( $id );
					if ( $post instanceof \WP_Post && (string) $post->post_name === $slug
					&& current_user_can( 'edit_post', $id ) ) {
						$row['post_id']      = $id;
						$row['target_label'] = (string) $post->post_title . ' (#' . $id . ')';
						$stored_type         = (string) get_post_meta( $id, '_elementor_template_type', true );
						if ( 'builder' !== (string) get_post_meta( $id, '_elementor_edit_mode', true ) ) {
							$row['status'] = 'conflict: existing item is not an Elementor document';
						} elseif ( 'elementor_library' === $post_type
						&& ( '' === $document_type || $stored_type !== $document_type ) ) {
							$row['status'] = 'conflict: Elementor template type differs';
						} else {
							try {
								$inspection = ( new ElementorAdapter() )->inspect( array( 'id' => $id ) );
								$doc        = $inspection['document'] ?? null;
								if ( ! is_array( $doc ) || empty( $doc['data'] ) ) {
									$row['status'] = 'conflict: existing Elementor document is empty';
								} else {
									$row['fingerprint'] = (string) $inspection['fingerprint'];
									$row['status']      = 'ready';
								}
							} catch ( Throwable $error ) {
								$row['status'] = 'conflict: Elementor document read unavailable';
							}
						}
					} else {
						$row['status'] = 'conflict: insufficient permission or wrong slug';
					}
				} elseif ( count( $matches ) > 1 ) {
					$row['status'] = 'conflict: duplicate slug';
				} else {
					$row['status'] = 'conflict: no exact existing slug';
				}
			}
			if ( $row['post_id'] > 0 ) {
				if ( isset( $seen_targets[ $row['post_id'] ] ) ) {
					$row['status']                                      = 'conflict: repeated target';
					$plan[ $seen_targets[ $row['post_id'] ] ]['status'] = 'conflict: repeated target';
				} else {
					$seen_targets[ $row['post_id'] ] = count( $plan );
				}
			}
			$plan[] = $row;
		}
		return $plan;
	}
	/**
	 * Infer only recognized export filename forms. Unknown suffixes are left
	 * untouched so an accidental filename never becomes a fuzzy target.
	 * Every inferred slug still requires exactly one matching post type.
	 *
	 * @param string $filename Filename to process.
	 */
	private function bulk_filename_slug( string $filename ): string {
		$stem = sanitize_title( (string) pathinfo( $filename, PATHINFO_FILENAME ) );
		if ( preg_match( '/^(.+)-elementor(?:-(?:with-site-parts|seo-herzien|lang-min125))?(?:-[1-9][0-9]*)?$/D', $stem, $match ) ) {
			return $match[1];
		}
		return $stem;
	}

	/**
	 * Process the import title operation in the ElementorJsonImport component.
	 *
	 * @param array  $payload Requested action payload.
	 * @param string $post_type Input for the operation.
	 *
	 * @return string Operation result.
	 */
	private function import_title( array $payload, string $post_type ): string {
		$title = isset( $payload['title'] ) && is_string( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : '';
		return '' !== $title ? $title : ( 'elementor_library' === $post_type ? 'Imported Elementor Template' : 'Imported Elementor ' . ucfirst( $post_type ) );
	}

	/**
	 * Process the template type operation in the ElementorJsonImport component.
	 *
	 * @param array $payload Requested action payload.
	 *
	 * @return string Operation result.
	 */
	private function template_type( array $payload ): string {
		$type = isset( $payload['type'] ) && is_string( $payload['type'] ) ? sanitize_key( $payload['type'] ) : '';
		return '' !== $type ? $type : 'page';
	}

	/**
	 * Process the import url operation in the ElementorJsonImport component.
	 *
	 * @param string $post_type Input for the operation.
	 * @param int    $post_id Input for the operation.
	 *
	 * @return string Operation result.
	 */
	private function import_url( string $post_type, int $post_id ): string {
		$slug = 'page' === $post_type ? 'wpconnector-import-elementor-page' : ( 'post' === $post_type ? 'wpconnector-import-elementor-post' : 'wpconnector-import-elementor-template' );
		$base = 'post' === $post_type ? admin_url( 'edit.php' ) : admin_url( 'edit.php?post_type=' . $post_type );
		return add_query_arg(
			array(
				'page'    => $slug,
				'post_id' => $post_id,
			),
			$base
		);
	}

	/**
	 * Process the capability for post type operation in the ElementorJsonImport component.
	 *
	 * @param string $post_type Input for the operation.
	 *
	 * @return string Operation result.
	 */
	private function capability_for_post_type( string $post_type ): string {
		return 'page' === $post_type ? 'edit_pages' : 'edit_posts';
	}

	/**
	 * Resolve the same canonical target ID for import form selection and submission.
	 *
	 * @param mixed $raw Unsanitized, unslashed request value.
	 *
	 * @return int Validated target (zero means create a new draft).
	 * @throws \RuntimeException When the target is malformed or out of integer range.
	 */
	private function exact_import_target_id( $raw ): int {
		if ( ! is_string( $raw ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $raw )
			|| false === filter_var( $raw, FILTER_VALIDATE_INT ) ) {
			throw new RuntimeException( 'Invalid Elementor JSON import target.' );
		}
		return (int) $raw;
	}

	/**
	 * Check support for post type for the ElementorJsonImport component.
	 *
	 * @param string $post_type Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private function supports_post_type( string $post_type ): bool {
		return in_array( $post_type, self::POST_TYPES, true );
	}
}
