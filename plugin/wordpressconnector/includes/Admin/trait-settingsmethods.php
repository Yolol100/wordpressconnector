<?php
/**
 * Canonical public administration methods for Settings.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Admin;

use Webactueel\WordPressConnector\Security\SitePairing;
use Webactueel\WordPressConnector\Security\NativeOAuthServer;
use Webactueel\WordPressConnector\Adapters\ConnectionReadinessAdapter;

/**
 * Canonical implementations with native compatibility aliases.
 */
trait SettingsMethods {
	/**
	 * Process the unpaired notice operation in the Settings component.
	 */
	public function unpaired_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || SitePairing::pairedAdministratorId() ) {
			return;
		}
		// The settings overview already displays pairing state and its action.
		// Avoid repeating the global notice inside this screen's page heading.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && 'settings_page_' . self::PAGE === $screen->id ) {
			return;
		}
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'WordPress Connector: GitHub connection is not active. Normal administrator activation connects automatically; CLI, network-wide or older installations can be connected here.', 'wordpressconnector' ) . ' ';
		echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Review site authorization', 'wordpressconnector' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Process the register page operation in the Settings component.
	 */
	public function register_page(): void {
		add_options_page( 'WordPress Connector', 'WordPress Connector', 'manage_options', self::PAGE, array( $this, 'renderPage' ) );
	}


	/**
	 * Test only the currently authenticated administrator browser session.
	 * This does not prove external ChatGPT OAuth access.
	 *
	 * @param string $hook Registered WordPress hook name.
	 */
	public function enqueue_connection_doctor( string $hook ): void {
		if ( 'settings_page_' . self::PAGE !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$src = plugins_url( 'assets/connection-doctor.js', WPCONNECTOR_FILE );
		wp_enqueue_script( 'wpconnector-connection-doctor', $src, array(), WPCONNECTOR_VERSION, true );
		wp_localize_script(
			'wpconnector-connection-doctor',
			'WPConnectorDoctor',
			array(
				'url'        => rest_url( 'webactueel-wordpress-connector/v1/mcp' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'checking'   => __( 'Checking this signed-in browser session...', 'wordpressconnector' ),
				'success'    => __( 'WordPress browser session confirmed. External ChatGPT OAuth is NOT yet proven.', 'wordpressconnector' ),
				'failed'     => __( 'This browser could not reach the authenticated MCP endpoint.', 'wordpressconnector' ),
				'copied'     => __( 'Endpoint copied. Do not paste application passwords into chats.', 'wordpressconnector' ),
				'copyFailed' => __( 'Copy unavailable; select the address above and copy it manually.', 'wordpressconnector' ),
			)
		);
	}

	/**
	 * Process the save site pairing operation in the Settings component.
	 */
	public function save_site_pairing(): void {
		if ( 'POST' !== self::request_method() ) {
			wp_die(
				esc_html__( 'Use the WordPress Connector settings form to change GitHub site authorization.', 'wordpressconnector' ),
				esc_html__( 'Method not allowed', 'wordpressconnector' ),
				array( 'response' => 405 )
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Only a site administrator can authorize the Connector.', 'wordpressconnector' ),
				esc_html__( 'Permission denied', 'wordpressconnector' ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( 'wpconnector_site_pairing' );
		$decision = isset( $_POST['pairing_decision'] ) && is_string( $_POST['pairing_decision'] )
			? sanitize_key( wp_unslash( $_POST['pairing_decision'] ) )
			: '';
		try {
			if ( 'enable' === $decision ) {
				SitePairing::enableForAdministrator( get_current_user_id() );
			} elseif ( 'disable' === $decision ) {
				SitePairing::disable();
			} else {
				wp_die(
					esc_html__( 'Unsupported GitHub authorization decision.', 'wordpressconnector' ),
					esc_html__( 'Invalid request', 'wordpressconnector' ),
					array( 'response' => 400 )
				);
			}
		} catch ( \RuntimeException $error ) {
			wp_die(
				esc_html__( 'Could not save GitHub site authorization. Please review the WordPress storage configuration.', 'wordpressconnector' ),
				esc_html__( 'Site authorization unavailable', 'wordpressconnector' ),
				array( 'response' => 503 )
			);
		}
		wp_safe_redirect( add_query_arg( 'pairing_saved', '1', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}


	/**
	 * A WordPress administrator may explicitly generate one dedicated
	 * Application Password for their OWN account. Only this one response
	 * contains the plaintext credential; GitHub OIDC never uses it.
	 */
	public function create_application_password(): void {
		if ( 'POST' !== self::request_method() ) {
			wp_die(
				esc_html__( 'Use the WordPress Connector settings form to create a credential.', 'wordpressconnector' ),
				esc_html__( 'Method not allowed', 'wordpressconnector' ),
				array( 'response' => 405 )
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Only the signed-in site administrator can create a Connector credential.', 'wordpressconnector' ),
				esc_html__( 'Permission denied', 'wordpressconnector' ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( 'wpconnector_create_application_password' );
		$confirmation = isset( $_POST['confirm_direct_access'] ) && is_string( $_POST['confirm_direct_access'] )
			? sanitize_text_field( wp_unslash( $_POST['confirm_direct_access'] ) )
			: '';
		if ( '1' !== $confirmation ) {
			wp_die(
				esc_html__( 'Confirm that the credential will have your WordPress account permissions.', 'wordpressconnector' ),
				esc_html__( 'Confirmation required', 'wordpressconnector' ),
				array( 'response' => 400 )
			);
		}

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();
		if ( $user_id <= 0 || ! is_object( $user ) || (int) $user->ID !== $user_id ||
			! current_user_can( 'create_app_password', $user_id ) ) {
			wp_die(
				esc_html__( 'Application Password permission is not available for the signed-in account.', 'wordpressconnector' ),
				esc_html__( 'Permission denied', 'wordpressconnector' ),
				array( 'response' => 403 )
			);
		}

		$mcp_url = rest_url( 'webactueel-wordpress-connector/v1/mcp' );
		if ( ! is_ssl() || 'https' !== strtolower( (string) wp_parse_url( $mcp_url, PHP_URL_SCHEME ) ) ||
			! function_exists( 'wp_is_application_passwords_available_for_user' ) ||
			! wp_is_application_passwords_available_for_user( $user_id ) ) {
			wp_die(
				esc_html__( 'Application Passwords require HTTPS and must be enabled for this WordPress account.', 'wordpressconnector' ),
				esc_html__( 'Secure connection unavailable', 'wordpressconnector' ),
				array( 'response' => 403 )
			);
		}

		if ( \WP_Application_Passwords::application_name_exists_for_user( $user_id, self::DIRECT_MCP_PASSWORD_LABEL ) ) {
			wp_die(
				esc_html__( 'A direct MCP credential already exists for this account. Revoke it under Users > Profile before generating a replacement.', 'wordpressconnector' ),
				esc_html__( 'Credential already exists', 'wordpressconnector' ),
				array( 'response' => 409 )
			);
		}

		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => self::DIRECT_MCP_PASSWORD_LABEL )
		);
		if ( is_wp_error( $created ) || ! is_array( $created ) || ! isset( $created[0] ) || ! is_string( $created[0] ) ) {
			wp_die(
				esc_html__( 'WordPress could not create the application password.', 'wordpressconnector' ),
				esc_html__( 'Credential creation failed', 'wordpressconnector' ),
				array( 'response' => 500 )
			);
		}

		// No redirects, query parameters, options, transient storage or
		// GitHub requests may carry the one-time plaintext value.
		nocache_headers();
		$message      = '<h1>' . esc_html__( 'Direct MCP credential created', 'wordpressconnector' ) . '</h1>';
		$message     .= '<p>' . esc_html__( 'Copy this password now. WordPress will not show it again.', 'wordpressconnector' ) . '</p>';
		$message     .= '<p><label for="wpconnector-mcp-url">' . esc_html__( 'MCP endpoint', 'wordpressconnector' ) . '</label>';
		$message     .= '<input id="wpconnector-mcp-url" type="text" readonly="readonly" value="' . esc_attr( $mcp_url ) . '" style="width:100%" /></p>';
		$message     .= '<p><label for="wpconnector-mcp-user">' . esc_html__( 'WordPress username', 'wordpressconnector' ) . '</label>';
		$message     .= '<input id="wpconnector-mcp-user" type="text" readonly="readonly" value="' . esc_attr( (string) $user->user_login ) . '" style="width:100%" /></p>';
		$message     .= '<p><label for="wpconnector-mcp-password">' . esc_html__( 'One-time application password', 'wordpressconnector' ) . '</label>';
		$message     .= '<input id="wpconnector-mcp-password" type="text" readonly="readonly" autocomplete="off" value="' . esc_attr( $created[0] ) . '" style="width:100%" /></p>';
		$message     .= '<p>' . esc_html__( 'Enter the details only into a trusted HTTPS REST/MCP client that supports WordPress Application Passwords. ChatGPT web connections may require OAuth instead. Never put this password in a GitHub branch, workflow, chat or URL. GitHub Actions already uses password-free OIDC.', 'wordpressconnector' ) . '</p>';
		$message     .= '<p><a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'Manage or revoke this password in your profile', 'wordpressconnector' ) . '</a></p>';
		$allowed_html = array(
			'h1'    => array(),
			'p'     => array(),
			'label' => array( 'for' => true ),
			'input' => array(
				'id'           => true,
				'type'         => true,
				'readonly'     => true,
				'autocomplete' => true,
				'value'        => true,
				'style'        => true,
			),
			'a'     => array( 'href' => true ),
		);
		wp_die(
			wp_kses( $message, $allowed_html ),
			esc_html__( 'WordPress Connector direct MCP setup', 'wordpressconnector' ),
			array(
				'response'  => 200,
				'back_link' => false,
			)
		);
	}

	/**
	 * Render page for the Settings component.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$paired = (bool) SitePairing::pairedAdministratorId();
		$oauth  = NativeOAuthServer::is_enabled();
		$legacy = $this->legacy_bridge_active();
		$checks = array(
			__( 'WordPress 6.4 or newer', 'wordpressconnector' ) => version_compare( get_bloginfo( 'version' ), '6.4', '>=' ),
			__( 'PHP 7.4 or newer', 'wordpressconnector' ) => version_compare( PHP_VERSION, '7.4', '>=' ),
			__( 'PHP OpenSSL extension', 'wordpressconnector' ) => extension_loaded( 'openssl' ),
			__( 'WordPress sees HTTPS', 'wordpressconnector' ) => is_ssl(),
			__( 'REST endpoint uses HTTPS', 'wordpressconnector' ) => 'https' === strtolower( (string) wp_parse_url( rest_url( 'webactueel-wordpress-connector/v1/presence' ), PHP_URL_SCHEME ) ),
		);

		echo '<div class="wrap wpconnector-admin">';
		echo '<header class="wpconnector-header"><div>';
		echo '<p class="wpconnector-eyebrow">' . esc_html__( 'Connections and site access', 'wordpressconnector' ) . '</p>';
		echo '<h1>' . esc_html__( 'WordPress Connector', 'wordpressconnector' ) . '</h1>';
		echo '<p>' . esc_html__( 'Manage ChatGPT and GitHub access without leaving your WordPress dashboard.', 'wordpressconnector' ) . '</p>';
		echo '</div><a class="button button-primary" href="#wpconnector-chatgpt">' . esc_html__( 'Manage ChatGPT', 'wordpressconnector' ) . '</a></header>';

		if ( $legacy ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Legacy Elementor JSON Bridge detected.', 'wordpressconnector' ) . '</strong> ';
			echo esc_html__( 'Keep the legacy plugin until the staging parity and rollback checklist passes, then deactivate it.', 'wordpressconnector' ) . '</p></div>';
		}

		$tabs = self::navigation_tabs();
		echo '<div class="wpconnector-admin-tabs" id="wpconnector-admin-tabs">';
		echo '<nav class="wpconnector-tab-fallback" aria-label="' . esc_attr__( 'WordPress Connector sections', 'wordpressconnector' ) . '">';
		foreach ( $tabs as $name => $title ) {
			echo '<a href="#wpconnector-' . esc_attr( $name ) . '">' . esc_html( $title ) . '</a>';
		}
		echo '</nav></div>';

		echo '<div class="wpconnector-panels">';
		echo '<section id="wpconnector-overview" class="wpconnector-panel" aria-labelledby="wpconnector-heading-overview">';
		$this->render_overview( $paired, $oauth, $checks );
		echo '</section>';
		echo '<section id="wpconnector-chatgpt" class="wpconnector-panel" aria-labelledby="wpconnector-heading-chatgpt">';
		echo '<h2 id="wpconnector-heading-chatgpt">' . esc_html__( 'ChatGPT connection', 'wordpressconnector' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Use WordPress-hosted OAuth to connect ChatGPT. Read-only access is the default; staging file edits require separate OAuth consent and server approval.', 'wordpressconnector' ) . '</p>';
		$this->render_connection_recovery_guide();
		echo '</section>';
		echo '<section id="wpconnector-github" class="wpconnector-panel" aria-labelledby="wpconnector-heading-github">';
		$this->render_github_panel( $paired );
		echo '</section>';
		echo '<section id="wpconnector-advanced" class="wpconnector-panel" aria-labelledby="wpconnector-heading-advanced">';
		$this->render_advanced_panel( $checks, $legacy );
		echo '</section>';
		echo '</div></div>';
	}
}
