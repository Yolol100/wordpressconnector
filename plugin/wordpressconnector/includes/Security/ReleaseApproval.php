<?php
/**
 * Require a WordPress administrator's browser approval for each exact
 * Connector release before an OAuth client may request its installation.
 *
 * @package WordPressConnector
 */
declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

use Throwable;
use Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter;

/**
 * A ChatGPT-provided confirm:true is NOT evidence of owner consent.
 *
 * Owner approval originates only from an authenticated wp-admin form with a
 * WordPress nonce, a live cookie session and the exact release fingerprint.
 */
final class ReleaseApproval {
	private const OPTION = 'wpconnector_oauth_release_approval_v1';
	private const CLAIM_PREFIX = 'wpconnector_oauth_release_claim_';
	private const TTL_SECONDS = 600;
	private const NONCE_ACTION = 'wpconnector_oauth_approve_release';

	/**
	 * A host administrator must pin the exact ZIP digest after independently
	 * verifying the trusted build attestation against protected main.
	 * A GitHub release checksum alone is not provenance proof. No pin: deny.
	 */
	private static function pinnedDigest( string $digest ): bool {
		$pin = defined( 'WPCONNECTOR_OAUTH_ATTESTED_RELEASE_SHA256' )
			? constant( 'WPCONNECTOR_OAUTH_ATTESTED_RELEASE_SHA256' )
			: null;
		return is_string( $pin ) && preg_match( '/^sha256:[a-f0-9]{64}\z/', $pin )
			&& hash_equals( $pin, $digest );
	}

	/** Register the dashboard and POST-only owner consent flow. */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'renderForm' ) );
		add_action( 'admin_post_wpconnector_oauth_approve_release', array( $this, 'approve' ) );
	}

	/** Does the current authenticated browser belong to the explicitly linked owner? */
	private static function ownerBrowser(): bool {
		if ( ! is_ssl() || ! OAuthResourceServer::selfUpdateConfigured()
			|| ! is_user_logged_in() || ! current_user_can( 'manage_options' )
			|| ! current_user_can( 'update_plugins' )
			|| ! function_exists( 'wp_get_session_token' )
			|| '' === wp_get_session_token()
			|| ! defined( 'WPCONNECTOR_OAUTH_WORDPRESS_USER_ID' ) ) {
			return false;
		}
		$id = constant( 'WPCONNECTOR_OAUTH_WORDPRESS_USER_ID' );
		return is_int( $id ) && $id > 0 && get_current_user_id() === $id;
	}

	/**
	 * Compute the same release fingerprint that the existing self-updater
	 * enforces on a confirmed update. No release id/ZIP URL is user supplied.
	 *
	 * @return array{fingerprint:string,tag:string,digest:string}|null
	 */
	private static function preview(): ?array {
		try {
			$data = ( new ConnectorUpdateAdapter() )->apply( array(), array( 'dry_run' => true, 'require_release_provenance' => true ) );
		} catch ( Throwable $error ) {
			return null;
		}
		$fp   = $data['release_fingerprint'] ?? null;
		$plan = $data['would_update_connector'] ?? null;
		if ( ! is_string( $fp ) || ! preg_match( '/^[a-f0-9]{64}\z/', $fp )
			|| ! is_array( $plan ) || ( $data['_current_fingerprint'] ?? null ) !== $fp
			|| ! isset( $plan['tag'], $plan['github_asset_digest'] )
			|| ! is_string( $plan['tag'] ) || ! preg_match( '/^v[0-9]+\.[0-9]+\.[0-9]+\z/', $plan['tag'] )
			|| ! is_string( $plan['github_asset_digest'] )
			|| ! preg_match( '/^sha256:[a-f0-9]{64}\z/', $plan['github_asset_digest'] ) ) {
			return null;
		}
		if ( ! self::pinnedDigest( $plan['github_asset_digest'] ) ) {
			return null;
		}
		return array(
			'fingerprint' => $fp,
			'tag'         => $plan['tag'],
			'digest'      => $plan['github_asset_digest'],
		);
	}

	/** Display exactly which published release the linked owner would approve. */
	public function renderForm(): void {
		if ( ! function_exists( 'get_current_screen' ) || ! self::ownerBrowser() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! is_object( $screen ) || 'plugins' !== ( $screen->id ?? null ) ) {
			return;
		}
		$release = self::preview();
		if ( null === $release ) {
			return; // No verified newer release available: no approval action.
		}
		echo '<div class="notice notice-warning"><p><strong>';
		echo esc_html__( 'WordPress Connector: approve one verified release', 'wordpressconnector' );
		echo '</strong></p><p>';
		echo esc_html( $release['tag'] . ' · ' . $release['digest'] );
		echo '</p><p>';
		echo esc_html__( 'Approving permits one installation of this exact release for 10 minutes, using the separately authorized ChatGPT update scope.', 'wordpressconnector' );
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wpconnector_oauth_approve_release" />';
		echo '<input type="hidden" name="expected_fingerprint" value="' . esc_attr( $release['fingerprint'] ) . '" />';
		echo wp_nonce_field( self::NONCE_ACTION, '_wpnonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress-generated hidden nonce field markup.
		echo '<button type="submit" class="button button-primary">';
		echo esc_html__( 'Approve this Connector release', 'wordpressconnector' );
		echo '</button></form></div>';
	}

	/** Create short-lived, single-use consent through the real wp-admin session. */
	public function approve(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::ownerBrowser() ) {
			wp_die( esc_html__( 'A logged-in owner session is required.', 'wordpressconnector' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );
		$raw = $_POST['expected_fingerprint'] ?? null;
		$requested = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}\z/', $requested ) ) {
			wp_die( esc_html__( 'Invalid release approval request.', 'wordpressconnector' ), '', array( 'response' => 400 ) );
		}
		$release = self::preview();
		if ( null === $release || ! hash_equals( $release['fingerprint'], $requested ) ) {
			wp_die( esc_html__( 'The release changed. Reload and approve the current version.', 'wordpressconnector' ), '', array( 'response' => 409 ) );
		}
		$approval_id = str_replace( '-', '', wp_generate_uuid4() );
		if ( ! preg_match( '/^[a-f0-9]{32}\z/', $approval_id ) ) {
			wp_die( esc_html__( 'Unable to create a secure approval.', 'wordpressconnector' ), '', array( 'response' => 500 ) );
		}
		$now = time();
		$approval = array(
			'id'          => $approval_id,
			'fingerprint' => $release['fingerprint'],
			'digest'      => $release['digest'],
			'owner'       => get_current_user_id(),
			'issued'      => $now,
			'expires'     => $now + self::TTL_SECONDS,
		);
		if ( ! update_option( self::OPTION, $approval, false ) ) {
			wp_die( esc_html__( 'The approval could not be saved.', 'wordpressconnector' ), '', array( 'response' => 503 ) );
		}
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Atomically claim an unexpired, exact owner approval. A second MCP
	 * request cannot reuse it even with a different idempotency request_id.
	 */
	public static function consume( string $fingerprint ): bool {
		if ( ! OAuthResourceServer::selfUpdateConfigured() || ! preg_match( '/^[a-f0-9]{64}\z/', $fingerprint )
			|| ! is_user_logged_in() || ! current_user_can( 'update_plugins' ) ) {
			return false;
		}
		$record = get_option( self::OPTION, false );
		$now = time();
		if ( ! is_array( $record )
			|| ! isset( $record['id'], $record['fingerprint'], $record['digest'], $record['owner'], $record['issued'], $record['expires'] )
			|| ! is_string( $record['id'] ) || ! preg_match( '/^[a-f0-9]{32}\z/', $record['id'] )
			|| ! is_string( $record['fingerprint'] )
			|| ! hash_equals( $record['fingerprint'], $fingerprint )
			|| ! is_string( $record['digest'] ) || ! self::pinnedDigest( $record['digest'] )
			|| ! is_int( $record['owner'] ) || get_current_user_id() !== $record['owner']
			|| ! is_int( $record['issued'] ) || $record['issued'] > $now
			|| ! is_int( $record['expires'] ) || $record['expires'] <= $now
			|| $record['expires'] - $record['issued'] !== self::TTL_SECONDS ) {
			return false;
		}
		// add_option uses the unique option_name database constraint. The
		// immutable per-approval id prevents concurrent double consumption.
		$claimed = add_option( self::CLAIM_PREFIX . $record['id'], (string) $now, '', 'no' );
		if ( ! $claimed ) {
			return false;
		}
		delete_option( self::OPTION );
		return true;
	}
}
