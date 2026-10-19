<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic, content-free error guidance. Never copies exception messages,
 * request payloads, credentials or URLs into diagnostic metadata.
 */
final class Diagnostics {

	public static function classify( string $message, bool $mutating = false ): array {
		$msg        = strtolower( substr( $message, 0, 1000 ) );
		$category   = 'unknown_failure';
		$resolution = 'Inspect the request-correlated, access-controlled WordPress and transport logs.';

		if ( self::has( $msg, array( 'github oidc is not approved', 'github oidc site approval is unavailable or was revoked' ) ) ) {
			$category   = 'site_approval_required';
			$resolution = 'Ask an administrator of the exact WordPress site to approve or renew its site-bound GitHub OIDC connection.';
		} elseif ( self::has( $msg, array( 'github oidc audience is invalid for this wordpress site', 'site identity mismatch', 'wordpress site url changed' ) ) ) {
			$category   = 'site_identity_mismatch';
			$resolution = 'Verify the exact HTTPS site origin, WordPress REST base and OIDC audience; never redirect a request to another site.';
		} elseif ( self::has( $msg, array( 'php openssl extension', 'php ziparchive is required', 'requires php ', 'requires wordpress ', 'minimum wordpress version' ) ) ) {
			$category   = 'runtime_incompatible';
			$resolution = 'Check the required PHP extension and minimum WordPress/PHP version before retrying; do not bypass runtime checks.';
		} elseif ( self::has( $msg, array( 'github api rate limit', 'github rate limit' ) ) ) {
			$category   = 'github_rate_limited';
			$resolution = $mutating
				? 'Reconcile the original request_id and remote state before any retry after the GitHub rate limit clears.'
				: 'Check GitHub API rate-limit status and retry this read with backoff.';
		} elseif ( self::has( $msg, array( 'hosting waf blocked', 'blocked by hosting waf', 'hosting firewall denied', 'security plugin blocked' ) ) ) {
			$category   = 'security_policy_blocked';
			$resolution = 'Check authorized hosting WAF and WordPress security logs; do not attribute the block to a particular plugin without evidence or disable protection.';
		} elseif ( self::has( $msg, array( 'outbound http is blocked', 'outbound network blocked' ) ) ) {
			$category   = 'outbound_network_blocked';
			$resolution = 'Check hosting outbound HTTPS policy and DNS access; do not bypass network restrictions.';
		} elseif ( self::has( $msg, array( 'wordpress filesystem api could not write', 'wordpress filesystem api could not initialize', 'direct filesystem mode', 'filesystem credentials', 'filesystem refused deletion', 'filesystem api is unavailable', 'filesystem permissions may block' ) ) ) {
			$category   = 'filesystem_unavailable';
			$resolution = 'Check WordPress filesystem mode, ownership, writable paths and server policy; never request FTP/SSH credentials through public transport.';
		} elseif ( self::has( $msg, array( 'wordpress plugin installation failed', 'plugin installer returned success', 'plugin update failed', 'wordpress could not complete plugin deletion' ) ) ) {
			$category   = 'plugin_operation_failed';
			$resolution = 'Inspect the WordPress upgrader result, target identity, update availability and filesystem policy; reread installed plugin state.';
		} elseif ( self::has( $msg, array( 'readback failed', 'readback verification failed', 'did not persist', 'installed version did not increase', 'file still exists after attempted deletion' ) ) ) {
			$category   = 'persistence_unverified';
			$resolution = 'Treat the change as unverified, reread the exact WordPress target and reconcile original request_id before any retry or rollback.';
		} elseif ( self::has( $msg, array( 'http 401', 'authentication is required', 'not authenticated', 'oidc authentication' ) ) ) {
			$category   = 'authentication_required';
			$resolution = 'Verify the existing HTTPS authentication and OIDC trust configuration; do not bypass authentication.';
		} elseif ( self::has( $msg, array( 'http 403', 'forbidden', 'lacks permission', 'lacks the required', 'not allowed', 'disabled by server policy', 'privileged actions are blocked' ) ) ) {
			$category   = 'permission_denied';
			$resolution = 'Check WordPress capabilities and the hosting or WAF access policy; keep authorization enabled.';
		} elseif ( self::has( $msg, array( 'fingerprint does not match', 'fingerprint mismatch', 'state token', 'stale state', 'changed since', 'changed after', 'request branch moved' ) ) ) {
			$category   = 'stale_state';
			$resolution = 'Read the current state, repeat the dry-run and use the new fingerprint before any write.';
		} elseif ( self::has( $msg, array( 'another connector mutation', 'already in progress', 'locked by another' ) ) ) {
			$category   = 'mutation_busy';
			$resolution = 'Check the existing request_id outcome and wait for the active mutation to finish before retrying that same request_id.';
		} elseif ( self::has( $msg, array( 'already used for a different mutation', 'already exists', 'ownership conflict', 'conflicting change' ) ) ) {
			$category   = 'conflict';
			$resolution = 'Resolve request or resource ownership; do not overwrite or choose another identifier blindly.';
		} elseif ( self::has( $msg, array( 'timed out', 'timeout', 'http 429', 'http 502', 'http 503', 'http 504', 'rate limit', 'could not resolve host', 'connection refused' ) ) ) {
			$category   = 'transient_transport';
			$resolution = $mutating
				? 'Reconcile the original request_id and server state before any retry; the write outcome may be unknown.'
				: 'Retry this read with backoff after checking the network and provider status.';
		} elseif ( self::has( $msg, array( 'not active', 'not installed', 'missing dependency', 'not available', 'not supported', 'requires an active' ) ) ) {
			$category   = 'dependency_unavailable';
			$resolution = 'Inspect the installed plugin version and required feature; do not guess an alternative API.';
		} elseif ( self::has( $msg, array( 'unknown connector action', 'unsupported field', 'invalid ', 'requires ', 'must be ', 'not a valid', 'is required' ) ) ) {
			$category   = 'invalid_request';
			$resolution = 'Correct the request using the advertised action schema and run a dry-run first.';
		}

		return array(
			'code'                   => $category,
			'retryable_read'         => ! $mutating && in_array( $category, array( 'transient_transport', 'github_rate_limited' ), true ),
			'reconcile_before_retry' => $mutating,
			'next_step'              => $resolution,
		);
	}

	private static function has( string $message, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
