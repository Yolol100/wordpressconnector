<?php
/**
 * Supports Settings workflows in WordPress administration.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Admin;

use Webactueel\WordPressConnector\Security\SitePairing;
use Webactueel\WordPressConnector\Security\NativeOAuthServer;
use Webactueel\WordPressConnector\Adapters\ConnectionReadinessAdapter;

require_once __DIR__ . '/trait-settingsmethods.php';

/**
 * Supports Settings workflows in WordPress administration.
 */
final class Settings {
	use SettingsMethods {
		unpaired_notice as unpairedNotice;
		register_page as registerPage;
		enqueue_connection_doctor as enqueueConnectionDoctor;
		save_site_pairing as saveSitePairing;
		create_application_password as createApplicationPassword;
		render_page as renderPage;
	}

	private const PAGE                      = 'wordpressconnector';
	private const DIRECT_MCP_PASSWORD_LABEL = 'WordPress Connector - Direct MCP';

	/**
	 * Register the Settings component actions and handlers.
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return; }
		add_action( 'admin_menu', array( $this, 'registerPage' ) );
		add_action( 'admin_notices', array( $this, 'unpairedNotice' ) );
		add_action( 'admin_post_wpconnector_site_pairing', array( $this, 'saveSitePairing' ) );
		add_action( 'admin_post_wpconnector_create_application_password', array( $this, 'createApplicationPassword' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueConnectionDoctor' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_interface' ) );
	}

	/**
	 * Canonical navigation shared by the server-rendered fallback and React tabs.
	 *
	 * @return array<string, string> Human-readable tab labels by stable ID.
	 */
	private static function navigation_tabs(): array {
		return array(
			'overview' => __( 'Overview', 'wordpressconnector' ),
			'chatgpt'  => __( 'ChatGPT', 'wordpressconnector' ),
			'github'   => __( 'GitHub', 'wordpressconnector' ),
			'advanced' => __( 'Advanced', 'wordpressconnector' ),
		);
	}

	/**
	 * Load the WordPress-native admin presentation only on this settings screen.
	 * React handles navigation; existing nonce-protected PHP forms remain canonical.
	 *
	 * @param string $hook Current admin screen.
	 */
	public function enqueue_admin_interface( string $hook ): void {
		if ( 'settings_page_' . self::PAGE !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_style(
			'wpconnector-admin-ui',
			plugins_url( 'assets/admin-ui.css', WPCONNECTOR_FILE ),
			array( 'wp-components' ),
			WPCONNECTOR_VERSION
		);
		wp_enqueue_script(
			'wpconnector-admin-ui',
			plugins_url( 'assets/admin-ui.js', WPCONNECTOR_FILE ),
			array( 'wp-element', 'wp-components' ),
			WPCONNECTOR_VERSION,
			true
		);
		$tab_config = array();
		foreach ( self::navigation_tabs() as $name => $title ) {
			$tab_config[] = array(
				'name'  => $name,
				'title' => $title,
			);
		}
		wp_localize_script(
			'wpconnector-admin-ui',
			'WPConnectorAdminUI',
			array(
				'tabs'     => $tab_config,
				'navLabel' => __( 'WordPress Connector sections', 'wordpressconnector' ),
			)
		);
	}

	/**
	 * Read a validated request method for administration-only write handlers.
	 */
	private static function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';
	}

	/**
	 * Show only the core decisions and local-state evidence first.
	 *
	 * @param bool  $paired Whether GitHub approval is locally recorded.
	 * @param bool  $oauth  Whether native OAuth is enabled.
	 * @param array $checks Installation prerequisite results.
	 */
	private function render_overview( bool $paired, bool $oauth, array $checks ): void {
		$ready = count( array_filter( $checks ) );
		$total = count( $checks );
		echo '<h2 id="wpconnector-heading-overview">' . esc_html__( 'At a glance', 'wordpressconnector' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Settings shown here are local WordPress status, not proof of a remote ChatGPT or GitHub login.', 'wordpressconnector' ) . '</p>';
		echo '<div class="wpconnector-summary-grid">';

		echo '<div class="wpconnector-summary-card"><h3>' . esc_html__( 'ChatGPT OAuth', 'wordpressconnector' ) . '</h3>';
		echo '<p class="wpconnector-state">' . esc_html( $oauth ? __( 'Enabled', 'wordpressconnector' ) : __( 'Disabled', 'wordpressconnector' ) ) . '</p>';
		if ( NativeOAuthServer::staging_files_enabled() && ( current_user_can( 'edit_plugins' ) || current_user_can( 'edit_themes' ) ) ) {
			echo '<p>' . esc_html__( 'Read-only by default. Staging file edits require separate OAuth consent; an active ChatGPT grant is not yet verified.', 'wordpressconnector' ) . '</p>';
		} elseif ( NativeOAuthServer::staging_files_enabled() ) {
			echo '<p>' . esc_html__( 'Read-only ChatGPT access is available. WordPress blocks this account from editing plugin or theme files.', 'wordpressconnector' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Grant read-only access with explicit WordPress consent. Staging file editing is not configured.', 'wordpressconnector' ) . '</p>';
		}
		echo '<a href="#wpconnector-chatgpt">' . esc_html__( 'Manage ChatGPT connection', 'wordpressconnector' ) . '</a></div>';

		echo '<div class="wpconnector-summary-card"><h3>' . esc_html__( 'GitHub Actions', 'wordpressconnector' ) . '</h3>';
		echo '<p class="wpconnector-state">' . esc_html( $paired ? __( 'Site authorization recorded', 'wordpressconnector' ) : __( 'Not connected', 'wordpressconnector' ) ) . '</p>';
		echo '<p>' . esc_html__( 'Remote workflow access still requires an independent check.', 'wordpressconnector' ) . '</p>';
		echo '<a href="#wpconnector-github">' . esc_html__( 'Manage GitHub access', 'wordpressconnector' ) . '</a></div>';

		echo '<div class="wpconnector-summary-card"><h3>' . esc_html__( 'Site readiness', 'wordpressconnector' ) . '</h3>';
		echo '<p class="wpconnector-state">' . esc_html( sprintf( '%1$d / %2$d', $ready, $total ) ) . '</p>';
		echo '<p>' . esc_html__( 'Basic requirements checked within this WordPress request.', 'wordpressconnector' ) . '</p>';
		echo '<a href="#wpconnector-advanced">' . esc_html__( 'View technical checks', 'wordpressconnector' ) . '</a></div>';
		echo '</div>';

		echo '<div class="wpconnector-card wpconnector-steps"><h3>' . esc_html__( 'Connect in three steps', 'wordpressconnector' ) . '</h3>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Enable WordPress-hosted OAuth in the ChatGPT tab.', 'wordpressconnector' ) . '</li>';
		echo '<li>' . esc_html__( 'In ChatGPT Plugins, add a custom MCP server with this site’s MCP URL and select OAuth. Approve the WordPress consent screen.', 'wordpressconnector' ) . '</li>';
		echo '<li>' . esc_html__( 'Run the browser connection check. Test the actual ChatGPT link separately.', 'wordpressconnector' ) . '</li>';
		echo '</ol></div>';
	}

	/**
	 * Render the original GitHub pairing and release controls.
	 *
	 * @param bool $paired Whether site approval is recorded.
	 */
	private function render_github_panel( bool $paired ): void {
		echo '<h2 id="wpconnector-heading-github">' . esc_html__( 'GitHub connection', 'wordpressconnector' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This connection uses short-lived GitHub Actions OIDC tokens, not a stored API key.', 'wordpressconnector' ) . '</p>';
		echo '<div class="wpconnector-card"><h3>' . esc_html__( 'Site authorization', 'wordpressconnector' ) . '</h3>';
		echo '<p class="wpconnector-state">' . esc_html( $paired ? __( 'Trusted workflow authorized on this site', 'wordpressconnector' ) : __( 'Not connected', 'wordpressconnector' ) ) . '</p>';
		echo '<p>' . esc_html__( 'Administrator activation normally pairs this site with the pinned Yolol100/wordpressconnector workflow. Only sites you manage should be paired. A moved or cloned site needs new authorization.', 'wordpressconnector' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpconnector_site_pairing' );
		echo '<input type="hidden" name="action" value="wpconnector_site_pairing" />';
		if ( $paired ) {
			echo '<input type="hidden" name="pairing_decision" value="disable" />';
			submit_button( __( 'Revoke GitHub Actions access for this site', 'wordpressconnector' ), 'secondary' );
		} else {
			echo '<input type="hidden" name="pairing_decision" value="enable" />';
			submit_button( __( 'Reconnect trusted GitHub Actions', 'wordpressconnector' ), 'primary' );
		}
		echo '</form></div>';
		echo '<details class="wpconnector-disclosure"><summary>' . esc_html__( 'Plugin updates through GitHub', 'wordpressconnector' ) . '</summary>';
		echo '<p>' . esc_html__( 'The Plugins screen checks verified GitHub releases when an administrator views it. Use Check GitHub update now for a manual refresh. A commit on main is not an installable update until CI and the versioned release ZIP are published.', 'wordpressconnector' ) . '</p>';
		echo '</details>';
	}

	/**
	 * Keep credential creation secondary and preserve security prerequisites.
	 *
	 * @param array $checks Installation prerequisite results.
	 * @param bool  $legacy Whether the old Elementor bridge is active.
	 */
	private function render_advanced_panel( array $checks, bool $legacy ): void {
		echo '<h2 id="wpconnector-heading-advanced">' . esc_html__( 'Advanced settings', 'wordpressconnector' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Technical checks and sensitive alternatives. Most sites do not need to change anything here.', 'wordpressconnector' ) . '</p>';

		echo '<details class="wpconnector-disclosure"><summary>' . esc_html__( 'Direct MCP Application Password (advanced)', 'wordpressconnector' ) . '</summary>';
		echo '<p>' . esc_html__( 'A trusted direct REST/MCP client may use an Application Password. This is not needed for WordPress-hosted ChatGPT OAuth or GitHub OIDC. A password grants the same permissions as your WordPress account.', 'wordpressconnector' ) . '</p>';
		$current_id = get_current_user_id();
		$can_issue  = $current_id > 0 && current_user_can( 'create_app_password', $current_id )
			&& is_ssl()
			&& 'https' === strtolower( (string) wp_parse_url( rest_url( 'webactueel-wordpress-connector/v1/mcp' ), PHP_URL_SCHEME ) )
			&& function_exists( 'wp_is_application_passwords_available_for_user' )
			&& wp_is_application_passwords_available_for_user( $current_id );
		if ( $can_issue ) {
			$exists = \WP_Application_Passwords::application_name_exists_for_user( $current_id, self::DIRECT_MCP_PASSWORD_LABEL );
			if ( $exists ) {
				echo '<p>' . esc_html__( 'A direct MCP Application Password already exists for your account. Revoke it in your profile before requesting another; its value cannot be shown again.', 'wordpressconnector' ) . '</p>';
			} else {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'wpconnector_create_application_password' );
				echo '<input type="hidden" name="action" value="wpconnector_create_application_password" />';
				echo '<p><label><input type="checkbox" name="confirm_direct_access" value="1" required="required" /> ';
				echo esc_html__( 'I understand this password grants my WordPress account permissions and will only be displayed once.', 'wordpressconnector' ) . '</label></p>';
				submit_button( __( 'Generate my direct MCP password', 'wordpressconnector' ), 'secondary' );
				echo '</form>';
			}
		} else {
			echo '<p>' . esc_html__( 'Direct Application Password setup is unavailable: check HTTPS, account capabilities, and WordPress settings.', 'wordpressconnector' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'Manage or revoke Application Passwords', 'wordpressconnector' ) . '</a></p></details>';

		echo '<div class="wpconnector-card"><h3>' . esc_html__( 'Installation readiness', 'wordpressconnector' ) . '</h3>';
		echo '<table class="widefat striped wpconnector-table"><tbody>';
		foreach ( $checks as $label => $ready ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $ready ? __( 'Ready', 'wordpressconnector' ) : __( 'Needs attention', 'wordpressconnector' ) ) . '</td></tr>';
		}
		if ( is_multisite() ) {
			echo '<tr><th scope="row">' . esc_html__( 'WordPress multisite blog ID', 'wordpressconnector' ) . '</th><td><code>' . esc_html( (string) get_current_blog_id() ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Firewall rules, outbound GitHub JWKS, permalinks, and compatibility still need a separate end-to-end test.', 'wordpressconnector' ) . '</p></div>';

		echo '<details class="wpconnector-disclosure"><summary>' . esc_html__( 'Connection details', 'wordpressconnector' ) . '</summary>';
		echo '<p>' . esc_html__( 'After a WordPress administrator clicks Activate, this plugin records authorization for the pinned GitHub workflow. Remote OIDC validation remains a separate check.', 'wordpressconnector' ) . '</p>';
		echo '<p>' . esc_html__( 'Optional emergency policy overrides can disable connector actions without changing this connection status.', 'wordpressconnector' ) . '</p>';
		echo '<table class="widefat striped wpconnector-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Connector version', 'wordpressconnector' ) . '</th><td><code>' . esc_html( defined( 'WPCONNECTOR_VERSION' ) ? WPCONNECTOR_VERSION : '' ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Presence endpoint', 'wordpressconnector' ) . '</th><td><code>' . esc_html( rest_url( 'webactueel-wordpress-connector/v1/presence' ) ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'REST health endpoint', 'wordpressconnector' ) . '</th><td><code>' . esc_html( rest_url( 'webactueel-wordpress-connector/v1/health' ) ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'GitHub authentication', 'wordpressconnector' ) . '</th><td>' . esc_html__( 'Short-lived GitHub Actions OIDC token, normally enabled on administrator activation', 'wordpressconnector' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Persistent connector secrets', 'wordpressconnector' ) . '</th><td>' . esc_html__( 'None required', 'wordpressconnector' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Execution safety', 'wordpressconnector' ) . '</th><td>' . esc_html__( 'Capability checks, dry-run, confirmation, stale-state protection, readback, and rollback', 'wordpressconnector' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Supported integrations', 'wordpressconnector' ) . '</th><td>' . esc_html__( 'WordPress, Elementor, Gutenberg, WooCommerce, ACF, Yoast SEO, media, plugin settings', 'wordpressconnector' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Legacy bridge', 'wordpressconnector' ) . '</th><td>' . esc_html( $legacy ? __( 'Active — stage removal check required', 'wordpressconnector' ) : __( 'Not active', 'wordpressconnector' ) ) . '</td></tr>';
		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Suggested test sequence: presence → OIDC health → connector.discover → system.doctor → dry-run → approved staging write and rollback.', 'wordpressconnector' ) . '</p>';
		echo '</details>';
	}

	/**
	 * Guided setup does not create OAuth credentials, trigger backups,
	 * connect hosting accounts, or claim that a staging restore was tested.
	 * It reuses the private read-only connector readiness facts.
	 */
	private function render_connection_recovery_guide(): void {
		$status   = ( new ConnectionReadinessAdapter() )->inspect();
		$site     = $status['wordpress'];
		$oauth    = $status['chatgpt'];
		$recovery = $status['recovery'];

		echo '<div class="wpconnector-card wpconnector-oauth-card">';
		$this->render_native_oauth_controls();
		echo '</div>';
		echo '<div class="wpconnector-card"><h3>' . esc_html__( 'MCP address and browser check', 'wordpressconnector' ) . '</h3>';
		echo '<p>' . esc_html__( 'This page tests only WordPress data; it cannot log in to ChatGPT or prove that an offsite backup can be restored.', 'wordpressconnector' ) . '</p>';
		echo '<div class="wpconnector-connection-doctor">';
		echo '<p><label for="wpconnector-mcp-address"><strong>' . esc_html__( 'Your WordPress MCP endpoint', 'wordpressconnector' ) . '</strong></label></p>';
		echo '<input id="wpconnector-mcp-address" class="regular-text" readonly="readonly" type="url" value="' . esc_attr( rest_url( 'webactueel-wordpress-connector/v1/mcp' ) ) . '" />';
		echo '<p><button type="button" class="button button-secondary" id="wpconnector-copy-mcp">' . esc_html__( 'Copy endpoint', 'wordpressconnector' ) . '</button> ';
		echo '<button type="button" class="button button-secondary" id="wpconnector-test-mcp">' . esc_html__( 'Test MCP connection from this WordPress browser', 'wordpressconnector' ) . '</button></p>';
		echo '<p role="status" aria-live="polite" id="wpconnector-mcp-doctor-result"></p>';
		echo '<p><small>' . esc_html__( 'This test only uses your current signed-in WordPress session. It does NOT connect ChatGPT, authorize OAuth, test public access from ChatGPT, or make changes.', 'wordpressconnector' ) . '</small></p>';
		echo '<p>' . esc_html__( 'ChatGPT error: This app does not provide a browser configuration URL? This is a ChatGPT app/reconnect setting, not the WordPress MCP address. Do not use Configure browser. In ChatGPT Plugins, add a new custom MCP server with the MCP endpoint shown above and OAuth, then verify the new connection before removing the old one. Updating WordPress alone cannot repair a cached ChatGPT app.', 'wordpressconnector' ) . '</p>';
		echo '</div></div>';
		echo '<details class="wpconnector-disclosure"><summary>' . esc_html__( 'ChatGPT connection and recovery setup', 'wordpressconnector' ) . '</summary>';
		echo '<table class="widefat striped wpconnector-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Setup step', 'wordpressconnector' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Current evidence', 'wordpressconnector' ) . '</th>';
		echo '</tr></thead><tbody>';

		$rows = array(
			__( 'HTTPS for WordPress and MCP', 'wordpressconnector' ) =>
				$site['https_ready'] ? __( 'Available', 'wordpressconnector' ) : __( 'Needs attention', 'wordpressconnector' ),
			__( 'GitHub site pairing', 'wordpressconnector' ) =>
				$site['github_pairing_recorded'] ? __( 'Recorded in WordPress (remote OIDC requires its own test)', 'wordpressconnector' ) : __( 'Not recorded', 'wordpressconnector' ),
			__( 'Direct ChatGPT OAuth', 'wordpressconnector' ) =>
				$oauth['oauth_21_ready'] ? __( 'Verified', 'wordpressconnector' ) : ( ! empty( $oauth['oauth_provider_configured'] ) ? __( 'OAuth resource configured; external login and consent have not been verified', 'wordpressconnector' ) : __( 'Not configured: enable WordPress-hosted OAuth or configure a trusted external provider', 'wordpressconnector' ) ),
			__( 'Full database and files backup', 'wordpressconnector' ) =>
				__( 'Not independently verified', 'wordpressconnector' ),
			__( 'Copy stored outside this hosting account', 'wordpressconnector' ) =>
				__( 'Not independently verified', 'wordpressconnector' ),
			__( 'Full restore tested on staging', 'wordpressconnector' ) =>
				__( 'Not independently verified', 'wordpressconnector' ),
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$providers = $recovery['active_backup_plugins_detected'];
		if ( count( $providers ) > 0 ) {
			echo '<p>' . esc_html__( 'Active backup plugin detected (not proof of a usable backup):', 'wordpressconnector' ) . ' <strong>' . esc_html( implode( ', ', $providers ) ) . '</strong>.</p>';
		} else {
			echo '<p>' . esc_html__( 'No recognized backup plugin is active. Your hosting company may still provide backups; WordPress cannot verify their contents or restore status by itself.', 'wordpressconnector' ) . '</p>';
		}

		echo '<ol>';
		echo '<li>' . esc_html__( 'Enable the WordPress-hosted OAuth server here, or configure a trusted external OAuth provider. Then use explicit WordPress consent with PKCE. Never share Application Passwords.', 'wordpressconnector' ) . '</li>';
		echo '<li>' . esc_html__( 'In a ChatGPT workspace that permits custom MCP connections, an admin must add the HTTPS MCP endpoint and approve OAuth linking.', 'wordpressconnector' ) . '</li>';
		echo '<li>' . esc_html__( 'Configure a full backup with the hosting or backup provider: files plus database, with an independent offsite copy.', 'wordpressconnector' ) . '</li>';
		echo '<li>' . esc_html__( 'Restore that backup on a separate staging site and confirm that content, files, plugins and the database are intact before high-risk writes.', 'wordpressconnector' ) . '</li>';
		echo '</ol>';
		echo '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Review backup plugins', 'wordpressconnector' ) . '</a> | ';
		echo '<a href="' . esc_url( 'https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt' ) . '">' . esc_html__( 'Official ChatGPT MCP connection instructions', 'wordpressconnector' ) . '</a> | ';
		echo '<a href="' . esc_url( 'https://developer.wordpress.org/advanced-administration/security/backup/' ) . '">' . esc_html__( 'WordPress full-site backup guidance', 'wordpressconnector' ) . '</a></p>';
		echo '<p><strong>' . esc_html__( 'High-risk actions must remain on a tested staging and backup path.', 'wordpressconnector' ) . '</strong> ';
		echo esc_html__( 'An installed backup plugin, the WordPress temporary update backup, or a green CI run are not proof that a complete site can be restored.', 'wordpressconnector' ) . '</p>';
		echo '</details>';
	}

	/**
	 * Render the opt-in native OAuth status and administrator controls.
	 */
	private function render_native_oauth_controls(): void {
		$enabled          = NativeOAuthServer::is_enabled();
		$prior_approval   = NativeOAuthServer::has_prior_approval();
		$revocation_ready = $enabled || $prior_approval;
		echo '<h3>' . esc_html__( 'WordPress-hosted ChatGPT OAuth (explicit consent)', 'wordpressconnector' ) . '</h3>';
		echo '<p>' . esc_html__( 'No additional provider account or API key is needed. Only your signed-in administrator account can approve a ChatGPT connection. Read-only access is the default; file editing requires an independently approved staging-only scope. OAuth does not authorize content updates or plugin installs.', 'wordpressconnector' ) . '</p>';
		if ( NativeOAuthServer::staging_files_enabled() && ( current_user_can( 'edit_plugins' ) || current_user_can( 'edit_themes' ) ) ) {
			echo '<p><strong>' . esc_html__( 'Staging file-edit scope available', 'wordpressconnector' ) . '</strong>: ' . esc_html__( 'A new ChatGPT OAuth approval must explicitly include wpconnector:files. Existing read-only grants do not gain file-write permissions automatically.', 'wordpressconnector' ) . '</p>';
		} elseif ( NativeOAuthServer::staging_files_enabled() ) {
			echo '<p>' . esc_html__( 'Staging file editing is configured, but your WordPress account has no plugin/theme file-editor permission. ChatGPT can still connect read-only. WordPress security remains enforced.', 'wordpressconnector' ) . '</p>';
		}
		if ( defined( 'WPCONNECTOR_OAUTH_ISSUER' ) ) {
			echo '<p>' . esc_html__( 'An external OAuth provider is already configured. Native OAuth cannot be enabled at the same time.', 'wordpressconnector' ) . '</p>';
			if ( ! $prior_approval ) {
				return;
			}
			echo '<p>' . esc_html__( 'A previous native OAuth approval is still stored. Revoke it to prevent old grants from becoming active again when external OAuth is removed.', 'wordpressconnector' ) . '</p>';
		}
		$state = $enabled ? __( 'Enabled: consent required for each link', 'wordpressconnector' ) : ( $prior_approval ? __( 'Inactive: revoke previous native approval', 'wordpressconnector' ) : __( 'Disabled', 'wordpressconnector' ) );
		echo '<p><strong>' . esc_html( $state ) . '</strong></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpconnector_native_oauth_mode' );
		echo '<input type="hidden" name="action" value="wpconnector_native_oauth_mode" />';
		echo '<input type="hidden" name="native_oauth_decision" value="' . esc_attr( $revocation_ready ? 'disable' : 'enable' ) . '" />';
		submit_button(
			$revocation_ready
			? __( 'Disable and revoke all ChatGPT OAuth tokens', 'wordpressconnector' )
			: __( 'Enable WordPress-hosted OAuth', 'wordpressconnector' ),
			$revocation_ready ? 'secondary' : 'primary'
		);
		echo '</form>';
		if ( $enabled ) {
			echo '<p><strong>' . esc_html__( 'Authorization server', 'wordpressconnector' ) . ':</strong> <code>' . esc_html( NativeOAuthServer::discovery_url() ) . '</code></p>';
			echo '<p>' . esc_html__( 'Tokens expire after 15 minutes and rotate on refresh. Disable OAuth here to immediately revoke all previously issued connections. Live ChatGPT consent must be tested separately.', 'wordpressconnector' ) . '</p>';
			echo '<details class="wpconnector-disclosure"><summary>' . esc_html__( 'Custom ChatGPT callback (advanced)', 'wordpressconnector' ) . '</summary>';
			echo '<p>' . esc_html__( 'Use only the exact callback URL shown in the ChatGPT plugin settings when your custom plugin uses a user-provided OAuth client. Saving a new callback revokes existing native OAuth tokens.', 'wordpressconnector' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wpconnector_native_oauth_mode' );
			echo '<input type="hidden" name="action" value="wpconnector_native_oauth_mode" />';
			echo '<input type="hidden" name="native_oauth_decision" value="register_redirect" />';
			echo '<p><label for="wpconnector_native_oauth_redirect">' . esc_html__( 'Exact ChatGPT callback URL', 'wordpressconnector' ) . '</label></p>';
			echo '<p><input class="regular-text" type="url" id="wpconnector_native_oauth_redirect" name="native_oauth_redirect" value="' . esc_attr( NativeOAuthServer::registered_redirect() ) . '" required /></p>';
			submit_button( __( 'Register callback and revoke existing tokens', 'wordpressconnector' ), 'secondary' );
			echo '</form></details>';
		}
	}

	/** Report whether the legacy OAuth bridge is active. */
	private function legacy_bridge_active(): bool {
		if ( class_exists( 'Webactueel\\ElementorJsonBridge\\Plugin' ) ) {
			return true; }
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		foreach ( array( 'elementor-json-bridge/elementor-json-bridge.php', 'Elementorconnector/elementor-json-bridge.php' ) as $plugin_file ) {
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) {
				return true; }
		}
		return false;
	}
}
