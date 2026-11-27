<?php
/**
 * Canonical public method implementations for ElementorJsonImport.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Admin;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Adapters\ElementorAdapter;
/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait ElementorJsonImportMethods {
	/**
	 * Process the row actions operation in the ElementorJsonImport component.
	 *
	 * @param array    $actions Input for the operation.
	 * @param \WP_Post $post Input for the operation.
	 *
	 * @return array Operation result.
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( ! $this->supports_post_type( (string) $post->post_type ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $actions;
		}
		$actions['wpconnector_import_elementor_json'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $this->import_url( (string) $post->post_type, (int) $post->ID ) ), esc_html__( 'Import Elementor JSON', 'wordpressconnector' ) );
		return $actions;
	}

	/**
	 * Process the register import pages operation in the ElementorJsonImport component.
	 */
	public function register_import_pages(): void {
		add_management_page( __( 'Elementor bulkimport', 'wordpressconnector' ), __( 'Elementor bulkimport', 'wordpressconnector' ), 'manage_options', 'wpconnector-elementor-bulk', array( $this, 'renderBulkPage' ) );
		add_submenu_page(
			'edit.php?post_type=page',
			__( 'Import Elementor JSON', 'wordpressconnector' ),
			__( 'Import Elementor JSON', 'wordpressconnector' ),
			'edit_pages',
			'wpconnector-import-elementor-page',
			function (): void {
				$this->renderImportPage( 'page' );
			}
		);
		add_submenu_page(
			'edit.php',
			__( 'Import Elementor JSON', 'wordpressconnector' ),
			__( 'Import Elementor JSON', 'wordpressconnector' ),
			'edit_posts',
			'wpconnector-import-elementor-post',
			function (): void {
				$this->renderImportPage( 'post' );
			}
		);
		if ( post_type_exists( 'elementor_library' ) ) {
			add_submenu_page(
				'edit.php?post_type=elementor_library',
				__( 'Import Elementor JSON', 'wordpressconnector' ),
				__( 'Import Elementor JSON', 'wordpressconnector' ),
				'edit_posts',
				'wpconnector-import-elementor-template',
				function (): void {
					$this->renderImportPage( 'elementor_library' );
				}
			);
		}
	}

	/**
	 * Render bulk page for the ElementorJsonImport component.
	 */
	public function render_bulk_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>Elementor JSON bulkimport</h1><p>Upload maximaal 20 losse Elementor JSON-bestanden (totaal maximaal 8 MB). Elk bestand wordt alleen gekoppeld aan een <strong>unieke bestaande slug in het juiste berichttype</strong>. Er worden geen pagina’s op titelgelijkheid gekozen en er worden geen nieuwe berichten gemaakt. Eerst controleren, daarna afzonderlijk bevestigen.</p>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" enctype="multipart/form-data">';
		wp_nonce_field( self::BULK_PREVIEW );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::BULK_PREVIEW ) . '">';
		echo '<p><input type="file" name="elementor_jsons[]" accept=".json,application/json" multiple required></p>';
		submit_button( 'Controleer bestanden en doelpagina’s' );
		echo '</form></div>';
	}

	/**
	 * Process the preview bulk operation in the ElementorJsonImport component.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function preview_bulk(): void {
		check_admin_referer( self::BULK_PREVIEW );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		try {
			$entries = $this->bulk_uploads();
			$plan    = $this->build_bulk_plan( $entries );
			$all_ok  = true;
			foreach ( $plan as $row ) {
				if ( 'ready' !== $row['status'] ) {
					$all_ok = false;
				}
			}
			$token = wp_generate_password( 48, false, false );
			// Store confirmed previews only: cap transient size to avoid large database writes.
			if ( $all_ok && ! set_transient(
				'wpconnector_bulk_' . get_current_user_id() . '_' . hash( 'sha256', $token ),
				array(
					'plan'    => $plan,
					'created' => time(),
				),
				10 * MINUTE_IN_SECONDS
			) ) {
				throw new RuntimeException( 'Could not store this import preview securely. No changes were made.' );
			}
			echo '<div class="wrap"><h1>Bulkimport controleren</h1><p>Alleen de hieronder getoonde bestaande Elementor-documenten kunnen worden overschreven. Bij een conflict wordt de hele batch geblokkeerd.</p><table class="widefat striped"><thead><tr><th>Bestand</th><th>Berichttype</th><th>Doel</th><th>Status</th></tr></thead><tbody>';
			foreach ( $plan as $row ) {
				echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['post_type'] ) . '</td><td>' . esc_html( $row['target_label'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( $all_ok ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::BULK_CONFIRM );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::BULK_CONFIRM ) . '"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
				echo '<p><label><input type="checkbox" name="confirmed" value="yes" required> Ik heb alle doel-ID’s gecontroleerd en geef toestemming om hun Elementor-inhoud te overschrijven.</label></p>';
				submit_button( 'Bevestig overschrijven van ' . count( $plan ) . ' documenten', 'primary' );
				echo '</form>';
			} else {
				echo '<p><strong>Batch geblokkeerd:</strong> los alle conflicten op en upload opnieuw.</p>';
			}
			echo '</div>';
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 400 ) );
		}
	}

	/**
	 * Process the confirm bulk operation in the ElementorJsonImport component.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public function confirm_bulk(): void {
		check_admin_referer( self::BULK_CONFIRM );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9]{48}$/D', $token ) || ! isset( $_POST['confirmed'] ) || 'yes' !== $_POST['confirmed'] ) {
			wp_die( 'Bulkimport not confirmed.', '', array( 'response' => 400 ) );
		}
		$key   = 'wpconnector_bulk_' . get_current_user_id() . '_' . hash( 'sha256', $token );
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['plan'] ) || ! is_array( $state['plan'] ) ) {
			wp_die( 'Preview expired. Upload again.', '', array( 'response' => 409 ) );
		}
		delete_transient( $key );
		// Single-use confirmation.
		$plan = $state['plan'];
		if ( count( $plan ) < 1 || count( $plan ) > self::BULK_LIMIT ) {
			wp_die( 'Invalid plan.', '', array( 'response' => 400 ) );
		}
		// Reuse the same mutation lock as authenticated REST/MCP requests.
		$store = new \Webactueel\WordPressConnector\Runtime\ProcessedStore();
		$lock  = $store->acquireMutationLock();
		if ( '' === $lock ) {
			wp_die( 'Another connector mutation is running. Preview again after it finishes.', '', array( 'response' => 409 ) );
		}
		$adapter      = new ElementorAdapter();
		$snapshots    = array();
		$updated      = array();
		$in_flight_id = 0;
		try {
			foreach ( $plan as $row ) {
				$id = (int) ( $row['post_id'] ?? 0 );
				if ( 'ready' !== ( $row['status'] ?? '' ) || $id < 1 || ! current_user_can( 'edit_post', $id ) ) {
					throw new RuntimeException( 'Target permission changed.' );
				}
				$post = get_post( $id );
				if ( ! $post instanceof \WP_Post || (string) $post->post_type !== $row['post_type']
				|| (string) $post->post_name !== $row['slug']
				|| 'builder' !== (string) get_post_meta( $id, '_elementor_edit_mode', true ) ) {
					throw new RuntimeException( 'Target identity or Elementor editing mode changed.' );
				}
				$current = $adapter->inspect( array( 'id' => $id ) );
				if ( ! hash_equals( (string) $row['fingerprint'], (string) ( $current['fingerprint'] ?? '' ) ) ) {
					throw new RuntimeException( 'Document changed since preview.' );
				}
				$snapshots[ $id ] = $current['document'];
			}
			foreach ( $plan as $row ) {
				$id = (int) $row['post_id'];
				// A concurrent normal editor save is not protected by Connector locks.
				// Check the target again immediately before overwriting it.
				$current = $adapter->inspect( array( 'id' => $id ) );
				if ( ! hash_equals( (string) $row['fingerprint'], (string) ( $current['fingerprint'] ?? '' ) ) ) {
					throw new RuntimeException( 'Document changed during bulk import.' );
				}
				$payload = array(
					'id'            => $id,
					'data'          => $row['content'],
					'page_settings' => $row['settings'],
					'edit_mode'     => 'builder',
				);
				if ( 'elementor_library' === $row['post_type'] ) {
					$payload['template_type'] = $row['template_type'];
				}
				$in_flight_id = $id;
				$adapter->replaceDocument( $payload, array( 'dry_run' => false ) );
				$updated[]    = $id;
				$in_flight_id = 0;
			}
			echo '<div class="wrap"><h1>Elementor bulkimport voltooid</h1><p>'
			. count( $updated ) . ' documenten zijn opgeslagen en gecontroleerd.</p>'
			. '<p>Controleer vervolgens de frontend, formulieren en responsieve opmaak.</p></div>';
		} catch ( Throwable $error ) {
			$failed_rollbacks = array();
			foreach ( array_reverse( $updated ) as $id ) {
				try {
					$before = $snapshots[ $id ];
					$adapter->replaceDocument(
						array(
							'id'            => $id,
							'data'          => $before['data'],
							'page_settings' => $before['page_settings'],
							'edit_mode'     => $before['edit_mode'],
							'template_type' => $before['template_type'],
							'conditions'    => $before['conditions'],
						),
						array( 'dry_run' => false )
					);
				} catch ( Throwable $rollback_error ) {
					$failed_rollbacks[] = $id;
				}
			}
			$message = 'Bulkimport gestopt: ' . $error->getMessage() . '. ';
			if ( ! $updated && $in_flight_id <= 0 ) {
				$message .= 'Er is door deze batch geen document bijgewerkt.';
			} elseif ( ! $updated ) {
				$message .= 'Een schrijfactie op document-ID ' . $in_flight_id
				. ' kan gedeeltelijk zijn uitgevoerd. Controleer de Elementor-inhoud en back-up vóór opnieuw importeren.';
			} elseif ( ! $failed_rollbacks ) {
				$message .= 'Eerdere voltooide documenten zijn teruggezet en gecontroleerd.';
			} else {
				$message .= 'Herstel moet handmatig worden gecontroleerd voor document-ID(s): '
				. implode( ', ', $failed_rollbacks ) . '. Controleer de back-up of revisies.';
			}
			if ( $in_flight_id > 0 && $updated ) {
				$message .= ' Controleer ook het mislukte document-ID ' . $in_flight_id
				. '; de interne Elementor-rollback kon hier niet onafhankelijk worden bevestigd.';
			}
			// wp_die() can terminate PHP without invoking finally: release first.
			$store->releaseMutationLock( $lock );
			wp_die( esc_html( $message ), '', array( 'response' => 409 ) );
		} finally {
			$store->releaseMutationLock( $lock );
		}
	}

	/**
	 * Preserve the legacy PHP entrypoint; delegate to the canonical implementation.
	 *
	 * @param string $post_type Input for the operation.
	 */
	private function render_import_page_compat( string $post_type ): void {
		$this->render_import_page( $post_type );
	}

	/**
	 * Preserve the legacy PHP entrypoint; delegate to the canonical implementation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	private function handle_import_compat(): void {
		$this->handle_import();
	}
}
